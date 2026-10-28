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
    var Live = window.MitSporLive;
    var Kamera = window.MitSporKamera;

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
    var liveValg = document.getElementById('optag-live-valg');
    var liveSelect = document.getElementById('optag-live');
    var liveLabel = document.getElementById('optag-live-label');
    // Punktet "Opret ny livesending …" i valget. Det kan aldrig stå som valgt.
    var NY_LIVE = 'ny';
    var forrigeLive = '';
    var liveStatus = document.getElementById('optag-live-status');
    var laasTekst = document.getElementById('optag-laas');
    var wptKnap = document.getElementById('optag-wpt');
    var wptAntal = document.getElementById('optag-wpt-antal');
    var wptDialog = document.getElementById('wpt-dialog');
    var wptForm = document.getElementById('wpt-form');
    var wptTitel = document.getElementById('wpt-titel');
    var wptTekst = document.getElementById('wpt-tekst');
    var wptBilleder = document.getElementById('wpt-billeder');
    var wptTagKnap = document.getElementById('wpt-tag');
    var wptVaelgKnap = document.getElementById('wpt-vaelg');
    var MAX_BILLEDER = 10;   // pr. waypoint

    // Den aktive optagelse. aktiv er sand, også når den holder pause.
    var aktiv = false;
    var pauset = false;
    var meta = null;
    var punkter = [];
    var sidst = null;
    // Sidste brugbare position, også én, der ikke blev gemt som punkt
    // (fx når man står stille). Bruges til waypoints.
    var sidstPos = null;
    var waypoints = [];
    var wptPos = null;    // placeringen til waypointet i dialogen
    var valgteBilleder = []; // billederne i dialogen (kamera.js), endnu ikke gemt
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
    var livesendinger = []; // brugerens åbne livesendinger (live.js)

    // Skrivninger køres én ad gangen, i den rækkefølge de kommer.
    var koe = Promise.resolve();

    function iKoe(opgave) {
        koe = koe.then(opgave).catch(function (fejl) {
            visFejl('Kunne ikke gemme på enheden: ' + (fejl && fejl.message ? fejl.message : fejl));
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
        liveStatus.textContent = meta ? Live.status(meta.live) : '';
        liveStatus.hidden = !liveStatus.textContent;
        wptAntal.textContent = waypoints.length === 1 ? '1 waypoint indsat' : waypoints.length + ' waypoints indsat';
        wptAntal.hidden = !aktiv || !waypoints.length;
    }

    // Sendes turen live lige nu? Så bliver brugeren i optageren: åbnes sitet
    // i appen, holder afsendelsen pause (se app.js). Under pause er der
    // ingen GPS og intet at sende.
    function sendesLive() {
        return aktiv && !pauset && !!(meta && meta.live && !meta.live.stoppet);
    }

    function opdaterKnapper() {
        startKnap.hidden = aktiv;
        pauseKnap.hidden = !aktiv || pauset;
        genoptagKnap.hidden = !aktiv || !pauset;
        stopKnap.hidden = !aktiv;
        wptKnap.hidden = !aktiv;
        startKnap.disabled = !!afbrudt;
        aabnKnap.textContent = !aktiv ? 'Optag tur' : (pauset ? 'Optagelse på pause' : 'Optagelse i gang');
        afbrudtBoks.hidden = !afbrudt || aktiv;
        tilbageKnap.hidden = sendesLive();
        laasTekst.hidden = !sendesLive();
        // Vises også uden livesendinger, så man kan oprette en ("Ny livesending").
        liveValg.hidden = aktiv || !!afbrudt || !logget;
        liveLabel.textContent = liveSelect.value ? 'Send live til' : 'Send live';
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

    // Turens navn i listen: titlen fra upload (også mens den venter i køen),
    // ellers navnet fra optagelsen ("Tur 22. okt. 2026 kl. 12.26").
    function turTitel(m) {
        return (m.upload && m.upload.titel) || m.navn;
    }

    function listePunkt(m) {
        var li = document.createElement('li');
        li.className = 'tur';

        var navn = document.createElement('div');
        navn.className = 'tur-navn';
        navn.textContent = turTitel(m);

        var info = document.createElement('div');
        info.className = 'tur-info';
        info.textContent = km(m.afstandM) + ' · ' + varighed(koretid(m, m.slut || m.start)) + ' · ' + m.antalPunkter + ' punkter';

        var status = document.createElement('div');
        status.className = 'tur-upload' + (m.upload && m.upload.status === 'uploadet' ? ' tur-upload--ok' : '');
        status.textContent = uploadTekst(m);
        status.hidden = !status.textContent;

        var liveTekst = document.createElement('div');
        liveTekst.className = 'tur-upload';
        liveTekst.textContent = m.live && !m.live.faerdig ? Live.status(m.live) : '';
        liveTekst.hidden = !liveTekst.textContent;

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
        li.appendChild(liveTekst);
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
        urTimer = setInterval(function () {
            opdaterTal();
            // Livstegn, også når der ingen GPS-punkter kommer (live.js
            // sender højst hvert minut). Uret kan gå i stå i baggrunden;
            // derfor sendes der også fra modtag().
            if (meta && meta.live && !pauset) {
                Live.send(meta, punkter, false);
            }
        }, 1000);
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
        // Hver position fra GPS'en, også én der springes over nedenfor
        // (stillestående, svagt signal), giver live.js lejlighed til at
        // sende nye punkter eller et livstegn. Højst hvert minut.
        if (meta.live) {
            Live.send(meta, punkter, false);
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
        var hoejde = (typeof pos.altitude === 'number') ? Math.round(pos.altitude * 10) / 10 : null;
        sidstPos = [pos.latitude, pos.longitude, hoejde, tid];

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
        var gpx = Gpx.lav(m.navn, m.start, renset, waypoints);
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
                var valgt = valgtLivesending();
                if (valgt) {
                    meta.live = Live.ny(valgt);
                }
                // Næste optagelse sendes kun live, hvis den vælges igen.
                liveSelect.value = '';
                forrigeLive = '';
                opdaterKnapper();
                punkter = [];
                sidst = null;
                sidstPos = null;
                waypoints = [];
                segment = 0;
                sidstGpx = Date.now();
                sidstRens = 0;
                renset = [];
                aktiv = true;
                pauset = false;
                opdaterTal();
                opdaterKnapper();
                startUr();
                if (meta.live) {
                    // Gem valget, før der sendes noget, og start etapen på sitet.
                    var kopi = Object.assign({}, meta);
                    iKoe(function () { return Lager.gemMeta(kopi); });
                    Live.send(meta, punkter, true);
                }
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
        return Promise.all([Lager.hentPunkter(m.id), Lager.hentWaypoints(m.id)]).then(function (svar) {
            var gemte = svar[0];
            waypoints = svar[1];
            meta = m;
            meta.pauseMs = meta.pauseMs || 0;
            meta.pauseStart = meta.pauseStart || null;
            punkter = gemte;
            sidst = gemte.length ? gemte[gemte.length - 1] : null;
            sidstPos = null;
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
        if (meta.live) {
            Live.send(meta, punkter, true);
        }
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
    // sidsteEtape: afslut også livesendingen (kun når turen sendes live).
    function stop(stille, sidsteEtape) {
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

        var liste = punkter;
        var wpts = waypoints;
        return bgStop
            .then(function () { return afslut(m, liste, wpts, stille); })
            .then(function () { return afslutLive(m, liste, !!sidsteEtape); })
            .then(function () {
                meta = null;
                punkter = [];
                sidst = null;
                sidstPos = null;
                waypoints = [];
                opdaterTal();
                return visListe();
            });
    }

    // Gemmer en optagelse som afsluttet. Uden punkter slettes den.
    function afslut(m, liste, wpts, stille) {
        if (!liste.length) {
            return iKoe(function () { return Lager.slet(m.id); }).then(function () {
                if (!stille) {
                    window.alert('Der blev ikke optaget nogen punkter, så turen er ikke gemt.');
                }
            });
        }
        var ren = Spor.rens(liste);
        var gpx = Gpx.lav(m.navn, m.start, ren, wpts);
        m.afstandM = samletAfstand(ren);
        m.antalPunkter = ren.length;
        return iKoe(function () {
            return Lager.gemGpx(m, gpx).then(function () { return Lager.gemMeta(m); });
        });
    }

    // Turen er sendt live: send resten og afslut etapen på sitet, og sæt
    // turen i kø til upload. Det færdige spor erstatter så de rå punkter
    // på livesiden. (Uden net sker begge dele, når der er net igen.)
    function afslutLive(m, liste, sidsteEtape) {
        if (!m.live) {
            return Promise.resolve();
        }
        return koe
            .then(function () { return Live.afslut(m, liste, sidsteEtape); })
            .then(function () {
                if (!liste.length || m.upload) {
                    return;
                }
                return Upload.saetIKoe(m.id, liveTitel(m.live), m.live.aktivitet);
            });
    }

    // Livesendingens titel og etapens nummer, fx "Vandring på Gudenåstien –
    // etape 1". Etapens egen titel fra planen bruges ikke: den er den planlagte
    // rutes titel, så den uploadede tur ville hedde det samme som ruten.
    function liveTitel(live) {
        var etape = live.etape;
        if (etape && (etape.titel || etape.position > 1)) {
            return live.titel + ' – etape ' + etape.position;
        }
        return live.titel;
    }

    function afslutAfbrudt() {
        var m = afbrudt;
        if (!m) {
            return;
        }
        Promise.all([Lager.hentPunkter(m.id), Lager.hentWaypoints(m.id)])
            .then(function (svar) {
                var gemte = svar[0];
                m.status = 'afsluttet';
                // Afbrudt under en pause: turen sluttede, da pausen begyndte.
                m.slut = m.pauseStart || (gemte.length ? gemte[gemte.length - 1][3] : m.start);
                m.pauseStart = null;
                afbrudt = null;
                return afslut(m, gemte, svar[1]).then(function () { return afslutLive(m, gemte, false); });
            })
            .then(visListe);
    }

    // ---------- Waypoints ----------

    // Placeringen tages, når der trykkes på knappen, ikke når der gemmes,
    // så det ikke gør noget, at man går videre, mens man skriver.
    function aabnWaypoint() {
        if (!aktiv) {
            return;
        }
        var pos = sidstPos;
        if (sidst && (!pos || sidst[3] > pos[3])) {
            pos = sidst;
        }
        if (!pos) {
            window.alert('Der er endnu ingen præcis GPS-placering. Vent, til GPS\'en har fundet dig, og prøv igen.');
            return;
        }
        wptPos = pos;
        wptForm.reset();
        valgteBilleder = [];
        visValgteBilleder();
        wptDialog.returnValue = '';
        wptDialog.showModal();
        wptTitel.focus();
    }

    function visValgteBilleder() {
        wptBilleder.textContent = '';
        valgteBilleder.forEach(function (b, i) {
            var li = document.createElement('li');
            var img = document.createElement('img');
            img.src = b.miniature;
            img.alt = '';
            var fjern = document.createElement('button');
            fjern.type = 'button';
            fjern.className = 'wpt-billede-fjern';
            fjern.setAttribute('aria-label', 'Fjern billedet');
            fjern.textContent = '×';
            fjern.addEventListener('click', function () {
                valgteBilleder.splice(i, 1);
                visValgteBilleder();
            });
            li.appendChild(img);
            li.appendChild(fjern);
            wptBilleder.appendChild(li);
        });
        wptBilleder.hidden = !valgteBilleder.length;
        wptTagKnap.disabled = wptVaelgKnap.disabled = valgteBilleder.length >= MAX_BILLEDER;
    }

    // hent: Kamera.tag eller Kamera.vaelg. Lukkes dialogen imens, glemmes billederne.
    function hentBilleder(hent) {
        var plads = MAX_BILLEDER - valgteBilleder.length;
        if (plads <= 0) {
            return;
        }
        hent(plads)
            .then(function (nye) {
                if (!wptDialog.open) {
                    return;
                }
                valgteBilleder = valgteBilleder.concat(nye).slice(0, MAX_BILLEDER);
                visValgteBilleder();
            })
            .catch(function (fejl) {
                window.alert('Billedet kunne ikke hentes: ' + (fejl && fejl.message ? fejl.message : fejl));
            });
    }

    function gemWaypoint() {
        var pos = wptPos;
        wptPos = null;
        if (!aktiv || !meta || !pos) {
            return;
        }
        var d = new Date();
        var titel = wptTitel.value.trim() || ('Waypoint kl. ' + to(d.getHours()) + '.' + to(d.getMinutes()));
        var w = {
            lat: pos[0],
            lon: pos[1],
            ele: pos[2],
            tid: d.getTime(),
            titel: titel,
            tekst: wptTekst.value.trim(),
            billeder: []
        };
        var id = meta.id;
        var liste = waypoints;   // turens liste, også hvis turen stoppes imens
        var valgte = valgteBilleder;
        valgteBilleder = [];
        liste.push(w);
        iKoe(function () { return Lager.gemWaypoints(id, liste.slice()); });
        opdaterTal();

        // Billederne kopieres til turens mappe ét ad gangen. Et billede, der
        // ikke kan gemmes, springes over, og resten gemmes stadig.
        var mislykket = [];
        valgte.forEach(function (b) {
            iKoe(function () {
                var billedId = Lager.nyId();
                return Lager.gemBillede(id, billedId, b.uri).then(function (gemt) {
                    w.billeder.push({ id: billedId, fil: gemt.fil, type: gemt.type, url: null, fejl: null });
                }, function (fejl) {
                    mislykket.push(fejl && fejl.message ? fejl.message : String(fejl));
                });
            });
        });
        if (valgte.length) {
            iKoe(function () { return Lager.gemWaypoints(id, liste.slice()); }).then(function () {
                if (mislykket.length) {
                    visFejl('Et billede kunne ikke gemmes på enheden: ' + mislykket[0]);
                }
            });
        }
    }

    // ---------- Livesending ----------

    function valgtLivesending() {
        var id = liveSelect.value;
        return livesendinger.filter(function (l) { return l.event === id; })[0] || null;
    }

    // Valget starter altid på "Nej": der sendes kun live, når brugeren selv vælger det.
    // vaelg: id på en livesending, brugeren lige har oprettet fra optageren.
    function hentLivesendinger(vaelg) {
        if (!logget) {
            livesendinger = [];
            opdaterKnapper();
            return;
        }
        Live.hentLivesendinger().then(function (liste) {
            var valgt = vaelg || liveSelect.value;
            livesendinger = liste;
            liveSelect.textContent = '';
            var nej = document.createElement('option');
            nej.value = '';
            nej.textContent = 'Nej';
            liveSelect.appendChild(nej);
            var ny = document.createElement('option');
            ny.value = NY_LIVE;
            ny.textContent = 'Opret ny livesending …';
            liveSelect.appendChild(ny);
            liste.forEach(function (l) {
                var o = document.createElement('option');
                o.value = l.event;
                o.textContent = l.titel;
                liveSelect.appendChild(o);
            });
            liveSelect.value = liste.some(function (l) { return l.event === valgt; }) ? valgt : '';
            forrigeLive = liveSelect.value;
            opdaterKnapper();
        });
    }

    // ---------- Gemte ture ----------

    function delGpx(m) {
        if (!Capacitor.isNativePlatform()) {
            window.alert('Deling virker kun i appen.');
            return;
        }
        Lager.gpxUri(m)
            .then(function (uri) {
                return Share.share({ title: turTitel(m), files: [uri], dialogTitle: 'Del GPX-fil' });
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
        if (!window.confirm('Slet "' + turTitel(m) + '" fra enheden?\n\n' + advarsel)) {
            return;
        }
        iKoe(function () { return Lager.slet(m.id); }).then(visListe);
    }

    // ---------- Hændelser ----------

    // "Opret ny livesending …" åbner sitet. Valget springer tilbage til det
    // forrige; efter Gem lukker sitet WebView'et (/app-luk.php?live=<id>),
    // og app.js sender 'mitspor:ny-livesending', som vælger den nye.
    liveSelect.addEventListener('change', function () {
        if (liveSelect.value === NY_LIVE) {
            liveSelect.value = forrigeLive;
            window.MitSporApp.aabnSite('/dashboard.php?ny_live=app');
        }
        forrigeLive = liveSelect.value;
        opdaterKnapper();
    });
    document.addEventListener('mitspor:ny-livesending', function (ev) {
        if (aktiv) {
            return;
        }
        visView(true);
        hentLivesendinger(ev.detail);
    });

    aabnKnap.addEventListener('click', function () {
        visView(true);
        visListe();
        if (!aktiv) {
            hentLivesendinger();
        }
    });
    tilbageKnap.addEventListener('click', function () { visView(false); });
    startKnap.addEventListener('click', start);
    pauseKnap.addEventListener('click', pause);
    genoptagKnap.addEventListener('click', genoptag);
    stopKnap.addEventListener('click', function () {
        if (!window.confirm('Stop og gem optagelsen?')) {
            return;
        }
        var sidsteEtape = !!(meta && meta.live) && window.confirm(
            'Var det den sidste etape i livesendingen "' + meta.live.titel + '"?\n\n' +
            'OK: afslut livesendingen.\nAnnuller: hold den åben til næste etape.'
        );
        stop(false, sidsteEtape);
    });
    wptKnap.addEventListener('click', aabnWaypoint);
    wptTagKnap.addEventListener('click', function () { hentBilleder(Kamera.tag); });
    wptVaelgKnap.addEventListener('click', function () { hentBilleder(Kamera.vaelg); });
    wptDialog.addEventListener('close', function () {
        if (wptDialog.returnValue === 'gem') {
            gemWaypoint();
        }
        wptPos = null;
        valgteBilleder = [];
    });
    fortsaetKnap.addEventListener('click', fortsaet);
    afslutKnap.addEventListener('click', afslutAfbrudt);

    // Android-tilbageknappen: under optagelse lægges appen i baggrunden i
    // stedet for at blive lukket, så optagelsen ikke stopper.
    App.addListener('backButton', function () {
        var side = window.MitSporApp.aktivSide();
        if (window.MitSporMenu && window.MitSporMenu.tilbage()) {
            return;
        }
        if (wptDialog.open) {
            wptDialog.close();
        } else if (side === 'view-login' || side === 'view-upload') {
            visView(true);
        } else if (side === 'view-optag' && sendesLive()) {
            // Bliv i optageren; i baggrunden sendes der fint.
            App.minimizeApp();
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
        hentLivesendinger();
    }
    window.MitSporApp.spaerSite(sendesLive);
    Upload.onSkift(visListe);
    Live.onSkift(function () {
        opdaterTal();
        opdaterKnapper();
        if (!aktiv) {
            visListe();
        }
    });
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
