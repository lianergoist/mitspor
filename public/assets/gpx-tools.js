/* ------------------------------------------------------------------
   GpxTools — rene GPX-handlinger på et indlæst XML-dokument.

   Flyttet ud af GPX-editoren (værktøjskassen), så de kan bruges direkte
   fra kontrolpanelet. Intet her rører siden eller serveren: alle
   funktioner arbejder på det objekt, parse() returnerer, og kalderen
   står selv for at hente og gemme filen.

     var gpx = GpxTools.parse(text);
     GpxTools.detectFields(gpx);              // ['ele', 'time', 'hr', ...]
     GpxTools.thinPlan(gpx, 5);               // tolerance i meter → { plans, total }
     GpxTools.applyThin(gpx, plan);
     GpxTools.stripField(gpx, 'time');        // antal fjernede værdier
     await GpxTools.fetchElevation(gpx, { proxyUrl, onProgress });
     GpxTools.serialize(gpx);                 // streng til upload
------------------------------------------------------------------- */
window.GpxTools = (function () {
    "use strict";

    // De indbyggede visnings-funktioner, der findes — hver kræver at ét
    // eller flere bestemte felter findes i filen. Skal matche
    // GRAPH_METRICS i route-view.php.
    var FUNCTIONS = [
        { key: "elevation_profile", label: "Højdeprofil (graf)", requires: ["ele"] },
        { key: "time_total", label: "Tid", requires: ["time"] },
        { key: "speed_current", label: "Hastighed (aktuel)", requires: ["time"] },
        { key: "speed_avg", label: "Gennemsnit", requires: ["time"] },
        { key: "time_moving", label: "Tid i bevægelse", requires: ["time"] },
        { key: "speed_avg_moving", label: "Gennemsnit i bevægelse", requires: ["time"] },
        { key: "speed_min", label: "Laveste hastighed", requires: ["time"] },
        { key: "speed_max", label: "Højeste hastighed", requires: ["time"] },
        { key: "hr_graph", label: "Puls (graf)", requires: ["hr"] },
        { key: "cadence_graph", label: "Kadence (graf)", requires: ["cad"] },
        { key: "power_graph", label: "Watt (graf)", requires: ["power"] }
    ];

    function tagAll(root, tag) {
        return Array.prototype.slice.call(root.getElementsByTagNameNS("*", tag));
    }

    function firstText(root, tag) {
        var el = root.getElementsByTagNameNS("*", tag)[0];
        return el ? el.textContent.trim() : null;
    }

    function pointFromNode(node) {
        return {
            node: node,
            lat: parseFloat(node.getAttribute("lat")),
            lon: parseFloat(node.getAttribute("lon")),
            ele: firstText(node, "ele"),
            time: firstText(node, "time")
        };
    }

    // Indlæser en GPX-fil. Punkter samles pr. <trkseg>; findes der slet
    // ingen <trkpt> (ren rute-fil), bruges <rtept> pr. <rte> i stedet —
    // samme regel som serveren ("foretræk spor").
    function parse(text) {
        var xml = new DOMParser().parseFromString(text, "application/xml");
        if (xml.querySelector("parsererror")) {
            throw new Error("Kunne ikke fortolke GPX-filen.");
        }
        var ns = xml.documentElement.namespaceURI || "http://www.topografix.com/GPX/1/1";

        var segments = [];
        tagAll(xml, "trkseg").forEach(function (segEl) {
            var pts = tagAll(segEl, "trkpt").map(pointFromNode);
            pts.node = segEl;
            segments.push(pts);
        });

        if (segments.reduce(function (n, s) { return n + s.length; }, 0) === 0) {
            segments = [];
            tagAll(xml, "rte").forEach(function (rteEl) {
                var pts = tagAll(rteEl, "rtept").map(pointFromNode);
                pts.node = rteEl;
                segments.push(pts);
            });
        }

        return { xml: xml, ns: ns, segments: segments };
    }

    function serialize(gpx) {
        var out = new XMLSerializer().serializeToString(gpx.xml);
        if (out.indexOf("<?xml") !== 0) {
            out = '<?xml version="1.0" encoding="UTF-8"?>\n' + out;
        }
        return out;
    }

    function allPoints(gpx) {
        var all = [];
        gpx.segments.forEach(function (seg) { seg.forEach(function (p) { all.push(p); }); });
        return all;
    }

    function hasTimeData(gpx) {
        return allPoints(gpx).some(function (p) { return !!p.time; });
    }

    // ---------- Udtynd punkter ----------
    // Udtynder efter formen på sporet (Douglas-Peucker), ikke efter tid:
    // et punkt fjernes kun, hvis linjen uden det afviger mindre end
    // tolerancen (meter) fra det oprindelige spor. Lige strækninger mister
    // mange punkter, mens sving og slingrende stier bevares. Højden regnes
    // med, når alle segmentets punkter har en, så bakketoppe og dale
    // bevares. Bagefter fyldes huller, så der højst er THIN_MAX_METERS og
    // (når der er tidsstempler) THIN_MAX_SECONDS mellem bevarede punkter,
    // så højdeprofil og fart stadig har opløsning. Punkterne flyttes aldrig.
    var THIN_MAX_METERS = 50;
    var THIN_MAX_SECONDS = 60;

    // Punkterne i lokale meter omkring segmentets første punkt.
    function toMeters(seg) {
        var lat0 = seg[0].lat;
        var lon0 = seg[0].lon;
        var kx = 111320 * Math.cos(lat0 * Math.PI / 180);
        var ky = 110540;
        var useEle = seg.every(function (p) { return p.ele !== null && isFinite(parseFloat(p.ele)); });
        return seg.map(function (p) {
            return {
                x: (p.lon - lon0) * kx,
                y: (p.lat - lat0) * ky,
                z: useEle ? parseFloat(p.ele) : 0,
                t: p.time ? Date.parse(p.time) : NaN
            };
        });
    }

    function dist(a, b) {
        var dx = b.x - a.x, dy = b.y - a.y, dz = b.z - a.z;
        return Math.sqrt(dx * dx + dy * dy + dz * dz);
    }

    // Afstand i anden fra punkt p til linjestykket a-b.
    function segmentDist2(p, a, b) {
        var dx = b.x - a.x, dy = b.y - a.y, dz = b.z - a.z;
        var ex = p.x - a.x, ey = p.y - a.y, ez = p.z - a.z;
        var l2 = dx * dx + dy * dy + dz * dz;
        if (l2 > 0) {
            var t = Math.max(0, Math.min(1, (ex * dx + ey * dy + ez * dz) / l2));
            ex -= t * dx;
            ey -= t * dy;
            ez -= t * dz;
        }
        return ex * ex + ey * ey + ez * ez;
    }

    // Uden rekursion, så meget lange spor ikke sprænger kaldstakken.
    function douglasPeucker(m, keep, tolerance) {
        var t2 = tolerance * tolerance;
        var stack = [[0, m.length - 1]];
        while (stack.length) {
            var part = stack.pop();
            var a = part[0], b = part[1];
            var max = -1, index = -1;
            for (var i = a + 1; i < b; i++) {
                var d2 = segmentDist2(m[i], m[a], m[b]);
                if (d2 > max) {
                    max = d2;
                    index = i;
                }
            }
            if (index > 0 && max > t2) {
                keep[index] = 1;
                stack.push([a, index], [index, b]);
            }
        }
    }

    // Bevarer ekstra punkter, hvor der ellers ville blive for langt
    // (afstand eller tid) mellem to bevarede punkter.
    function fillGaps(m, keep) {
        var last = 0;
        for (var i = 1; i < m.length; i++) {
            if (keep[i]) {
                last = i;
                continue;
            }
            var next = m[i + 1];
            var tooFar = dist(m[last], next) > THIN_MAX_METERS;
            var tooLong = !isNaN(m[last].t) && !isNaN(next.t) && (next.t - m[last].t) / 1000 > THIN_MAX_SECONDS;
            if (tooFar || tooLong) {
                keep[i] = 1;
                last = i;
            }
        }
    }

    function removalForSegment(seg, tolerance) {
        if (seg.length < 3) return [];
        // Punkter uden gyldige koordinater kan ikke vurderes og bevares.
        var valid = seg.filter(function (p) { return isFinite(p.lat) && isFinite(p.lon); });
        if (valid.length < 3) return [];

        var m = toMeters(valid);
        var keep = new Uint8Array(valid.length);
        keep[0] = 1;
        keep[valid.length - 1] = 1;
        douglasPeucker(m, keep, tolerance);
        fillGaps(m, keep);

        return valid.filter(function (p, i) { return !keep[i]; });
    }

    function thinPlan(gpx, toleranceMeters) {
        var plans = gpx.segments.map(function (seg) { return removalForSegment(seg, toleranceMeters); });
        var total = plans.reduce(function (sum, arr) { return sum + arr.length; }, 0);
        return { plans: plans, total: total };
    }

    function applyThin(gpx, plan) {
        gpx.segments.forEach(function (seg, segIndex) {
            var removeSet = new Set(plan.plans[segIndex]);
            for (var i = seg.length - 1; i >= 0; i--) {
                if (removeSet.has(seg[i])) {
                    seg[i].node.parentNode.removeChild(seg[i].node);
                    seg.splice(i, 1);
                }
            }
        });
    }

    // ---------- Datafelter ----------
    // Gennemløber alle punkter og finder de felter, filen rent faktisk
    // indeholder — både faste elementer (ele/time) og alt i <extensions>
    // (fx <gpxtpx:hr>), uanset navnerum-prefix. lat/lon er bevidst IKKE
    // med: det er koordinater, ikke en datatype man kan slå fra.
    function detectFields(gpx) {
        var fields = new Set();

        function walkExtensions(node) {
            Array.prototype.forEach.call(node.children, function (child) {
                if (child.children.length > 0) {
                    walkExtensions(child);
                } else {
                    fields.add(child.localName || child.tagName);
                }
            });
        }

        allPoints(gpx).forEach(function (p) {
            Array.prototype.forEach.call(p.node.children, function (child) {
                var name = child.localName || child.tagName;
                if (name === "extensions") {
                    walkExtensions(child);
                } else {
                    fields.add(name);
                }
            });
        });

        return Array.from(fields);
    }

    function availableFunctions(fields) {
        return FUNCTIONS.filter(function (fn) {
            return fn.requires.every(function (f) { return fields.indexOf(f) !== -1; });
        });
    }

    // Fjerner alle forekomster af et felt fra filen. Returnerer antallet.
    function stripField(gpx, fieldName) {
        var nodes = tagAll(gpx.xml, fieldName);
        var POINT_TAGS = ["trkpt", "rtept", "wpt", "trkseg", "rte", "trk", "gpx"];

        nodes.forEach(function (node) {
            var parent = node.parentNode;
            parent.removeChild(node);

            // Fjern også tomme beholdere, som feltet lå i (fx <extensions> og
            // <gpxtpx:TrackPointExtension>), så de ikke bliver stående som
            // "tomme felter" i listen. Punktet selv røres aldrig.
            while (parent && parent.nodeType === 1 &&
                   POINT_TAGS.indexOf(parent.localName) === -1 &&
                   parent.children.length === 0 && parent.textContent.trim() === "") {
                var up = parent.parentNode;
                up.removeChild(parent);
                parent = up;
            }
        });

        if (fieldName === "time" || fieldName === "ele") {
            allPoints(gpx).forEach(function (p) { p[fieldName] = null; });
        }
        return nodes.length;
    }

    // ---------- Højdedata fra internettet ----------
    // Open Topo Data (SRTM 30m, gratis offentlig instans) via vores egen
    // proxy. Max 100 lokationer pr. kald og ca. 1 kald/sekund — derfor
    // portioner med en lille pause imellem.
    function fetchElevation(gpx, options) {
        options = options || {};
        var proxyUrl = options.proxyUrl || "elevation-proxy.php";
        var onProgress = options.onProgress || function () {};
        var points = allPoints(gpx);
        var CHUNK_SIZE = 100;
        var elevations = [];

        function wait(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }

        function step(i) {
            if (i >= points.length) return Promise.resolve();
            var chunk = points.slice(i, i + CHUNK_SIZE);
            var locations = chunk.map(function (p) { return p.lat + "," + p.lon; }).join("|");

            return fetch(proxyUrl + "?locations=" + locations)
                .then(function (res) {
                    if (!res.ok) throw new Error("API'et svarede med fejl " + res.status);
                    return res.json();
                })
                .then(function (data) {
                    if (!data.results) throw new Error("Uventet svar fra højdedata-API'et");
                    data.results.forEach(function (r) { elevations.push(r.elevation); });
                    onProgress(Math.min(i + CHUNK_SIZE, points.length), points.length);
                    return i + CHUNK_SIZE < points.length ? wait(1100) : null;
                })
                .then(function () { return step(i + CHUNK_SIZE); });
        }

        onProgress(0, points.length);

        return step(0).then(function () {
            var updated = 0;
            points.forEach(function (p, i) {
                var ele = elevations[i];
                if (ele === null || ele === undefined) return; // uden for datasættets dækning

                p.ele = String(Math.round(ele * 10) / 10);

                var eleEl = p.node.getElementsByTagNameNS(gpx.ns, "ele")[0];
                if (!eleEl) {
                    eleEl = gpx.xml.createElementNS(gpx.ns, "ele");
                    // <ele> skal ifølge GPX-skemaet stå FØRST i et punkt (før <time> m.fl.)
                    p.node.insertBefore(eleEl, p.node.firstChild);
                }
                eleEl.textContent = p.ele;
                updated++;
            });
            return { updated: updated, total: points.length };
        });
    }

    return {
        FUNCTIONS: FUNCTIONS,
        parse: parse,
        serialize: serialize,
        allPoints: allPoints,
        pointCount: function (gpx) { return allPoints(gpx).length; },
        hasTimeData: hasTimeData,
        thinPlan: thinPlan,
        applyThin: applyThin,
        detectFields: detectFields,
        availableFunctions: availableFunctions,
        stripField: stripField,
        fetchElevation: fetchElevation
    };
})();
