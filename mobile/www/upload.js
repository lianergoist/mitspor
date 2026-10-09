// Upload af gemte ture til mitspor.dk med offline-kø.
//
// Når brugeren trykker "Upload" på en tur, sættes den i kø (meta.upload i
// meta.json, så køen overlever, at appen lukkes). Køen sendes, så snart der
// er net og et login: ved opstart, når nettet kommer tilbage, når appen
// vækkes, efter login og ellers hvert minut, mens appen er åben.
//
// Turens id bruges som client_upload_id, så et nyt forsøg efter en netfejl
// aldrig giver en dublet på sitet. Ture uploades som private.
//
// meta.upload: { status, titel, aktivitet, fejl, forsoeg, naeste, url }
//   status 'koe'      venter på at blive sendt
//          'fejl'     afvist af sitet (fx ugyldig GPX eller kvote) — kan sendes igen
//          'uploadet' oprettet på sitet (url er ruten)
//          'slettet'  uploadet, men siden slettet på sitet
(function () {
    'use strict';

    var Network = window.capacitorNetwork.Network;
    var App = window.capacitorApp.App;
    var Lager = window.MitSporLager;
    var Konto = window.MitSporKonto;

    var TJEK_INTERVAL_MS = 60 * 1000;
    var FOERSTE_PAUSE_MS = 30 * 1000;    // ventetid efter første serverfejl, fordobles
    var MAX_PAUSE_MS = 30 * 60 * 1000;
    var UPLOAD_TIMEOUT_MS = 2 * 60 * 1000;

    var koerer = null;     // promise, mens køen sendes
    var igen = false;      // køen skal køres igen, når den er færdig
    var igang = null;      // id på turen, der sendes lige nu
    var lyttere = [];

    function meld() {
        lyttere.forEach(function (fn) { fn(); });
    }

    function erPaaNet() {
        return Network.getStatus()
            .then(function (status) { return status.connected; })
            .catch(function () { return true; });
    }

    function gemUpload(id, aendringer) {
        return Lager.opdaterMeta(id, function (meta) {
            meta.upload = Object.assign({}, meta.upload, aendringer);
            meta.uploadet = meta.upload.status === 'uploadet';
        });
    }

    // Sætter en afsluttet tur i kø til upload med den valgte titel og aktivitet.
    function saetIKoe(id, titel, aktivitet) {
        return gemUpload(id, {
            status: 'koe',
            titel: titel,
            aktivitet: aktivitet,
            fejl: null,
            forsoeg: 0,
            naeste: 0
        }).then(function () {
            meld();
            koerKoe();
        });
    }

    // Midlertidig fejl (serveren svarer ikke eller har travlt): prøv igen
    // senere, med længere og længere pause.
    function senere(m, tekst) {
        var forsoeg = (m.upload.forsoeg || 0) + 1;
        var pause = Math.min(FOERSTE_PAUSE_MS * Math.pow(2, forsoeg - 1), MAX_PAUSE_MS);
        return gemUpload(m.id, { forsoeg: forsoeg, naeste: Date.now() + pause, fejl: tekst });
    }

    // Behandler sitets svar. Svarer true, hvis køen kan fortsætte med næste tur.
    function behandlSvar(m, svar) {
        var data = svar.data || {};
        var fejl = data.error || 'Ukendt fejl (' + svar.status + ').';

        if ((svar.status === 200 || svar.status === 201) && data.success && data.route) {
            return gemUpload(m.id, { status: 'uploadet', url: data.route.url, fejl: null }).then(function () { return true; });
        }
        if (svar.status === 409) {
            return gemUpload(m.id, { status: 'slettet', fejl: fejl }).then(function () { return true; });
        }
        if (svar.status === 401) {
            // Tokenet er udløbet eller tilbagekaldt: turen bliver i køen,
            // til brugeren logger ind igen.
            return Konto.glem().then(function () { return false; });
        }
        if (svar.status === 400 || svar.status === 413 || svar.status === 422) {
            return gemUpload(m.id, { status: 'fejl', fejl: fejl }).then(function () { return true; });
        }
        // 429, 5xx og andet: prøv igen senere.
        return senere(m, fejl).then(function () { return false; });
    }

    function uploadEn(m, login) {
        igang = m.id;
        meld();

        return Lager.laesGpx(m)
            .then(function (gpx) {
                var data = new FormData();
                data.append('client_upload_id', m.id);
                data.append('title', m.upload.titel);
                data.append('activity_type_id', String(m.upload.aktivitet));
                data.append('gpx_file', new Blob([gpx], { type: 'application/gpx+xml' }), m.gpxFil);
                return data;
            }, function () {
                var fejl = new Error('GPX-filen kunne ikke læses på telefonen.');
                fejl.lokal = true;
                throw fejl;
            })
            .then(function (data) {
                return Konto.api('upload-route.php', { method: 'POST', token: login.token, body: data, timeout: UPLOAD_TIMEOUT_MS });
            })
            .then(function (svar) {
                return behandlSvar(m, svar);
            }, function (fejl) {
                if (fejl.lokal) {
                    return gemUpload(m.id, { status: 'fejl', fejl: fejl.message }).then(function () { return true; });
                }
                // Intet net: stop her. Køen prøver igen, når nettet er tilbage.
                return false;
            })
            .finally(function () {
                igang = null;
                meld();
            });
    }

    function sendListe(liste, login) {
        if (!liste.length) {
            return Promise.resolve();
        }
        return uploadEn(liste.shift(), login).then(function (fortsaet) {
            if (fortsaet) {
                return sendListe(liste, login);
            }
        });
    }

    // Sender de ture, der venter. Ældste tur først, én ad gangen.
    function koerKoe() {
        if (koerer) {
            igen = true;
            return koerer;
        }

        koerer = Konto.hent()
            .then(function (login) {
                if (!login) {
                    return;
                }
                return erPaaNet().then(function (net) {
                    if (!net) {
                        return;
                    }
                    return Lager.alle().then(function (liste) {
                        var nu = Date.now();
                        var klar = liste.filter(function (m) {
                            return m.status === 'afsluttet' && m.upload && m.upload.status === 'koe' && !(m.upload.naeste > nu);
                        }).reverse();
                        return sendListe(klar, login);
                    });
                });
            })
            .catch(function () {})
            .finally(function () {
                koerer = null;
                if (igen) {
                    igen = false;
                    koerKoe();
                }
            });

        return koerer;
    }

    Network.addListener('networkStatusChange', function (status) {
        if (status.connected) {
            koerKoe();
        }
    });
    App.addListener('resume', koerKoe);
    Konto.onSkift(function (login) {
        meld();
        if (login) {
            koerKoe();
        }
    });
    setInterval(koerKoe, TJEK_INTERVAL_MS);
    koerKoe();

    window.MitSporUpload = {
        saetIKoe: saetIKoe,
        koerKoe: koerKoe,
        igang: function () { return igang; },
        onSkift: function (fn) { lyttere.push(fn); }
    };
})();
