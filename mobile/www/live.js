// Livesending fra optageren til en livesending på mitspor.dk
// (api/v1/live-events.php, live-stage.php og live-points.php).
//
// Brugeren vælger selv en livesending, før optagelsen startes. Hver
// optagelse er én etape, med turens id som client_upload_id. Appen sender
// de punkter, sitet ikke har bekræftet endnu, højst hvert minut. Det sker
// fra optagerens GPS-punkter, så det også sker, når appen er i baggrunden.
// Sitet springer punkter over, det allerede har, så et nyt forsøg efter
// en netfejl giver ingen dubletter. Er der ingen nye punkter (man står
// stille, eller GPS-signalet er svagt), sendes en tom liste som livstegn,
// så livesiden kan vise "står stille" i stedet for "ingen kontakt".
//
// Tilstanden ligger i meta.live (meta.json), så den overlever et nedbrud:
//   { event, titel, aktivitet,          livesendingen (fra live-events.php)
//     etape: { position, titel }|null,  sat, når sitet har startet etapen
//     sidstMs,                          tid på det seneste bekræftede punkt
//     slut: null|'etape'|'alt',         optagelsen er stoppet; 'alt' afslutter
//                                       også livesendingen
//     faerdig, stoppet, fejl, sendt }   sendt: Date.now() ved seneste svar
//
// Er turen stoppet, før alt er sendt (fx uden net), sendes resten senere
// fra køen her, ligesom upload.js gør med uploads.
(function () {
    'use strict';

    var Network = window.capacitorNetwork.Network;
    var App = window.capacitorApp.App;
    var Lager = window.MitSporLager;
    var Konto = window.MitSporKonto;

    var INTERVAL_MS = 60 * 1000;
    var MAX_PUNKTER = 2000;          // som LIVE_POINTS_MAX_PER_REQUEST på sitet
    // Livesendingerne gemmes, så de kan vælges uden net. Kun titler og id'er.
    var LISTE_NOEGLE = 'mitspor-livesendinger';

    var igang = {};          // turens id -> sand, mens der sendes
    var sidstForsoeg = {};   // turens id -> Date.now()
    var lyttere = [];

    function meld() {
        lyttere.forEach(function (fn) { fn(); });
    }

    function gemteLivesendinger() {
        try {
            var liste = JSON.parse(window.localStorage.getItem(LISTE_NOEGLE));
            return Array.isArray(liste) ? liste : [];
        } catch (e) {
            return [];
        }
    }

    // Brugerens åbne livesendinger. Uden net bruges den gemte liste.
    function hentLivesendinger() {
        return Konto.hent().then(function (login) {
            if (!login) {
                return [];
            }
            return Konto.api('live-events.php', { token: login.token, timeout: 15000 }).then(function (svar) {
                if (svar.status === 401) {
                    return Konto.glem().then(function () { return []; });
                }
                if (!svar.data.success || !Array.isArray(svar.data.events)) {
                    return gemteLivesendinger();
                }
                var liste = svar.data.events.map(function (ev) {
                    return { event: ev.public_id, titel: ev.title, aktivitet: ev.activity_type_id };
                });
                try {
                    window.localStorage.setItem(LISTE_NOEGLE, JSON.stringify(liste));
                } catch (e) {
                    // Uden localStorage hentes listen bare igen næste gang.
                }
                return liste;
            }, gemteLivesendinger);
        });
    }

    function ny(valg) {
        return {
            event: valg.event,
            titel: valg.titel,
            aktivitet: valg.aktivitet,
            etape: null,
            sidstMs: 0,
            slut: null,
            faerdig: false,
            stoppet: false,
            fejl: null,
            sendt: 0
        };
    }

    // Fejl, der ikke går over af sig selv (livesendingen er slettet eller
    // afsluttet, eller sitet afviser punkterne). Der sendes ikke mere.
    function stop(live, tekst) {
        live.stoppet = true;
        live.fejl = tekst;
        return false;
    }

    // Behandler et svar. Svarer true, hvis der kan sendes videre.
    function svarOk(live, svar) {
        var data = svar.data || {};
        if (svar.status === 200 && data.success) {
            live.fejl = null;
            live.sendt = Date.now();
            return true;
        }
        var tekst = data.error || 'Ukendt fejl (' + svar.status + ').';
        if (svar.status === 401) {
            live.fejl = 'Log ind igen for at sende live.';
            return Konto.glem().then(function () { return false; });
        }
        if (svar.status === 404 || svar.status === 409 || svar.status === 422) {
            return stop(live, tekst);
        }
        // 429, 5xx: prøv igen ved næste forsøg.
        live.fejl = tekst;
        return false;
    }

    function post(sti, login, body) {
        return Konto.api(sti, { method: 'POST', token: login.token, body: body, timeout: 30000 });
    }

    function startEtape(meta, login) {
        var live = meta.live;
        if (live.etape) {
            return Promise.resolve(true);
        }
        var body = new URLSearchParams({ event: live.event, client_upload_id: meta.id, action: 'start' });
        return post('live-stage.php', login, body).then(function (svar) {
            return Promise.resolve(svarOk(live, svar)).then(function (ok) {
                if (ok) {
                    live.etape = { position: svar.data.stage.position, titel: svar.data.stage.title };
                }
                return ok;
            });
        });
    }

    // Sender de punkter, der er nyere end sidstMs, i bidder. Er der ingen
    // nye punkter i første omgang, og optages der stadig, sendes et livstegn.
    function sendPunkter(meta, punkter, login, flere) {
        var live = meta.live;
        var nye = punkter.filter(function (p) { return p[3] > live.sidstMs; }).slice(0, MAX_PUNKTER);
        if (!nye.length && (flere || live.slut)) {
            return Promise.resolve(true);
        }
        var body = JSON.stringify({
            event: live.event,
            client_upload_id: meta.id,
            points: nye.map(function (p) { return [p[0], p[1], p[2], p[3], p[4]]; })
        });
        return post('live-points.php', login, body).then(function (svar) {
            return Promise.resolve(svarOk(live, svar)).then(function (ok) {
                if (!ok) {
                    return false;
                }
                // Sitet svarer med sit seneste punkt. Det kan ikke være
                // ældre end det, vi lige har sendt.
                if (nye.length) {
                    live.sidstMs = Math.max(live.sidstMs, svar.data.last_ms || 0, nye[nye.length - 1][3]);
                }
                return nye.length ? sendPunkter(meta, punkter, login, true) : true;
            });
        });
    }

    function afslutEtape(meta, login) {
        var live = meta.live;
        var body = new URLSearchParams({ event: live.event, client_upload_id: meta.id, action: 'end' });
        if (live.slut === 'alt') {
            body.append('finish_event', '1');
        }
        return post('live-stage.php', login, body).then(function (svar) {
            return Promise.resolve(svarOk(live, svar)).then(function (ok) {
                if (ok) {
                    live.faerdig = true;
                }
                return ok;
            });
        });
    }

    // Sender det, der mangler: start af etapen, nye punkter og til sidst
    // slut. Højst hvert minut, medmindre tving er sand.
    function send(meta, punkter, tving) {
        var live = meta && meta.live;
        if (!live || live.faerdig || live.stoppet || igang[meta.id]) {
            return Promise.resolve();
        }
        var nu = Date.now();
        if (!tving && nu - (sidstForsoeg[meta.id] || 0) < INTERVAL_MS) {
            return Promise.resolve();
        }
        sidstForsoeg[meta.id] = nu;
        igang[meta.id] = true;

        return Konto.hent()
            .then(function (login) {
                if (!login) {
                    live.fejl = 'Log ind for at sende live.';
                    return;
                }
                return startEtape(meta, login)
                    .then(function (ok) { return ok && sendPunkter(meta, punkter, login); })
                    .then(function (ok) { return ok && live.slut && afslutEtape(meta, login); });
            })
            .catch(function () {
                live.fejl = 'Ingen forbindelse. Punkterne sendes, når der er net.';
            })
            .finally(function () {
                delete igang[meta.id];
                meld();
            });
    }

    // Gemmer meta.live i meta.json for en tur, der ikke optages længere.
    function gemLive(meta) {
        return Lager.opdaterMeta(meta.id, function (gemt) {
            gemt.live = meta.live;
        });
    }

    // Kaldes, når optagelsen er stoppet og gemt. alt: afslut også livesendingen.
    function afslut(meta, punkter, alt) {
        meta.live.slut = alt ? 'alt' : 'etape';
        return gemLive(meta)
            .then(function () { return send(meta, punkter, true); })
            .then(function () { return gemLive(meta); });
    }

    // Stoppede ture, hvor noget mangler at blive sendt.
    function koerKoe() {
        return Lager.alle()
            .then(function (liste) {
                var mangler = liste.filter(function (m) {
                    return m.status === 'afsluttet' && m.live && !m.live.faerdig && !m.live.stoppet && !igang[m.id];
                });
                return mangler.reduce(function (kaede, m) {
                    return kaede.then(function () {
                        return Lager.hentPunkter(m.id)
                            .then(function (punkter) { return send(m, punkter, false); })
                            .then(function () { return gemLive(m); });
                    });
                }, Promise.resolve());
            })
            .catch(function () {});
    }

    // Kort status til optageren og listen over ture.
    function status(live) {
        if (!live) {
            return '';
        }
        if (live.faerdig) {
            return 'Livesending: alt er sendt.';
        }
        if (live.stoppet) {
            return 'Livesending stoppet: ' + live.fejl;
        }
        if (live.fejl) {
            return 'Livesending: ' + live.fejl;
        }
        if (live.slut) {
            return 'Livesending: sender resten …';
        }
        if (!live.sendt) {
            return 'Sender live til ' + live.titel + ' …';
        }
        var min = Math.floor((Date.now() - live.sendt) / 60000);
        return 'Sender live til ' + live.titel + ' · sidst sendt ' + (min < 1 ? 'for under 1 min. siden' : 'for ' + min + ' min. siden');
    }

    Network.addListener('networkStatusChange', function (s) {
        if (s.connected) {
            koerKoe();
        }
    });
    App.addListener('resume', koerKoe);
    Konto.onSkift(function (login) {
        if (login) {
            koerKoe();
        }
    });
    setInterval(koerKoe, INTERVAL_MS);
    koerKoe();

    window.MitSporLive = {
        hentLivesendinger: hentLivesendinger,
        ny: ny,
        send: send,
        afslut: afslut,
        status: status,
        onSkift: function (fn) { lyttere.push(fn); }
    };
})();
