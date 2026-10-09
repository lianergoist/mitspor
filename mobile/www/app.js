// Appens lokale startside: åbner mitspor.dk i en in-app WebView.
//
// Websiden kører i sin egen WebView (InAppBrowser) og har derfor IKKE
// adgang til appens native funktioner. Session-cookies bevares mellem
// gangene, så brugeren forbliver logget ind på sitet.
(function () {
    'use strict';

    var config = window.MITSPOR_CONFIG;
    var Capacitor = window.capacitorExports.Capacitor;
    var browser = window.capacitorInAppBrowser;
    // navigator.onLine er ikke pålidelig i Android-WebView (forbliver true
    // i flytilstand), så netstatus hentes fra styresystemet via Network.
    var Network = window.capacitorNetwork.Network;

    var openSiteButton = document.getElementById('open-site');
    var privacyLink = document.getElementById('open-privacy');
    var offlineNotice = document.getElementById('offline-notice');

    function webViewOptions(rydCookies, userAgent) {
        // Tag pluginets standardværdier, og ret dem, der betyder noget for os.
        var options = Object.assign({}, browser.DefaultWebViewOptions, {
            // Standard er true — så ville brugeren blive logget ud hver gang.
            // Kun lige efter log ud i appen ryddes cookies, så sitet også logges ud.
            clearCache: rydCookies,
            clearSessionCache: rydCookies,
            // "MitSporApp/2 (…)" fortæller sitet, at det kører i appen (fælles login).
            customWebViewUserAgent: userAgent,
            showURL: false,
            showToolbar: true,
            showNavigationButtons: true,
            closeButtonText: 'Luk',
            toolbarPosition: browser.ToolbarPosition.TOP
        });

        options.android = Object.assign({}, browser.DefaultAndroidWebViewOptions, {
            // Tilbage-knappen går tilbage i sitets historik og lukker til sidst WebView'et.
            hardwareBack: true
        });
        options.iOS = Object.assign({}, browser.DefaultiOSWebViewOptions, {
            allowsBackForwardNavigationGestures: true
        });

        return options;
    }

    function showOnlineState(connected) {
        offlineNotice.hidden = connected;
    }

    // Spørg styresystemet om netstatus. Kan det ikke afgøres, antages
    // der at være net, så knappen aldrig blokeres af en fejl her.
    function isConnected() {
        return Network.getStatus()
            .then(function (status) { return status.connected; })
            .catch(function () { return true; });
    }

    // Kan sitet faktisk nås? Fanger også Wi-Fi uden internet og tilfælde,
    // hvor styresystemets netstatus er forkert. Svaret er "opaque"
    // (no-cors), men det er nok at vide, at forespørgslen gik igennem.
    function canReachSite() {
        var controller = new AbortController();
        var timer = setTimeout(function () { controller.abort(); }, 5000);

        return fetch(config.siteUrl + '/', { method: 'HEAD', mode: 'no-cors', cache: 'no-store', signal: controller.signal })
            .then(function () { return true; })
            .catch(function () { return false; })
            .finally(function () { clearTimeout(timer); });
    }

    // ---------- Fælles login med sitet (se app/app_login.php på sitet) ----------
    //
    // Appen sender oplysningerne i WebView'ets user-agent, så der ikke skal
    // vises mellemsider: "MitSporApp/2 (par=…; tok=…; kode=…)".
    //   kode: engangskode, der logger sitet ind (/app-login.php omdirigerer straks)
    //   par:  SHA-256 af en hemmelighed; logger brugeren ind på sitet, kan appen
    //         veksle hemmeligheden til et token
    //   tok:  SHA-256 af appens token; log ud på sitet tilbagekalder det

    // Hemmeligheden for det WebView, der er åbent nu (ny for hver åbning).
    var parHemmelighed = null;
    var veksler = false;

    function hex(bytes) {
        return Array.prototype.map.call(bytes, function (b) { return (b + 0x100).toString(16).slice(1); }).join('');
    }

    // SHA-256 som hex, eller null hvis browseren ikke kan.
    function sha256(tekst) {
        try {
            return window.crypto.subtle.digest('SHA-256', new TextEncoder().encode(tekst))
                .then(function (buffer) { return hex(new Uint8Array(buffer)); })
                .catch(function () { return null; });
        } catch (e) {
            return Promise.resolve(null);
        }
    }

    // Adresse og user-agent til WebView'et. Er appen logget ind, går vejen
    // forbi /app-login.php, så sitet logges ind med samme konto.
    function forbered(path) {
        var Konto = window.MitSporKonto;
        var hemmelighed = hex(window.crypto.getRandomValues(new Uint8Array(32)));

        return Promise.all([Konto.webLoginKode(), Konto.hent()]).then(function (svar) {
            var kode = svar[0];
            var login = svar[1];
            return Promise.all([sha256(hemmelighed), login ? sha256(login.token) : null]).then(function (hashes) {
                var dele = [];
                if (hashes[0]) {
                    dele.push('par=' + hashes[0]);
                    parHemmelighed = hemmelighed;
                }
                if (hashes[1]) {
                    dele.push('tok=' + hashes[1]);
                }
                if (kode) {
                    dele.push('kode=' + kode);
                }
                return {
                    url: kode ? config.siteUrl + '/app-login.php?til=' + encodeURIComponent(path) : config.siteUrl + path,
                    userAgent: navigator.userAgent + ' MitSporApp/2' + (dele.length ? ' (' + dele.join('; ') + ')' : '')
                };
            });
        });
    }

    // Har brugeren logget ind på sitet i WebView'et, får appen et token for
    // hemmeligheden. Indtil da svarer sitet 401. Spørges efter hver side.
    function proevVeksling() {
        var hemmelighed = parHemmelighed;
        if (!hemmelighed || veksler) {
            return Promise.resolve();
        }
        veksler = true;
        return window.MitSporKonto.vekslKode(hemmelighed)
            .catch(function () {})
            .finally(function () { veksler = false; });
    }

    function openSite(path) {
        openSiteButton.disabled = true;
        isConnected()
            .then(function (connected) {
                return connected ? canReachSite() : false;
            })
            .then(function (connected) {
                showOnlineState(connected);
                if (!connected) {
                    return;
                }

                // I en almindelig browser (test af startsiden uden app) åbnes siden bare.
                if (!Capacitor.isNativePlatform()) {
                    window.location.href = config.siteUrl + path;
                    return;
                }

                var Konto = window.MitSporKonto;
                var ryd = Konto.skalRydWeb();
                return forbered(path)
                    .then(function (aabning) {
                        return browser.InAppBrowser.openInWebView({ url: aabning.url, options: webViewOptions(ryd, aabning.userAgent) });
                    })
                    .then(function () {
                        if (ryd) {
                            Konto.webRyddet();
                        }
                    })
                    .catch(function () {
                        window.alert('mitspor.dk kunne ikke åbnes. Prøv igen.');
                    });
            })
            .finally(function () {
                openSiteButton.disabled = false;
            });
    }

    openSiteButton.addEventListener('click', function () {
        openSite('/');
    });

    privacyLink.addEventListener('click', function (event) {
        event.preventDefault();
        openSite('/privatlivspolitik.php');
    });

    // Appens lokale skærme er <main>-elementer. Kun én vises ad gangen.
    function visSide(id) {
        Array.prototype.forEach.call(document.querySelectorAll('body > main'), function (side) {
            side.hidden = side.id !== id;
        });
        window.scrollTo(0, 0);
    }

    function aktivSide() {
        var side = document.querySelector('body > main:not([hidden])');
        return side ? side.id : null;
    }

    window.MitSporApp = {
        aabnSite: openSite,
        visSide: visSide,
        aktivSide: aktivSide
    };

    // Når WebView'et lukkes: er appens token tilbagekaldt (brugeren loggede
    // ud på sitet), glemmer appen det; hentAktiviteter() opdager 401. Derefter
    // et sidste forsøg på veksling, hvis brugeren loggede ind på sitet.
    function webLukket() {
        var Konto = window.MitSporKonto;
        Konto.hent()
            .then(function (nu) {
                return nu ? Konto.hentAktiviteter().catch(function () {}) : null;
            })
            .then(proevVeksling)
            .then(function () { parHemmelighed = null; });
    }

    if (Capacitor.isNativePlatform()) {
        browser.InAppBrowser.addListener('browserPageNavigationCompleted', proevVeksling);
        browser.InAppBrowser.addListener('browserClosed', webLukket);
    }

    Network.addListener('networkStatusChange', function (status) {
        showOnlineState(status.connected);
    });
    isConnected().then(showOnlineState);
})();
