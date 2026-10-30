// Menuerne: på startsiden (Hjælp og "Om MitSpor" med appens
// versionsnummer) og i optageren (Gemte ture).
(function () {
    'use strict';

    var App = window.capacitorApp.App;
    var omDialog = document.getElementById('om-dialog');
    var omVersion = document.getElementById('om-version');
    var tureDialog = document.getElementById('ture-dialog');

    // En menu: knappen åbner og lukker listen; et tryk på et punkt kører
    // punktets handling (attributten attr), og et tryk udenfor lukker den.
    function menu(knapId, menuId, attr, handlinger) {
        var knap = document.getElementById(knapId);
        var liste = document.getElementById(menuId);

        function saetAaben(aaben) {
            liste.hidden = !aaben;
            knap.setAttribute('aria-expanded', aaben ? 'true' : 'false');
        }

        knap.addEventListener('click', function (event) {
            event.stopPropagation();
            saetAaben(liste.hidden);
        });
        document.addEventListener('click', function (event) {
            if (!liste.hidden && !liste.contains(event.target)) {
                saetAaben(false);
            }
        });
        Array.prototype.forEach.call(liste.querySelectorAll('[' + attr + ']'), function (punkt) {
            punkt.addEventListener('click', function () {
                saetAaben(false);
                handlinger[punkt.getAttribute(attr)]();
            });
        });

        return {
            aaben: function () { return !liste.hidden; },
            luk: function () { saetAaben(false); }
        };
    }

    // Versionen fra butikken (fx 1.1) og buildnummeret (fx 7).
    // I en almindelig browser findes de ikke.
    function visVersion() {
        App.getInfo()
            .then(function (info) {
                omVersion.textContent = 'Version ' + info.version + ' (build ' + info.build + ')';
            })
            .catch(function () {
                omVersion.textContent = 'Version ukendt';
            });
    }

    var startMenu = menu('menu-knap', 'menu', 'data-menu', {
        hjaelp: function () { window.MitSporApp.visSide('view-hjaelp'); },
        om: function () {
            visVersion();
            omDialog.showModal();
        }
    });

    var optagMenu = menu('optag-menu-knap', 'optag-menu', 'data-optag-menu', {
        ture: function () { tureDialog.showModal(); }
    });

    // Et tryk på baggrunden uden for en dialog lukker den også.
    [omDialog, tureDialog].forEach(function (dialog) {
        dialog.addEventListener('click', function (event) {
            if (event.target === dialog) {
                dialog.close();
            }
        });
    });
    document.getElementById('ture-luk').addEventListener('click', function () {
        tureDialog.close();
    });

    document.getElementById('hjaelp-tilbage').addEventListener('click', function () {
        window.MitSporApp.visSide('view-start');
    });

    // Android-tilbageknappen (kaldes fra optager.js): true, hvis en menu,
    // en af dialogerne eller hjælpesiden tog sig af trykket.
    window.MitSporMenu = {
        tilbage: function () {
            if (omDialog.open) {
                omDialog.close();
            } else if (tureDialog.open) {
                tureDialog.close();
            } else if (startMenu.aaben()) {
                startMenu.luk();
            } else if (optagMenu.aaben()) {
                optagMenu.luk();
            } else if (window.MitSporApp.aktivSide() === 'view-hjaelp') {
                window.MitSporApp.visSide('view-start');
            } else {
                return false;
            }
            return true;
        }
    };
})();
