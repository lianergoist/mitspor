// Lokal lagring af optagelser i appens private mappe (Directory.Data).
// Kræver ingen tilladelser og kan ikke ændres af andre apps.
//
// optagelser/<id>/meta.json      oplysninger om turen (id er client_upload_id)
// optagelser/<id>/punkter.jsonl  ét punkt pr. linje, tilføjes løbende
// optagelser/<id>/waypoints.json waypoints, brugeren har indsat (findes kun, hvis der er nogen)
// optagelser/<id>/billeder/      billeder til waypoints (<billed-id>.<endelse>)
// optagelser/<id>/<navn>.gpx     skrives hvert minut og ved stop
//
// punkter.jsonl er den egentlige kilde. GPX-filen kan altid laves igen ud fra den.
(function () {
    'use strict';

    var fs = window.capacitorFilesystemPluginCapacitor;
    var Filesystem = fs.Filesystem;
    var DIR = fs.Directory.Data;
    var UTF8 = fs.Encoding.UTF8;
    var ROD = 'optagelser';

    function sti(id, fil) {
        return ROD + '/' + id + '/' + fil;
    }

    function skriv(path, data) {
        return Filesystem.writeFile({ path: path, data: data, directory: DIR, encoding: UTF8, recursive: true });
    }

    function laes(path) {
        return Filesystem.readFile({ path: path, directory: DIR, encoding: UTF8 })
            .then(function (svar) { return svar.data; });
    }

    function slet(path) {
        return Filesystem.deleteFile({ path: path, directory: DIR }).catch(function () {});
    }

    function nyId() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID();
        }
        var b = window.crypto.getRandomValues(new Uint8Array(16));
        b[6] = (b[6] & 0x0f) | 0x40;
        b[8] = (b[8] & 0x3f) | 0x80;
        var hex = Array.prototype.map.call(b, function (x) { return (x + 0x100).toString(16).slice(1); }).join('');
        return hex.slice(0, 8) + '-' + hex.slice(8, 12) + '-' + hex.slice(12, 16) + '-' + hex.slice(16, 20) + '-' + hex.slice(20);
    }

    function to(n) {
        return (n < 10 ? '0' : '') + n;
    }

    // Filnavn som "mitspor-2026-10-08-1432.gpx" (lokal tid), så det er til at kende igen.
    function gpxFilnavn(startTid) {
        var d = new Date(startTid);
        return 'mitspor-' + d.getFullYear() + '-' + to(d.getMonth() + 1) + '-' + to(d.getDate()) +
            '-' + to(d.getHours()) + to(d.getMinutes()) + '.gpx';
    }

    function turNavn(startTid) {
        var d = new Date(startTid);
        return 'Tur ' + d.toLocaleDateString('da-DK', { day: 'numeric', month: 'short', year: 'numeric' }) +
            ' kl. ' + to(d.getHours()) + '.' + to(d.getMinutes());
    }

    function opret() {
        var start = Date.now();
        var meta = {
            id: nyId(),
            navn: turNavn(start),
            gpxFil: gpxFilnavn(start),
            start: start,
            slut: null,
            status: 'optager',
            antalPunkter: 0,
            afstandM: 0,
            uploadet: false
        };
        return skriv(sti(meta.id, 'punkter.jsonl'), '')
            .then(function () { return gemMeta(meta); })
            .then(function () { return meta; });
    }

    function gemMeta(meta) {
        return skriv(sti(meta.id, 'meta.json'), JSON.stringify(meta));
    }

    function tilfoejPunkt(id, punkt) {
        return Filesystem.appendFile({
            path: sti(id, 'punkter.jsonl'),
            data: JSON.stringify(punkt) + '\n',
            directory: DIR,
            encoding: UTF8
        });
    }

    // Læser punkterne. En halvt skrevet sidste linje (efter et nedbrud) springes over.
    function hentPunkter(id) {
        return laes(sti(id, 'punkter.jsonl')).then(function (tekst) {
            var punkter = [];
            tekst.split('\n').forEach(function (linje) {
                if (!linje) {
                    return;
                }
                try {
                    var p = JSON.parse(linje);
                    if (Array.isArray(p) && p.length >= 6) {
                        punkter.push(p);
                    }
                } catch (e) {
                    // Ufuldstændig linje: ignoreres.
                }
            });
            return punkter;
        });
    }

    function gemWaypoints(id, waypoints) {
        return skriv(sti(id, 'waypoints.json'), JSON.stringify(waypoints));
    }

    // Ingen fil (eller en ulæselig) betyder ingen waypoints.
    function hentWaypoints(id) {
        return laes(sti(id, 'waypoints.json'))
            .then(function (tekst) {
                var liste = JSON.parse(tekst);
                return Array.isArray(liste) ? liste : [];
            })
            .catch(function () { return []; });
    }

    // Billedfilens type ud fra de første bytes (base64). Sitet tager imod
    // JPEG, PNG, GIF og WebP; andet (fx HEIC) afvises her.
    var BILLEDTYPER = [
        ['/9j/', 'jpg', 'image/jpeg'],
        ['iVBORw0KGgo', 'png', 'image/png'],
        ['R0lGOD', 'gif', 'image/gif'],
        ['UklGR', 'webp', 'image/webp']
    ];

    // Kopierer et billede fra kameraets midlertidige fil (uri) til turens
    // mappe. Svarer med { fil, type } til waypointet.
    function gemBillede(id, billedId, uri) {
        return Filesystem.readFile({ path: uri }).then(function (svar) {
            var data = typeof svar.data === 'string' ? svar.data : '';
            var type = BILLEDTYPER.filter(function (t) { return data.indexOf(t[0]) === 0; })[0];
            if (!type) {
                throw new Error('Billedformatet understøttes ikke.');
            }
            var fil = 'billeder/' + billedId + '.' + type[1];
            return Filesystem.writeFile({ path: sti(id, fil), data: data, directory: DIR, recursive: true })
                .then(function () { return { fil: fil, type: type[2] }; });
        });
    }

    // Billedet som Blob til upload.
    function laesBillede(id, billede) {
        return Filesystem.readFile({ path: sti(id, billede.fil), directory: DIR }).then(function (svar) {
            var binaer = atob(svar.data);
            var bytes = new Uint8Array(binaer.length);
            for (var i = 0; i < binaer.length; i++) {
                bytes[i] = binaer.charCodeAt(i);
            }
            return new Blob([bytes], { type: billede.type });
        });
    }

    // Skriver først til en midlertidig fil og omdøber den bagefter, så
    // GPX-filen aldrig er halvt skrevet.
    function gemGpx(meta, gpx) {
        var maal = sti(meta.id, meta.gpxFil);
        var tmp = maal + '.tmp';
        return skriv(tmp, gpx)
            .then(function () { return slet(maal); })
            .then(function () {
                return Filesystem.rename({ from: tmp, to: maal, directory: DIR, toDirectory: DIR });
            });
    }

    function laesGpx(meta) {
        return laes(sti(meta.id, meta.gpxFil));
    }

    // Læser meta.json igen, lader aendr() rette i den og gemmer den.
    // Er turen slettet imens, gemmes intet, og svaret er null.
    // Kaldene køres ét ad gangen, så upload.js og live.js ikke overskriver
    // hinandens ændringer.
    var metaKoe = Promise.resolve();

    function opdaterMeta(id, aendr) {
        var svar = metaKoe.then(function () { return opdaterMetaNu(id, aendr); });
        metaKoe = svar.catch(function () {});
        return svar;
    }

    function opdaterMetaNu(id, aendr) {
        return laes(sti(id, 'meta.json'))
            .then(function (tekst) { return JSON.parse(tekst); })
            .catch(function () { return null; })
            .then(function (meta) {
                if (!meta) {
                    return null;
                }
                aendr(meta);
                return gemMeta(meta).then(function () { return meta; });
            });
    }

    function gpxUri(meta) {
        return Filesystem.getUri({ path: sti(meta.id, meta.gpxFil), directory: DIR })
            .then(function (svar) { return svar.uri; });
    }

    // Alle optagelser, nyeste først. Mapper uden læsbar meta.json springes over.
    function alle() {
        return Filesystem.readdir({ path: ROD, directory: DIR })
            .catch(function () { return { files: [] }; })
            .then(function (svar) {
                return Promise.all(svar.files.map(function (fil) {
                    if (fil.type !== 'directory') {
                        return null;
                    }
                    return laes(sti(fil.name, 'meta.json'))
                        .then(function (tekst) { return JSON.parse(tekst); })
                        .catch(function () { return null; });
                }));
            })
            .then(function (liste) {
                return liste
                    .filter(function (meta) { return meta && meta.id; })
                    .sort(function (a, b) { return b.start - a.start; });
            });
    }

    function sletOptagelse(id) {
        return Filesystem.rmdir({ path: ROD + '/' + id, directory: DIR, recursive: true });
    }

    window.MitSporLager = {
        opret: opret,
        nyId: nyId,
        gemMeta: gemMeta,
        tilfoejPunkt: tilfoejPunkt,
        hentPunkter: hentPunkter,
        gemWaypoints: gemWaypoints,
        hentWaypoints: hentWaypoints,
        gemBillede: gemBillede,
        laesBillede: laesBillede,
        gemGpx: gemGpx,
        laesGpx: laesGpx,
        opdaterMeta: opdaterMeta,
        gpxUri: gpxUri,
        alle: alle,
        slet: sletOptagelse
    };
})();
