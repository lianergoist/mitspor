<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/route_groups.php';
require_once __DIR__ . '/../app/auth_dialogs.php';
require_once __DIR__ . '/../app/app_login.php';
require_once __DIR__ . '/../app/app_tilbage.php';

$loggedIn = !empty($_SESSION['user_id']);
$displayName = null;

if ($loggedIn) {
    require_once __DIR__ . '/../app/auth.php';
    $user = current_user((int) $_SESSION['user_id']);
    if ($user === null) {
        $_SESSION = [];
        session_destroy();
        $loggedIn = false;
    } else {
        $displayName = $user['display_name'];
    }
}

$pdo = get_db();
$stmt = $pdo->query(
    "SELECT r.public_id, r.title, r.activity_type_id, r.route_type, a.category,
            COALESCE(r.start_lon, ST_X(r.centroid)) AS lon,
            COALESCE(r.start_lat, ST_Y(r.centroid)) AS lat,
            r.min_lat, r.min_lon, r.max_lat, r.max_lon,
            r.distance_meters, r.group_id, r.group_position,
            g.public_id AS group_public_id, g.title AS group_title
     FROM routes r
     JOIN activity_types a ON a.id = r.activity_type_id
     LEFT JOIN route_groups g ON g.id = r.group_id
     WHERE r.visibility = 'public' AND r.status = 'active'"
);
// Etaper i en gruppe (fx en 14-dages tur) vises som ÉN post på kortet.
$publicRoutes = group_collapse_overview($stmt->fetchAll());

// Webintroen (velkomstboks og video) vises først, når videoen ligger i
// public/grafik/intro.mp4, og aldrig i appen (appen har sin egen intro).
$introVideoFile = __DIR__ . '/grafik/intro.mp4';
$showIntro = !is_app_webview() && is_file($introVideoFile);
?>
<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/svg+xml" href="/grafik/favicon.svg">
    <link rel="icon" type="image/png" sizes="32x32" href="/grafik/favicon-32.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>MitSpor</title>

    <meta name="description" content="MitSpor er et gratis dansk website til at dele og redigere GPX-ruter og -spor. Upload dine spor, tegn ruter direkte på kortet, og del dem med andre. Ingen tracking, ingen sporings- eller reklamecookies, ingen reklamer.">
    <link rel="canonical" href="<?= APP_BASE_URL ?>/">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="MitSpor">
    <meta property="og:title" content="MitSpor — Del og redigér GPX-ruter og -spor">
    <meta property="og:description" content="Gratis dansk website til at dele og redigere GPX-ruter og -spor. Upload dine spor, tegn ruter direkte på kortet, og del dem med andre.">
    <meta property="og:url" content="<?= APP_BASE_URL ?>/">
    <meta property="og:image" content="<?= APP_BASE_URL ?>/grafik/mitspor_full.png">
    <meta name="twitter:card" content="summary_large_image">

    <link rel="stylesheet" href="vendor/leaflet/leaflet.css">
    <link rel="stylesheet" href="assets/style.css?v=28">
    <style>
        html, body { height: 100%; margin: 0; }
        .start-dot { width: 11px; height: 11px; border-radius: 50%; border: 2px solid #fff; box-shadow: 0 0 3px rgba(0,0,0,.55); }
        .group-marker { width: 26px; height: 26px; border-radius: 50%; background: #2563eb; color: #fff; border: 2px solid #fff; box-shadow: 0 1px 5px rgba(0,0,0,.45); font-size: 11px; font-weight: 700; display: flex; align-items: center; justify-content: center; }
    </style>
</head>
<body>

<div class="map-page">

    <div class="topbar" id="index-topbar">
        <?php if (show_app_back_link()): ?>
        <a href="/app-luk.php" class="app-tilbage"><span aria-hidden="true">‹</span>Appen</a>
        <?php else: ?>
        <span class="brand"><img src="grafik/mitspor_50x50.png" alt="" class="brand-logo"><span class="brand-text">MitSpor</span></span>
        <?php endif; ?>

        <div class="topbar-filters">
            <div class="segmented-filter" role="group" aria-label="Filtrer ruter">
                <button type="button" class="segmented-btn active" data-filter="">Alle</button>
                <button type="button" class="segmented-btn" data-filter="category:water">Ruter på vand</button>
                <button type="button" class="segmented-btn" data-filter="category:land">Ruter på land</button>
            </div>
            <input type="text" id="route-search" placeholder="Søg i offentlige ruter...">
            <button type="button" id="btn-toggle-search" class="search-toggle-btn" aria-label="Søg i offentlige ruter" title="Søg i offentlige ruter"><svg class="tb-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.35-4.35"/></svg></button>
        </div>

        <div class="topbar-actions">
            <?php if ($loggedIn): ?>
                <a href="dashboard.php" class="btn" title="Kontrolpanel" aria-label="Kontrolpanel"><svg class="tb-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="6" cy="19" r="3"/><path d="M9 19h8.5a3.5 3.5 0 0 0 0-7h-11a3.5 3.5 0 0 1 0-7H15"/><circle cx="18" cy="5" r="3"/></svg><span class="tb-label">Kontrolpanel</span></a>
                <form method="post" action="logout.php">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                    <button type="submit" class="btn" title="Log ud" aria-label="Log ud"><svg class="tb-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="9" cy="7" r="4"/><path d="M2 21v-2a4 4 0 0 1 4-4h6a4 4 0 0 1 4 4v2"/><path d="M16 11h6"/><path d="M19 8l3 3-3 3"/></svg><span class="tb-label">Log ud</span></button>
                </form>
            <?php else: ?>
                <button type="button" id="btn-open-register" class="btn" title="Opret konto" aria-label="Opret konto"><svg class="tb-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="9" cy="7" r="4"/><path d="M2 21v-2a4 4 0 0 1 4-4h6a4 4 0 0 1 4 4v2"/><path d="M19 8v6"/><path d="M16 11h6"/></svg><span class="tb-label">Opret konto</span></button>
                <button type="button" id="btn-open-login" class="btn" title="Log ind" aria-label="Log ind"><svg class="tb-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="9" cy="7" r="4"/><path d="M2 21v-2a4 4 0 0 1 4-4h6a4 4 0 0 1 4 4v2"/><path d="M22 11h-6"/><path d="M19 8l-3 3 3 3"/></svg><span class="tb-label">Log ind</span></button>
            <?php endif; ?>
            <?php if ($showIntro): ?>
                <button type="button" id="btn-open-intro-video" class="btn" title="Sådan virker det" aria-label="Sådan virker det"><svg class="tb-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="9"/><path d="M10 8.5v7l5.5-3.5z"/></svg><span class="tb-label">Sådan virker det</span></button>
            <?php endif; ?>
            <button type="button" id="btn-open-about" class="btn" title="Hjælp" aria-label="Hjælp"><span class="help-icon" aria-hidden="true">?</span><span class="tb-label">Hjælp</span></button>
        </div>
    </div>

    <dialog id="about-dialog">
        <div class="dialog-header">
            <h2>Om MitSpor</h2>
            <button type="button" id="about-dialog-close" class="close-btn" aria-label="Luk">&times;</button>
        </div>
        <div class="dialog-content">
            <p>Formålet med MitSpor er at dele spor og ruter. Man kan uploade sine spor (eller tegne en rute) og få den vist på et kort man kan linke til og dele med andre. Man kan også redigere spor og ruter: i den indbyggede GPX-editor kan man flytte punkter og indsætte kommentarer og billeder, og i kontrolpanelet kan man fx udtynde punkter, hente højdedata og vælge, hvad rutens side viser.</p>
            <p>Man kan markere spor og ruter som offentlige eller private.</p>
            <ul>
                <li><strong>Offentlig</strong> betyder, at alle kan se ruten. Man kan søge i titel og beskrivelse, og få vist alle de offentlige spor og ruter der matcher.</li>
                <li><strong>Privat</strong> betyder, at det kun er dig, der kan se sporet/ruten — med mindre du laver et del-link, der giver adgang til netop den rute.</li>
            </ul>
            <p>MitSpor er gratis at bruge. Alt det kræver er en emailadresse, og så er du i gang. Du kan downloade dine data og slette din profil som det passer dig. Ingen tracking, ingen sporings- eller reklamecookies, ingen deling af dine data med andre. Ægte gratis.</p>
            <p>Har du spørgsmål eller kommentarer, er du velkommen til at skrive til <a href="mailto:info@mitspor.dk">info@mitspor.dk</a>.</p>
        </div>
    </dialog>

    <?php if ($showIntro): ?>
    <dialog id="intro-video-dialog">
        <div class="dialog-header">
            <h2>Sådan virker MitSpor</h2>
            <button type="button" id="intro-video-close" class="close-btn" aria-label="Luk">&times;</button>
        </div>
        <div class="intro-video-frame">
            <video src="/grafik/intro.mp4?v=<?= (int) filemtime($introVideoFile) ?>" controls preload="none" playsinline></video>
        </div>
    </dialog>

    <?php if (!$loggedIn && !isset($_GET['deleted'])): ?>
    <!-- Velkomstboks: vises første gang (skjult, til JS har tjekket localStorage). -->
    <section id="intro-welcome" class="intro-welcome" aria-labelledby="intro-welcome-title" hidden>
        <button type="button" id="intro-welcome-close" class="close-btn intro-welcome-close" aria-label="Luk">&times;</button>
        <h2 id="intro-welcome-title">Find og del ruter til vands og til lands</h2>
        <p>Se offentlige ruter på kortet uden at logge ind.</p>
        <p>Upload dine GPX-spor, og få din egen profil. Det er gratis.</p>
        <div class="intro-welcome-buttons">
            <button type="button" id="intro-welcome-video" class="btn btn-primary">Se video (1 min)</button>
            <button type="button" id="intro-welcome-explore" class="btn">Udforsk kortet</button>
        </div>
    </section>
    <?php endif; ?>
    <?php endif; ?>

    <?php if (isset($_GET['deleted'])): ?>
        <div class="map-overlay-panel" style="top: calc(var(--topbar-height, 58px) + 8px); left: 50%; transform: translateX(-50%);">
            Din konto er blevet slettet.
        </div>
    <?php endif; ?>

    <div id="search-results-panel" class="map-overlay-panel" style="top: calc(var(--topbar-height, 58px) + 8px); left: 12px; right: 12px; max-height: calc(100% - var(--topbar-height, 58px) - 24px); overflow-y: auto; padding: 14px 16px;" hidden>
        <div id="search-results-header" style="font-size: 13px; color: var(--text-muted); margin-bottom: 8px;"></div>
        <ul id="search-results-list" class="route-list"></ul>
    </div>

    <div id="map"></div>

</div>

<?php if (!$loggedIn) { render_auth_dialogs(); } ?>

<script src="vendor/leaflet/leaflet.js"></script>
<script>
(function () {
    "use strict";

    var map = L.map('map', { zoomControl: false }).setView([56.0, 10.0], 7);

    // Kortet ligger under topbaren og skifter størrelse, når topbaren gør
    // (fx ombrydning eller søgefeltet på mobil) — så Leaflet skal have besked.
    if (window.ResizeObserver) {
        new ResizeObserver(function () { map.invalidateSize({ pan: false }); })
            .observe(document.getElementById('map'));
    }
    L.control.zoom({ position: 'bottomright' }).addTo(map);
    L.tileLayer('tile.php?z={z}&x={x}&y={y}', {
        attribution: '&copy; OpenStreetMap-bidragydere',
        maxZoom: <?= (int) TILE_MAX_ZOOM ?>
    }).addTo(map);

    var routes = <?= json_encode(array_map(static function (array $r): array {
        $entry = [
            'type' => $r['type'],
            'id' => $r['id'],
            'title' => $r['title'],
            'categories' => $r['categories'],
            'activityTypeId' => $r['activityTypeId'],
            'lat' => $r['lat'],
            'lon' => $r['lon'],
            'km' => $r['km'],
            // [syd, vest, nord, øst] afrundet til ca. 1 m
            'bbox' => array_map(static fn(float $v): float => round($v, 5), $r['bbox']),
        ];
        if ($r['type'] === 'group') {
            $entry['stages'] = $r['stages'];
            $entry['sids'] = $r['sids'];
            $entry['kinds'] = (object) $r['kinds']; // etape-id -> "spor:water" osv.
        } else {
            $entry['kind'] = $r['kind'];            // "rute:land" osv.
        }
        return $entry;
    }, $publicRoutes), JSON_UNESCAPED_UNICODE) ?>;

    // Ingen klyngedannelse: HVER rute får sin egen start-prik, uanset zoom.
    // Prikkerne for enkeltruter er cirkel-markører på samme canvas som linjerne
    // (hurtigt, selv med mange ruter); grupper får en blå cirkel med antal etaper.
    var dotsLayer = L.layerGroup().addTo(map);

    function matchesFilter(r, filterValue) {
        if (!filterValue) return true;
        if (filterValue.indexOf('category:') === 0) {
            return r.categories.indexOf(filterValue.slice(9)) !== -1;
        }
        return true;
    }

    // ---------------------------------------------------------------
    // Ruter som linjer på kortet
    //
    // Alle ruter vises med en start-prik. Når en rute fylder nok på skærmen
    // (LINE_MIN_PX) til, at en linje giver mening, tegnes den også som en
    // linje (hentet forenklet fra overview-lines.php) — så jo mere man
    // zoomer ind, jo flere ruter får linje. Lange ruter får altså linje
    // tidligt, korte først når man er kommet tæt på.
    // ---------------------------------------------------------------
    // Farver: type (spor/rute) og underlag (vand/land).
    //   spor på vand: blå     spor på land: rød
    //   rute på vand: lilla   rute på land: orange
    var KIND_COLORS = {
        'spor:water': '#2563eb',
        'spor:land': '#dc2626',
        'rute:water': '#7c3aed',
        'rute:land': '#ea580c'
    };
    var MAX_LINES = 400;          // højst så mange linjer tegnes ad gangen
    var LINE_MIN_PX = 30;         // en rute får linje, når dens udstrækning er mindst så mange pixels
    var LINES_ALL_ZOOM = 13;      // fra dette zoom tegnes ALLE ruter i billedet som linjer
    var lineRenderer = L.canvas({ padding: 0.4, tolerance: 6 });
    var linesLayer = L.layerGroup().addTo(map);
    var entryMarkers = [];        // [{ entry, marker }] for de viste ruter/grupper
    var dotMarkers = [];          // start-prikkerne (flyttes foran linjerne, når linjer tegnes)
    var lineCache = {};           // "niveau:id" -> [[lat, lon], ...]
    var lineInflight = {};        // "niveau:id" -> true, mens den hentes
    var lineWait = {};            // "niveau:id" -> tidspunkt (ms), før hvilket vi ikke spørger igen
    var drawn = {};               // id -> { layer, level }
    var refreshTimer = null;
    var retryTimer = null;

    function levelForZoom(z) {
        return z <= 8 ? 0 : (z <= 11 ? 1 : 2);
    }

    function lineWeight(z) {
        return z <= 8 ? 3 : (z <= 12 ? 4 : 5);
    }

    function bboxIntersects(b, bounds) {
        return !(b[2] < bounds.getSouth() || b[0] > bounds.getNorth() ||
                 b[3] < bounds.getWest() || b[1] > bounds.getEast());
    }

    // Rutens udstrækning på skærmen (pixels) ved et givent zoom.
    function entryPixelSize(e, zoom) {
        var a = map.project([e.bbox[0], e.bbox[1]], zoom);
        var b = map.project([e.bbox[2], e.bbox[3]], zoom);
        return Math.max(Math.abs(b.x - a.x), Math.abs(b.y - a.y));
    }

    function popupHtml(r) {
        var url = (r.type === 'group' ? 'group-view.php?id=' : 'route-view.php?id=') + encodeURIComponent(r.id);
        var meta = r.type === 'group'
            ? r.stages + ' etaper &middot; ' + String(r.km).replace('.', ',') + ' km'
            : String(r.km).replace('.', ',') + ' km';
        return '<a href="' + url + '">' + escapeHtml(r.title) + '</a><br>' + meta;
    }

    function kindColor(kind) {
        return KIND_COLORS[kind] || KIND_COLORS['rute:land'];
    }

    // Farve til en rute/etape: hver etape i en gruppe har sin egen type og sit eget underlag.
    function colorFor(entry, id) {
        return kindColor(entry.type === 'group' ? (entry.kinds || {})[id] : entry.kind);
    }

    function clearLines() {
        linesLayer.clearLayers();
        drawn = {};
        clearTimeout(refreshTimer);
        clearTimeout(retryTimer);
    }

    // De linjer, der skal vises lige nu: ruter, der fylder nok på skærmen,
    // og hvis udstrækning berører kortudsnittet (også selvom starten er uden
    // for billedet), nærmest kortets midte først.
    function wantedLines() {
        var zoom = Math.round(map.getZoom());
        var view = map.getBounds().pad(0.15);
        var c = map.getCenter();
        var found = [];
        entryMarkers.forEach(function (em) {
            var e = em.entry;
            if (!bboxIntersects(e.bbox, view)) return;
            if (zoom < LINES_ALL_ZOOM && entryPixelSize(e, zoom) < LINE_MIN_PX) return;
            var dy = (e.bbox[0] + e.bbox[2]) / 2 - c.lat;
            var dx = ((e.bbox[1] + e.bbox[3]) / 2 - c.lng) * Math.cos(c.lat * Math.PI / 180);
            var d = dx * dx + dy * dy;
            (e.type === 'group' ? e.sids : [e.id]).forEach(function (id) {
                found.push({ id: id, entry: e, d: d });
            });
        });
        found.sort(function (a, b) { return a.d - b.d; });
        return found.slice(0, MAX_LINES);
    }

    function drawLine(w, level, pts) {
        var existing = drawn[w.id];
        if (existing && existing.level === level) return false;
        if (existing) linesLayer.removeLayer(existing.layer);

        var z = Math.round(map.getZoom());
        var weight = lineWeight(z);
        var color = colorFor(w.entry, w.id);
        var line = L.polyline(pts, { renderer: lineRenderer, color: color, weight: weight, opacity: 0.9, lineJoin: 'round' });
        line.bindTooltip(escapeHtml(w.entry.title), { sticky: true });
        line.bindPopup(popupHtml(w.entry));
        line.on('mouseover', function () { line.setStyle({ weight: weight + 3, opacity: 1 }); line.bringToFront(); });
        line.on('mouseout', function () { line.setStyle({ weight: weight, opacity: 0.9 }); });
        linesLayer.addLayer(line);
        drawn[w.id] = { layer: line, level: level };
        return true;
    }

    function fetchLines(ids, level) {
        var now = Date.now();
        var todo = ids.filter(function (id) {
            var key = level + ':' + id;
            return !lineInflight[key] && !(lineWait[key] > now);
        });
        for (var i = 0; i < todo.length; i += 60) {
            (function (batch) {
                batch.forEach(function (id) { lineInflight[level + ':' + id] = true; });
                fetch('overview-lines.php?level=' + level + '&ids=' + batch.join(','))
                    .then(function (res) {
                        if (!res.ok) throw new Error('HTTP ' + res.status);
                        return res.json();
                    })
                    .then(function (data) {
                        var got = data.lines || {};
                        var pending = {};
                        (data.pending || []).forEach(function (id) { pending[id] = true; });
                        if (Object.keys(lineCache).length > 2500) lineCache = {}; // hold hukommelsen nede
                        batch.forEach(function (id) {
                            var key = level + ':' + id;
                            if (got[id]) {
                                lineCache[key] = got[id];
                            } else if (pending[id]) {
                                lineWait[key] = Date.now() + 1500;   // endnu ikke beregnet — prøv igen om lidt
                            } else {
                                lineWait[key] = Infinity;            // findes ikke som offentlig linje
                            }
                        });
                        if (data.pending && data.pending.length) {
                            clearTimeout(retryTimer);
                            retryTimer = setTimeout(refreshLines, 1600);
                        }
                    })
                    .catch(function () {
                        // Linjerne er kun pynt — prikkerne virker stadig. Prøv igen om lidt.
                        batch.forEach(function (id) { lineWait[level + ':' + id] = Date.now() + 15000; });
                        clearTimeout(retryTimer);
                        retryTimer = setTimeout(refreshLines, 15500);
                    })
                    .then(function () {
                        batch.forEach(function (id) { delete lineInflight[level + ':' + id]; });
                        refreshLines();
                    });
            })(todo.slice(i, i + 60));
        }
    }

    function refreshLines() {
        if (!entryMarkers.length) return;
        var level = levelForZoom(Math.round(map.getZoom()));
        var want = wantedLines();
        var wantIds = {};
        want.forEach(function (w) { wantIds[w.id] = true; });

        Object.keys(drawn).forEach(function (id) {
            if (!wantIds[id]) {
                linesLayer.removeLayer(drawn[id].layer);
                delete drawn[id];
            }
        });

        // En linje i et andet detaljeniveau bliver stående, mens det nye hentes.
        var need = [];
        var drewNew = false;
        want.forEach(function (w) {
            var pts = lineCache[level + ':' + w.id];
            if (pts) {
                if (drawLine(w, level, pts)) drewNew = true;
            } else {
                need.push(w.id);
            }
        });
        // Nye linjer lægger sig oven på prikkerne — flyt prikkerne tilbage forrest.
        if (drewNew) dotMarkers.forEach(function (m) { m.bringToFront(); });
        if (need.length) fetchLines(need, level);
    }

    function scheduleRefresh() {
        clearTimeout(refreshTimer);
        refreshTimer = setTimeout(refreshLines, 120);
    }

    map.on('moveend zoomend', scheduleRefresh);

    function renderRoutes(filterValue) {
        dotsLayer.clearLayers();
        clearLines();
        entryMarkers = [];
        dotMarkers = [];

        var filtered = routes.filter(function (r) { return matchesFilter(r, filterValue); });

        if (filtered.length === 0) return;

        var bounds = L.latLngBounds([]);
        filtered.forEach(function (r) {
            var marker;
            if (r.type === 'group') {
                // En gruppe (etape-tur): blå cirkel med antal etaper.
                marker = L.marker([r.lat, r.lon], {
                    icon: L.divIcon({
                        className: '',
                        html: '<div class="group-marker">' + r.stages + '</div>',
                        iconSize: [26, 26],
                        iconAnchor: [13, 13],
                        popupAnchor: [0, -12]
                    })
                });
            } else {
                // Startpunkt: en lille farvet prik med hvid kant.
                marker = L.circleMarker([r.lat, r.lon], {
                    renderer: lineRenderer,
                    radius: 6,
                    color: '#ffffff',
                    weight: 2,
                    opacity: 1,
                    fillColor: colorFor(r, r.id),
                    fillOpacity: 1
                });
                dotMarkers.push(marker);
            }
            marker.bindPopup(popupHtml(r), r.type === 'group' ? {} : { offset: [0, -4] });
            marker.bindTooltip(escapeHtml(r.title), { direction: 'top', offset: [0, -8] });
            marker.mitsporEntry = r;
            entryMarkers.push({ entry: r, marker: marker });
            dotsLayer.addLayer(marker);
            bounds.extend([r.lat, r.lon]);
        });

        map.fitBounds(bounds, { padding: [40, 40], maxZoom: 12 });
        scheduleRefresh();
    }

    renderRoutes('');

    var filterButtons = document.querySelectorAll('.segmented-btn[data-filter]');
    var activeFilterValue = '';
    var activeFilterLabel = 'Alle';

    filterButtons.forEach(function (btn) {
        btn.addEventListener('click', function () {
            filterButtons.forEach(function (b) { b.classList.remove('active'); });
            btn.classList.add('active');
            activeFilterValue = btn.getAttribute('data-filter');
            activeFilterLabel = btn.textContent;

            renderRoutes(activeFilterValue);
            if (searchInput.value.trim().length >= 2) performSearch();
        });
    });

    // ---------- Topbarens højde ----------
    // Topbaren ligger oven på kortet og er en, to eller tre linjer høj
    // (filterknapper og åbent søgefelt på mobil og tablet). Søgeresultaterne
    // og andre bokse lægges lige under den via --topbar-height.
    var indexTopbar = document.getElementById('index-topbar');
    var mapPage = document.querySelector('.map-page');
    function syncTopbarHeight() {
        mapPage.style.setProperty('--topbar-height', indexTopbar.offsetHeight + 'px');
    }
    syncTopbarHeight();
    if (window.ResizeObserver) {
        new ResizeObserver(syncTopbarHeight).observe(indexTopbar);
    } else {
        window.addEventListener('resize', syncTopbarHeight);
    }

    // ---------- Søgning ----------
    var searchInput = document.getElementById('route-search');
    var resultsPanel = document.getElementById('search-results-panel');
    var resultsHeader = document.getElementById('search-results-header');
    var resultsList = document.getElementById('search-results-list');
    var searchDebounceTimer = null;

    // På mobil er søgefeltet skjult som udgangspunkt — søge-ikonet
    // åbner (og fokuserer) det ved klik.
    var btnToggleSearch = document.getElementById('btn-toggle-search');
    if (btnToggleSearch) {
        btnToggleSearch.addEventListener('click', function () {
            searchInput.classList.toggle('search-open');
            syncTopbarHeight(); // til browsere uden ResizeObserver
            if (searchInput.classList.contains('search-open')) {
                searchInput.focus();
            }
        });
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function currentFilterParams() {
        if (activeFilterValue.indexOf('category:') === 0) return { category: activeFilterValue.slice(9) };
        return {};
    }

    function filterLabel() {
        return activeFilterLabel;
    }

    function renderSearchResults(results, q) {
        resultsPanel.hidden = false;

        if (results.length === 0) {
            var suggestion = activeFilterValue.indexOf('category:') === 0
                ? ' Prøv at vælge "Alle" i stedet.'
                : '';
            resultsHeader.textContent = 'Ingen resultater for "' + q + '" (' + filterLabel() + ').' + suggestion;
            resultsList.innerHTML = '';
            return;
        }

        resultsHeader.textContent = results.length + ' resultat(er) for "' + q + '" (' + filterLabel() + ')';
        resultsList.innerHTML = results.map(function (r) {
            if (r.type === 'group') {
                return '<li><div>' +
                    '<a class="route-title" href="group-view.php?id=' + encodeURIComponent(r.public_id) + '">' + escapeHtml(r.title) + '</a>' +
                    '<span class="route-visibility">' + r.stages + ' etaper &middot; ' + (r.distance_meters / 1000).toFixed(1) + ' km</span>' +
                    '</div></li>';
            }
            return '<li><div>' +
                '<a class="route-title" href="route-view.php?id=' + encodeURIComponent(r.public_id) + '">' + escapeHtml(r.title) + '</a>' +
                '<span class="route-visibility">' + escapeHtml(r.activity_name) + ' &middot; ' + (r.distance_meters / 1000).toFixed(1) + ' km</span>' +
                '</div></li>';
        }).join('');
    }

    function performSearch() {
        var q = searchInput.value.trim();
        if (q.length < 2) {
            resultsPanel.hidden = true;
            return;
        }

        var params = Object.assign({ q: q }, currentFilterParams());
        fetch('search-routes.php?' + new URLSearchParams(params))
            .then(function (res) { return res.json(); })
            .then(function (data) { renderSearchResults(data.results || [], q); })
            .catch(function () {
                resultsPanel.hidden = false;
                resultsHeader.textContent = 'Der skete en fejl under søgningen. Prøv igen.';
                resultsList.innerHTML = '';
            });
    }

    searchInput.addEventListener('input', function () {
        clearTimeout(searchDebounceTimer);
        searchDebounceTimer = setTimeout(performSearch, 300);
    });
})();

(function () {
    "use strict";
    var aboutDialog = document.getElementById('about-dialog');
    document.getElementById('btn-open-about').addEventListener('click', function () {
        aboutDialog.showModal();
    });
    document.getElementById('about-dialog-close').addEventListener('click', function () {
        aboutDialog.close();
    });
    aboutDialog.addEventListener('click', function (ev) {
        if (ev.target === aboutDialog) aboutDialog.close();
    });
})();

// ---------- Intro: video og velkomstboks (ikke i appen) ----------
(function () {
    "use strict";
    var videoDialog = document.getElementById('intro-video-dialog');
    if (!videoDialog) return;

    function openVideo() {
        videoDialog.showModal();
    }
    function closeVideo() {
        videoDialog.close();
    }
    // Videoen stopper, når vinduet lukkes (også med Esc).
    videoDialog.addEventListener('close', function () {
        var video = videoDialog.querySelector('video');
        if (video) video.pause();
    });
    document.getElementById('btn-open-intro-video').addEventListener('click', openVideo);
    document.getElementById('intro-video-close').addEventListener('click', closeVideo);
    videoDialog.addEventListener('click', function (ev) {
        if (ev.target === videoDialog) closeVideo();
    });

    var welcome = document.getElementById('intro-welcome');
    if (!welcome) return;
    var STORAGE_KEY = 'mitspor-intro-lukket';
    var dismissed = false;
    try { dismissed = localStorage.getItem(STORAGE_KEY) === '1'; } catch (e) {}
    if (dismissed) return;
    welcome.hidden = false;

    function dismiss() {
        welcome.hidden = true;
        try { localStorage.setItem(STORAGE_KEY, '1'); } catch (e) {}
    }
    document.getElementById('intro-welcome-close').addEventListener('click', dismiss);
    document.getElementById('intro-welcome-explore').addEventListener('click', dismiss);
    document.getElementById('intro-welcome-video').addEventListener('click', function () {
        dismiss();
        openVideo();
    });
})();

</script>
<?php if (!$loggedIn): ?>
<script src="assets/genindlaes.js?v=1"></script>
<script src="assets/auth-dialogs.js?v=3"></script>
<script>MitSporAuth.init({ handleUrl: true });</script>
<?php endif; ?>

</body>
</html>
