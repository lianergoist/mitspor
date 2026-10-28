<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/simple_page.php';
require_once __DIR__ . '/../app/route_groups.php';
require_once __DIR__ . '/../app/og_image.php';
require_once __DIR__ . '/../app/group_file.php';
require_once __DIR__ . '/../app/app_hent.php';

$pdo = get_db();
$group = group_find($pdo, (string) ($_GET['id'] ?? ''));

$isOwner = $group !== null
    && !empty($_SESSION['user_id'])
    && (int) $_SESSION['user_id'] === (int) $group['user_id'];

// Besøgende ser kun de etaper, der er offentlige. Har gruppen ingen, ser
// de kun en "findes ikke"-side — så en helt privat gruppe ikke afslører sig.
$members = $group !== null ? group_members($pdo, (int) $group['id'], $isOwner) : [];

if ($group === null || (!$isOwner && count($members) === 0)) {
    http_response_code(404);
    simple_message_page('Gruppen blev ikke fundet', 'Der findes ingen gruppe med det ID.');
    exit;
}

$totals = group_totals($members);

// Download af hele gruppen som én GPX. I appen kun fra MitSporApp/6, der
// selv kan hente gruppen (se group_download_available() i app/app_hent.php).
$downloadPlan = group_download_plan($members, $isOwner);
$showGroupDownload = $downloadPlan['included'] !== [] && group_download_available();
$geometry = group_geometry($group, $members, $isOwner);
$hasPublic = false;
foreach ($members as $m) {
    if ($m['visibility'] === 'public') {
        $hasPublic = true;
        break;
    }
}

// Navnet vises kun, hvis ALLE viste etaper selv viser det.
$showUploader = count($members) > 0;
foreach ($members as $m) {
    if ((int) $m['hide_uploader_name'] === 1) {
        $showUploader = false;
        break;
    }
}

$e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$km = static fn(int $meters): string => number_format($meters / 1000, 1, ',', '.') . ' km';

$canonicalUrl = APP_BASE_URL . '/group-view.php?id=' . urlencode($group['public_id']);
$summary = $totals['stages'] . ($totals['stages'] === 1 ? ' etape' : ' etaper') . ' · ' . $km($totals['distance_meters']);
$seoDescription = $summary;
if (!empty($group['description'])) {
    $seoDescription .= ' — ' . trim(mb_substr((string) $group['description'], 0, 150));
}

// Delingsbillede (og:image): et kort med alle offentlige etaper. Har gruppen
// ingen offentlige etaper (kun ejeren kan se den), bruges det generiske billede.
$publicMembers = $isOwner ? group_members($pdo, (int) $group['id'], false) : $members;
if ($publicMembers) {
    $ogImageUrl = APP_BASE_URL . '/og-image.php?group=' . urlencode($group['public_id'])
        . '&v=' . og_group_version($group, $publicMembers);
    $ogImageType = 'image/jpeg';
    $ogImageAlt = 'Kort over etaperne i ' . $group['title'];
} else {
    $ogImageUrl = APP_BASE_URL . '/grafik/mitspor_full.png?v=4';
    $ogImageType = 'image/png';
    $ogImageAlt = 'MitSpor – del dine ture på et kort';
}

$palette = ['#e6194b', '#3cb44b', '#4363d8', '#f58231', '#911eb4', '#0891b2', '#d946ef', '#9a6324', '#0f766e', '#7f1d1d'];
?>
<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/svg+xml" href="/grafik/favicon.svg">
    <link rel="icon" type="image/png" sizes="32x32" href="/grafik/favicon-32.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= $e($group['title']) ?></title>

    <meta name="description" content="<?= $e($seoDescription) ?>">
    <?php if (!$hasPublic): ?>
    <meta name="robots" content="noindex, nofollow">
    <?php else: ?>
    <link rel="canonical" href="<?= $e($canonicalUrl) ?>">
    <?php endif; ?>
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="MitSpor">
    <meta property="og:title" content="<?= $e($group['title']) ?>">
    <meta property="og:description" content="<?= $e($seoDescription) ?>">
    <meta property="og:url" content="<?= $e($canonicalUrl) ?>">
    <meta property="og:image" content="<?= $e($ogImageUrl) ?>">
    <meta property="og:image:type" content="<?= $ogImageType ?>">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="<?= $e($ogImageAlt) ?>">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:image" content="<?= $e($ogImageUrl) ?>">

    <link rel="stylesheet" href="vendor/leaflet/leaflet.css">
    <link rel="stylesheet" href="assets/style.css?v=35">
    <style>
        html, body { height: 100%; margin: 0; }
        .info-panel { top: 80px; left: 12px; width: 320px; max-width: calc(100vw - 24px); max-height: calc(100dvh - 100px); overflow-y: auto; }
        .info-panel h1 { font-size: 16px; margin: 0 0 4px; }
        .info-panel .meta { color: var(--text-muted); font-size: 12px; margin-bottom: 6px; }
        .info-panel p { margin: 6px 0; font-size: 13px; }
        /* Knapper i topbaren, som på rutesiden (route-view.php). */
        .topbar-buttons { display: flex; align-items: center; flex-wrap: wrap; justify-content: flex-end; gap: 6px; }
        @media (max-width: 520px) {
            .btn-label { display: none; }
            .map-page .topbar .btn { padding: 6px 12px; min-height: 40px; }
        }
        .stage-list { list-style: none; margin: 10px 0 0; padding: 0; border-top: 1px solid var(--border); }
        .stage-list li { border-bottom: 1px solid var(--border); }
        .stage-list a { display: flex; align-items: center; gap: 10px; padding: 7px 4px; color: inherit; text-decoration: none; font-size: 13px; }
        .stage-list a:hover, .stage-list li.hl a { background: rgba(37, 99, 235, 0.08); }
        .stage-num { flex: 0 0 auto; width: 24px; height: 24px; border-radius: 50%; color: #fff; font-size: 12px; font-weight: 700; display: flex; align-items: center; justify-content: center; }
        .stage-title { flex: 1 1 auto; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .stage-km { flex: 0 0 auto; color: var(--text-muted); font-size: 12px; }
        .stage-private { color: var(--text-muted); font-size: 11px; }
        .stage-marker { width: 24px; height: 24px; border-radius: 50%; border: 2px solid #fff; color: #fff; font-size: 12px; font-weight: 700; display: flex; align-items: center; justify-content: center; box-shadow: 0 1px 4px rgba(0,0,0,.4); }
    </style>
</head>
<body>

<div class="map-page">

    <div class="topbar">
        <a href="<?= $isOwner ? 'dashboard.php' : 'index.php' ?>" class="brand">&larr; <?= $isOwner ? 'Mit kontrolpanel' : 'MitSpor' ?></a>
        <?php if ($showGroupDownload): ?>
            <div class="topbar-buttons">
                <a class="btn" href="<?= $e(group_download_url($group['public_id'])) ?>" title="Download hele gruppen som én GPX-fil" aria-label="Download"><svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path d="M12 3v12m0 0l-5-5m5 5l5-5M5 20h14" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round"/></svg><span class="btn-label">Download</span></a>
            </div>
        <?php endif; ?>
    </div>

    <div id="map"></div>

    <div class="map-overlay-panel info-panel">
        <h1><?= $e($group['title']) ?></h1>
        <div class="meta">
            <?= $e($summary) ?><?php if ($totals['elevation_gain_meters'] > 0): ?> · <?= number_format($totals['elevation_gain_meters'], 0, ',', '.') ?> m stigning<?php endif; ?>
        </div>

        <?php if ($showUploader): ?>
            <div class="meta">Uploadet af: <?= $e((string) $group['uploader_name']) ?></div>
        <?php endif; ?>

        <?php if (!empty($group['description'])): ?>
            <p><?= nl2br($e((string) $group['description'])) ?></p>
        <?php endif; ?>

        <?php if ($isOwner && !$hasPublic): ?>
            <p class="meta">Ingen af etaperne er offentlige — kun du kan se denne gruppe.</p>
        <?php endif; ?>

        <?php if ($showGroupDownload && $downloadPlan['skipped'] > 0): ?>
            <p class="meta">Download: <?= (int) $downloadPlan['skipped'] ?> af <?= count($members) ?> etaper må ikke downloades og kommer ikke med i filen.</p>
        <?php endif; ?>

        <ol class="stage-list" id="stage-list">
            <?php foreach ($members as $i => $m): ?>
                <li data-stage="<?= $i ?>">
                    <a href="route-view.php?id=<?= $e($m['public_id']) ?>">
                        <span class="stage-num" style="background: <?= $palette[$i % count($palette)] ?>"><?= $i + 1 ?></span>
                        <span class="stage-title"><?= $e($m['title']) ?></span>
                        <?php if ($isOwner && $m['visibility'] === 'private'): ?><span class="stage-private">privat</span><?php endif; ?>
                        <span class="stage-km"><?= $km((int) $m['distance_meters']) ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ol>
    </div>
</div>

<script src="vendor/leaflet/leaflet.js"></script>
<script>
(function () {
    "use strict";

    var stages = <?= json_encode($geometry, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    var palette = <?= json_encode($palette) ?>;

    var map = L.map('map', { zoomControl: false });

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

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    var lines = [];
    var all = [];

    stages.forEach(function (st, i) {
        var color = palette[i % palette.length];
        var pts = st.points;
        if (!pts || pts.length < 2) { lines.push(null); return; }

        var url = 'route-view.php?id=' + encodeURIComponent(st.id);
        var popup = '<a href="' + url + '">' + escapeHtml((i + 1) + '. ' + st.title) + '</a>';

        // Hvid kant under den farvede linje, så etaperne står tydeligt på kortet.
        L.polyline(pts, { color: '#fff', weight: 8, opacity: 0.9, interactive: false }).addTo(map);
        var line = L.polyline(pts, { color: color, weight: 5, opacity: 0.95 }).addTo(map);
        line.bindPopup(popup);
        line.on('mouseover', function () { line.setStyle({ weight: 8 }); highlight(i, true); });
        line.on('mouseout', function () { line.setStyle({ weight: 5 }); highlight(i, false); });
        lines.push(line);

        var icon = L.divIcon({
            className: '',
            html: '<div class="stage-marker" style="background:' + color + '">' + (i + 1) + '</div>',
            iconSize: [24, 24],
            iconAnchor: [12, 12]
        });
        L.marker(pts[0], { icon: icon, title: st.title }).addTo(map).bindPopup(popup);

        pts.forEach(function (p) { all.push(p); });
    });

    if (all.length) {
        map.fitBounds(L.latLngBounds(all), { padding: [50, 50] });
    } else {
        map.setView([56.0, 10.0], 7);
    }

    function highlight(i, on) {
        var li = document.querySelector('#stage-list li[data-stage="' + i + '"]');
        if (li) li.classList.toggle('hl', on);
    }

    // Peg på en etape i listen -> fremhæv linjen på kortet.
    document.querySelectorAll('#stage-list li').forEach(function (li) {
        var i = parseInt(li.getAttribute('data-stage'), 10);
        li.addEventListener('mouseenter', function () {
            if (lines[i]) { lines[i].setStyle({ weight: 8 }); lines[i].bringToFront(); }
        });
        li.addEventListener('mouseleave', function () {
            if (lines[i]) lines[i].setStyle({ weight: 5 });
        });
    });
})();
</script>

</body>
</html>
