// Login i appen og kald til API'et på mitspor.dk (/api/v1/).
//
// Ved login får appen et token, som sendes i headeren X-Api-Token.
// Tokenet gemmes i Keychain (iOS) eller Keystore (Android) via
// capacitor-secure-storage-plugin, aldrig i localStorage, og det logges
// aldrig. Adgangskoden gemmes ikke.
(function () {
    'use strict';

    var Capacitor = window.capacitorExports.Capacitor;
    var Sikker = window.capacitorSecureStoragePlugin.SecureStoragePlugin;
    var API_URL = window.MITSPOR_CONFIG.siteUrl + '/api/v1/';
    var NOEGLE = 'mitspor-login';
    // Ikke-hemmelige oplysninger må gerne ligge i localStorage.
    var AKTIVITETER_NOEGLE = 'mitspor-aktiviteter';
    var SIDSTE_AKTIVITET_NOEGLE = 'mitspor-sidste-aktivitet';
    // Sat ved log ud: næste gang sitet åbnes, ryddes WebView'ets cookies,
    // så brugeren også er logget ud dér.
    var RYD_WEB_NOEGLE = 'mitspor-ryd-web';

    var login = null;      // { token, navn } eller null
    var hentet = null;     // promise for det gemte login
    var lyttere = [];

    function meld() {
        lyttere.forEach(function (fn) { fn(login); });
    }

    // Uden for appen (test af siderne i en browser) gemmes tokenet kun i hukommelsen.
    function hent() {
        if (!hentet) {
            hentet = (Capacitor.isNativePlatform()
                ? Sikker.get({ key: NOEGLE }).then(function (svar) { return JSON.parse(svar.value); })
                : Promise.resolve(null)
            )
                .catch(function () { return null; })
                .then(function (gemt) {
                    login = gemt && gemt.token ? gemt : null;
                    return login;
                });
        }
        return hentet;
    }

    function gem(nyt) {
        login = nyt;
        hentet = Promise.resolve(nyt);
        var skriv = Capacitor.isNativePlatform()
            ? Sikker.set({ key: NOEGLE, value: JSON.stringify(nyt) })
            : Promise.resolve();
        return skriv.then(meld);
    }

    // Glemmer login på telefonen (tokenet er udløbet, eller brugeren logger ud).
    function glem() {
        login = null;
        hentet = Promise.resolve(null);
        var fjern = Capacitor.isNativePlatform()
            ? Sikker.remove({ key: NOEGLE }).catch(function () {})
            : Promise.resolve();
        return fjern.then(meld);
    }

    // Kald til API'et. Svarer med { status, data }. Kan sitet ikke nås,
    // afvises promiset med en fejl, hvor net er sand.
    function api(sti, valg) {
        valg = valg || {};
        var controller = new AbortController();
        var timer = setTimeout(function () { controller.abort(); }, valg.timeout || 30000);
        var headers = {};
        if (valg.token) {
            headers['X-Api-Token'] = valg.token;
        }

        return fetch(API_URL + sti, {
            method: valg.method || 'GET',
            headers: headers,
            body: valg.body,
            cache: 'no-store',
            signal: controller.signal
        })
            .then(function (svar) {
                return svar.json()
                    .catch(function () { return {}; })
                    .then(function (data) { return { status: svar.status, data: data }; });
            }, function () {
                var fejl = new Error('Ingen forbindelse til mitspor.dk.');
                fejl.net = true;
                throw fejl;
            })
            .finally(function () { clearTimeout(timer); });
    }

    function enhedsnavn() {
        return Capacitor.getPlatform() === 'ios' ? 'MitSpor-app (iOS)' : 'MitSpor-app (Android)';
    }

    // Fælles for login med adgangskode og med engangskode fra sitet.
    // Et tidligere token (fx en anden konto) tilbagekaldes på sitet.
    function modtagToken(svar) {
        if (!svar.data.success || !svar.data.token) {
            throw new Error(svar.data.error || 'Login mislykkedes. Prøv igen.');
        }
        var gammelt = login;
        return gem({ token: svar.data.token, navn: svar.data.display_name || '' }).then(function () {
            if (gammelt && gammelt.token !== svar.data.token) {
                api('logout.php', { method: 'POST', token: gammelt.token }).catch(function () {});
            }
            // Aktivitetstyperne hentes nu, så de er der, når en tur skal
            // uploades uden net.
            return hentAktiviteter().catch(function () {});
        });
    }

    function logInd(email, kode) {
        var body = new URLSearchParams();
        body.append('email', email);
        body.append('password', kode);
        body.append('device_name', enhedsnavn());

        return api('login.php', { method: 'POST', body: body }).then(modtagToken);
    }

    // Har brugeren logget ind på sitet i appens WebView, har sitet koblet
    // hashen af hemmeligheden (par i user-agent) til kontoen: veksl
    // hemmeligheden til et token. Ellers afvises promiset (401).
    function vekslKode(hemmelighed) {
        var body = new URLSearchParams();
        body.append('code', hemmelighed);
        body.append('device_name', enhedsnavn());

        return api('exchange-code.php', { method: 'POST', body: body }).then(modtagToken);
    }

    // Engangskode, der logger appens WebView ind på sitet (/app-login.php).
    // Svarer med null, hvis det ikke kan lade sig gøre lige nu.
    function webLoginKode() {
        return hent().then(function (nu) {
            if (!nu) {
                return null;
            }
            return api('web-login-code.php', { method: 'POST', token: nu.token, timeout: 10000 }).then(function (svar) {
                if (svar.status === 401) {
                    return glem().then(function () { return null; });
                }
                return svar.data.success ? svar.data.code : null;
            }, function () { return null; });
        });
    }

    // Logger ud på telefonen med det samme og tilbagekalder tokenet på
    // sitet, hvis der er net. (Uden net udløber det af sig selv.)
    function logUd() {
        var gammelt = login;
        gemLokalt(RYD_WEB_NOEGLE, true);
        return glem().then(function () {
            if (gammelt) {
                return api('logout.php', { method: 'POST', token: gammelt.token }).catch(function () {});
            }
        });
    }

    function laesLokalt(noegle) {
        try {
            return JSON.parse(window.localStorage.getItem(noegle));
        } catch (e) {
            return null;
        }
    }

    function gemLokalt(noegle, vaerdi) {
        try {
            window.localStorage.setItem(noegle, JSON.stringify(vaerdi));
        } catch (e) {
            // Uden localStorage hentes listen bare igen næste gang.
        }
    }

    // Aktivitetstyperne fra sitet. Gemmes, så de kan vælges uden net.
    function gemteAktiviteter() {
        var liste = laesLokalt(AKTIVITETER_NOEGLE);
        return Array.isArray(liste) ? liste : [];
    }

    function hentAktiviteter() {
        return hent().then(function (nu) {
            if (!nu) {
                return gemteAktiviteter();
            }
            return api('activity-types.php', { token: nu.token }).then(function (svar) {
                if (svar.status === 401) {
                    return glem().then(gemteAktiviteter);
                }
                if (svar.data.success && Array.isArray(svar.data.activity_types)) {
                    gemLokalt(AKTIVITETER_NOEGLE, svar.data.activity_types);
                    return svar.data.activity_types;
                }
                return gemteAktiviteter();
            }, gemteAktiviteter);
        });
    }

    window.MitSporKonto = {
        hent: hent,
        logInd: logInd,
        logUd: logUd,
        vekslKode: vekslKode,
        webLoginKode: webLoginKode,
        skalRydWeb: function () { return laesLokalt(RYD_WEB_NOEGLE) === true; },
        webRyddet: function () { gemLokalt(RYD_WEB_NOEGLE, false); },
        glem: glem,
        api: api,
        hentAktiviteter: hentAktiviteter,
        gemteAktiviteter: gemteAktiviteter,
        sidsteAktivitet: function () { return laesLokalt(SIDSTE_AKTIVITET_NOEGLE); },
        gemSidsteAktivitet: function (id) { gemLokalt(SIDSTE_AKTIVITET_NOEGLE, id); },
        onSkift: function (fn) { lyttere.push(fn); }
    };
})();
