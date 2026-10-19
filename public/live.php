<?php
declare(strict_types=1);

// Livesiden: en fast adresse (live.php?id=…), der kan deles, før turen
// starter. Kortet henter data fra live-data.php og opdateres løbende
// (assets/live.js).

session_start();

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/live_view.php';

$pdo = get_db();
$event = live_event_find($pdo, (string) ($_GET['id'] ?? ''));
$key = (string) ($_GET['k'] ?? '');
$sessionUserId = !empty($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;

if ($event === null || !live_can_view($event, $sessionUserId, $key)) {
    http_response_code(404);
    echo '<h1>Livesendingen blev ikke fundet</h1><p>Der findes ingen livesending med det ID.</p>';
    exit;
}

$isOwner = $sessionUserId === (int) $event['user_id'];
$e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

$plannedStart = $event['planned_start_at'] !== null
    ? date('d.m.Y \k\l. H.i', strtotime((string) $event['planned_start_at']))
    : null;

$config = [
    'id'           => $event['public_id'],
    'key'          => $key,
    'pollSeconds'  => LIVE_POLL_SECONDS,
    'tileMaxZoom'  => (int) TILE_MAX_ZOOM,
    'isOwner'      => $isOwner,
    'eventDelay'   => (int) $event['delay_minutes'],
    'plannedStart' => $plannedStart,
];

$palette = ['#e6194b', '#3cb44b', '#4363d8', '#f58231', '#911eb4', '#0891b2', '#d946ef', '#9a6324', '#0f766e', '#7f1d1d'];
?>
<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/svg+xml" href="/grafik/favicon.svg">
    <link rel="icon" type="image/png" sizes="32x32" href="/grafik/favicon-32.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $e($event['title']) ?> – live</title>
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="MitSpor">
    <meta property="og:title" content="<?= $e($event['title']) ?> – live">
    <meta property="og:description" content="Følg turen live på MitSpor.">

    <link rel="stylesheet" href="vendor/leaflet/leaflet.css">
    <link rel="stylesheet" href="assets/style.css?v=28">
    <style>
        html, body { height: 100%; margin: 0; }
        .info-panel { top: 80px; left: 12px; width: 320px; max-width: calc(100vw - 24px); max-height: calc(100dvh - 100px); overflow-y: auto; }
        .info-panel h1 { font-size: 16px; margin: 0 0 4px; }
        .info-panel .meta { color: var(--text-muted); font-size: 12px; margin-bottom: 6px; }
        .info-panel p { margin: 6px 0; font-size: 13px; }
        .topbar-buttons { display: flex; align-items: center; flex-wrap: wrap; justify-content: flex-end; gap: 6px; }
        .live-status { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 600; margin: 2px 0 6px; }
        .live-dot { width: 10px; height: 10px; border-radius: 50%; background: #94a3b8; }
        .live-status[data-status="live"] .live-dot { background: #dc2626; animation: live-pulse 1.6s ease-in-out infinite; }
        .live-status[data-status="pause"] .live-dot { background: #f59e0b; }
        .live-status[data-status="afsluttet"] .live-dot { background: #16a34a; }
        @keyframes live-pulse { 50% { opacity: 0.3; } }
        @media (prefers-reduced-motion: reduce) { .live-status[data-status="live"] .live-dot { animation: none; } }
        .live-error { color: #b91c1c; font-size: 12px; }
        .stage-list { list-style: none; margin: 10px 0 0; padding: 0; border-top: 1px solid var(--border); }
        .stage-list li { display: flex; align-items: center; gap: 10px; padding: 7px 4px; border-bottom: 1px solid var(--border); font-size: 13px; }
        .stage-list a { color: inherit; }
        .stage-num { flex: 0 0 auto; width: 24px; height: 24px; border-radius: 50%; color: #fff; font-size: 12px; font-weight: 700; display: flex; align-items: center; justify-content: center; }
        .stage-list li[data-state="planlagt"] .stage-num { background: #94a3b8 !important; }
        .stage-title { flex: 1 1 auto; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .stage-state { flex: 0 0 auto; color: var(--text-muted); font-size: 12px; }
        .live-marker { width: 18px; height: 18px; border-radius: 50%; background: #dc2626; border: 3px solid #fff; box-shadow: 0 0 0 2px rgba(220, 38, 38, 0.35), 0 1px 4px rgba(0,0,0,.4); box-sizing: border-box; }
        .live-marker.is-old { background: #64748b; box-shadow: 0 1px 4px rgba(0,0,0,.4); }
        .btn[aria-pressed="true"] { background: #2563eb; color: #fff; }
        @media (max-width: 520px) {
            .btn-label { display: none; }
            .map-page .topbar .btn { padding: 6px 12px; min-height: 40px; }
        }
    </style>
</head>
<body>

<div class="map-page">

    <div class="topbar">
        <a href="<?= $isOwner ? '/dashboard.php#live:' . $e($event['public_id']) : '/index.php' ?>" class="brand">&larr; <?= $isOwner ? 'Mit kontrolpanel' : 'MitSpor' ?></a>
        <div class="topbar-buttons">
            <button type="button" class="btn" id="live-follow" aria-pressed="false" title="Hold kortet centreret på den seneste position" hidden><svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="4" fill="none" stroke="currentColor" stroke-width="2.4"/><path d="M12 2v4M12 18v4M2 12h4M18 12h4" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"/></svg><span class="btn-label">Følg</span></button>
        </div>
    </div>

    <div id="map"></div>

    <div class="map-overlay-panel info-panel">
        <h1><?= $e($event['title']) ?></h1>
        <div class="live-status" id="live-status" data-status=""><span class="live-dot" aria-hidden="true"></span><span id="live-status-text">Henter …</span></div>
        <div class="meta" id="live-updated"></div>
        <?php if ($isOwner): ?>
            <div class="meta">Du ser alt uden forsinkelse.<?php if ((int) $event['delay_minutes'] > 0): ?> Andre ser det <?= (int) $event['delay_minutes'] ?> min. senere.<?php endif; ?><?php if ($event['visibility'] !== 'public'): ?> Kun personer med linket kan se siden.<?php endif; ?></div>
        <?php elseif ((int) $event['delay_minutes'] > 0): ?>
            <div class="meta">Vises med <?= (int) $event['delay_minutes'] ?> min. forsinkelse.</div>
        <?php endif; ?>
        <div class="meta"><?= $e((string) $event['owner_name']) ?></div>

        <?php if (!empty($event['description'])): ?>
            <p><?= nl2br($e((string) $event['description'])) ?></p>
        <?php endif; ?>

        <p class="live-error" id="live-error" hidden>Kunne ikke hente nye data. Prøver igen …</p>

        <ol class="stage-list" id="stage-list" hidden></ol>
    </div>
</div>

<script>
window.MITSPOR_LIVE = <?= json_encode($config, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
window.MITSPOR_LIVE.palette = <?= json_encode($palette) ?>;
</script>
<script src="vendor/leaflet/leaflet.js"></script>
<script src="assets/live.js?v=5"></script>

</body>
</html>
