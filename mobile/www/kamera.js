// Billeder til waypoints: tag et billede med kameraet eller vælg fra galleriet.
//
// Billederne gøres mindre på telefonen (højst MAX_SIDE_PX på den længste
// side), så de fylder mindre på enheden og ved upload. Sitet fjerner
// EXIF og GPS, når de modtages.
//
// Et valgt billede er { uri, miniature }: uri peger på plugin'ets
// midlertidige fil, og miniature er en data-URL til visning i dialogen.
// Billedet kopieres først til turens mappe, når waypointet gemmes (lager.js).
(function () {
    'use strict';

    var Camera = window.capacitorCamera.Camera;
    var MAX_SIDE_PX = 2048;
    var KVALITET = 80;

    function miniature(resultat) {
        var t = resultat.thumbnail || '';
        if (t && t.indexOf('data:') !== 0) {
            t = 'data:image/jpeg;base64,' + t;
        }
        return t || resultat.webPath || '';
    }

    function tilValgt(resultat) {
        return { uri: resultat.uri || resultat.webPath, miniature: miniature(resultat) };
    }

    // Brugeren lukkede kameraet eller galleriet uden at vælge: ikke en fejl.
    function afbrudt(fejl) {
        var tekst = (fejl && (fejl.message || fejl.code)) || '';
        return /cancel|annul|no image|no media|not selected/i.test(String(tekst));
    }

    function haandter(fejl) {
        if (afbrudt(fejl)) {
            return [];
        }
        throw fejl;
    }

    // Svarer med en liste (tom, hvis brugeren fortrød).
    function tag() {
        return Camera.takePhoto({
            quality: KVALITET,
            targetWidth: MAX_SIDE_PX,
            targetHeight: MAX_SIDE_PX,
            correctOrientation: true,
            saveToGallery: false
        }).then(function (resultat) { return [tilValgt(resultat)]; }, haandter);
    }

    function vaelg(maks) {
        return Camera.chooseFromGallery({
            allowMultipleSelection: maks > 1,
            limit: maks,
            quality: KVALITET,
            targetWidth: MAX_SIDE_PX,
            targetHeight: MAX_SIDE_PX,
            correctOrientation: true
        }).then(function (svar) {
            return (svar.results || []).slice(0, maks).map(tilValgt);
        }, haandter);
    }

    window.MitSporKamera = {
        tag: tag,
        vaelg: vaelg
    };
})();
