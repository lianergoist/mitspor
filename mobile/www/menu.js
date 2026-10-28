// Menuen på startsiden: Hjælp, Sådan virker appen, Privatlivspolitik og
// "Om MitSpor" med appens versionsnummer.
(function () {
    'use strict';

    var App = window.capacitorApp.App;
    var knap = document.getElementById('menu-knap');
    var menu = document.getElementById('menu');
    var omDialog = document.getElementById('om-dialog');
    var omVersion = document.getElementById('om-version');

    function aabn() {
        menu.hidden = false;
        knap.setAttribute('aria-expanded', 'true');
    }

    function luk() {
        menu.hidden = true;
        knap.setAttribute('aria-expanded', 'false');
    }

    knap.addEventListener('click', function (event) {
        event.stopPropagation();
        if (menu.hidden) {
            aabn();
        } else {
            luk();
        }
    });

    // Et tryk uden for menuen lukker den.
    document.addEventListener('click', function (event) {
        if (!menu.hidden && !menu.contains(event.target)) {
            luk();
        }
    });

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

    var handlinger = {
        hjaelp: function () { window.MitSporApp.visSide('view-hjaelp'); },
        intro: function () { document.getElementById('open-intro').click(); },
        privatliv: function () { window.MitSporApp.aabnSite('/privatlivspolitik.php'); },
        om: function () {
            visVersion();
            omDialog.showModal();
        }
    };

    Array.prototype.forEach.call(menu.querySelectorAll('[data-menu]'), function (punkt) {
        punkt.addEventListener('click', function () {
            luk();
            handlinger[punkt.getAttribute('data-menu')]();
        });
    });

    // Et tryk på baggrunden uden for dialogen lukker den også.
    omDialog.addEventListener('click', function (event) {
        if (event.target === omDialog) {
            omDialog.close();
        }
    });

    document.getElementById('hjaelp-tilbage').addEventListener('click', function () {
        window.MitSporApp.visSide('view-start');
    });

    // Android-tilbageknappen (kaldes fra optager.js): true, hvis menuen,
    // Om-dialogen eller hjælpesiden tog sig af trykket.
    window.MitSporMenu = {
        tilbage: function () {
            if (omDialog.open) {
                omDialog.close();
            } else if (!menu.hidden) {
                luk();
            } else if (window.MitSporApp.aktivSide() === 'view-hjaelp') {
                window.MitSporApp.visSide('view-start');
            } else {
                return false;
            }
            return true;
        }
    };
})();
