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

    function updateOnlineState() {
        offlineNotice.hidden = navigator.onLine;
    }

    function openSite(path) {
        if (!navigator.onLine) {
            updateOnlineState();
            return;
        }

        var url = config.siteUrl + path;

        // I en almindelig browser (test af startsiden uden app) åbnes siden bare.
        if (!Capacitor.isNativePlatform()) {
            window.location.href = url;
            return;
        }

        openSiteButton.disabled = true;
        browser.InAppBrowser.openInWebView({ url: url, options: webViewOptions() })
            .catch(function () {
                window.alert('mitspor.dk kunne ikke åbnes. Prøv igen.');
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

    window.addEventListener('online', updateOnlineState);
    window.addEventListener('offline', updateOnlineState);
    updateOnlineState();
})();
