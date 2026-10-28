<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/simple_page.php';
require_once __DIR__ . '/../app/og_image.php';
require_once __DIR__ . '/../app/route_groups.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/auth_dialogs.php';
require_once __DIR__ . '/../app/app_hent.php';
require_once __DIR__ . '/../app/videoer.php';
require_once __DIR__ . '/../app/markers.php';

$publicId = (string) ($_GET['id'] ?? '');

$pdo = get_db();

// En igangværende etape i en livesending: route-view.php?live=<id>&etape=<n>[&k=<nøgle>]
// viser etapens rå livepunkter (live-gpx.php) som et spor. Adgang og
// forsinkelse som på livesiden. Når etapens færdige rute må vises, sendes
// den besøgende videre dertil.
$liveEvent = null;
$liveStage = null;
$liveKey = (string) ($_GET['k'] ?? '');
if (isset($_GET['live'])) {
    require_once __DIR__ . '/../app/live_view.php';
    $liveEvent = live_event_find($pdo, (string) $_GET['live']);
    $liveUserId = !empty($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    if ($liveEvent === null || !live_can_view($liveEvent, $liveUserId, $liveKey)) {
        http_response_code(404);
        simple_message_page('Livesendingen blev ikke fundet', 'Der findes ingen livesending med det ID.');
        exit;
    }
    $liveStage = live_stage_for_view($pdo, $liveEvent, (int) ($_GET['etape'] ?? 0), $liveUserId === (int) $liveEvent['user_id']);
    if ($liveStage === null || !$liveStage['started_visible']) {
        http_response_code(404);
        simple_message_page('Etapen er ikke startet endnu', '<a href="' . htmlspecialchars(live_url($liveEvent), ENT_QUOTES, 'UTF-8') . '">Til livesiden</a>');
        exit;
    }
    if ($liveStage['link'] !== null) {
        header('Location: ' . $liveStage['link']);
        exit;
    }
}

$stmt = $pdo->prepare(
    "SELECT r.id, r.public_id, r.title, r.description, r.visibility, r.status, r.allow_download, r.route_type,
            r.distance_meters, r.elevation_gain_meters, r.elevation_loss_meters, r.hide_uploader_name,
            r.share_token, r.enabled_data_fields, r.enabled_functions,
            r.updated_at, r.gpx_file_path, r.group_id,
            r.user_id, a.name_da AS activity_name, COALESCE(r.marker_icon, a.icon_filename) AS activity_icon, r.marker_icon,
            u.display_name AS uploader_name
     FROM routes r
     JOIN activity_types a ON a.id = r.activity_type_id
     JOIN users u ON u.id = r.user_id
     WHERE r.public_id = :public_id
     LIMIT 1"
);
$stmt->execute(['public_id' => $publicId]);
$route = $stmt->fetch();

if ($liveEvent !== null) {
    $stmt = $pdo->prepare(
        'SELECT a.name_da, a.icon_filename, u.display_name
         FROM activity_types a, users u
         WHERE a.id = :act AND u.id = :uid'
    );
    $stmt->execute(['act' => $liveEvent['activity_type_id'], 'uid' => $liveEvent['user_id']]);
    $liveInfo = $stmt->fetch() ?: ['name_da' => '', 'icon_filename' => null, 'display_name' => ''];
    // En "rute" uden række i routes: kun de felter, siden bruger.
    $route = [
        'id' => 0, 'public_id' => '', 'title' => live_stage_name($liveEvent, $liveStage),
        'description' => $liveEvent['description'], 'visibility' => $liveEvent['visibility'],
        'status' => 'active', 'allow_download' => 0, 'route_type' => 'spor',
        'distance_meters' => 0, 'elevation_gain_meters' => 0, 'elevation_loss_meters' => 0,
        'hide_uploader_name' => 0, 'share_token' => null, 'enabled_data_fields' => null,
        'enabled_functions' => null, 'updated_at' => null, 'gpx_file_path' => '', 'group_id' => null,
        'user_id' => $liveEvent['user_id'], 'activity_name' => $liveInfo['name_da'],
        'activity_icon' => $liveInfo['icon_filename'], 'marker_icon' => null,
        'uploader_name' => $liveInfo['display_name'],
    ];
}

if (!$route) {
    http_response_code(404);
    simple_message_page('Ruten blev ikke fundet', 'Der findes ingen rute med det ID.');
    exit;
}

// Hvilke GPX-datafelter/-funktioner ejeren har valgt skal kunne vises
// (sat via "Visning af datafelter" i kontrolpanelet). NULL = ikke taget stilling
// endnu -> se isFieldEnabled()/isFunctionEnabled() i JS forneden for
// hvad der så gælder som udgangspunkt.
$enabledFields = $route['enabled_data_fields'] !== null
    ? json_decode($route['enabled_data_fields'], true)
    : null;
if (!is_array($enabledFields)) {
    $enabledFields = null;
}

$enabledFunctionsList = $route['enabled_functions'] !== null
    ? json_decode($route['enabled_functions'], true)
    : null;
if (!is_array($enabledFunctionsList)) {
    $enabledFunctionsList = null;
}

if ($route['status'] === 'deleted') {
    http_response_code(410);
    simple_message_page('Ruten er blevet slettet', 'Ejeren har slettet denne rute.');
    exit;
}

if ($route['status'] !== 'active') {
    http_response_code(404);
    simple_message_page('Ruten blev ikke fundet', 'Ruten er ikke tilgængelig.');
    exit;
}

$isOwner = !empty($_SESSION['user_id']) && (int) $_SESSION['user_id'] === (int) $route['user_id'];

// Er besøgende logget ind? (Kontoen kan være slettet, mens sessionen lever.)
// Ikke-loggede-ind besøgende får "Opret konto" og "Log ind" i topbaren.
$loggedIn = !empty($_SESSION['user_id']);
if ($loggedIn && current_user((int) $_SESSION['user_id']) === null) {
    unset($_SESSION['user_id']);
    $loggedIn = false;
}

$shareToken = (string) ($_GET['share'] ?? '');
$hasValidShareToken = $route['share_token'] !== null
    && $shareToken !== ''
    && hash_equals($route['share_token'], $shareToken);

if ($route['visibility'] === 'private' && !$isOwner && !$hasValidShareToken && $liveEvent === null) {
    http_response_code(403);
    simple_message_page('Ingen adgang', 'Denne rute er privat.');
    exit;
}

// Hører ruten til en gruppe (etape-tur)? Så vises forrige/næste etape —
// kun blandt de etaper, den besøgende må se (private springes over).
$groupContext = $route['group_id'] !== null
    ? group_context_for_route($pdo, (int) $route['group_id'], (int) $route['id'], $isOwner)
    : null;

$gpxUrl = 'route-file.php?id=' . urlencode($route['public_id']) . '&mode=view'
    . ($hasValidShareToken ? '&share=' . urlencode($shareToken) : '');
$canDownload = $isOwner || $route['allow_download'];
$liveUrl = null;
if ($liveEvent !== null) {
    $liveQuery = '?id=' . urlencode($liveEvent['public_id']) . ($liveKey !== '' ? '&k=' . urlencode($liveKey) : '');
    $gpxUrl = 'live-gpx.php' . $liveQuery . '&etape=' . $liveStage['position'];
    $liveUrl = '/live.php' . $liveQuery;
    $canDownload = false;
}

// SEO/deling: en kort beskrivelse til meta description + Open Graph.
$seoDescription = trim($route['activity_name'] . ' · ' . number_format($route['distance_meters'] / 1000, 1) . ' km');
if (!empty($route['description'])) {
    $seoDescription .= ' — ' . trim(mb_substr($route['description'], 0, 150));
}
$routeCanonicalUrl = APP_BASE_URL . '/route-view.php?id=' . urlencode($route['public_id']);

// Delingsbillede: offentlige ruter får et automatisk genereret kortbillede
// (public/og-image.php). Private ruter — også via delelink — får det
// generiske MitSpor-billede, så et privat spor aldrig ender som et cachet
// forhåndsvisningsbillede hos Facebook m.fl.
if ($route['visibility'] === 'public' && $liveEvent === null) {
    $ogImageUrl  = APP_BASE_URL . '/og-image.php?id=' . urlencode($route['public_id'])
        . '&v=' . og_route_version($route);
    $ogImageType = 'image/jpeg';
    $ogImageAlt  = 'Kort over ruten "' . $route['title'] . '"';
} else {
    $ogImageUrl  = APP_BASE_URL . '/grafik/mitspor_full.png';
    $ogImageType = 'image/png';
    $ogImageAlt  = 'MitSpor – del dine ture på et kort';
}
?>
<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/svg+xml" href="/grafik/favicon.svg">
    <link rel="icon" type="image/png" sizes="32x32" href="/grafik/favicon-32.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= htmlspecialchars($route['title'], ENT_QUOTES, 'UTF-8') ?></title>

    <meta name="description" content="<?= htmlspecialchars($seoDescription, ENT_QUOTES, 'UTF-8') ?>">
    <?php if ($route['visibility'] === 'private' || $liveEvent !== null): ?>
    <meta name="robots" content="noindex, nofollow">
    <?php else: ?>
    <link rel="canonical" href="<?= htmlspecialchars($routeCanonicalUrl, ENT_QUOTES, 'UTF-8') ?>">
    <?php endif; ?>
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="MitSpor">
    <meta property="og:title" content="<?= htmlspecialchars($route['title'], ENT_QUOTES, 'UTF-8') ?>">
    <meta property="og:description" content="<?= htmlspecialchars($seoDescription, ENT_QUOTES, 'UTF-8') ?>">
    <meta property="og:url" content="<?= htmlspecialchars($routeCanonicalUrl, ENT_QUOTES, 'UTF-8') ?>">
    <meta property="og:image" content="<?= htmlspecialchars($ogImageUrl, ENT_QUOTES, 'UTF-8') ?>">
    <meta property="og:image:type" content="<?= $ogImageType ?>">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="<?= htmlspecialchars($ogImageAlt, ENT_QUOTES, 'UTF-8') ?>">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:image" content="<?= htmlspecialchars($ogImageUrl, ENT_QUOTES, 'UTF-8') ?>">

    <link rel="stylesheet" href="vendor/leaflet/leaflet.css">
    <link rel="stylesheet" href="assets/style.css?v=35">
    <link rel="stylesheet" href="assets/videoer.css?v=1">
    <style>
        html, body { height: 100%; margin: 0; }
        /* Topbar + info-boks: .top-area ligger øverst over kortet, og info-boksen
           hænger 8 px under topbaren og 8 px fra venstre kant — uanset hvor høj
           topbaren er. Boksen er halvgennemsigtig, så kortet anes bagved. */
        .top-area { position: relative; flex: 0 0 auto; z-index: 1000; }
        .map-page .top-area .topbar { position: relative; }
        .map-page .info-panel {
            top: calc(100% + 8px);
            left: 8px;
            max-width: min(320px, calc(100vw - 16px));
            padding: 8px 12px;
            background: rgba(255, 255, 255, 0.85);
            -webkit-backdrop-filter: blur(4px);
            /* Titelrækken står fast øverst; udfoldet scrolles kun resten
               (.info-body). max-height sættes i JS, se applyStatsFolding(). */
            display: flex;
            flex-direction: column;
        }
        .info-panel .info-body { min-height: 0; overflow-y: auto; }
        /* Titel og "vis mere"-knap på én linje. Knappen fylder sin egen plads
           (ligger ikke over teksten), og titlen afkortes med "…", hvis titel +
           knap bliver bredere end boksens maksbredde. */
        .info-title-row { display: flex; align-items: center; gap: 8px; margin: 0 0 4px; }
        .info-panel h1 {
            flex: 0 1 auto;
            min-width: 0;
            font-size: 16px;
            margin: 0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .info-panel .route-description { font-size: 13px; margin: 0 0 8px; }
        .info-panel .route-description-text,
        .info-panel .route-description-short { margin: 0; }
        .info-panel .route-description-short { display: none; }
        /* Beskrivelsen hører til "mere"-delen og vises først ved udfoldning. */
        .info-panel .route-description { display: none; }
        .info-panel.info-expanded .route-description { display: block; }
        .info-panel .meta { color: var(--text-muted); font-size: 12px; margin-bottom: 6px; }
        .info-panel .stats { display: flex; flex-direction: column; gap: 4px; font-size: 13px; }
        .info-panel .stat-extra-field { display: flex; flex-direction: column; }
        .info-panel .stat-current-line { display: block; }
        .info-panel .stat-range-line { display: block; font-size: 0.85em; color: #64748b; }
        .info-panel .stat-extra { display: none; }
        .info-panel .stats.stats-expanded .stat-extra { display: block; }
        #btn-stats-toggle,
        .desc-toggle {
            flex: 0 0 auto;
            margin-left: auto; /* helt ud til højre, også når titlen er kort */
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 22px;
            height: 22px;
            margin-top: 0;
            margin-bottom: 0;
            padding: 0;
            border: 1px solid var(--border);
            border-radius: 5px;
            background: #f1f5f9;
            color: var(--text);
            font-size: 11px;
            line-height: 1;
            text-decoration: none;
        }
        #btn-stats-toggle[hidden],
        .desc-toggle[hidden] { display: none; }
        .stats-toggle-label { display: none; }
        .meta-line { display: block; }
        /* "Uploadet af" på sin egen linje, afkortet med "…" hvis navnet er langt. */
        .meta-uploader { display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .meta-sep-uploader { display: none; }
        /* Knapperne i topbaren ligger i en flex-række på ALLE skærmbredder. Uden den er
           beholderen en almindelig blok med inline-knapper, som linjes op efter tekstens
           grundlinje — og en knap med et ikon forrest (SVG har ingen tekstgrundlinje)
           hamler da ikke op med en ren tekstknap som Hjælp, der så ender for lavt. */
        .topbar-buttons { display: flex; align-items: center; flex-wrap: wrap; justify-content: flex-end; gap: 6px; }
        /* Smalle skærme: "← MitSpor" bliver bare "←" (som på forsiden og i kontrolpanelet på mobil). */
        @media (max-width: 520px) {
            .topbar-guest .brand-name { display: none; }
        }
        .group-nav { margin: 0 0 10px; padding-bottom: 8px; border-bottom: 1px solid var(--border); }
        .group-nav-title { font-size: 12px; color: var(--text-muted); margin-bottom: 5px; }
        .group-nav-title a { font-weight: 600; }
        .group-nav-controls { display: flex; align-items: center; justify-content: space-between; gap: 6px; }
        .group-nav-controls .btn { padding: 4px 9px; font-size: 13px; text-decoration: none; }
        .group-nav-controls .btn[aria-disabled="true"] { opacity: .4; pointer-events: none; }
        .group-nav-pos { font-size: 12px; color: var(--text-muted); white-space: nowrap; }

        /* ---------------------------------------------------------------
           Mobil: topbaren er en bjælke i fuld bredde øverst, og kortet fylder
           resten af skærmen under den. Info-boksen svæver over kortet lige
           under topbaren, som på computer.
        ------------------------------------------------------------------ */
        @media (max-width: 600px) {
            /* Topbar: kun pilen (ikke "Mit kontrolpanel"), og knapperne må
               gerne ombrydes frem for at skubbe noget ud over kanten. */
            .brand-name { display: none; }
            .map-page .topbar {
                position: relative;
                border-bottom: 1px solid var(--border);
                padding: 9px 10px; /* samme højde som forsidens og kontrolpanelets topbar */
                gap: 6px;
            }
            .map-page .topbar .brand { padding: 0; }
            /* Grafer og Download er kun ikoner på mobil, så topbaren holdes på én linje. */
            .btn-label { display: none; }
            .topbar-buttons { flex: 1 1 0; min-width: 0; display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 6px; }
            .map-page .topbar .btn { padding: 6px 12px; font-size: 14px; min-height: 40px; }

            /* Info-boksen er et panel i fuld bredde lige under topbaren (lidt
               lysere end den) og ligger ikke over kortet: kortet fylder resten
               af skærmen og bliver mindre, når panelet foldes ud. */
            .map-page .info-panel {
                position: static;
                max-width: none;
                padding: 6px 12px 8px;
                background: #fafbfc;
                backdrop-filter: none;
                -webkit-backdrop-filter: none;
                border: 0;
                border-bottom: 1px solid var(--border);
                border-radius: 0;
                box-shadow: none;
            }
            /* Udfoldet højst godt halvdelen af skærmen; resten kan scrolles. */
            .map-page .info-panel.info-expanded { max-height: 55vh; max-height: 55dvh; }
            /* Tæt under titlen. */
            .info-title-row { margin: 0; }
            .info-title-row h1 { flex: 1 1 auto; line-height: 1.3; }

            /* Sammenfoldet: titlen, uploaderen og de to afstande; udfoldet
               type · synlighed · uploader på én linje. Resten (inkl. etape-knapperne) hører til "mere"-delen. */
            .info-panel .meta-line {
                display: flex;
                align-items: baseline;
                gap: 5px;
                white-space: nowrap;
                margin: 0 0 3px;
            }
            .meta-type, .meta-vis, .meta-sep, .meta-sep-uploader { flex: 0 0 auto; }
            .meta-sep-uploader { display: inline; }
            /* Type og offentlig/privat vises kun udfoldet; sammenfoldet står
               "Uploadet af" alene (og linjen forsvinder, hvis den ikke vises). */
            .info-panel:not(.info-expanded) :is(.meta-type, .meta-vis, .meta-sep, .meta-sep-uploader) { display: none; }
            .info-panel:not(.info-expanded) .meta-line:not(:has(.meta-uploader)) { display: none; }
            /* Er der ikke plads til hele uploader-navnet, afkortes det med "…". */
            .meta-uploader { flex: 0 1 auto; min-width: 0; }
            /* Beskrivelsen vises altid på mobil: sammenfoldet på én linje med "…",
               udfoldet på to linjer med en lille knap til højre på linje 2, der
               viser hele teksten med linjeskift (knappen står så ud for den
               sidste linje). Den afkortede tekst er uden linjeskift, så en tom
               linje ikke optager en af de to linjer. */
            .info-panel .route-description,
            .info-panel.info-expanded .route-description { display: flex; align-items: flex-end; gap: 8px; margin: 2px 0 4px; }
            .info-panel .route-description-text,
            .info-panel .route-description-short { flex: 1 1 auto; min-width: 0; }
            .info-panel:not(.info-expanded) .route-description-text,
            .info-panel .route-description.desc-short .route-description-text { display: none; }
            .info-panel:not(.info-expanded) .route-description-short,
            .info-panel .route-description.desc-short .route-description-short {
                display: -webkit-box;
                -webkit-box-orient: vertical;
                -webkit-line-clamp: 2;
                line-clamp: 2;
                overflow: hidden;
            }
            /* Sammenfoldet altid én linje (skal vinde over .desc-short ovenfor). */
            .info-panel:not(.info-expanded) .route-description .route-description-short {
                -webkit-line-clamp: 1;
                line-clamp: 1;
            }
            .info-panel:not(.info-expanded) .desc-toggle { display: none; }

            /* Datafelterne i to kolonner (ca. halvdelen af bredden hver):
               afstandene på første linje, de øvrige to og to, når boksen er
               foldet ud. Et felt, der er bredere end kolonnen, ombrydes. */
            .info-panel .stats { display: grid; grid-template-columns: 1fr 1fr; gap: 2px 12px; }
            .info-panel .stats > * { min-width: 0; }
            .info-panel .stats.stats-expanded .stat-extra-field.stat-extra { display: flex; }

            /* Gruppe: "Del af …" og etape-knapperne nederst i det udfoldede panel. */
            .group-nav { order: 2; display: none; margin: 6px 0 0; padding: 6px 0 0; border-top: 1px solid var(--border); border-bottom: 0; }
            .info-panel.info-expanded .group-nav { display: block; }
            .group-nav-controls { gap: 8px; }
            .group-nav-controls .btn { flex: 1 1 0; justify-content: center; text-align: center; }
            .group-nav-pos { flex: 0 0 auto; }
            /* Kun pilene på knapperne (teksten står i aria-label). */
            .group-nav-label { display: none; }

            /* Waypoint-billeder: modalen bruger hele skærmbredden minus 5 px i hver side,
               og billedet har en tynd hvid ramme (8 px) rundt om sig. */
            .wpt-modal { padding: 5px; }
            .wpt-modal-box {
                width: 100%;
                max-width: none;
                max-height: 98vh;
                max-height: 98dvh;
                padding: 8px;
                gap: 4px;
            }
            .wpt-popup-images { border-radius: 6px; }
            .wpt-popup-img-caption,
            .wpt-popup-name,
            .wpt-popup-desc { padding: 0 2px; }
            .wpt-popup-img-caption { margin-top: 2px; }
            /* Luk-knappen ligger inde på selve billedet (ikke oppe i rammen). */
            .wpt-modal-close { top: 14px; right: 14px; }

        }
        /* Mobil: datafelternes to kolonner rykkes op til 16 px ind fra begge
           sider, men kun så meget, at "Afstand fra start: 0.00 km" (ca. 172 px)
           stadig kan stå på én linje: 0 px ved 384 px bredde, 16 px fra 416 px. */
        @media (max-width: 600px) {
            .info-panel .stats { padding: 0 clamp(0px, calc((100vw - 384px) / 2), 16px); }
        }
        /* Igangværende etape i en livesending (route-view.php?live=…). */
        .live-note { display: flex; align-items: center; gap: 6px; margin: 2px 0 6px; font-size: 13px; font-weight: 600; color: #b91c1c; }
        .live-note .live-dot { flex: 0 0 auto; width: 10px; height: 10px; border-radius: 50%; background: #dc2626; animation: live-pulse 1.6s ease-in-out infinite; }
        @keyframes live-pulse { 50% { opacity: 0.3; } }
        @media (prefers-reduced-motion: reduce) { .live-note .live-dot { animation: none; } }
    </style>
</head>
<body>

<div class="map-page">

    <div class="top-area">

    <div class="topbar<?= $loggedIn ? '' : ' topbar-guest' ?>">
        <?php if ($liveUrl !== null): ?>
            <a href="<?= htmlspecialchars($liveUrl, ENT_QUOTES, 'UTF-8') ?>" id="btn-back" class="brand" title="Til livesiden" aria-label="Til livesiden"><span class="back-arrow" aria-hidden="true">&larr;</span><span class="brand-name"> Livesiden</span></a>
        <?php elseif ($isOwner): ?>
            <a href="dashboard.php" id="btn-back" class="brand" title="Tilbage" aria-label="Tilbage"><span class="back-arrow" aria-hidden="true">&larr;</span></a>
        <?php else: ?>
            <a href="index.php" id="btn-back" class="brand" title="Tilbage" aria-label="Tilbage"><span class="back-arrow" aria-hidden="true">&larr;</span><span class="brand-name"> MitSpor</span></a>
        <?php endif; ?>
        <div class="topbar-buttons">
            <button type="button" id="btn-graphs-toggle" class="btn" hidden title="Vis/skjul grafer" aria-label="Grafer"><svg class="tb-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 5-6"/></svg><span class="btn-label">Grafer</span></button>
            <?php if ($canDownload): ?>
                <a class="btn" href="<?= htmlspecialchars(gpx_download_url($route['public_id'], $hasValidShareToken ? $shareToken : ''), ENT_QUOTES, 'UTF-8') ?>" title="Download GPX-fil" aria-label="Download"><svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path d="M12 3v12m0 0l-5-5m5 5l5-5M5 20h14" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round"/></svg><span class="btn-label">Download</span></a>
            <?php endif; ?>
            <?php if ($isOwner && $liveEvent === null): ?>
                <a href="editor/?route=<?= htmlspecialchars($route['public_id'], ENT_QUOTES, 'UTF-8') ?>" class="btn" title="Redigér punkter, billeder og waypoints i editoren" aria-label="Editor"><svg class="tb-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg><span class="btn-label">Editor</span></a>
            <?php endif; ?>
            <?php if (!$loggedIn): ?>
                <button type="button" id="btn-open-register" class="btn" title="Opret konto" aria-label="Opret konto"><svg class="tb-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="9" cy="7" r="4"/><path d="M2 21v-2a4 4 0 0 1 4-4h6a4 4 0 0 1 4 4v2"/><path d="M19 8v6"/><path d="M16 11h6"/></svg><span class="btn-label">Opret konto</span></button>
                <button type="button" id="btn-open-login" class="btn" title="Log ind" aria-label="Log ind"><svg class="tb-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="9" cy="7" r="4"/><path d="M2 21v-2a4 4 0 0 1 4-4h6a4 4 0 0 1 4 4v2"/><path d="M22 11h-6"/><path d="M19 8l-3 3 3 3"/></svg><span class="btn-label">Log ind</span></button>
            <?php endif; ?>
            <button type="button" id="btn-open-route-help" class="btn" title="Hjælp og vejledning" aria-label="Hjælp"><span class="help-icon" aria-hidden="true">?</span><span class="btn-label">Hjælp</span></button>
        </div>
    </div>

    <div class="map-overlay-panel info-panel">
        <?php if ($groupContext !== null): ?>
            <nav class="group-nav" aria-label="Etaper i gruppen">
                <div class="group-nav-title">
                    Del af <a href="group-view.php?id=<?= htmlspecialchars($groupContext['group']['public_id'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($groupContext['group']['title'], ENT_QUOTES, 'UTF-8') ?></a>
                </div>
                <div class="group-nav-controls">
                    <?php if ($groupContext['prev'] !== null): ?>
                        <a class="btn" id="btn-group-prev" href="route-view.php?id=<?= htmlspecialchars($groupContext['prev']['public_id'], ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars($groupContext['prev']['title'], ENT_QUOTES, 'UTF-8') ?>" aria-label="Forrige etape">&#9664;<span class="group-nav-label"> Forrige</span></a>
                    <?php else: ?>
                        <a class="btn" aria-disabled="true" aria-label="Forrige etape">&#9664;<span class="group-nav-label"> Forrige</span></a>
                    <?php endif; ?>
                    <span class="group-nav-pos">Etape <?= (int) $groupContext['index'] ?> af <?= (int) $groupContext['total'] ?></span>
                    <?php if ($groupContext['next'] !== null): ?>
                        <a class="btn" id="btn-group-next" href="route-view.php?id=<?= htmlspecialchars($groupContext['next']['public_id'], ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars($groupContext['next']['title'], ENT_QUOTES, 'UTF-8') ?>" aria-label="Næste etape"><span class="group-nav-label">Næste </span>&#9654;</a>
                    <?php else: ?>
                        <a class="btn" aria-disabled="true" aria-label="Næste etape"><span class="group-nav-label">Næste </span>&#9654;</a>
                    <?php endif; ?>
                </div>
            </nav>
        <?php endif; ?>
        <!-- Titlen med "vis mere"-knappen til højre. Er der ikke plads til begge
             inden for boksens maksbredde, afkortes titlen med "…". -->
        <div class="info-title-row">
            <h1><?= htmlspecialchars($route['title'], ENT_QUOTES, 'UTF-8') ?></h1>
            <button type="button" id="btn-stats-toggle" class="link-btn" hidden><span class="stats-toggle-label"></span><span class="stats-toggle-icon" aria-hidden="true"></span></button>
        </div>
        <div class="info-body">
        <?php if ($liveEvent !== null): ?>
            <p class="live-note"><span class="live-dot" aria-hidden="true"></span> <?= $liveStage['finished'] ? 'Etapen er slut. Det færdige spor vises, når det er uploadet.' : 'Live: etapen er i gang. Siden opdateres hvert minut.' ?></p>
        <?php endif; ?>

        <?php if ($route['description']): ?>
            <div class="route-description">
                <p class="route-description-text"><?= nl2br(htmlspecialchars($route['description'], ENT_QUOTES, 'UTF-8')) ?></p>
                <?php // Kun mobil: den afkortede visning (1–2 linjer), hvor linjeskift og tomme linjer er lagt sammen til mellemrum. ?>
                <p class="route-description-short"><?= htmlspecialchars(trim((string) preg_replace('/\s+/u', ' ', $route['description'])), ENT_QUOTES, 'UTF-8') ?></p>
                <button type="button" class="desc-toggle" hidden><span aria-hidden="true">&#9652;</span></button>
            </div>
        <?php endif; ?>

        <div class="meta meta-line">
            <span class="meta-type"><?= htmlspecialchars($route['activity_name'], ENT_QUOTES, 'UTF-8') ?></span>
            <span class="meta-sep">&middot;</span>
            <span class="meta-vis"><?= $route['visibility'] === 'public' ? 'offentlig' : 'privat' ?></span>
            <?php // Uploaderen vises kun på offentlige ruter (ikke på private/del-link). ?>
            <?php if ($route['visibility'] === 'public' && !$route['hide_uploader_name']): ?>
                <span class="meta-sep-uploader">&middot;</span>
                <span class="meta-uploader">Uploadet af: <?= htmlspecialchars($route['uploader_name'], ENT_QUOTES, 'UTF-8') ?></span>
            <?php endif; ?>
        </div>

        <div class="stats-row">
        <div class="stats" id="route-stats">
            <span id="stat-distance">Afstand ialt: <?= number_format($route['distance_meters'] / 1000, 1) ?>&nbsp;km</span>
            <span id="stat-distance-from-start">Afstand fra start: 0.00&nbsp;km</span>
            <span id="stat-total-time" hidden></span>
            <span id="stat-current-speed" hidden></span>
            <span id="stat-avg-speed" hidden></span>
            <span id="stat-moving-time" hidden></span>
            <span id="stat-avg-moving-speed" hidden></span>
            <span id="stat-min-speed" hidden></span>
            <span id="stat-max-speed" hidden></span>
        </div>
        </div>
        </div><!-- /.info-body -->
    </div>

    </div><!-- /.top-area -->

    <dialog id="route-help-dialog">
        <div class="dialog-header">
            <h2>Hjælp til rutevisning</h2>
            <button type="button" id="route-help-dialog-close" class="close-btn" aria-label="Luk">&times;</button>
        </div>
        <div class="dialog-content">
            <p>På MitSpor kan man finde to slags "ruter":</p>
            <ul>
                <li><strong>Spor</strong>, der er optaget med en GPS-enhed, og som viser tid og hastighed (hvis filen indeholder tidsdata).</li>
                <li><strong>Ruter</strong>, der er plottet ind på et kort — de viser aldrig tid/hastighed, og bruger altid en simpel blå prik som bevægelig markør.</li>
            </ul>
            <p>Knapperne under kortet (<strong>◀◀ ◀ ▶ ▶▶</strong>) lader dig bevæge dig punkt for punkt eller i spring på 1/25 af ruten (højst 25 klik fra start til slut) langs ruten — den blå/farvede markør følger med. Indeholder filen data til det (fx højde, puls, kadence eller watt), og ejeren har slået det til, vises en graf nederst på siden fra start. Du kan skjule og vise den igen med <strong>Grafer</strong>-knappen øverst — findes der data til flere typer, skifter du mellem dem med fanebladene øverst i grafen.</p>
            <p>Pilen øverst til venstre tager dig tilbage til det, du kom fra. <strong>Download</strong> henter rutens GPX-fil, når ejeren har tilladt det.</p>
            <p>Er ruten <strong>offentlig</strong>, kan alle se den; er den <strong>privat</strong>, kræver det enten at du er ejeren, eller at du har et gyldigt del-link.</p>
            <?php if ($isOwner): ?>
                <p><strong>Som ejer</strong> kan du trykke <strong>Editor</strong> for at rette ruten (punkter, waypoints og billeder). Hvad siden viser — højdeprofil, hastighed, puls m.m. — vælger du i kontrolpanelet under <strong>Visning af datafelter</strong>.</p>
            <?php endif; ?>
            <?php if ($route['visibility'] === 'public' && !$route['hide_uploader_name'] && $liveEvent === null): ?>
                <p><a href="uploader-routes.php?route=<?= htmlspecialchars($route['public_id'], ENT_QUOTES, 'UTF-8') ?>">Andre ruter fra denne bruger</a></p>
            <?php endif; ?>
        </div>
    </dialog>

    <div id="map"></div>

    <div id="graphs-panel" hidden>
        <div id="graph-tabs" class="graph-tabs"></div>
        <div id="graph-stats"></div>
        <div id="graph-chart-wrap">
            <svg id="graph-svg" viewBox="0 0 1000 200" preserveAspectRatio="none">
                <g id="graph-gridlines"></g>
                <polygon id="graph-area" points=""></polygon>
                <polyline id="graph-line" points=""></polyline>
                <line id="graph-marker" x1="0" y1="0" x2="0" y2="200" visibility="hidden"></line>
            </svg>
            <div id="graph-gridline-labels"></div>
        </div>
    </div>

    <div class="scrubber" id="scrubber" hidden>
        <button id="btn-start" title="Start"><img src="route-icons/start.png" alt="Start"></button>
        <button id="btn-prev5" title="Spring tilbage (1/25 af ruten)"><img src="route-icons/prev5.png" alt="Spring tilbage"></button>
        <button id="btn-prev" title="Forrige"><img src="route-icons/prev.png" alt="Forrige"></button>
        <button id="btn-next" title="Næste"><img src="route-icons/next.png" alt="Næste"></button>
        <button id="btn-next5" title="Spring frem (1/25 af ruten)"><img src="route-icons/next5.png" alt="Spring frem"></button>
        <button id="btn-end" title="Slut"><img src="route-icons/end.png" alt="Slut"></button>
    </div>

</div>

<div id="wpt-modal" class="wpt-modal" hidden>
    <div class="wpt-modal-backdrop"></div>
    <div class="wpt-modal-box">
        <button type="button" class="wpt-modal-close" aria-label="Luk">&times;</button>
        <div class="wpt-popup-images">
            <button type="button" class="wpt-img-prev" aria-label="Forrige billede">&#8249;</button>
            <img class="wpt-popup-img" src="" alt="">
            <button type="button" class="wpt-img-next" aria-label="Næste billede">&#8250;</button>
            <div class="wpt-img-counter"></div>
        </div>
        <div class="wpt-popup-img-caption"></div>
        <div class="wpt-popup-name"></div>
        <div class="wpt-popup-desc"></div>
    </div>
</div>

<script src="vendor/leaflet/leaflet.js"></script>
<script src="vendor/leaflet-gpx/gpx.js"></script>
<script src="assets/gpx-field-labels.js?v=3"></script>
<script src="assets/activity-marker.js?v=3"></script>
<script>
(function () {
    "use strict";

    var map = L.map('map', { zoomControl: false });
    L.control.zoom({ position: 'bottomright' }).addTo(map);

    // ---------- Igangværende etape (route-view.php?live=…) ----------
    // Siden hentes igen hvert minut. Kortets udsnit, og om graferne er
    // åbne, gemmes først, så visningen ikke springer ved hver opdatering.
    var isLive = <?= json_encode($liveEvent !== null) ?>;
    var liveRefresh = isLive && <?= json_encode($liveEvent !== null && !$liveStage['finished']) ?>;
    var LIVE_VIEW_KEY = 'mitspor-live-visning:' + location.search;
    var liveSaved = null;
    if (isLive) {
        try {
            liveSaved = JSON.parse(sessionStorage.getItem(LIVE_VIEW_KEY));
            sessionStorage.removeItem(LIVE_VIEW_KEY);
        } catch (e) { liveSaved = null; }
    }

    function fitRoute(bounds) {
        if (liveSaved && liveSaved.zoom) {
            map.setView([liveSaved.lat, liveSaved.lng], liveSaved.zoom);
            return;
        }
        map.fitBounds(bounds, { padding: [40, 40], maxZoom: 16 });
    }

    function liveReload() {
        try {
            var c = map.getCenter();
            sessionStorage.setItem(LIVE_VIEW_KEY, JSON.stringify({ lat: c.lat, lng: c.lng, zoom: map.getZoom(), graphs: graphsPanelOpen }));
        } catch (e) { /* uden sessionStorage zoomer kortet bare til sporet igen */ }
        window.MitSporGenindlaes();
    }

    if (liveRefresh) {
        var liveTimer = setInterval(function () {
            if (!document.hidden) liveReload();
        }, 60000);
        // Har fanen været skjult, hentes nye data, så snart den vises igen.
        var liveHiddenAt = 0;
        document.addEventListener('visibilitychange', function () {
            if (document.hidden) {
                liveHiddenAt = Date.now();
            } else if (liveHiddenAt && Date.now() - liveHiddenAt > 60000) {
                clearInterval(liveTimer);
                liveReload();
            }
        });
    }
    // Kortets højde afhænger på mobil af, hvor høj info-bjælken øverst er (og af
    // graf-panelet) — så Leaflet skal have besked, hver gang kortet skifter størrelse.
    if (window.ResizeObserver) {
        new ResizeObserver(function () { map.invalidateSize({ pan: false }); })
            .observe(document.getElementById('map'));
    }
    L.tileLayer('tile.php?z={z}&x={x}&y={y}', {
        attribution: '&copy; OpenStreetMap-bidragydere',
        maxZoom: <?= (int) TILE_MAX_ZOOM ?>
    }).addTo(map);

    // Afspilningsknapperne skal stå 5 px over copyright-linjen nederst på
    // kortet. Linjens højde følger tekststørrelsen (fx større i appens
    // Android-WebView), så den måles og gives videre til CSS'en.
    var attribution = document.querySelector('#map .leaflet-control-attribution');
    if (attribution) {
        var updateAttributionHeight = function () {
            document.querySelector('.map-page').style.setProperty('--attribution-height', attribution.offsetHeight + 'px');
        };
        updateAttributionHeight();
        if (window.ResizeObserver) {
            new ResizeObserver(updateAttributionHeight).observe(attribution);
        }
    }

    // Egne lag med garanteret z-rækkefølge (uafhængigt af i hvilken
    // rækkefølge markørerne tilføjes) — den bevægelige positions-markør
    // skal altid ligge OVER de faste start-/slut-markører, fx når den
    // står lige ved siden af/oven på et af dem.
    map.createPane('routeEndpointPane').style.zIndex = 590;
    map.createPane('routePositionPane').style.zIndex = 620;

    var allPoints = [];
    var polylineRef = null;
    var currentIndex = 0;
    var positionMarker = null; // den bevægelige "nuværende position"-markør ved scrubning
    var isSpor = <?= json_encode($route['route_type'] === 'spor') ?>; // brugervalgt type, ikke afledt af selve filen
    var hasTimeData = false; // om GPX-filen rent faktisk INDEHOLDER tidsstempler
    var hasUsableTimeData = false; // isSpor OG hasTimeData — det der reelt styrer visningen
    // Hvilke datafelter ejeren har valgt skal kunne vises (sat via
    // "Visning af datafelter" i kontrolpanelet) — null = ikke taget stilling
    // endnu, og så gælder standardværdierne fra assets/gpx-field-labels.js.
    var enabledFields = <?= $enabledFields !== null ? json_encode($enabledFields) : 'null' ?>;
    function isFieldEnabled(name) {
        if (enabledFields !== null) return enabledFields.indexOf(name) !== -1;
        return window.gpxFieldDefaultEnabled ? window.gpxFieldDefaultEnabled(name) : true;
    }

    // Hvilke INDBYGGEDE VISNINGS-FUNKTIONER (højdeprofil, tid/hastighed,
    // puls/kadence/watt-grafer, osv.) ejeren har valgt skal være aktive —
    // null = ikke taget stilling endnu, og så er alle muligt aktive.
    // Gamle ruter, der har gemt 'time_speed', udvides til tid + hastighed +
    // gennemsnit (se gpxExpandFunctions() i assets/gpx-field-labels.js).
    var enabledFunctions = <?= $enabledFunctionsList !== null ? json_encode($enabledFunctionsList) : 'null' ?>;
    if (enabledFunctions !== null && window.gpxExpandFunctions) {
        enabledFunctions = window.gpxExpandFunctions(enabledFunctions);
    }
    function isFunctionEnabled(key) {
        if (enabledFunctions !== null) return enabledFunctions.indexOf(key) !== -1;
        // Ikke taget stilling endnu: alt er slået til, undtagen de nye
        // bevægelses-/min-/maks-linjer (GPX_FUNCTIONS_DEFAULT_OFF).
        return window.gpxFunctionDefaultEnabled ? window.gpxFunctionDefaultEnabled(key) : true;
    }

    // De syv tid/hastigheds-funktioner -> deres linje i info-panelet.
    var TIME_STAT_FUNCTIONS = [
        { key: 'time_total',       el: 'stat-total-time' },
        { key: 'speed_current',    el: 'stat-current-speed' },
        { key: 'speed_avg',        el: 'stat-avg-speed' },
        { key: 'time_moving',      el: 'stat-moving-time' },
        { key: 'speed_avg_moving', el: 'stat-avg-moving-speed' },
        { key: 'speed_min',        el: 'stat-min-speed' },
        { key: 'speed_max',        el: 'stat-max-speed' }
    ];

    // Et punkt-til-punkt-stykke regnes som "i bevægelse", når farten er over
    // dette (m/s, ca. 1,8 km/t). Under den tæller det som pause/GPS-støj.
    var MOVING_MIN_SPEED = 0.5;
    // Min/maks-hastighed måles over mindst så mange sekunder, så enkelte
    // GPS-hop ikke giver urealistiske toppe.
    var SPEED_WINDOW_SECONDS = 5;
    var movingStats = null; // kumulative tabeller pr. punkt — se buildMovingStats()

    // ---------- Grafer (højde, puls, kadence, watt) ----------
    // Alle grafer deler samme x-akse (kumuleret afstand) og samme
    // tegne-/gitterlinje-kode — kun hvilken værdi der plottes på
    // y-aksen ændrer sig. Se buildGraphMetricSeries()/renderGraphChart().
    var GRAPH_METRICS = [
        { key: 'elevation_profile', field: 'ele', label: 'Højde', unit: 'm' },
        { key: 'hr_graph', field: 'hr', label: 'Puls', unit: 'bpm' },
        { key: 'cadence_graph', field: 'cad', label: 'Kadence', unit: 'rpm' },
        { key: 'power_graph', field: 'power', label: 'Watt', unit: 'W' }
    ];
    var cumulativeDistances = []; // ét tal pr. punkt i allPoints — delt af alle grafer
    var metricSeriesByKey = {}; // { funktionsnøgle: [{dist, value}, ...] } — kun metrics med reelle data
    var activeGraphMetricKey = null; // hvilken graf-fane er valgt lige nu
    var graphChartMeta = null; // { totalDist, VB_W, VB_H } for DEN AKTIVE graf
    var graphsPanelOpen = false;

    var routeMarkerIcon = L.divIcon({
        className: 'route-marker-icon-wrap',
        html: '<div class="route-marker-icon"></div>',
        iconSize: [10, 10],
        iconAnchor: [5, 5]
    });

    var startMarkerIcon = L.divIcon({
        className: 'route-marker-icon-wrap',
        html: '<div class="route-start-icon"></div>',
        iconSize: [10, 10],
        iconAnchor: [5, 5]
    });

    var endMarkerStaticIcon = L.divIcon({
        className: 'route-marker-icon-wrap',
        html: '<div class="route-end-icon"></div>',
        iconSize: [10, 10],
        iconAnchor: [5, 5]
    });

    // start.png/finish.png bruges som faste start-/slut-markører, når
    // aktiviteten ikke har retningsikoner. routeMarkerIcon (blå) bruges
    // som den bevægelige positions-markør ved scrubning i samme tilfælde;
    // startMarkerIcon/endMarkerStaticIcon (grøn/rød prik) bruges som de
    // faste start-/slut-markører, når aktiviteten HAR retningsikoner
    // (så de ikke konkurrerer visuelt med den bevægelige markør).
    var routeStartIcon = L.icon({
        iconUrl: 'markers/start.png',
        iconSize: [60, 60],
        iconAnchor: [30, 29]
    });
    var routeFinishIcon = L.icon({
        iconUrl: 'markers/finish.png',
        iconSize: [60, 60],
        iconAnchor: [30, 29]
    });

    // Retningsbestemte activity-ikoner (east og west, se marker_image_urls()
    // i app/markers.php). Findes kun hvis ruten eller aktiviteten har en
    // markør i public/markers/ (activity_icon fra PHP) —
    // ellers falder vi tilbage til de simple farvede prikker ovenfor.
    // Ruter og spor viser begge markøren (den valgte eller aktivitetens
    // standard), ligesom "Standard" i metadata-dialogen viser.
    var activityIconUrls = <?= json_encode(marker_image_urls($route['activity_icon'] ?: null)) ?>;
    var activityIconEast = null;
    var activityIconWest = null;
    if (activityIconUrls && window.ActivityMarker) {
        // Ikonerne vises i halv størrelse (120 px-billede -> 60 px, skarpt på retina).
        // Ankeret (ikonets fod) beregnes af ActivityMarker ud fra billedets egen
        // gennemsigtighed, så det passer uanset hvor tæt billederne er beskåret.
        // Indtil billederne er indlæst (og hvis de ikke kan indlæses) vises den
        // blå prik; når de er klar, tegnes den bevægelige markør om med ikonet.
        Promise.all([
            window.ActivityMarker.icon(activityIconUrls.east),
            window.ActivityMarker.icon(activityIconUrls.west, { mirror: activityIconUrls.mirror })
        ]).then(function (icons) {
            activityIconEast = icons[0];
            activityIconWest = icons[1];
            if (allPoints && allPoints.length > 0) updateRoute();
        }).catch(function () { /* behold prikken */ });
    }

    var waypointIcon = L.divIcon({
        className: 'waypoint-dot-wrap',
        html: '<div class="waypoint-dot-icon"></div>',
        iconSize: [16, 16],
        iconAnchor: [8, 8]
    });
    var waypointImgIcon = L.divIcon({
        className: 'waypoint-dot-wrap',
        html: '<div class="waypoint-dot-icon-img"></div>',
        iconSize: [16, 16],
        iconAnchor: [8, 8]
    });

    // ---------- Waypoint-billedmodal ----------
    var wptModalEl, wptModalImg, wptModalCounter, wptModalName, wptModalDesc, wptModalCaption;
    var wptModalPrevBtn, wptModalNextBtn, wptModalCloseBtn, wptModalBackdrop;
    var wptModalLinks = [];
    var wptModalIndex = 0;

    function initWptModal() {
        wptModalEl = document.getElementById('wpt-modal');
        if (!wptModalEl) return;

        wptModalImg = wptModalEl.querySelector('.wpt-popup-img');
        wptModalCounter = wptModalEl.querySelector('.wpt-img-counter');
        wptModalCaption = wptModalEl.querySelector('.wpt-popup-img-caption');
        wptModalName = wptModalEl.querySelector('.wpt-popup-name');
        wptModalDesc = wptModalEl.querySelector('.wpt-popup-desc');
        wptModalPrevBtn = wptModalEl.querySelector('.wpt-img-prev');
        wptModalNextBtn = wptModalEl.querySelector('.wpt-img-next');
        wptModalCloseBtn = wptModalEl.querySelector('.wpt-modal-close');
        wptModalBackdrop = wptModalEl.querySelector('.wpt-modal-backdrop');

        wptModalCloseBtn.addEventListener('click', closeWptModal);
        wptModalBackdrop.addEventListener('click', closeWptModal);
        wptModalPrevBtn.addEventListener('click', function (ev) {
            ev.stopPropagation();
            showWptModalImage(wptModalIndex - 1);
        });
        wptModalNextBtn.addEventListener('click', function (ev) {
            ev.stopPropagation();
            showWptModalImage(wptModalIndex + 1);
        });
        document.addEventListener('keydown', function (ev) {
            if (wptModalEl.hasAttribute('hidden')) return;
            if (ev.key === 'Escape') closeWptModal();
            else if (ev.key === 'ArrowLeft') showWptModalImage(wptModalIndex - 1);
            else if (ev.key === 'ArrowRight') showWptModalImage(wptModalIndex + 1);
        });
    }

    function showWptModalImage(i) {
        wptModalIndex = (i + wptModalLinks.length) % wptModalLinks.length;
        var lnk = wptModalLinks[wptModalIndex];
        wptModalImg.src = lnk.href;
        wptModalImg.alt = lnk.text || '';
        wptModalCaption.textContent = lnk.text || '';
        wptModalCaption.style.display = lnk.text ? '' : 'none';
        if (wptModalLinks.length > 1) {
            wptModalCounter.style.display = '';
            wptModalCounter.textContent = (wptModalIndex + 1) + ' / ' + wptModalLinks.length;
        } else {
            wptModalCounter.style.display = 'none';
        }
    }

    function openWptModal(name, desc, links) {
        if (!wptModalEl || !links.length) return;
        wptModalLinks = links;
        wptModalName.textContent = name || '';
        wptModalName.style.display = name ? '' : 'none';
        wptModalDesc.textContent = desc || '';
        wptModalDesc.style.display = desc ? '' : 'none';
        var multi = links.length > 1;
        wptModalPrevBtn.style.display = multi ? '' : 'none';
        wptModalNextBtn.style.display = multi ? '' : 'none';
        showWptModalImage(0);
        wptModalEl.removeAttribute('hidden');
    }

    function closeWptModal() {
        if (wptModalEl) wptModalEl.setAttribute('hidden', '');
    }

    initWptModal();

    function firstTagText(el, tag) {
        var found = el.getElementsByTagNameNS('*', tag);
        return found.length ? found[0].textContent : '';
    }

    // Kun MitSpors egne billedlinks vises. Serveren har allerede fjernet
    // links til andre brugeres billeder (app/image_links.php); eksterne
    // adresser (fx http://www.garmin.com) hentes aldrig, så en GPX-fil ikke
    // kan få besøgendes browsere til at kontakte fremmede servere.
    var IMAGE_LINK_PATTERN = new RegExp('^(?:/|\\.\\./)?' + <?= json_encode(preg_quote(IMAGE_PUBLIC_URL_BASE, '/')) ?>
        + '/\\d+/[0-9a-f]{32}(?:-thumb)?\\.(?:jpe?g|png|gif|webp)$', 'i');

    function getWaypointLinks(wptEl) {
        var linkEls = wptEl.getElementsByTagNameNS('*', 'link');
        var links = [];
        for (var i = 0; i < linkEls.length; i++) {
            var href = (linkEls[i].getAttribute('href') || '').trim();
            if (!IMAGE_LINK_PATTERN.test(href)) continue;
            links.push({
                href: href,
                text: firstTagText(linkEls[i], 'text'),
                type: firstTagText(linkEls[i], 'type')
            });
        }
        return links;
    }

    // ---------- Indlæs GPX ----------
    // BEMÆRK: leaflet-gpx v1.7.0 pakker kun flere lag ind i en
    // FeatureGroup — har en rute kun ÉT lag (fx en ren linje uden
    // waypoints, som her hvor start-/slut-ikoner er slået fra),
    // returneres laget UPAKKET. Denne funktion finder derfor alle
    // polylinjer uanset om de er pakket ind eller ej, i vilkårlig dybde.
    function collectPolylines(layer, found) {
        if (layer instanceof L.Polyline) {
            found.push(layer);
        } else if (layer && typeof layer.eachLayer === 'function') {
            layer.eachLayer(function (subLayer) {
                collectPolylines(subLayer, found);
            });
        }
    }

    fetch(<?= json_encode($gpxUrl) ?>)
        .then(function (res) { return res.text(); })
        .then(function (gpxText) {
            // Afgør selv om ruten har ÆGTE tidsdata, ved at kigge i den
            // rå GPX-tekst — leaflet-gpx v1.7.0 sætter internt en
            // vilkårlig standardtid (1970-01-01) på ethvert punkt der
            // MANGLER et <time>-tag, så dets egen meta.time er altid
            // "sand" og kan derfor ikke bruges til at afgøre dette.
            var xmlDoc = new DOMParser().parseFromString(gpxText, 'text/xml');
            hasTimeData = xmlDoc.getElementsByTagNameNS('*', 'time').length > 0;
            // Spor OG faktisk tidsdata — en rute (selv med tidsstempler i
            // filen) skal ikke vise tid/hastighed eller retningsikoner.
            hasUsableTimeData = isSpor && hasTimeData;
            buildExtraFieldSeries(xmlDoc);

            var gpx = new L.GPX(gpxText, {
                async: true,
                marker_options: { startIconUrl: null, endIconUrl: null, shadowUrl: null }
            }).on('error', function (e) {
                console.log('[DEBUG] leaflet-gpx error-event:', e.err);
            }).on('addpoint', function (e) {
                if (e.point_type === 'waypoint') {
                    var links = getWaypointLinks(e.element);
                    var name = firstTagText(e.element, 'name');
                    var desc = firstTagText(e.element, 'desc');

                    if (links.length > 0) {
                        e.point.setIcon(waypointImgIcon);
                        e.point.unbindPopup();
                        e.point.on('click', function () {
                            openWptModal(name, desc, links);
                        });
                    } else {
                        e.point.setIcon(waypointIcon);
                    }
                    e.point.closePopup();
                }
            }).on('loaded', function (e) {
                var gpxLayer = e.target;

                var polylines = [];
                gpxLayer.eachLayer(function (layer) {
                    collectPolylines(layer, polylines);
                });

                // Alle spor/segmenter i filen lægges efter hinanden i allPoints.
                // Første punkt i hvert nyt spor får segStart, så afstand og
                // grafer ikke tæller springet mellem sporene med (som
                // gpx_extract_points() på serveren). Linjerne tegnes stadig
                // hver for sig, så der ikke kommer en streg mellem dem.
                if (polylines.length > 0) {
                    allPoints = [];
                    polylines.forEach(function (line, n) {
                        var latlngs = line.getLatLngs();
                        if (Array.isArray(latlngs[0])) latlngs = latlngs.flat();
                        latlngs.forEach(function (ll, i) {
                            ll.meta = ll.meta || {};
                            ll.segStart = n > 0 && i === 0;
                            allPoints.push(ll);
                        });
                    });

                    polylineRef = polylines[0];
                    currentIndex = allPoints.length;
                }

                if (allPoints.length > 0 && polylineRef) {
                    var bounds = L.latLngBounds(allPoints.map(function (p) { return L.latLng(p.lat, p.lng); }));
                    fitRoute(bounds);

                    // Ruten tegnes altid fuldt ud, fra start til slut —
                    // det er kun den bevægelige positions-markør (herunder),
                    // der ændrer sig ved scrubning, ikke selve linjen.
                    // Med flere spor tegner hvert spor allerede sig selv.
                    if (polylines.length === 1) {
                        polylineRef.setLatLngs(allPoints.map(function (p) { return L.latLng(p.lat, p.lng); }));
                    }

                    buildGraphMetricSeries();
                    if (Object.keys(metricSeriesByKey).length > 0) {
                        document.getElementById('btn-graphs-toggle').hidden = false;

                        // Er der en graf at vise (en graf-funktion er slået til),
                        // åbnes grafpanelet fra start. Kortet får lidt mindre plads,
                        // så ruten tilpasses igen, når panelet er på plads.
                        if (!graphsPanelOpen && !(liveSaved && liveSaved.graphs === false)) {
                            toggleGraphsPanel();
                            setTimeout(function () {
                                map.invalidateSize();
                                fitRoute(bounds);
                            }, 200);
                        }
                    }

                    if (hasUsableTimeData) {
                        buildMovingStats();
                        TIME_STAT_FUNCTIONS.forEach(function (f) {
                            document.getElementById(f.el).hidden = !isFunctionEnabled(f.key);
                        });
                    }

                    renderExtraFieldStats();
                    applyStatsFolding();

                    // Frem/tilbage-knapperne vises altid, for både Rute og Spor.
                    document.getElementById('scrubber').hidden = false;

                    // Faste start-/slut-markører — altid grøn/rød prik, på
                    // alle ruter og spor, uanset aktivitetstype.
                    var startPoint = allPoints[0];
                    var endPoint = allPoints[allPoints.length - 1];

                    L.marker([startPoint.lat, startPoint.lng], {
                        icon: startMarkerIcon,
                        pane: 'routeEndpointPane'
                    }).addTo(map);
                    L.marker([endPoint.lat, endPoint.lng], {
                        icon: endMarkerStaticIcon,
                        pane: 'routeEndpointPane'
                    }).addTo(map);

                    // Markøren starter ved begyndelsen af ruten, ikke ved
                    // slutningen — man scrubber sig FREM gennem den.
                    // Under en livesending står den ved den seneste position.
                    currentIndex = isLive ? allPoints.length : 1;
                    updateRoute();

                    // En livesending har ingen gemt afstand: regn den ud af punkterne.
                    if (isLive) {
                        var liveDist = 0;
                        for (var di = 1; di < allPoints.length; di++) liveDist += stepDistance(di);
                        document.getElementById('stat-distance').textContent =
                            'Afstand ialt: ' + (liveDist / 1000).toFixed(1) + '\u00A0km';
                    }
                } else {
                    map.setView([56.0, 10.0], 6);
                }
            }).addTo(map);
        })
        .catch(function () {
            map.setView([56.0, 10.0], 6);
        });

    // ---------- Afstand & stats ----------
    // Afstand fra punkt i-1 til punkt i; 0 ved starten af et nyt spor.
    function stepDistance(i) {
        return allPoints[i].segStart ? 0 : distance(allPoints[i - 1], allPoints[i]);
    }

    function distance(p1, p2) {
        var lat1 = p1.lat, lon1 = p1.lng;
        var lat2 = p2.lat, lon2 = p2.lng;
        if (lat1 == null || lon1 == null || lat2 == null || lon2 == null) return 0;

        var R = 6371000;
        var phi1 = lat1 * Math.PI / 180;
        var phi2 = lat2 * Math.PI / 180;
        var dPhi = (lat2 - lat1) * Math.PI / 180;
        var dLambda = (lon2 - lon1) * Math.PI / 180;

        var a = Math.sin(dPhi / 2) ** 2 + Math.cos(phi1) * Math.cos(phi2) * Math.sin(dLambda / 2) ** 2;
        return 2 * R * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    }

    // ---------- Højdeprofil ----------
    function buildGraphMetricSeries() {
        metricSeriesByKey = {};
        if (!allPoints || allPoints.length < 2) return;

        cumulativeDistances = [];
        var cumDist = 0;
        for (var i = 0; i < allPoints.length; i++) {
            if (i > 0) cumDist += stepDistance(i);
            cumulativeDistances.push(cumDist);
        }

        GRAPH_METRICS.forEach(function (metric) {
            var raw = extraFieldSeries[metric.field];
            if (!raw) return;

            // segStart: første punkt med en værdi i et nyt spor, så "Op/Ned"
            // ikke tæller springet mellem to spor med.
            var points = [];
            var newSeg = false;
            for (var i = 0; i < allPoints.length; i++) {
                if (allPoints[i].segStart) newSeg = true;
                if (typeof raw[i] === 'number' && !isNaN(raw[i])) {
                    points.push({ dist: cumulativeDistances[i], value: raw[i], segStart: newSeg && points.length > 0 });
                    newSeg = false;
                }
            }
            if (points.length >= 2) metricSeriesByKey[metric.key] = points;
        });
    }

    // ---------- Rå datafelter (puls, kadence, watt, m.fl.) ----------
    // Felter der ikke (endnu) har en dedikeret visning som højdeprofilen
    // vises i stedet med den AKTUELLE værdi ved scrubber-positionen
    // (opdateres i updateRoute(), ligesom hastighed) plus en statisk
    // min-maks-linje. Kun for felter, ejeren har slået til
    // (isFieldEnabled), og aldrig for 'time'/'ele', som allerede har
    // deres egen visning ovenfor.
    var extraFieldSeries = {}; // { feltnavn: [værdi_eller_undefined, ...] } — samme indeksering som allPoints
    var extraFieldCurrentEls = {}; // { feltnavn: <span> } — til hurtig opdatering ved scrub

    function buildExtraFieldSeries(xmlDoc) {
        extraFieldSeries = {};
        var trkpts = xmlDoc.getElementsByTagNameNS('*', 'trkpt');
        // Felter med en dedikeret graf-funktion styres af isFunctionEnabled()
        // i stedet for isFieldEnabled() — se GRAPH_METRICS ovenfor.
        var functionFieldMap = {};
        GRAPH_METRICS.forEach(function (m) { functionFieldMap[m.field] = m.key; });

        for (var i = 0; i < trkpts.length; i++) {
            var children = trkpts[i].getElementsByTagName('*');
            for (var j = 0; j < children.length; j++) {
                var el = children[j];
                var name = (el.localName || el.tagName || '').toLowerCase();
                if (!name || name === 'time' || name === 'extensions') continue;
                if (el.children && el.children.length > 0) continue; // container-element, ikke selve feltet

                var functionKey = functionFieldMap[name];
                var enabled = functionKey ? isFunctionEnabled(functionKey) : isFieldEnabled(name);
                if (!enabled) continue;

                var value = parseFloat(el.textContent);
                if (isNaN(value)) continue;

                if (!extraFieldSeries[name]) extraFieldSeries[name] = [];
                extraFieldSeries[name][i] = value;
            }
        }
    }

    function renderExtraFieldStats() {
        var statsEl = document.getElementById('route-stats');
        extraFieldCurrentEls = {};

        Object.keys(extraFieldSeries).sort().forEach(function (name) {
            var values = extraFieldSeries[name].filter(function (v) { return typeof v === 'number' && !isNaN(v); });
            if (values.length === 0) return;

            var min = Math.min.apply(null, values);
            var max = Math.max.apply(null, values);
            var label = window.gpxFieldLabel ? window.gpxFieldLabel(name) : name;

            var wrapper = document.createElement('span');
            wrapper.className = 'stat-extra-field';

            var currentSpan = document.createElement('span');
            currentSpan.className = 'stat-current-line';
            currentSpan.textContent = label + ': –';
            wrapper.appendChild(currentSpan);

            var rangeSpan = document.createElement('span');
            rangeSpan.className = 'stat-range-line';
            rangeSpan.textContent = '(min ' + round1(min) + ' – maks ' + round1(max) + ')';
            wrapper.appendChild(rangeSpan);

            statsEl.appendChild(wrapper);
            extraFieldCurrentEls[name] = currentSpan;
        });
    }

    // Kaldes fra updateRoute() ved hver scrub, med samme punkt-indeks
    // som resten af scrubberen bruger.
    function updateExtraFieldCurrentValues(pointIndex) {
        Object.keys(extraFieldCurrentEls).forEach(function (name) {
            var series = extraFieldSeries[name];
            var value = series ? series[pointIndex] : undefined;
            var label = window.gpxFieldLabel ? window.gpxFieldLabel(name) : name;
            extraFieldCurrentEls[name].textContent = label + ': ' + (typeof value === 'number' ? round1(value) : '–');
        });
    }

    function round1(n) {
        return Math.round(n * 10) / 10;
    }

    // Sammenfoldet viser boksen titel, type/synlighed, uploader og de to
    // afstande. Resten (beskrivelse, øvrige felter) foldes ud via det lille
    // ▾-ikon til højre for titlen. Udfoldet vokser boksen med indholdet, men
    // aldrig ud af skærmen — er der mere, kan den scrolles.
    // Mobil: panelet under topbaren viser det samme sammenfoldet, men
    // aktivitet, offentlig/privat og etape-knapperne hører altid til
    // "mere"-delen (se CSS), så knappen vises altid dér.
    function applyStatsFolding() {
        var STATS_VISIBLE_BY_DEFAULT = 2; // afstand ialt + afstand fra start
        var statsEl = document.getElementById('route-stats');
        var toggleBtn = document.getElementById('btn-stats-toggle');
        var labelEl = toggleBtn.querySelector('.stats-toggle-label');
        var iconEl = toggleBtn.querySelector('.stats-toggle-icon');
        var panelEl = document.querySelector('.info-panel');
        var bodyEl = panelEl.querySelector('.info-body');
        var mobileMq = window.matchMedia('(max-width: 600px)');
        // Beskrivelsen er altid skjult som standard.
        var hasDescription = !!panelEl.querySelector('.route-description');
        var visibleItems = Array.prototype.filter.call(statsEl.children, function (el) { return !el.hidden; });
        var hasExtraStats = visibleItems.length > STATS_VISIBLE_BY_DEFAULT;

        if (hasExtraStats) {
            visibleItems.slice(STATS_VISIBLE_BY_DEFAULT).forEach(function (el) { el.classList.add('stat-extra'); });
        }

        // Toggle-knappen skal kun vises, når der reelt er noget mere at vise.
        function refreshToggleVisibility() {
            toggleBtn.hidden = !(hasExtraStats || hasDescription || mobileMq.matches);
        }

        function setExpanded(expanded) {
            var text = expanded ? 'Vis mindre' : 'Vis mere';
            labelEl.textContent = text;
            iconEl.textContent = expanded ? '\u25B4' : '\u25BE'; // ▴ / ▾
            toggleBtn.setAttribute('aria-label', text);
            toggleBtn.setAttribute('title', text);
            toggleBtn.setAttribute('aria-expanded', expanded ? 'true' : 'false');

            if (expanded) {
                panelEl.classList.add('info-expanded');
                statsEl.classList.add('stats-expanded');
            } else {
                panelEl.classList.remove('info-expanded');
                statsEl.classList.remove('stats-expanded');
                bodyEl.scrollTop = 0;
            }
            fitPanelHeight();
        }

        // Computer/tablet: boksen er så høj, som indholdet kræver, men vokser
        // ikke ud af skærmen (8 px luft forneden); er der mere, kan den
        // scrolles. Mobil: højden styres af CSS (højst godt halvdelen af skærmen).
        function fitPanelHeight() {
            if (mobileMq.matches) {
                panelEl.style.maxHeight = '';
                return;
            }
            var room = window.innerHeight - panelEl.getBoundingClientRect().top - 8;
            panelEl.style.maxHeight = Math.max(80, Math.round(room)) + 'px';
        }

        // Mobil: udfoldet vises beskrivelsen på to linjer; knappen til at vise
        // hele teksten vises kun, når der er mere: teksten fylder over to
        // linjer, eller den har linjeskift (som den afkortede tekst ikke viser).
        var descEl = panelEl.querySelector('.route-description');
        var descTextEl = descEl ? descEl.querySelector('.route-description-short') : null;
        var descHasBreaks = descEl ? !!descEl.querySelector('.route-description-text br') : false;
        var descToggle = descEl ? descEl.querySelector('.desc-toggle') : null;
        function setDescShort(isShort) {
            descEl.classList.toggle('desc-short', isShort);
            var text = isShort ? 'Vis hele beskrivelsen' : 'Vis kun to linjer';
            descToggle.firstChild.textContent = isShort ? '\u25BE' : '\u25B4'; // ▾ / ▴
            descToggle.setAttribute('aria-label', text);
            descToggle.setAttribute('title', text);
            descToggle.setAttribute('aria-expanded', isShort ? 'false' : 'true');
        }
        function refreshDescToggle() {
            if (!descEl) return;
            if (!mobileMq.matches) {
                descToggle.hidden = true;
                return;
            }
            // Mål med to linjer, uanset om panelet er foldet ud eller ej.
            var saved = descTextEl.style.cssText;
            descTextEl.style.cssText = 'display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:2;overflow:hidden';
            var overflows = descTextEl.scrollHeight > descTextEl.clientHeight + 1;
            descTextEl.style.cssText = saved;
            descToggle.hidden = !(overflows || descHasBreaks);
        }
        if (descEl) {
            setDescShort(true);
            descToggle.addEventListener('click', function () {
                setDescShort(!descEl.classList.contains('desc-short'));
            });
        }

        // Skift mellem mobil og bred skærm (fx når telefonen vendes): fold
        // sammen, så højden ikke er beregnet til det forrige layout.
        function onLayoutChange() {
            setExpanded(false);
            refreshToggleVisibility();
            refreshDescToggle();
        }

        setExpanded(false);
        refreshToggleVisibility();
        refreshDescToggle();
        window.addEventListener('resize', function () {
            fitPanelHeight();
            refreshDescToggle();
        });
        if (mobileMq.addEventListener) mobileMq.addEventListener('change', onLayoutChange);
        else if (mobileMq.addListener) mobileMq.addListener(onLayoutChange);

        toggleBtn.addEventListener('click', function () {
            setExpanded(!statsEl.classList.contains('stats-expanded'));
        });
    }

    // Finder "pæne", runde højdeværdier til de vandrette gitterlinjer
    // (fx 20/40/60/80 m i stedet for 17/34/51/68 m) — samme princip som
    // de fleste graf-biblioteker bruger til akse-inddelinger.
    function computeNiceGridlineValues(minEle, maxEle, targetCount) {
        var range = maxEle - minEle;
        if (range <= 0) return [Math.round(minEle)];

        var rawStep = range / targetCount;
        var magnitude = Math.pow(10, Math.floor(Math.log(rawStep) / Math.LN10));
        var residual = rawStep / magnitude;
        var niceResidual;
        if (residual > 5) niceResidual = 10;
        else if (residual > 2) niceResidual = 5;
        else if (residual > 1) niceResidual = 2;
        else niceResidual = 1;
        var step = niceResidual * magnitude;

        var values = [];
        for (var v = Math.ceil(minEle / step) * step; v <= maxEle; v += step) {
            values.push(Math.round(v));
        }
        return values;
    }

    function renderGraphChart(key) {
        var metric = GRAPH_METRICS.filter(function (m) { return m.key === key; })[0];
        var series = metricSeriesByKey[key];
        if (!metric || !series || series.length < 2) return;

        var minVal = Math.min.apply(null, series.map(function (p) { return p.value; }));
        var maxVal = Math.max.apply(null, series.map(function (p) { return p.value; }));
        var totalDist = series[series.length - 1].dist;

        var gain = 0, loss = 0;
        for (var i = 1; i < series.length; i++) {
            if (series[i].segStart) continue;
            var diff = series[i].value - series[i - 1].value;
            if (diff > 0) gain += diff; else loss += -diff;
        }

        var VB_W = 1000, VB_H = 200, PAD = 6;
        var valRange = Math.max(maxVal - minVal, 1); // undgå division med 0 ved en helt flad serie

        function xForDist(d) { return totalDist > 0 ? (d / totalDist) * VB_W : 0; }
        function yForVal(v) { return VB_H - PAD - ((v - minVal) / valRange) * (VB_H - 2 * PAD); }

        var linePoints = series.map(function (p) { return xForDist(p.dist) + ',' + yForVal(p.value); }).join(' ');
        var areaPoints = linePoints + ' ' + VB_W + ',' + VB_H + ' 0,' + VB_H;

        document.getElementById('graph-area').setAttribute('points', areaPoints);
        document.getElementById('graph-line').setAttribute('points', linePoints);

        // Vandrette gitterlinjer (4-5 stk) med tal ved siden af. Selve
        // linjerne tegnes i SVG'en (skalerer fint, uanset at
        // preserveAspectRatio="none" strækker den ujævnt) — tallene
        // derimod lægges som almindelige HTML-elementer ovenpå, positioneret
        // med CSS-procent, så de IKKE bliver forvrænget af samme stræk.
        var gridlinesGroup = document.getElementById('graph-gridlines');
        var labelsContainer = document.getElementById('graph-gridline-labels');
        gridlinesGroup.innerHTML = '';
        labelsContainer.innerHTML = '';

        var gridlineValues = computeNiceGridlineValues(minVal, maxVal, 4);
        gridlineValues.forEach(function (value) {
            var y = yForVal(value);

            var lineEl = document.createElementNS('http://www.w3.org/2000/svg', 'line');
            lineEl.setAttribute('x1', 0);
            lineEl.setAttribute('x2', VB_W);
            lineEl.setAttribute('y1', y);
            lineEl.setAttribute('y2', y);
            gridlinesGroup.appendChild(lineEl);

            var label = document.createElement('span');
            label.style.top = (y / VB_H * 100) + '%';
            label.textContent = value + (metric.unit ? ' ' + metric.unit : '');
            labelsContainer.appendChild(label);
        });

        if (key === 'elevation_profile') {
            // "Op/ned" giver kun mening for højde.
            document.getElementById('graph-stats').textContent =
                'Min: ' + Math.round(minVal) + ' m  ·  Maks: ' + Math.round(maxVal) + ' m  ·  Op: +' +
                Math.round(gain) + ' m  ·  Ned: -' + Math.round(loss) + ' m';
        } else {
            var avg = series.reduce(function (sum, p) { return sum + p.value; }, 0) / series.length;
            document.getElementById('graph-stats').textContent =
                'Min: ' + round1(minVal) + (metric.unit ? ' ' + metric.unit : '') +
                '  ·  Gennemsnit: ' + round1(avg) + (metric.unit ? ' ' + metric.unit : '') +
                '  ·  Maks: ' + round1(maxVal) + (metric.unit ? ' ' + metric.unit : '');
        }

        graphChartMeta = { totalDist: totalDist, VB_W: VB_W, VB_H: VB_H };
    }

    function renderGraphTabs() {
        var availableMetrics = GRAPH_METRICS.filter(function (m) { return metricSeriesByKey[m.key]; });
        var tabsEl = document.getElementById('graph-tabs');

        if (availableMetrics.length <= 1) {
            tabsEl.hidden = true;
            tabsEl.innerHTML = '';
            return;
        }

        tabsEl.hidden = false;
        tabsEl.innerHTML = availableMetrics.map(function (m) {
            return '<button type="button" class="graph-tab" data-metric="' + m.key + '">' + m.label + '</button>';
        }).join('');

        Array.prototype.forEach.call(tabsEl.querySelectorAll('.graph-tab'), function (btn) {
            btn.addEventListener('click', function () { selectGraphMetric(btn.getAttribute('data-metric')); });
        });
    }

    function selectGraphMetric(key) {
        activeGraphMetricKey = key;
        Array.prototype.forEach.call(document.querySelectorAll('.graph-tab'), function (btn) {
            btn.classList.toggle('active', btn.getAttribute('data-metric') === key);
        });
        renderGraphChart(key);
        updateGraphMarker(currentIndex - 1);
    }

    // Bruger den DELTE afstands-liste (cumulativeDistances), ikke den
    // (evt. filtrerede) graf-serie — så markøren altid rammer rigtigt,
    // uanset om nogle punkter mangler netop DEN aktive metric.
    function updateGraphMarker(index) {
        if (!graphsPanelOpen || !activeGraphMetricKey || !graphChartMeta || !cumulativeDistances.length) return;

        var idx = Math.max(0, Math.min(index, cumulativeDistances.length - 1));
        var dist = cumulativeDistances[idx];

        var x = graphChartMeta.totalDist > 0
            ? (dist / graphChartMeta.totalDist) * graphChartMeta.VB_W
            : 0;

        var markerLine = document.getElementById('graph-marker');
        markerLine.setAttribute('x1', x);
        markerLine.setAttribute('x2', x);
        markerLine.setAttribute('y1', 0);
        markerLine.setAttribute('y2', graphChartMeta.VB_H);
        markerLine.setAttribute('visibility', 'visible');
    }

    function toggleGraphsPanel() {
        graphsPanelOpen = !graphsPanelOpen;

        document.getElementById('graphs-panel').hidden = !graphsPanelOpen;
        document.querySelector('.map-page').classList.toggle('graphs-open', graphsPanelOpen);
        document.getElementById('btn-graphs-toggle').setAttribute('aria-pressed', graphsPanelOpen ? 'true' : 'false');

        // Kortets container skifter størrelse (CSS-transition), så Leaflet
        // skal have besked om det, når overgangen er færdig.
        setTimeout(function () { map.invalidateSize(); }, 160);

        if (graphsPanelOpen) {
            renderGraphTabs();
            if (!activeGraphMetricKey || !metricSeriesByKey[activeGraphMetricKey]) {
                var firstAvailable = GRAPH_METRICS.filter(function (m) { return metricSeriesByKey[m.key]; })[0];
                activeGraphMetricKey = firstAvailable ? firstAvailable.key : null;
            }
            if (activeGraphMetricKey) {
                Array.prototype.forEach.call(document.querySelectorAll('.graph-tab'), function (btn) {
                    btn.classList.toggle('active', btn.getAttribute('data-metric') === activeGraphMetricKey);
                });
                renderGraphChart(activeGraphMetricKey);
                updateGraphMarker(currentIndex - 1);
            }
        }
    }

    function calculateStats(endIndex) {
        if (!allPoints || allPoints.length < 2) return null;

        var totalDistance = 0;
        var startTime = null, endTime = null, currentSpeed = 0;

        for (var i = 1; i < endIndex; i++) {
            var p1 = allPoints[i - 1], p2 = allPoints[i];
            var d = stepDistance(i);
            totalDistance += d;

            if (p1.meta && p1.meta.time && p2.meta && p2.meta.time) {
                var t1 = new Date(p1.meta.time), t2 = new Date(p2.meta.time);
                var dt = (t2 - t1) / 1000;
                if (dt > 0) {
                    if (!startTime) startTime = t1;
                    endTime = t2;
                    if (i === endIndex - 1) currentSpeed = d / dt;
                }
            }
        }

        var totalTime = startTime && endTime ? (endTime - startTime) / 1000 : 0;

        return {
            distance: totalDistance,
            time: totalTime,
            avspeed: totalTime > 0 ? totalDistance / totalTime : 0,
            cur_speed: currentSpeed
        };
    }

    // Forudberegner (én gang) kumulative tabeller til "i bevægelse"-linjerne,
    // så scrubberen bare slår op i stedet for at løbe ruten igennem hver gang.
    // Alle tabeller er indekseret som allPoints og gælder "fra start til
    // dette punkt" — ligesom Tid og Gennemsnit.
    //   timeCum / distCum : tid (s) og afstand (m) tilbagelagt i bevægelse
    //   minCum / maxCum   : laveste (kun i bevægelse) / højeste hastighed
    //                       (m/s) målt over mindst SPEED_WINDOW_SECONDS
    function buildMovingStats() {
        movingStats = null;
        var n = allPoints.length;
        if (n < 2 || cumulativeDistances.length !== n) return;

        var times = allPoints.map(function (p) {
            var t = p.meta && p.meta.time ? new Date(p.meta.time).getTime() : NaN;
            return isNaN(t) ? null : t / 1000;
        });

        var timeCum = new Array(n), distCum = new Array(n), minCum = new Array(n), maxCum = new Array(n);
        timeCum[0] = 0; distCum[0] = 0; minCum[0] = null; maxCum[0] = null;

        var lastValid = times[0] !== null ? 0 : -1; // sidste punkt med tidsstempel
        var windowStart = 0;

        for (var i = 1; i < n; i++) {
            timeCum[i] = timeCum[i - 1];
            distCum[i] = distCum[i - 1];
            minCum[i] = minCum[i - 1];
            maxCum[i] = maxCum[i - 1];

            if (times[i] === null) continue;

            // Stykke fra forrige punkt med tidsstempel -> tid i bevægelse
            if (lastValid >= 0) {
                var dt = times[i] - times[lastValid];
                var d = cumulativeDistances[i] - cumulativeDistances[lastValid];
                if (dt > 0 && d / dt >= MOVING_MIN_SPEED) {
                    timeCum[i] += dt;
                    distCum[i] += d;
                }
            }
            lastValid = i;

            // Vindue på mindst SPEED_WINDOW_SECONDS bagud -> udglattet hastighed
            while (windowStart < i - 1 && times[windowStart] === null) windowStart++;
            while (windowStart < i - 1 &&
                   times[windowStart + 1] !== null &&
                   times[i] - times[windowStart + 1] >= SPEED_WINDOW_SECONDS) {
                windowStart++;
            }
            if (times[windowStart] !== null && windowStart < i) {
                var wdt = times[i] - times[windowStart];
                if (wdt >= SPEED_WINDOW_SECONDS) {
                    var wspeed = (cumulativeDistances[i] - cumulativeDistances[windowStart]) / wdt;
                    if (maxCum[i] === null || wspeed > maxCum[i]) maxCum[i] = wspeed;
                    if (wspeed >= MOVING_MIN_SPEED && (minCum[i] === null || wspeed < minCum[i])) minCum[i] = wspeed;
                }
            }
        }

        movingStats = { timeCum: timeCum, distCum: distCum, minCum: minCum, maxCum: maxCum };
    }

    function formatDuration(seconds) {
        seconds = Math.round(seconds);
        var h = Math.floor(seconds / 3600);
        var m = Math.floor((seconds % 3600) / 60);
        var s = seconds % 60;
        return (h > 0 ? h + 't\u00A0' : '') + m + 'm\u00A0' + s + 's'; // hårde mellemrum: værdien brydes ikke over to linjer
    }

    function updateButtons() {
        document.getElementById('btn-start').disabled = currentIndex <= 1;
        document.getElementById('btn-prev').disabled = currentIndex <= 1;
        document.getElementById('btn-prev5').disabled = currentIndex <= 5;
        document.getElementById('btn-end').disabled = currentIndex >= allPoints.length;
        document.getElementById('btn-next').disabled = currentIndex >= allPoints.length;
        document.getElementById('btn-next5').disabled = currentIndex + 4 >= allPoints.length;
    }

    function updateRoute() {
        if (!allPoints || allPoints.length < 1) return;

        var idx = Math.max(1, Math.min(currentIndex, allPoints.length)) - 1;
        var currentPoint = allPoints[idx];
        var prevPoint = idx > 0 ? allPoints[idx - 1] : null;

        var markerIcon;
        if (activityIconEast && activityIconWest) {
            // Bevæger vi os mod øst (længdegraden stiger)? Så "1"-ikonet,
            // ellers "2". Uden et forrige punkt antages øst som standard.
            markerIcon = (!prevPoint || currentPoint.lng > prevPoint.lng) ? activityIconEast : activityIconWest;
        } else {
            // Blå prik = "nuværende position under scrubning" — bruges for
            // Ruter uden valgt markør, og når der ikke er et retningsikonsæt.
            markerIcon = routeMarkerIcon;
        }

        if (!positionMarker) {
            positionMarker = L.marker([currentPoint.lat, currentPoint.lng], { icon: markerIcon, pane: 'routePositionPane' }).addTo(map);
        } else {
            positionMarker.setLatLng([currentPoint.lat, currentPoint.lng]);
            positionMarker.setIcon(markerIcon);
        }

        if (hasUsableTimeData) {
            var stats = calculateStats(currentIndex);
            if (stats) {
                document.getElementById('stat-total-time').textContent = 'Tid: ' + formatDuration(stats.time);
                document.getElementById('stat-current-speed').textContent = 'Hastighed: ' + (stats.cur_speed * 3.6).toFixed(1) + '\u00A0km/t';
                document.getElementById('stat-avg-speed').textContent = 'Gennemsnit: ' + (stats.avspeed * 3.6).toFixed(1) + '\u00A0km/t';
            }
            if (movingStats) {
                var movingTime = movingStats.timeCum[idx];
                var movingDist = movingStats.distCum[idx];
                var minSpeed = movingStats.minCum[idx];
                var maxSpeed = movingStats.maxCum[idx];
                document.getElementById('stat-moving-time').textContent = 'Tid i bevægelse: ' + formatDuration(movingTime);
                document.getElementById('stat-avg-moving-speed').textContent = 'Gns. i bevægelse: ' +
                    (movingTime > 0 ? (movingDist / movingTime * 3.6).toFixed(1) + '\u00A0km/t' : '–');
                document.getElementById('stat-min-speed').textContent = 'Min. hastighed: ' +
                    (minSpeed !== null ? (minSpeed * 3.6).toFixed(1) + '\u00A0km/t' : '–');
                document.getElementById('stat-max-speed').textContent = 'Maks. hastighed: ' +
                    (maxSpeed !== null ? (maxSpeed * 3.6).toFixed(1) + '\u00A0km/t' : '–');
            }
        }

        updateExtraFieldCurrentValues(idx);

        // Afstand fra start: bruger den delte, kumulerede afstandsliste
        // (samme indeks som scrubberen, så tallet altid passer til
        // markørens position). Vises altid, uafhængigt af datafelt-valg.
        if (cumulativeDistances.length > idx) {
            document.getElementById('stat-distance-from-start').textContent =
                'Afstand fra start: ' + (cumulativeDistances[idx] / 1000).toFixed(2) + '\u00A0km';
        }

        updateButtons();
        updateGraphMarker(currentIndex - 1);
    }

    document.getElementById('btn-start').addEventListener('click', function () { currentIndex = 1; updateRoute(); });
    document.getElementById('btn-prev').addEventListener('click', function () { if (currentIndex > 1) currentIndex--; updateRoute(); });
    // De dobbelte pile springer 1/25 af ruten, så man højst skal klikke 25
    // gange fra start til slut. Beregnes ved hvert klik (livesider vokser).
    function bigStep() { return Math.max(1, Math.ceil(allPoints.length / 25)); }
    document.getElementById('btn-prev5').addEventListener('click', function () { currentIndex = Math.max(1, currentIndex - bigStep()); updateRoute(); });
    document.getElementById('btn-next').addEventListener('click', function () { if (currentIndex < allPoints.length) currentIndex++; updateRoute(); });
    document.getElementById('btn-next5').addEventListener('click', function () { currentIndex = Math.min(allPoints.length, currentIndex + bigStep()); updateRoute(); });
    document.getElementById('btn-end').addEventListener('click', function () { currentIndex = allPoints.length; updateRoute(); });
    document.getElementById('btn-graphs-toggle').addEventListener('click', toggleGraphsPanel);

    // Pilen til venstre går ét trin tilbage i browserens historik (dér hvor brugeren
    // kom fra — kontrolpanelet, forsiden, editoren, en anden etape ...). Er der ingen
    // side på dette site at gå tilbage til (delt link, bogmærke, ny fane), bruges
    // linkets almindelige mål: kontrolpanelet for ejeren, ellers forsiden.
    document.getElementById('btn-back').addEventListener('click', function (e) {
        var cameFromThisSite = false;
        try {
            cameFromThisSite = !!document.referrer && new URL(document.referrer).origin === window.location.origin;
        } catch (err) {
            cameFromThisSite = false;
        }
        if (cameFromThisSite && window.history.length > 1) {
            e.preventDefault();
            window.history.back();
        }
    });

    var routeHelpDialog = document.getElementById('route-help-dialog');
    document.getElementById('btn-open-route-help').addEventListener('click', function () {
        routeHelpDialog.showModal();
    });
    document.getElementById('route-help-dialog-close').addEventListener('click', function () {
        routeHelpDialog.close();
    });
    routeHelpDialog.addEventListener('click', function (ev) {
        if (ev.target === routeHelpDialog) routeHelpDialog.close();
    });
})();
</script>

<?php if (!$loggedIn): ?>
<?php render_auth_dialogs(); ?>
<script src="assets/genindlaes.js?v=1"></script>
<script src="assets/auth-dialogs.js?v=4"></script>
<script>MitSporAuth.init({ handleUrl: false });</script>
<script src="assets/videoer.js?v=1"></script>
<?php endif; ?>

</body>
</html>
