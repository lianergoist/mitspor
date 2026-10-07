/* ------------------------------------------------------------------
   Mini-kort i kontrolpanelets detaljepanel. Kortet oprettes først, når
   panelet vises første gang (Leaflet kan ikke måle et skjult element),
   og geometrien hentes fra route-geometry.php (kun ejerens egne ruter).

   Lytter på 'hub:detail-shown' fra assets/dashboard.js. Fordi dashboard.js
   kan have valgt en rute (fra adressens #hash) FØR dette script er
   indlæst, tjekkes det aktuelt viste panel også ved start.

   En rute vises med sin aktivitets markør (SUP, kajak, kano ...) i
   startpunktet — samme markørsæt som rutesiden (public/markers/).
------------------------------------------------------------------- */
(function () {
    "use strict";

    var hub = document.getElementById("hub");
    if (!hub || !window.L) return;

    var maxZoom = parseInt(hub.getAttribute("data-tile-max-zoom"), 10) || 19;
    var STAGE_COLORS = ["#2563eb", "#dc2626", "#16a34a", "#9333ea", "#ea580c", "#0891b2"];
    var maps = {}; // key -> { map, bounds }

    function message(holder, text) {
        holder.classList.add("detail-map--empty");
        holder.textContent = text;
    }

    function addLine(map, points, color) {
        if (!points || points.length < 2) return null;
        var line = window.L.polyline(points, { color: color, weight: 4, opacity: 0.9 }).addTo(map);
        return line;
    }

    function dot(map, latlng, stroke, fill) {
        return window.L.circleMarker(latlng, { radius: 4, color: stroke, weight: 1.5, fillColor: fill, fillOpacity: 1 }).addTo(map);
    }

    function addEndpoints(map, points) {
        if (!points || points.length < 2) return;
        dot(map, points[0], "#166534", "#22c55e");
        dot(map, points[points.length - 1], "#991b1b", "#ef4444");
    }

    // Aktivitetens markør i startpunktet (samme markørsæt som rutesiden, i 80 % af
    // dens størrelse). Ankeret — ikonets fod — beregnes af ActivityMarker ud fra
    // billedets gennemsigtighed, så markøren står på linjen uanset hvordan billedet
    // er beskåret. "1" er retning mod øst, "2" mod vest. Kan billedet ikke indlæses
    // (eller har aktiviteten intet markørsæt), bruges den grønne startprik i stedet.
    function addRouteMarkers(entry, points) {
        var map = entry.map;
        if (!points || points.length < 2) return;

        dot(map, points[points.length - 1], "#991b1b", "#ef4444");

        var base = entry.holder.getAttribute("data-activity-icon");
        if (!base || !window.ActivityMarker) {
            dot(map, points[0], "#166534", "#22c55e");
            return;
        }

        // Retning: mod vest, hvis ruten i starten bevæger sig mod vest.
        var ahead = points[Math.min(points.length - 1, Math.max(1, Math.floor(points.length * 0.05)))];
        var west = ahead[1] < points[0][1];
        var url = "markers/" + base + (west ? "2" : "1") + "_120x120.png";

        window.ActivityMarker.icon(url, { scale: window.ActivityMarker.SCALE * 0.8 })
            .then(function (icon) {
                if (!entry.map || entry.removed) return;
                window.L.marker(points[0], { icon: icon, interactive: false, keyboard: false }).addTo(map);
            })
            .catch(function () {
                if (!entry.map || entry.removed) return;
                dot(map, points[0], "#166534", "#22c55e");
            });
    }

    // Tilpasser kortet til ruten og zoomer så ét niveau længere ud end
    // "lige præcis plads". Så er der altid luft rundt om ruten til den
    // forholdsvis store aktivitetsmarkør, som ellers kunne blive skåret af.
    var PADDING = 16;
    function fitEntry(entry) {
        if (!entry.bounds) return;
        var map = entry.map;
        var zoom = map.getBoundsZoom(entry.bounds, false, window.L.point(PADDING * 2, PADDING * 2));

        if (!isFinite(zoom)) {
            // Kortets størrelse kunne ikke måles (skjult element) — brug standard.
            map.fitBounds(entry.bounds, { padding: [PADDING, PADDING] });
            return;
        }
        map.setView(entry.bounds.getCenter(), Math.max(zoom - 1, map.getMinZoom()), { animate: false });
    }

    function draw(entry, data, isGroup) {
        var layers = [];

        if (isGroup) {
            var stages = data.stages || [];
            stages.forEach(function (stage, i) {
                var line = addLine(entry.map, stage.points, STAGE_COLORS[i % STAGE_COLORS.length]);
                if (line) layers.push(line);
            });
            if (stages.length && stages[0].points && stages[0].points.length) {
                addEndpoints(entry.map, [stages[0].points[0], stages[stages.length - 1].points.slice(-1)[0] || stages[0].points[0]]);
            }
        } else {
            var line = addLine(entry.map, data.points, "#2563eb");
            if (line) layers.push(line);
            addRouteMarkers(entry, data.points);
        }

        if (!layers.length) {
            message(entry.holder, "Ruten har ingen punkter at vise.");
            return;
        }

        entry.bounds = window.L.featureGroup(layers).getBounds();
        fitEntry(entry);
    }

    function create(key, holder) {
        var isGroup = key.indexOf("group:") === 0;
        var id = key.slice(key.indexOf(":") + 1);

        var map = window.L.map(holder, { scrollWheelZoom: false, attributionControl: true });
        window.L.tileLayer("tile.php?z={z}&x={x}&y={y}", {
            attribution: "&copy; OpenStreetMap-bidragydere",
            maxZoom: maxZoom
        }).addTo(map);
        map.setView([56, 10], 6);

        var entry = { map: map, holder: holder, bounds: null };
        maps[key] = entry;

        var url = "route-geometry.php?" + (isGroup ? "group=" : "route=") + encodeURIComponent(id);
        fetch(url)
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data.success) throw new Error("fejl");
                draw(entry, data, isGroup);
            })
            .catch(function () {
                entry.removed = true;
                map.remove();
                delete maps[key];
                message(holder, "Kortet kunne ikke vises.");
            });
    }

    function show(key) {
        if (!key) return;

        var panel = hub.querySelector('[data-detail="' + key + '"]');
        var holder = panel && panel.querySelector(".detail-map");
        if (!holder) return;

        var existing = maps[key];
        if (existing) {
            // Panelet har været skjult — Leaflet skal måle sig selv igen.
            setTimeout(function () {
                existing.map.invalidateSize();
                fitEntry(existing);
            }, 0);
            return;
        }
        if (holder.classList.contains("detail-map--empty")) return;
        create(key, holder);
    }

    function visibleKey() {
        var panel = hub.querySelector("[data-detail]:not([hidden])");
        return panel ? panel.getAttribute("data-detail") : null;
    }

    document.addEventListener("hub:detail-shown", function (ev) {
        show(ev.detail && ev.detail.key);
    });

    // dashboard.js kan allerede have valgt et element fra adressens #hash,
    // før dette script lyttede (fx ved genindlæsning eller "tilbage" fra en
    // rutes side) — vis kortet for det panel, der er åbent lige nu.
    show(visibleKey());

    // Siden kan blive genoprettet fra browserens cache ("tilbage"): kortet
    // skal så måles igen.
    window.addEventListener("pageshow", function (ev) {
        if (ev.persisted) show(visibleKey());
    });
})();
