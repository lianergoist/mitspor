// Rensning af det rå spor, før det bliver til GPX (Smart-mode).
//
// Telefonen gemmer alle brugbare GPS-punkter i punkter.jsonl. GPX-filen
// laves ud fra en renset udgave:
//   1. Udglatning: et Kalman-filter kørt frem og tilbage (RTS-udglatning),
//      hvor hvert punkt vægtes efter sin nøjagtighed. Fjerner zigzag.
//   2. Højden udglattes på samme måde. GPS-højde svinger meget og giver
//      ellers alt for stor samlet stigning.
//   3. Forenkling: Douglas-Peucker fjerner punkter, der ligger tæt på en
//      ret linje mellem naboerne. Sving bevares, lige strækninger får få
//      punkter, dog mindst ét punkt for hver maksAfstand meter, så
//      højdeprofil og tider ikke bliver for grove.
//
// Et punkt er [bredde, længde, højde|null, tid (ms), nøjagtighed (m), segment].
(function () {
    'use strict';

    var M_PR_GRAD = 111320;

    var STANDARD = {
        // Hvor meget positionen kan ændre sig pr. sekund ud over målestøjen
        // (m/s). Lavere giver blødere spor, men skærer hjørner.
        bevaegelse: 4,
        // Mindste nøjagtighed, der regnes med (m). Telefoner melder ofte for
        // optimistiske tal.
        minNoejagtighed: 5,
        // Douglas-Peucker: største afvigelse fra den forenklede linje (m).
        tolerance: 3,
        // Største afstand mellem to punkter i det færdige spor (m).
        maksAfstand: 50,
        // Højde: målestøj (m) og hvor hurtigt højden kan ændre sig (m/s).
        hoejdeStoej: 8,
        hoejdeBevaegelse: 0.3
    };

    // Lokale meter-koordinater omkring første punkt (præcist nok inden for
    // en tur på nogle hundrede km).
    function tilMeter(punkter) {
        var lat0 = punkter[0][0];
        var lon0 = punkter[0][1];
        var kx = M_PR_GRAD * Math.cos(lat0 * Math.PI / 180);
        return {
            x: punkter.map(function (p) { return (p[1] - lon0) * kx; }),
            y: punkter.map(function (p) { return (p[0] - lat0) * M_PR_GRAD; }),
            tilbage: function (x, y) { return [lat0 + y / M_PR_GRAD, lon0 + x / kx]; }
        };
    }

    // Kalman-filter med "tilfældig gang"-model for én koordinat, efterfulgt
    // af Rauch-Tung-Striebel-udglatning baglæns.
    function udglat(vaerdier, varianser, tider, q2) {
        var n = vaerdier.length;
        var x = new Array(n);
        var p = new Array(n);
        var pForud = new Array(n);
        var i;

        x[0] = vaerdier[0];
        p[0] = varianser[0];
        pForud[0] = varianser[0];
        for (i = 1; i < n; i++) {
            var dt = Math.max(0, (tider[i] - tider[i - 1]) / 1000);
            pForud[i] = p[i - 1] + q2 * dt;
            var k = pForud[i] / (pForud[i] + varianser[i]);
            x[i] = x[i - 1] + k * (vaerdier[i] - x[i - 1]);
            p[i] = (1 - k) * pForud[i];
        }

        var ud = new Array(n);
        ud[n - 1] = x[n - 1];
        for (i = n - 2; i >= 0; i--) {
            var c = p[i] / pForud[i + 1];
            ud[i] = x[i] + c * (ud[i + 1] - x[i]);
        }
        return ud;
    }

    // Douglas-Peucker uden rekursion. Returnerer de indeks, der beholdes.
    function douglasPeucker(x, y, tolerance) {
        var n = x.length;
        var behold = new Uint8Array(n);
        var stak = [[0, n - 1]];
        var t2 = tolerance * tolerance;

        behold[0] = 1;
        behold[n - 1] = 1;
        while (stak.length) {
            var del = stak.pop();
            var a = del[0];
            var b = del[1];
            var dx = x[b] - x[a];
            var dy = y[b] - y[a];
            var l2 = dx * dx + dy * dy;
            var maks = -1;
            var indeks = -1;

            for (var i = a + 1; i < b; i++) {
                var ex = x[i] - x[a];
                var ey = y[i] - y[a];
                var d2;
                if (l2 === 0) {
                    d2 = ex * ex + ey * ey;
                } else {
                    var t = Math.max(0, Math.min(1, (ex * dx + ey * dy) / l2));
                    var fx = ex - t * dx;
                    var fy = ey - t * dy;
                    d2 = fx * fx + fy * fy;
                }
                if (d2 > maks) {
                    maks = d2;
                    indeks = i;
                }
            }
            if (indeks > 0 && maks > t2) {
                behold[indeks] = 1;
                stak.push([a, indeks], [indeks, b]);
            }
        }

        var ud = [];
        for (var j = 0; j < n; j++) {
            if (behold[j]) {
                ud.push(j);
            }
        }
        return ud;
    }

    // Udglatter højden for de punkter, der har en.
    function udglatHoejde(punkter, o) {
        var med = [];
        punkter.forEach(function (p, i) {
            if (p[2] !== null && isFinite(p[2])) {
                med.push(i);
            }
        });
        var ud = punkter.map(function () { return null; });
        if (med.length < 2) {
            med.forEach(function (i) { ud[i] = punkter[i][2]; });
            return ud;
        }
        var glat = udglat(
            med.map(function (i) { return punkter[i][2]; }),
            med.map(function () { return o.hoejdeStoej * o.hoejdeStoej; }),
            med.map(function (i) { return punkter[i][3]; }),
            o.hoejdeBevaegelse * o.hoejdeBevaegelse
        );
        med.forEach(function (i, j) { ud[i] = Math.round(glat[j] * 10) / 10; });
        return ud;
    }

    // Indsætter mellemliggende punkter, hvor der er længere end maksAfstand
    // mellem to beholdte punkter.
    function fyldHuller(indeks, x, y, maksAfstand) {
        var ud = [indeks[0]];
        for (var k = 1; k < indeks.length; k++) {
            var fra = ud[ud.length - 1];
            var til = indeks[k];
            for (var i = fra + 1; i < til; i++) {
                if (Math.hypot(x[i] - x[fra], y[i] - y[fra]) >= maksAfstand) {
                    ud.push(i);
                    fra = i;
                }
            }
            ud.push(til);
        }
        return ud;
    }

    // Renser et spor. Returnerer nye punkter i samme format.
    function rens(punkter, valg) {
        var o = Object.assign({}, STANDARD, valg || {});
        if (punkter.length < 3) {
            return punkter.slice();
        }

        var m = tilMeter(punkter);
        var tider = punkter.map(function (p) { return p[3]; });
        var varianser = punkter.map(function (p) {
            var a = Math.max(o.minNoejagtighed, p[4] || 0);
            return a * a;
        });
        var q2 = o.bevaegelse * o.bevaegelse;
        var x = udglat(m.x, varianser, tider, q2);
        var y = udglat(m.y, varianser, tider, q2);
        var hoejder = udglatHoejde(punkter, o);
        var indeks = fyldHuller(douglasPeucker(x, y, o.tolerance), x, y, o.maksAfstand);

        return indeks.map(function (i) {
            var p = punkter[i];
            var ll = m.tilbage(x[i], y[i]);
            return [ll[0], ll[1], hoejder[i], p[3], p[4], p[5]];
        });
    }

    window.MitSporSpor = {
        rens: rens
    };
})();
