// GPX og afstande for optageren.
//
// Et punkt er et array: [bredde, længde, højde|null, tid (ms), nøjagtighed (m), segment].
// Arrays fylder mindre end objekter, både i hukommelsen og i punkter.jsonl.
// Et waypoint er et objekt: { lat, lon, ele|null, tid (ms), titel, tekst, billeder }.
// Et billede er { id, fil, type, url|null, fejl|null }; url sættes, når det er
// uploadet, og kun billeder med url kommer med i GPX-filen (som <link>).
(function () {
    'use strict';

    var JORDRADIUS_M = 6371008.8;

    function rad(grader) {
        return grader * Math.PI / 180;
    }

    // Afstand i meter mellem to punkter (haversine).
    function afstand(lat1, lon1, lat2, lon2) {
        var dLat = rad(lat2 - lat1);
        var dLon = rad(lon2 - lon1);
        var a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
            Math.cos(rad(lat1)) * Math.cos(rad(lat2)) * Math.sin(dLon / 2) * Math.sin(dLon / 2);
        return 2 * JORDRADIUS_M * Math.asin(Math.min(1, Math.sqrt(a)));
    }

    function xml(tekst) {
        return String(tekst)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function trkpt(p) {
        var linje = '      <trkpt lat="' + p[0].toFixed(7) + '" lon="' + p[1].toFixed(7) + '">';
        if (p[2] !== null && isFinite(p[2])) {
            linje += '<ele>' + p[2].toFixed(1) + '</ele>';
        }
        return linje + '<time>' + new Date(p[3]).toISOString() + '</time></trkpt>\n';
    }

    function wpt(w) {
        var linje = '  <wpt lat="' + w.lat.toFixed(7) + '" lon="' + w.lon.toFixed(7) + '">';
        if (w.ele !== null && isFinite(w.ele)) {
            linje += '<ele>' + w.ele.toFixed(1) + '</ele>';
        }
        linje += '<time>' + new Date(w.tid).toISOString() + '</time><name>' + xml(w.titel) + '</name>';
        if (w.tekst) {
            linje += '<desc>' + xml(w.tekst) + '</desc>';
        }
        (w.billeder || []).forEach(function (b) {
            if (b.url) {
                linje += '<link href="' + xml(b.url) + '"><type>' + xml(b.type) + '</type></link>';
            }
        });
        return linje + '</wpt>\n';
    }

    function wptListe(waypoints) {
        return (waypoints || []).map(wpt).join('');
    }

    // Skifter waypoints i en GPX-fil, som lav() har skrevet, ud med
    // waypoints (fx når billederne er uploadet og har fået en url).
    function medWaypoints(gpx, waypoints) {
        var start = gpx.indexOf('</metadata>\n');
        var slut = gpx.indexOf('  <trk>\n');
        if (start < 0 || slut < start) {
            return gpx;
        }
        start += '</metadata>\n'.length;
        return gpx.slice(0, start) + wptListe(waypoints) + gpx.slice(slut);
    }

    // Laver hele GPX-filen. Et nyt segment (fx efter genoptagelse) giver en ny <trkseg>.
    // Waypoints skal stå før <trk> (GPX-skemaets rækkefølge).
    function lav(navn, startTid, punkter, waypoints) {
        var dele = [
            '<?xml version="1.0" encoding="UTF-8"?>\n',
            '<gpx version="1.1" creator="MitSpor-app" xmlns="http://www.topografix.com/GPX/1/1">\n',
            '  <metadata><name>' + xml(navn) + '</name><time>' + new Date(startTid).toISOString() + '</time></metadata>\n'
        ];
        dele.push(wptListe(waypoints));
        dele.push('  <trk>\n    <name>' + xml(navn) + '</name>\n');
        var segment = null;

        punkter.forEach(function (p) {
            if (p[5] !== segment) {
                if (segment !== null) {
                    dele.push('    </trkseg>\n');
                }
                dele.push('    <trkseg>\n');
                segment = p[5];
            }
            dele.push(trkpt(p));
        });
        if (segment !== null) {
            dele.push('    </trkseg>\n');
        }
        dele.push('  </trk>\n</gpx>\n');

        return dele.join('');
    }

    window.MitSporGpx = {
        afstand: afstand,
        lav: lav,
        medWaypoints: medWaypoints
    };
})();
