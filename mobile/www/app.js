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

    function webViewOptions(rydCookies) {
        // Tag pluginets standardværdier, og ret dem, der betyder noget for os.
        var options = Object.assign({}, browser.DefaultWebViewOptions, {
            // Standard er true — så ville brugeren blive logget ud hver gang.
            // Kun lige efter log ud i appen ryddes cookies, så sitet også logges ud.
            clearCache: rydCookies,
            clearSessionCache: rydCookies,
            // "MitSporApp" fortæller sitet, at det kører i appen (fælles login).
            customWebViewUserAgent: navigator.userAgent + ' MitSporApp/1',
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

    // Adressen, WebView'et åbnes med. Er appen logget ind, går vejen forbi
    // /app-login.php med en engangskode, så sitet logges ind med samme konto.
    function siteUrl(path) {
        var Konto = window.MitSporKonto;
        return Konto.webLoginKode().then(function (kode) {
            if (!kode) {
                return config.siteUrl + path;
            }
            return config.siteUrl + '/app-login.php#kode=' + kode + '&til=' + encodeURIComponent(path);
        });
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
                return siteUrl(path)
                    .then(function (url) {
                        return browser.InAppBrowser.openInWebView({ url: url, options: webViewOptions(ryd) });
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

    // Login og log ud på sitet i WebView'et: sitet går forbi
    // /app-forbind.php#kode=… (login) eller /app-logget-ud.php (log ud),
    // så appen kan følge med.
    var brugteKoder = {};

    function sideIndlaest(data) {
        var adresse;
        try {
            adresse = new URL(data && data.url ? data.url : '');
        } catch (e) {
            return;
        }
        if (adresse.origin !== new URL(config.siteUrl).origin) {
            return;
        }

        var Konto = window.MitSporKonto;
        if (adresse.pathname === '/app-forbind.php') {
            var kode = new URLSearchParams(adresse.hash.slice(1)).get('kode');
            if (kode && !brugteKoder[kode]) {
                brugteKoder[kode] = true;
                Konto.vekslKode(kode).catch(function () {
                    // Koden virkede ikke: appen kan stadig logges ind i optageren.
                });
            }
        } else if (adresse.pathname === '/app-logget-ud.php') {
            Konto.hent().then(function (nu) {
                if (nu) {
                    Konto.logUd();
                }
            });
        }
    }

    if (Capacitor.isNativePlatform()) {
        browser.InAppBrowser.addListener('browserPageNavigationCompleted', sideIndlaest);
    }

    Network.addListener('networkStatusChange', function (status) {
        showOnlineState(status.connected);
    });
    isConnected().then(showOnlineState);
})();
