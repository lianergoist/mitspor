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

    function webViewOptions() {
        // Tag pluginets standardværdier, og ret dem, der betyder noget for os.
        var options = Object.assign({}, browser.DefaultWebViewOptions, {
            // Standard er true — så ville brugeren blive logget ud hver gang.
            clearCache: false,
            clearSessionCache: false,
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

    function openSite(path) {
        var url = config.siteUrl + path;

        openSiteButton.disabled = true;
        isConnected()
            .then(function (connected) {
                showOnlineState(connected);
                if (!connected) {
                    return;
                }

                // I en almindelig browser (test af startsiden uden app) åbnes siden bare.
                if (!Capacitor.isNativePlatform()) {
                    window.location.href = url;
                    return;
                }

                return browser.InAppBrowser.openInWebView({ url: url, options: webViewOptions() })
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

    Network.addListener('networkStatusChange', function (status) {
        showOnlineState(status.connected);
    });
    isConnected().then(showOnlineState);
})();
