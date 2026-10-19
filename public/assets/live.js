/* ------------------------------------------------------------------
   Livesiden (live.php). Henter live-data.php hvert halve minut: første
   gang alt, derefter kun nye punkter. Når en etape starter, slutter eller
   får sit færdige spor, ændres versionen, og alt tegnes forfra.

   Forsinkelsen håndhæves på serveren; her vises bare det, der kommer.
------------------------------------------------------------------- */
(function () {
    "use strict";

    var cfg = window.MITSPOR_LIVE;
    if (!cfg || !window.L) return;

    var palette = cfg.palette;
    var version = "";
    var lastId = 0;
    var status = "";
    var current = null;
    var activity = null;      // { state: 'bevaeger'|'staar'|'ingen_kontakt', since }
    var clockOffset = 0;      // serverens ur minus browserens (ms)
    var firstFit = true;
    var follow = false;
    var timer = null;
    var busy = false;

    var map = L.map("map", { zoomControl: false });
    if (window.ResizeObserver) {
        new ResizeObserver(function () { map.invalidateSize({ pan: false }); })
            .observe(document.getElementById("map"));
    }
    L.control.zoom({ position: "bottomright" }).addTo(map);
    L.tileLayer("tile.php?z={z}&x={x}&y={y}", {
        attribution: "&copy; OpenStreetMap-bidragydere",
        maxZoom: cfg.tileMaxZoom
    }).addTo(map);
    map.setView([56.0, 10.0], 7);

    var stageLayer = L.layerGroup().addTo(map);
    var liveLines = {};       // etapens nummer -> polyline med rå punkter
    var marker = null;

    var statusEl = document.getElementById("live-status");
    var statusText = document.getElementById("live-status-text");
    var updatedEl = document.getElementById("live-updated");
    var errorEl = document.getElementById("live-error");
    var listEl = document.getElementById("stage-list");
    var followBtn = document.getElementById("live-follow");

    // Infopanelet ligger oven på kortet. På en smal skærm fylder det hele
    // bredden, så kortet skal holde det, der vises, fri af panelet.
    var panel = document.querySelector(".info-panel");
    function panelPadding() {
        var m = document.getElementById("map").getBoundingClientRect();
        var p = panel.getBoundingClientRect();
        if (p.width < m.width * 0.6) return [0, 0];
        return [0, Math.max(0, p.bottom - m.top)];
    }

    function centerOn(latlng, zoom) {
        var z = zoom === undefined ? map.getZoom() : zoom;
        var shift = panelPadding()[1] / 2;
        var pt = map.project(latlng, z).subtract([0, shift]);
        map.setView(map.unproject(pt, z), z);
    }

    function color(position) {
        return palette[(position - 1) % palette.length];
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
        });
    }

    function stageName(st) {
        return st.title || "Etape " + st.position;
    }

    function drawStages(stages) {
        stageLayer.clearLayers();
        liveLines = {};
        var bounds = L.latLngBounds([]);

        stages.forEach(function (st) {
            var c = color(st.position);
            if (st.planned && st.planned.length > 1) {
                L.polyline(st.planned, { color: "#475569", weight: 3, opacity: 0.7, dashArray: "6 8", interactive: false }).addTo(stageLayer);
                bounds.extend(st.planned);
            }
            var pts = st.route || st.points;
            if (st.state === "planlagt") return;

            // Hvid kant under den farvede linje, som på gruppesiden.
            var edge = L.polyline(pts, { color: "#fff", weight: 8, opacity: 0.9, interactive: false }).addTo(stageLayer);
            var line = L.polyline(pts, { color: c, weight: 5, opacity: 0.95 }).addTo(stageLayer);
            line.bindPopup(escapeHtml(st.position + ". " + stageName(st)));
            if (!st.route) {
                liveLines[st.position] = { line: line, edge: edge };
            }
            if (pts.length) bounds.extend(pts);
        });

        renderList(stages);
        return bounds;
    }

    function renderList(stages) {
        var shown = stages.length > 1 || (stages.length === 1 && stages[0].title);
        listEl.hidden = !shown;
        listEl.innerHTML = "";
        stages.forEach(function (st) {
            var li = document.createElement("li");
            li.setAttribute("data-state", st.state);
            var state = { planlagt: "planlagt", live: "i gang", afsluttet: "færdig" }[st.state] || "";
            var name = escapeHtml(stageName(st));
            li.innerHTML =
                '<span class="stage-num" style="background:' + color(st.position) + '">' + st.position + "</span>" +
                '<span class="stage-title">' + (st.link ? '<a href="' + escapeHtml(st.link) + '">' + name + "</a>" : name) + "</span>" +
                '<span class="stage-state">' + state + "</span>";
            listEl.appendChild(li);
        });
    }

    function addPoints(groups) {
        groups.forEach(function (g) {
            var lines = liveLines[g.stage];
            if (!lines) return;
            g.points.forEach(function (p) {
                lines.edge.addLatLng(p);
                lines.line.addLatLng(p);
            });
        });
    }

    function updateMarker() {
        if (!current) {
            if (marker) { map.removeLayer(marker); marker = null; }
            followBtn.hidden = true;
            return;
        }
        var latlng = [current.lat, current.lon];
        var old = status !== "live" || (activity && activity.state === "ingen_kontakt");
        var icon = L.divIcon({ className: "", html: '<div class="live-marker' + (old ? " is-old" : "") + '"></div>', iconSize: [18, 18], iconAnchor: [9, 9] });
        if (!marker) {
            marker = L.marker(latlng, { icon: icon, keyboard: false, zIndexOffset: 1000 }).addTo(map);
        } else {
            marker.setLatLng(latlng);
            marker.setIcon(icon);
        }
        marker.bindPopup("Seneste position " + ago(current.t));
        followBtn.hidden = false;
        if (follow) centerOn(latlng);
    }

    function ago(t) {
        var min = Math.max(0, Math.round((Date.now() + clockOffset - t) / 60000));
        if (min < 1) return "for under 1 min. siden";
        if (min < 60) return "for " + min + " min. siden";
        var h = Math.floor(min / 60);
        if (h < 24) return "for " + h + " t. " + (min % 60) + " min. siden";
        var d = new Date(t);
        return "d. " + d.toLocaleDateString("da-DK") + " kl. " + d.toLocaleTimeString("da-DK", { hour: "2-digit", minute: "2-digit" });
    }

    function renderStatus() {
        var texts = {
            live: "Live nu",
            pause: "Pause",
            afsluttet: "Afsluttet",
            planlagt: cfg.plannedStart ? "Starter " + cfg.plannedStart : "Ikke startet endnu"
        };
        statusEl.setAttribute("data-status", status);
        statusText.textContent = texts[status] || "";
        var text = current ? "Seneste position " + ago(current.t) : "";
        if (status === "live" && activity && activity.state === "staar") {
            text = "Står stille. Seneste position " + ago(activity.since);
        } else if (status === "live" && activity && activity.state === "ingen_kontakt") {
            text = "Ingen kontakt med appen " + ago(activity.since) + ". Måske dårlig dækning.";
        }
        updatedEl.textContent = text;
    }

    function url() {
        var q = new URLSearchParams({ id: cfg.id, v: version, since: String(lastId) });
        if (cfg.key) q.set("k", cfg.key);
        return "live-data.php?" + q.toString();
    }

    function load() {
        if (busy) return;
        busy = true;
        fetch(url(), { cache: "no-store", credentials: "same-origin" })
            .then(function (res) {
                if (!res.ok) throw new Error("HTTP " + res.status);
                return res.json();
            })
            .then(function (data) {
                if (!data.success) throw new Error("fejl");
                errorEl.hidden = true;
                clockOffset = data.now_ms - Date.now();
                status = data.status;

                if (data.stages) {
                    var bounds = drawStages(data.stages);
                    if (data.current) bounds.extend([data.current.lat, data.current.lon]);
                    if (firstFit && bounds.isValid()) {
                        var top = panelPadding()[1];
                        map.fitBounds(bounds, { paddingTopLeft: [40, top + 30], paddingBottomRight: [40, 40], maxZoom: 15 });
                        firstFit = false;
                    }
                } else {
                    addPoints(data.points);
                }
                version = data.version;
                lastId = data.last_id;
                current = data.current;
                activity = data.activity;
                updateMarker();
                renderStatus();
            })
            .catch(function () {
                errorEl.hidden = false;
            })
            .then(function () {
                busy = false;
                schedule();
            });
    }

    function schedule() {
        clearTimeout(timer);
        if (document.hidden) return;
        // En afsluttet livesending ændrer sig sjældent; spørg sjældnere.
        var seconds = status === "afsluttet" ? 300 : cfg.pollSeconds;
        timer = setTimeout(load, seconds * 1000);
    }

    // Ingen forespørgsler, mens fanen er skjult; hent med det samme, når den vises igen.
    document.addEventListener("visibilitychange", function () {
        if (document.hidden) {
            clearTimeout(timer);
        } else {
            load();
        }
    });

    followBtn.addEventListener("click", function () {
        follow = !follow;
        followBtn.setAttribute("aria-pressed", follow ? "true" : "false");
        if (follow && current) centerOn([current.lat, current.lon], Math.max(map.getZoom(), 14));
    });
    // Flytter brugeren selv kortet, slås "Følg" fra.
    map.on("dragstart", function () {
        if (!follow) return;
        follow = false;
        followBtn.setAttribute("aria-pressed", "false");
    });

    // "for 3 min. siden" skal tælle op mellem opdateringerne.
    setInterval(function () {
        if (!document.hidden) renderStatus();
    }, 30000);

    load();
})();
