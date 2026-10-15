// GPS-optageren (Smart-mode).
//
// Punkterne kommer fra @capgo/background-geolocation, der på Android kører
// som foreground service med en synlig notifikation. Hvert godkendt punkt
// skrives straks til punkter.jsonl, og GPX-filen skrives mindst hvert minut.
// Der optages kun, når brugeren selv har trykket "Start".
(function () {
    'use strict';

    var BG = window.capacitorBackgroundGeolocation.BackgroundGeolocation;
    var App = window.capacitorApp.App;
    var Share = window.capacitorShare.Share;
    var Capacitor = window.capacitorExports.Capacitor;
    var Lager = window.MitSporLager;
    var Gpx = window.MitSporGpx;
    var Spor = window.MitSporSpor;
    var Konto = window.MitSporKonto;
    var Upload = window.MitSporUpload;
    var UploadVisning = window.MitSporUploadVisning;

    // Smart-mode: alle brugbare GPS-punkter gemmes i punkter.jsonl, og
    // GPX-filen laves af det rensede spor (spor.js).
    var MAX_NOEJAGTIGHED_M = 20;
    // Før første punkt (og efter en pause) skal nøjagtigheden have været
    // mindst så god flere gange i træk. Retter skæve starter.
    var START_NOEJAGTIGHED_M = 15;
    var START_ANTAL = 3;
    var MIN_AFSTAND_M = 3;             // rå punkter tættere end dette springes over
    var MAX_FART_MS = 70;              // spring over 250 km/t er GPS-fejl
    var RENS_INTERVAL_MS = 15 * 1000;  // afstand og punkter på skærmen
    var GPX_INTERVAL_MS = 60 * 1000;
    // Hold GPX-filen et stykke under MAX_UPLOAD_SIZE_BYTES (10 MB) på sitet.
    var MAX_GPX_BYTES = 9.5 * 1024 * 1024;
    // Er der stadig ikke et præcist signal efter så lang tid, vises en
    // forklaring (indendørs, eller en tablet uden GPS).
    var GPS_HJAELP_MS = 60 * 1000;

    var aabnKnap = document.getElementById('open-recorder');
    var tilbageKnap = document.getElementById('optag-tilbage');
    var startKnap = document.getElementById('optag-start');
    var stopKnap = document.getElementById('optag-stop');
    var pauseKnap = document.getElementById('optag-pause');
    var genoptagKnap = document.getElementById('optag-genoptag');
    var tidFelt = document.getElementById('optag-tid');
    var afstandFelt = document.getElementById('optag-afstand');
    var punkterFelt = document.getElementById('optag-punkter');
    var gpsFelt = document.getElementById('optag-gps');
    var gpsHjaelp = document.getElementById('optag-gps-hjaelp');
    var fejlFelt = document.getElementById('optag-fejl');
    var afbrudtBoks = document.getElementById('optag-afbrudt');
    var afbrudtTekst = document.getElementById('optag-afbrudt-tekst');
    var fortsaetKnap = document.getElementById('optag-fortsaet');
    var afslutKnap = document.getElementById('optag-afslut');
    var listeFelt = document.getElementById('optag-liste');
    var tomFelt = document.getElementById('optag-tom');

    // Den aktive optagelse. aktiv er sand, også når den holder pause.
    var aktiv = false;
    var pauset = false;
    var meta = null;
    var punkter = [];
    var sidst = null;
    var segment = 0;
    var sidstGpx = 0;
    var sidstRens = 0;
    var renset = [];       // det rensede spor, som det så ud ved sidste rensning
    var klar = false;      // har GPS'en fundet et præcist signal?
    var godeITraek = 0;
    var urTimer = null;
    var hjaelpTimer = null;
    var afbrudt = null;   // en optagelse, der ikke blev afsluttet (nedbrud/genstart)
    var logget = false;   // er brugeren logget ind i appen?

    // Skrivninger køres én ad gangen, i den rækkefølge de kommer.
    var koe = Promise.resolve();

    function iKoe(opgave) {
        koe = koe.then(opgave).catch(function (fejl) {
            visFejl('Kunne ikke gemme på telefonen: ' + (fejl && fejl.message ? fejl.message : fejl));
        });
        return koe;
    }

    function visFejl(tekst) {
        fejlFelt.textContent = tekst;
        fejlFelt.hidden = !tekst;
    }

    // ---------- Formatering ----------

    function km(meter) {
        return (meter / 1000).toFixed(2).replace('.', ',') + ' km';
    }

    function to(n) {
        return (n < 10 ? '0' : '') + n;
    }

    function ur(ms) {
        var s = Math.max(0, Math.floor(ms / 1000));
        return Math.floor(s / 3600) + ':' + to(Math.floor(s / 60) % 60) + ':' + to(s % 60);
    }

    function varighed(ms) {
        var min = Math.round(ms / 60000);
        if (min < 60) {
            return min + ' min';
        }
        return Math.floor(min / 60) + ' t ' + (min % 60) + ' min';
    }

    // Tid i bevægelse: fra start til nu (eller slut) minus pauserne.
    // meta.pauseMs er de afsluttede pauser, meta.pauseStart en igangværende.
    function koretid(m, nu) {
        var pause = (m.pauseMs || 0) + (m.pauseStart ? nu - m.pauseStart : 0);
        return nu - m.start - pause;
    }

    // ---------- Visning ----------

    function visView(optag) {
        window.MitSporApp.visSide(optag ? 'view-optag' : 'view-start');
    }

    function opdaterTal() {
        tidFelt.textContent = meta ? ur(koretid(meta, Date.now())) : '0:00:00';
        afstandFelt.textContent = km(meta ? meta.afstandM : 0);
        punkterFelt.textContent = String(meta ? meta.antalPunkter : 0);
    }

    function opdaterKnapper() {
        startKnap.hidden = aktiv;
        pauseKnap.hidden = !aktiv || pauset;
        genoptagKnap.hidden = !aktiv || !pauset;
        stopKnap.hidden = !aktiv;
        startKnap.disabled = !!afbrudt;
        aabnKnap.textContent = !aktiv ? 'Optag tur' : (pauset ? 'Optagelse på pause' : 'Optagelse i gang');
        afbrudtBoks.hidden = !afbrudt || aktiv;
        if (afbrudt) {
            afbrudtTekst.textContent = afbrudt.navn + ' blev ikke afsluttet. Vil du fortsætte optagelsen eller afslutte og gemme den?';
        }
    }

    function visListe() {
        return Lager.alle().then(function (liste) {
            var faerdige = liste.filter(function (m) { return m.status === 'afsluttet'; });
            afbrudt = aktiv ? null : (liste.filter(function (m) { return m.status === 'optager' || m.status === 'pause'; })[0] || null);
            opdaterKnapper();

            listeFelt.textContent = '';
            tomFelt.hidden = faerdige.length > 0;
            faerdige.forEach(function (m) {
                listeFelt.appendChild(listePunkt(m));
            });
        });
    }

    function listePunkt(m) {
        var li = document.createElement('li');
        li.className = 'tur';

        var navn = document.createElement('div');
        navn.className = 'tur-navn';
        navn.textContent = m.navn;

        var info = document.createElement('div');
        info.className = 'tur-info';
        info.textContent = km(m.afstandM) + ' · ' + varighed(koretid(m, m.slut || m.start)) + ' · ' + m.antalPunkter + ' punkter';

        var status = document.createElement('div');
        status.className = 'tur-upload' + (m.upload && m.upload.status === 'uploadet' ? ' tur-upload--ok' : '');
        status.textContent = uploadTekst(m);
        status.hidden = !status.textContent;

        var knapper = document.createElement('div');
        knapper.className = 'tur-knapper';
        var u = m.upload;
        var sendes = Upload.igang() === m.id;

        if (!u || u.status === 'fejl') {
            knapper.appendChild(knap('Upload', 'btn-primary', function () { UploadVisning.aabn(m); }));
        } else if (u.status === 'koe' && u.fejl && logget && !sendes) {
            knapper.appendChild(knap('Prøv igen', 'btn-secondary', function () {
                Upload.saetIKoe(m.id, u.titel, u.aktivitet);
            }));
        } else if (u.status === 'uploadet' && u.url) {
            knapper.appendChild(knap('Vis', 'btn-secondary', function () {
                window.MitSporApp.aabnSite(u.url);
            }));
        }
        knapper.appendChild(knap('Del GPX', 'btn-secondary', function () { delGpx(m); }));
        knapper.appendChild(knap('Slet', 'btn-danger', function () { sletTur(m); })).disabled = sendes;

        li.appendChild(navn);
        li.appendChild(info);
        li.appendChild(status);
        li.appendChild(knapper);
        return li;
    }

    function knap(tekst, klasse, handling) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'btn btn-small ' + klasse;
        b.textContent = tekst;
        b.addEventListener('click', handling);
        return b;
    }

    function uploadTekst(m) {
        var u = m.upload;
        if (!u) {
            return '';
        }
        if (u.status === 'koe') {
            if (Upload.igang() === m.id) {
                return 'Uploader …';
            }
            if (!logget) {
                return 'Venter på upload. Log ind for at sende turen.';
            }
            if (u.fejl) {
                return 'Upload mislykkedes, prøver igen senere: ' + u.fejl;
            }
            return 'Venter på upload. Turen sendes, når du er på nettet.';
        }
        if (u.status === 'fejl') {
            return 'Ikke uploadet: ' + u.fejl;
        }
        if (u.status === 'uploadet') {
            return 'Uploadet til mitspor.dk (privat)';
        }
        if (u.status === 'slettet') {
            return 'Uploadet, men siden slettet på mitspor.dk';
        }
        return '';
    }

    // ---------- Optagelse ----------

    function samletAfstand(liste) {
        var sum = 0;
        for (var i = 1; i < liste.length; i++) {
            sum += Gpx.afstand(liste[i - 1][0], liste[i - 1][1], liste[i][0], liste[i][1]);
        }
        return sum;
    }

    function startUr() {
        clearInterval(urTimer);
        urTimer = setInterval(opdaterTal, 1000);
    }

    // Renser sporet og opdaterer afstand og antal punkter ud fra det rensede spor.
    function rensNu() {
        renset = Spor.rens(punkter);
        meta.afstandM = samletAfstand(renset);
        meta.antalPunkter = renset.length;
        opdaterTal();
    }

    // Viser GPS-status. Mens der ventes på et præcist signal (før første
    // punkt og efter en pause), får teksten gul baggrund.
    function visGps(tekst) {
        gpsFelt.textContent = tekst;
        gpsFelt.classList.toggle('optag-gps--venter', aktiv && !pauset && !klar);
    }

    // Viser forklaringen, hvis der efter GPS_HJAELP_MS stadig ventes på et
    // præcist signal. Den skjules igen, så snart der kommer gode punkter.
    function ventPaaGps() {
        skjulGpsHjaelp();
        hjaelpTimer = setTimeout(function () {
            gpsHjaelp.hidden = !(aktiv && !pauset && !klar);
        }, GPS_HJAELP_MS);
    }

    function skjulGpsHjaelp() {
        clearTimeout(hjaelpTimer);
        hjaelpTimer = null;
        gpsHjaelp.hidden = true;
    }

    function startGps() {
        klar = false;
        godeITraek = 0;
        visGps('Venter på GPS-signal …');
        ventPaaGps();
        return BG.start({
            backgroundTitle: 'MitSpor optager din tur',
            backgroundMessage: 'Tryk for at åbne MitSpor.',
            requestPermissions: true,
            stale: false,
            distanceFilter: 2
        }, function (position, fejl) {
            if (fejl) {
                gpsFejl(fejl);
                return;
            }
            if (position) {
                modtag(position);
            }
        });
    }

    function gpsFejl(fejl) {
        if (fejl.code === 'NOT_AUTHORIZED') {
            visGps('Ingen adgang til placering');
            stop(true).then(function () {
                if (window.confirm('MitSpor skal have adgang til din placering for at optage ture.\n\nVil du åbne indstillingerne?')) {
                    BG.openSettings();
                }
            });
            return;
        }
        visGps('GPS-fejl: ' + (fejl.message || fejl.code || 'ukendt'));
    }

    // Smart-mode: afgør, om et rå punkt skal gemmes. Udglatning og
    // forenkling sker først, når GPX-filen laves.
    function modtag(pos) {
        if (!aktiv || pauset) {
            return;
        }

        var noejagtighed = Math.round(pos.accuracy);
        if (!(noejagtighed <= MAX_NOEJAGTIGHED_M)) {
            godeITraek = 0;
            visGps('Svagt GPS-signal (±' + noejagtighed + ' m)');
            return;
        }
        if (!klar) {
            godeITraek = noejagtighed <= START_NOEJAGTIGHED_M ? godeITraek + 1 : 0;
            if (godeITraek < START_ANTAL) {
                visGps('Venter på præcist GPS-signal (±' + noejagtighed + ' m)');
                return;
            }
            klar = true;
            skjulGpsHjaelp();
        }
        visGps('GPS: ±' + noejagtighed + ' m');

        var tid = pos.time || Date.now();

        if (sidst && tid <= sidst[3]) {
            return;
        }
        if (sidst) {
            var afstand = Gpx.afstand(sidst[0], sidst[1], pos.latitude, pos.longitude);
            var sekunder = Math.max(1, (tid - sidst[3]) / 1000);
            if (afstand / sekunder > MAX_FART_MS || afstand < MIN_AFSTAND_M) {
                return;
            }
        }

        var hoejde = (typeof pos.altitude === 'number') ? Math.round(pos.altitude * 10) / 10 : null;
        var punkt = [pos.latitude, pos.longitude, hoejde, tid, noejagtighed, segment];
        var id = meta.id;

        punkter.push(punkt);
        sidst = punkt;

        iKoe(function () { return Lager.tilfoejPunkt(id, punkt); });

        // Styres af punkternes egen tid, så det også sker, når
        // JavaScript-timere bremses i baggrunden.
        if (tid - sidstGpx >= GPX_INTERVAL_MS) {
            sidstGpx = tid;
            sidstRens = tid;
            gemGpx();
        } else if (tid - sidstRens >= RENS_INTERVAL_MS) {
            sidstRens = tid;
            rensNu();
        }
    }

    function gemGpx() {
        var m = meta;
        rensNu();
        var gpx = Gpx.lav(m.navn, m.start, renset);
        if (gpx.length > MAX_GPX_BYTES) {
            stop().then(function () {
                window.alert('Turen er nu så lang, at GPX-filen nærmer sig grænsen på 10 MB. Optagelsen er stoppet og gemt. Start en ny optagelse for at fortsætte.');
            });
            return koe;
        }
        var kopi = Object.assign({}, m);
        return iKoe(function () {
            return Lager.gemGpx(kopi, gpx).then(function () { return Lager.gemMeta(kopi); });
        });
    }

    function start() {
        if (aktiv || afbrudt) {
            return;
        }
        visFejl('');
        startKnap.disabled = true;
        Lager.opret()
            .then(function (ny) {
                meta = ny;
                meta.pauseMs = 0;
                meta.pauseStart = null;
                punkter = [];
                sidst = null;
                segment = 0;
                sidstGpx = Date.now();
                sidstRens = 0;
                renset = [];
                aktiv = true;
                pauset = false;
                opdaterTal();
                opdaterKnapper();
                startUr();
                return startGps();
            })
            .catch(function (fejl) {
                visFejl('Optagelsen kunne ikke starte: ' + (fejl && fejl.message ? fejl.message : fejl));
                return stop(true);
            })
            .finally(function () {
                startKnap.disabled = false;
            });
    }

    // Henter en optagelse, der ikke blev afsluttet, ind som den aktive.
    // Sporet fortsætter, hvor det slap (samme segment).
    function indlaes(m) {
        return Lager.hentPunkter(m.id).then(function (gemte) {
            meta = m;
            meta.pauseMs = meta.pauseMs || 0;
            meta.pauseStart = meta.pauseStart || null;
            punkter = gemte;
            sidst = gemte.length ? gemte[gemte.length - 1] : null;
            segment = sidst ? sidst[5] : 0;
            rensNu();
            sidstGpx = Date.now();
            sidstRens = 0;
            afbrudt = null;
            aktiv = true;
        });
    }

    // Fortsætter en optagelse, der blev afbrudt af et nedbrud eller en genstart.
    function fortsaet() {
        var m = afbrudt;
        if (!m || aktiv) {
            return;
        }
        visFejl('');
        indlaes(m)
            .then(function () {
                if (meta.pauseStart) {
                    meta.pauseMs += Date.now() - meta.pauseStart;
                    meta.pauseStart = null;
                }
                meta.status = 'optager';
                pauset = false;
                opdaterTal();
                opdaterKnapper();
                startUr();
                return startGps();
            })
            .catch(function (fejl) {
                visFejl('Optagelsen kunne ikke fortsætte: ' + (fejl && fejl.message ? fejl.message : fejl));
            });
    }

    // Pause: GPS'en slukkes (og dermed notifikationen), så batteriet spares.
    // Tilstanden gemmes, så pausen overlever, at appen bliver lukket.
    function pause() {
        if (!aktiv || pauset) {
            return;
        }
        pauset = true;
        meta.status = 'pause';
        meta.pauseStart = Date.now();
        visGps('Pause');
        skjulGpsHjaelp();
        opdaterKnapper();
        opdaterTal();
        BG.stop().catch(function () {});
        gemGpx();
    }

    // Efter en pause fortsætter sporet, hvor det slap.
    function genoptag() {
        if (!aktiv || !pauset) {
            return;
        }
        meta.pauseMs += Date.now() - meta.pauseStart;
        meta.pauseStart = null;
        meta.status = 'optager';
        pauset = false;
        opdaterKnapper();
        var kopi = Object.assign({}, meta);
        iKoe(function () { return Lager.gemMeta(kopi); });
        startGps();
    }

    // Afslutter den aktive optagelse og gemmer GPX-filen.
    // stille: ingen besked, hvis turen er tom (fx når adgang til placering mangler).
    function stop(stille) {
        clearInterval(urTimer);
        skjulGpsHjaelp();
        var bgStop = BG.stop().catch(function () {});
        if (!aktiv || !meta) {
            aktiv = false;
            pauset = false;
            opdaterKnapper();
            return bgStop;
        }
        aktiv = false;
        var m = meta;
        m.status = 'afsluttet';
        // Stoppes der under en pause, slutter turen, da pausen begyndte.
        m.slut = pauset ? m.pauseStart : Date.now();
        m.pauseStart = null;
        pauset = false;
        visGps('–');

        return bgStop
            .then(function () { return afslut(m, punkter, stille); })
            .then(function () {
                meta = null;
                punkter = [];
                sidst = null;
                opdaterTal();
                return visListe();
            });
    }

    // Gemmer en optagelse som afsluttet. Uden punkter slettes den.
    function afslut(m, liste, stille) {
        if (!liste.length) {
            return iKoe(function () { return Lager.slet(m.id); }).then(function () {
                if (!stille) {
                    window.alert('Der blev ikke optaget nogen punkter, så turen er ikke gemt.');
                }
            });
        }
        var ren = Spor.rens(liste);
        var gpx = Gpx.lav(m.navn, m.start, ren);
        m.afstandM = samletAfstand(ren);
        m.antalPunkter = ren.length;
        return iKoe(function () {
            return Lager.gemGpx(m, gpx).then(function () { return Lager.gemMeta(m); });
        });
    }

    function afslutAfbrudt() {
        var m = afbrudt;
        if (!m) {
            return;
        }
        Lager.hentPunkter(m.id)
            .then(function (gemte) {
                m.status = 'afsluttet';
                // Afbrudt under en pause: turen sluttede, da pausen begyndte.
                m.slut = m.pauseStart || (gemte.length ? gemte[gemte.length - 1][3] : m.start);
                m.pauseStart = null;
                afbrudt = null;
                return afslut(m, gemte);
            })
            .then(visListe);
    }

    // ---------- Gemte ture ----------

    function delGpx(m) {
        if (!Capacitor.isNativePlatform()) {
            window.alert('Deling virker kun i appen.');
            return;
        }
        Lager.gpxUri(m)
            .then(function (uri) {
                return Share.share({ title: m.navn, files: [uri], dialogTitle: 'Del GPX-fil' });
            })
            .catch(function (fejl) {
                // Brugeren lukkede delemenuen: ikke en fejl.
                if (fejl && /cancel/i.test(fejl.message || '')) {
                    return;
                }
                window.alert('GPX-filen kunne ikke deles.');
            });
    }

    function sletTur(m) {
        var advarsel = m.uploadet
            ? 'Turen bliver ikke slettet på mitspor.dk.'
            : 'Turen er ikke uploadet til mitspor.dk og kan ikke hentes igen.';
        if (!window.confirm('Slet "' + m.navn + '" fra telefonen?\n\n' + advarsel)) {
            return;
        }
        iKoe(function () { return Lager.slet(m.id); }).then(visListe);
    }

    // ---------- Hændelser ----------

    aabnKnap.addEventListener('click', function () {
        visView(true);
        visListe();
    });
    tilbageKnap.addEventListener('click', function () { visView(false); });
    startKnap.addEventListener('click', start);
    pauseKnap.addEventListener('click', pause);
    genoptagKnap.addEventListener('click', genoptag);
    stopKnap.addEventListener('click', function () {
        if (window.confirm('Stop og gem optagelsen?')) {
            stop();
        }
    });
    fortsaetKnap.addEventListener('click', fortsaet);
    afslutKnap.addEventListener('click', afslutAfbrudt);

    // Android-tilbageknappen: under optagelse lægges appen i baggrunden i
    // stedet for at blive lukket, så optagelsen ikke stopper.
    App.addListener('backButton', function () {
        var side = window.MitSporApp.aktivSide();
        if (side === 'view-login' || side === 'view-upload') {
            visView(true);
        } else if (side === 'view-optag' || side === 'view-intro') {
            visView(false);
        } else if (aktiv) {
            App.minimizeApp();
        } else {
            App.exitApp();
        }
    });
    // Opdatér listen, når en upload starter eller slutter, eller når
    // brugeren logger ind eller ud.
    function nytLogin(login) {
        logget = !!login;
        visListe();
    }
    Upload.onSkift(visListe);
    Konto.onSkift(nytLogin);
    Konto.hent().then(nytLogin);

    App.addListener('resume', function () {
        if (aktiv && !pauset) {
            opdaterTal();
        }
    });

    // Ved opstart kører der ingen optagelse i denne side. Stop en eventuel
    // GPS-tjeneste fra en tidligere proces. En tur på pause hentes ind igen
    // på pause; en afbrudt tur tilbydes at blive fortsat.
    BG.stop().catch(function () {});
    visListe().then(function () {
        if (afbrudt && afbrudt.status === 'pause') {
            return indlaes(afbrudt).then(function () {
                pauset = true;
                visGps('Pause');
                opdaterTal();
                opdaterKnapper();
                startUr();
                visView(true);
            });
        }
        if (afbrudt) {
            visView(true);
        }
    });
    opdaterTal();
})();
