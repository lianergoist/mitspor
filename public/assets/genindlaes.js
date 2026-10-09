// Genindlæsning af siden, der også virker i MitSpor-appens WebView.
//
// window.location.reload() gør intet i appens WebView. I stedet hentes
// siden med en almindelig navigation. En midlertidig markør i adressen
// (?_genindlaes=…) gør adressen ny, så valget efter # (fx #route:abc i
// kontrolpanelet) bevares og siden alligevel hentes helt forfra.
// Markøren fjernes fra adressen igen, når siden er indlæst.
//
// Skal indlæses før de scripts, der bruger window.MitSporGenindlaes().
(function () {
    "use strict";

    var PARAM = '_genindlaes';

    var params = new URLSearchParams(window.location.search);
    if (params.has(PARAM)) {
        params.delete(PARAM);
        var query = params.toString();
        try {
            history.replaceState(history.state, '', window.location.pathname + (query ? '?' + query : '') + window.location.hash);
        } catch (e) { /* ikke kritisk */ }
    }

    window.MitSporGenindlaes = function () {
        var p = new URLSearchParams(window.location.search);
        p.set(PARAM, Date.now().toString(36));
        window.location.replace(window.location.pathname + '?' + p.toString() + window.location.hash);
    };
})();
