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

    // Smart-mode: punkter med dårligere nøjagtighed kasseres, og
    // minimumsafstanden mellem punkter følger farten.
    var MAX_NOEJAGTIGHED_M = 30;
    var MAX_FART_MS = 70;              // spring over 250 km/t er GPS-fejl
    var GPX_INTERVAL_MS = 60 * 1000;
    // Hold GPX-filen et stykke under MAX_UPLOAD_SIZE_BYTES (10 MB) på sitet.
    var MAX_GPX_BYTES = 9.5 * 1024 * 1024;

    function minAfstand(fart) {
        if (fart < 2.5) {
            return 5;    // gang og løb
        }
        if (fart < 8) {
            return 10;   // cykel
        }
        return 20;       // bil, tog, båd
    }

    var visStart = document.getElementById('view-start');
    var visOptag = document.getElementById('view-optag');
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
    var urTimer = null;
    var afbrudt = null;   // en optagelse, der ikke blev afsluttet (nedbrud/genstart)

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
        visStart.hidden = optag;
        visOptag.hidden = !optag;
        window.scrollTo(0, 0);
    }

    function opdaterTal() {
        tidFelt.textContent = meta ? ur(koretid(meta, Date.now())) : '0:00:00';
        afstandFelt.textContent = km(meta ? meta.afstandM : 0);
        punkterFelt.textContent = String(punkter.length);
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

        var knapper = document.createElement('div');
        knapper.className = 'tur-knapper';

        var del = document.createElement('button');
        del.type = 'button';
        del.className = 'btn btn-secondary btn-small';
        del.textContent = 'Del GPX';
        del.addEventListener('click', function () { delGpx(m); });

        var slet = document.createElement('button');
        slet.type = 'button';
        slet.className = 'btn btn-danger btn-small';
        slet.textContent = 'Slet';
        slet.addEventListener('click', function () { sletTur(m); });

        knapper.appendChild(del);
        knapper.appendChild(slet);
        li.appendChild(navn);
        li.appendChild(info);
        li.appendChild(knapper);
        return li;
    }

    // ---------- Optagelse ----------

    // meta.json gemmes kun hvert minut, så efter en afbrydelse regnes
    // afstanden igen ud fra punkterne. Hullet mellem segmenter tæller ikke.
    function samletAfstand(liste) {
        var sum = 0;
        for (var i = 1; i < liste.length; i++) {
            if (liste[i][5] === liste[i - 1][5]) {
                sum += Gpx.afstand(liste[i - 1][0], liste[i - 1][1], liste[i][0], liste[i][1]);
            }
        }
        return sum;
    }

    function startUr() {
        clearInterval(urTimer);
        urTimer = setInterval(opdaterTal, 1000);
    }

    function startGps() {
        gpsFelt.textContent = 'Venter på GPS-signal …';
        return BG.start({
            backgroundTitle: 'MitSpor optager din tur',
            backgroundMessage: 'Tryk for at åbne MitSpor.',
            requestPermissions: true,
            stale: false,
            distanceFilter: 5
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
            gpsFelt.textContent = 'Ingen adgang til placering';
            stop(true).then(function () {
                if (window.confirm('MitSpor skal have adgang til din placering for at optage ture.\n\nVil du åbne indstillingerne?')) {
                    BG.openSettings();
                }
            });
            return;
        }
        gpsFelt.textContent = 'GPS-fejl: ' + (fejl.message || fejl.code || 'ukendt');
    }

    // Smart-mode: afgør, om et punkt skal gemmes.
    function modtag(pos) {
        if (!aktiv || pauset) {
            return;
        }

        var noejagtighed = Math.round(pos.accuracy);
        if (!(noejagtighed <= MAX_NOEJAGTIGHED_M)) {
            gpsFelt.textContent = 'Svagt GPS-signal (±' + noejagtighed + ' m)';
            return;
        }
        gpsFelt.textContent = 'GPS: ±' + noejagtighed + ' m';

        var tid = pos.time || Date.now();
        var afstand = 0;

        if (sidst && tid <= sidst[3]) {
            return;
        }
        if (sidst && sidst[5] === segment) {
            afstand = Gpx.afstand(sidst[0], sidst[1], pos.latitude, pos.longitude);
            var sekunder = Math.max(1, (tid - sidst[3]) / 1000);
            var fart = (typeof pos.speed === 'number' && pos.speed >= 0) ? pos.speed : afstand / sekunder;

            if (afstand / sekunder > MAX_FART_MS) {
                return;
            }
            // Kræv mindst en halv nøjagtighed som afstand, så GPS-støj ved
            // stilstand ikke bliver til punkter.
            if (afstand < Math.max(minAfstand(fart), noejagtighed / 2)) {
                return;
            }
        }

        var hoejde = (typeof pos.altitude === 'number') ? Math.round(pos.altitude * 10) / 10 : null;
        var punkt = [pos.latitude, pos.longitude, hoejde, tid, noejagtighed, segment];
        var id = meta.id;

        punkter.push(punkt);
        sidst = punkt;
        meta.afstandM += afstand;
        meta.antalPunkter = punkter.length;
        opdaterTal();

        iKoe(function () { return Lager.tilfoejPunkt(id, punkt); });

        // Skrives ud fra punkternes egen tid, så det også sker, når
        // JavaScript-timere bremses i baggrunden.
        if (tid - sidstGpx >= GPX_INTERVAL_MS) {
            sidstGpx = tid;
            gemGpx();
        }
    }

    function gemGpx() {
        var m = meta;
        var gpx = Gpx.lav(m.navn, m.start, punkter);
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
    // Punkterne efter afbrydelsen kommer i et nyt segment, så der ikke
    // tegnes en lige streg hen over hullet.
    function indlaes(m) {
        return Lager.hentPunkter(m.id).then(function (gemte) {
            meta = m;
            meta.pauseMs = meta.pauseMs || 0;
            meta.pauseStart = meta.pauseStart || null;
            punkter = gemte;
            sidst = gemte.length ? gemte[gemte.length - 1] : null;
            segment = sidst ? sidst[5] + 1 : 0;
            meta.antalPunkter = punkter.length;
            meta.afstandM = samletAfstand(punkter);
            sidstGpx = Date.now();
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
        gpsFelt.textContent = 'Pause';
        opdaterKnapper();
        opdaterTal();
        BG.stop().catch(function () {});
        gemGpx();
    }

    // Efter en pause starter et nyt segment, så pausen ikke giver en lige
    // streg i GPX-filen eller tæller med i afstanden.
    function genoptag() {
        if (!aktiv || !pauset) {
            return;
        }
        meta.pauseMs += Date.now() - meta.pauseStart;
        meta.pauseStart = null;
        meta.status = 'optager';
        if (sidst) {
            segment = sidst[5] + 1;
        }
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
        gpsFelt.textContent = '–';

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
        var gpx = Gpx.lav(m.navn, m.start, liste);
        m.antalPunkter = liste.length;
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
                m.afstandM = samletAfstand(gemte);
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
        if (!window.confirm('Slet "' + m.navn + '" fra telefonen?\n\nTure, der allerede er uploadet til mitspor.dk, bliver ikke slettet dér.')) {
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
        if (!visOptag.hidden) {
            visView(false);
        } else if (aktiv) {
            App.minimizeApp();
        } else {
            App.exitApp();
        }
    });
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
                gpsFelt.textContent = 'Pause';
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
