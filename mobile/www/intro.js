// Intro: vises første gang appen åbnes, og igen via "Sådan virker appen".
// Kun et flag i localStorage (ingen personlige data).
(function () {
    'use strict';

    var NOEGLE = 'mitspor-intro-set';

    function vis() {
        window.MitSporApp.visSide('view-intro');
        // Markeres som set med det samme, så tilbageknappen ikke viser den igen.
        try { localStorage.setItem(NOEGLE, '1'); } catch (e) {}
    }

    document.getElementById('intro-videre').addEventListener('click', function () {
        window.MitSporApp.visSide('view-start');
    });
    document.getElementById('open-intro').addEventListener('click', function (event) {
        event.preventDefault();
        vis();
    });

    var set = false;
    try { set = localStorage.getItem(NOEGLE) === '1'; } catch (e) {}
    if (!set) {
        vis();
    }
})();
