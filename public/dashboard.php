<?php
declare(strict_types=1);

session_start();

// Kontrolpanelet viser brugerens egne, skiftende data (og er bag login). Browseren
// må derfor ikke vise en gemt kopi, når man går "tilbage" til siden fra fx editoren —
// ellers mangler en lige oprettet rute i listen.
header('Cache-Control: no-store');

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/route_groups.php';
require_once __DIR__ . '/../app/app_hent.php';
require_once __DIR__ . '/../app/videoer.php';
require_once __DIR__ . '/../app/markers.php';
require_once __DIR__ . '/../app/live.php';
require_once __DIR__ . '/../app/topbar_menu.php';

$userId = require_login();
$user = current_user($userId);

if ($user === null) {
    $_SESSION = [];
    session_destroy();
    header('Location: login.php');
    exit;
}

$pdo = get_db();
$activityTypes = $pdo->query(
    'SELECT id, name_da, icon_filename FROM activity_types WHERE is_active = 1 ORDER BY (slug IN (\'water_other\',\'land_other\')) DESC, name_da ASC'
)->fetchAll();

$stmt = $pdo->prepare(
    "SELECT r.public_id, r.title, r.visibility, r.route_type, r.created_at, r.original_gpx_file_path, r.share_token,
            r.group_id, r.group_position, g.public_id AS group_public_id, g.title AS group_title,
            r.distance_meters, r.allow_download, r.has_time_data, a.name_da AS activity_name,
            COALESCE(r.marker_icon, a.icon_filename) AS activity_icon, r.marker_icon, a.icon_filename AS activity_marker
     FROM routes r
     LEFT JOIN route_groups g ON g.id = r.group_id
     LEFT JOIN activity_types a ON a.id = r.activity_type_id
     WHERE r.user_id = :user_id AND r.status = 'active'
     ORDER BY r.title ASC"
);
$stmt->execute(['user_id' => $userId]);
$routes = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT COUNT(*) FROM user_images WHERE user_id = :user_id');
$stmt->execute(['user_id' => $userId]);
$imageCount = (int) $stmt->fetchColumn();

// Lagerplads som "12,3 MB / 500,0 MB" (samme format i profilen og over listen).
$storageText = number_format($user['storage_used_bytes'] / 1024 / 1024, 1, ',', '.') . ' MB / '
    . number_format($user['storage_quota_bytes'] / 1024 / 1024, 1, ',', '.') . ' MB';

$groups = group_list_for_user($pdo, $userId);
$liveEvents = live_events_for_dashboard($pdo, $userId);
?>
<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/svg+xml" href="/grafik/favicon.svg">
    <link rel="icon" type="image/png" sizes="32x32" href="/grafik/favicon-32.png">
    <meta name="robots" content="noindex, nofollow">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mit kontrolpanel</title>
    <link rel="stylesheet" href="assets/style.css?v=39">
    <link rel="stylesheet" href="vendor/leaflet/leaflet.css">
    <link rel="stylesheet" href="assets/dashboard.css?v=31">
    <link rel="stylesheet" href="assets/videoer.css?v=1">
</head>
<body>

<div class="topbar" id="dashboard-topbar">
    <a href="index.php" class="brand" title="Til forsiden" aria-label="Til forsiden">
        <span class="back-arrow" aria-hidden="true">&larr;</span><span class="brand-name">MitSpor</span>
    </a>
    <div class="topbar-filters">
        <div class="action-group" role="group" aria-label="Ruter">
            <button type="button" id="btn-open-upload" class="action-group-btn"><svg class="tb-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M17 8l-5-5-5 5"/><path d="M12 3v12"/></svg>Upload<span class="label-long"> GPX</span></button>
            <a href="editor/?new=1" class="action-group-btn"><svg class="tb-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>Tegn<span class="label-long"> ny</span> rute</a>
            <button type="button" id="btn-group-selected" class="action-group-btn" disabled title="Sæt kryds ved ruter i listen for at gruppere dem"><svg class="tb-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 3l9 5-9 5-9-5z"/><path d="M3 13l9 5 9-5"/></svg>Gruppér<span class="label-long"> valgte</span><span id="group-selected-count" hidden></span></button>
        </div>
    </div>
    <div class="topbar-actions">
        <?= topbar_menu([
            ['form' => '/logout.php', 'label' => 'Log ud'],
            ['id' => 'btn-open-profile', 'label' => 'Profil og konto'],
            ['id' => 'btn-open-dashboard-help', 'label' => 'Hjælp'],
        ]) ?>
    </div>
</div>

<?= about_dialog() ?>

<dialog id="dashboard-help-dialog">
    <div class="dialog-header">
        <h2>Hjælp til kontrolpanelet</h2>
        <button type="button" id="dashboard-help-close" class="close-btn" aria-label="Luk">&times;</button>
    </div>
    <div class="dialog-content">
        <p><strong>Sådan er kontrolpanelet bygget op:</strong> Til venstre ser du alle dine ruter, spor og grupper i faneblade (Spor, Ruter, Grupper og Live). Vælg et element, så vises et kort og dets værktøjer til højre. Ved siden af hver knap står et <strong>?</strong>, der forklarer, hvad knappen gør. På en mobil vises først listen, og værktøjerne kommer frem, når du vælger noget — med "Tilbage til listen" øverst.</p>

        <p><strong>Kom i gang:</strong> Tryk "Upload GPX" for at lægge en fil op fra din computer, eller "Tegn ny rute" for at tegne en helt ny rute i GPX-editoren. En ny rute oprettes først, når du trykker Gem i editoren.</p>

        <p><strong>Rute eller Spor?</strong> Vælg "Spor", hvis filen er optaget med en GPS-enhed og indeholder tidsstempler — så vises tid og hastighed på rutens side. Vælg "Rute", hvis det er en planlagt/tegnet rute — den viser ikke tid, selv hvis filen skulle indeholde tidsstempler.</p>

        <p><strong>Offentlig eller privat?</strong> Offentlige ruter kan findes af alle via søgning og oversigten på forsiden. Private ruter kan kun du se — med mindre du opretter et <strong>del-link</strong> til den enkelte rute (knappen "Del-link" blandt rutens værktøjer).</p>

        <p><strong>Rutens værktøjer:</strong></p>
        <ul>
            <li><strong>Vis rute på kort</strong> åbner rutens egen side — den, andre ser, hvis ruten er offentlig eller delt med et link.</li>
            <li><strong>Rediger i GPX-editor</strong> åbner editoren, hvor du flytter punkter og indsætter waypoints med billeder og kommentarer. I editorens menu (☰) finder du også Rediger metadata (titel, beskrivelse, aktivitet, synlighed m.m.), Slet datafelter, Rediger højdedata og Udtynd punkter.</li>
            <li><strong>Visning af datafelter</strong> vælger, hvad rutens side viser (fx højdeprofil, hastighed og puls).</li>
            <li><strong>Vælg markør</strong> vælger det billede, der viser ruten på kortet.</li>
            <li><strong>Del-link</strong> (kun private ruter).</li>
            <li><strong>Gendan original fil</strong> vises kun, hvis ruten er blevet udtyndet — den henter den oprindelige, fulde fil tilbage og kasserer ændringer, du har lavet siden.</li>
            <li><strong>Download GPX-fil</strong> og <strong>Slet</strong>.</li>
        </ul>

        <p><strong>Grupper:</strong> En gruppe samler flere ruter (etaper) til én tur, fx en flerdagestur. Sæt kryds ved ruterne i listen og tryk "Gruppér valgte", eller vælg flere GPX-filer på én gang, når du uploader. Under fanen Grupper vælger du en gruppe for at åbne den med "Vis gruppe på kort" eller ændre den med "Rediger gruppe". Der kan du også tilføje og fjerne etaper. Etaperne vises som én tur på forsiden, med "Forrige" og "Næste" på hver etape. Synligheden bestemmer du stadig pr. rute, men under "Rediger gruppe" kan du gøre hele gruppen offentlig eller privat med ét klik.</p>

        <p>Har du spørgsmål, forslag eller kommentarer, er du velkommen til at skrive til <a href="mailto:info@mitspor.dk">info@mitspor.dk</a>.</p>
    </div>
</dialog>

<dialog id="profile-dialog" class="modal-dialog">
    <button type="button" id="profile-dialog-close" class="modal-close" aria-label="Luk">&times;</button>
    <h2>Profil</h2>
    <dl>
        <dt>Navn</dt>
        <dd>
            <span id="profile-name-display"><?= htmlspecialchars($user['display_name'], ENT_QUOTES, 'UTF-8') ?></span>
            <button type="button" class="icon-edit-btn" id="btn-edit-name" title="Rediger navn">✎</button>
        </dd>

        <dt>E-mail</dt>
        <dd>
            <span id="profile-email-display"><?= htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8') ?></span>
            <button type="button" class="icon-edit-btn" id="btn-edit-email" title="Skift e-mail">✎</button>
        </dd>

        <dt>Medlem siden</dt>
        <dd><?= htmlspecialchars($user['created_at'], ENT_QUOTES, 'UTF-8') ?></dd>

        <dt>Ruter</dt>
        <dd><?= count($routes) ?> / <?= (int) $user['route_quota'] ?></dd>

        <dt>Billeder</dt>
        <dd><?= $imageCount ?></dd>

        <dt>Lagerplads</dt>
        <dd><?= $storageText ?></dd>
    </dl>

    <div id="edit-name-panel" class="inline-edit-panel" hidden>
        <div class="modal-errors" id="edit-name-error" hidden></div>
        <label>
            Nyt navn
            <input type="text" id="edit-name-input" maxlength="100">
        </label>
        <div class="inline-edit-actions">
            <button type="button" id="save-name-btn" class="btn btn-primary">Gem</button>
            <button type="button" id="cancel-name-btn" class="btn">Annullér</button>
        </div>
    </div>

    <div id="edit-email-panel" class="inline-edit-panel" hidden>
        <div class="modal-errors" id="edit-email-error" hidden></div>
        <div id="edit-email-success" hidden>Bekræftelsesmail sendt til den nye adresse — klik linket for at fuldføre skiftet.</div>
        <label>
            Ny e-mailadresse
            <input type="email" id="edit-email-input">
        </label>
        <label>
            Din nuværende adgangskode (for at bekræfte)
            <input type="password" id="edit-email-password" autocomplete="current-password">
        </label>
        <div class="inline-edit-actions">
            <button type="button" id="save-email-btn" class="btn btn-primary">Send bekræftelse</button>
            <button type="button" id="cancel-email-btn" class="btn">Annullér</button>
        </div>
    </div>

    <h2>Adgangskode</h2>
    <nav class="dialog-nav">
        <button type="button" id="btn-edit-password">Skift adgangskode</button>
    </nav>
    <div id="edit-password-panel" class="inline-edit-panel" hidden>
        <div class="modal-errors" id="edit-password-error" hidden></div>
        <div id="edit-password-success" hidden>Din adgangskode er ændret.</div>
        <label>
            Nuværende adgangskode
            <input type="password" id="edit-current-password" autocomplete="current-password">
        </label>
        <label>
            Ny adgangskode (mindst 10 tegn)
            <input type="password" id="edit-new-password" minlength="10" autocomplete="new-password">
        </label>
        <label>
            Gentag ny adgangskode
            <input type="password" id="edit-new-password-confirm" minlength="10" autocomplete="new-password">
        </label>
        <div class="inline-edit-actions">
            <button type="button" id="save-password-btn" class="btn btn-primary">Skift adgangskode</button>
            <button type="button" id="cancel-password-btn" class="btn">Annullér</button>
        </div>
    </div>

    <h2>Konto</h2>
    <nav class="dialog-nav">
        <a href="/manage-images.php">Mine billeder</a>
        <a href="<?= dash_h(data_export_url()) ?>">Download alle mine data</a>
        <a href="/privatlivspolitik.php">Privatlivspolitik</a>
        <a href="delete-account.php" class="danger">Slet min konto</a>
    </nav>
</dialog>

<?php
function dash_h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * Markørvalget (radioknapper med billeder) til "Vælg markør" og upload.
 * $defaultBoxId: feltet, der viser den valgte aktivitets standardmarkør
 * (fyldes af markerDefaultPreview() i assets/dashboard.js). Markører med en
 * aktivitet vises kun, når den aktivitet er valgt; de neutrale vises altid.
 * Markørerne læses fra mapperne i public/markers/ (se app/markers.php).
 */
function render_marker_picker(string $defaultBoxId): void
{
    ?>
        <fieldset class="marker-picker">
            <legend>Markør på kortet</legend>
            <div class="marker-options">
                <label class="marker-option">
                    <input type="radio" name="marker_icon" value="" checked>
                    <span class="marker-option-img" id="<?= dash_h($defaultBoxId) ?>"></span>
                    <span class="marker-option-name">Standard</span>
                </label>
                <?php foreach (marker_library() as $markerKey => $marker): ?>
                <label class="marker-option" data-marker-activity="<?= dash_h($marker['activity']) ?>">
                    <input type="radio" name="marker_icon" value="<?= dash_h($markerKey) ?>">
                    <span class="marker-option-img"><img src="<?= dash_h(marker_image_urls($markerKey)['east'] ?? '') ?>" alt="" loading="lazy"></span>
                    <span class="marker-option-name"><?= dash_h($marker['name']) ?></span>
                </label>
                <?php endforeach; ?>
            </div>
        </fieldset>
    <?php
}

// Lille "?"-knap til højre for et værktøj; teksten ligger i assets/dashboard.js (TOOL_HELP).
function dash_help_btn(string $key, string $label): string
{
    return '<button type="button" class="tool-help" data-tool-help="' . dash_h($key) . '" aria-label="Forklaring: ' . dash_h($label) . '" title="Hvad gør denne knap?">?</button>';
}

function dash_route_summary(array $route): string
{
    $parts = [];
    if ((int) ($route['distance_meters'] ?? 0) > 0) {
        $parts[] = number_format($route['distance_meters'] / 1000, 1, ',', '.') . ' km';
    }
    if (!empty($route['activity_name'])) {
        $parts[] = (string) $route['activity_name'];
    }
    $parts[] = $route['route_type'] === 'spor' ? 'Spor' : 'Rute';
    $parts[] = $route['visibility'] === 'public' ? 'Offentlig' : 'Privat';

    return implode(' · ', $parts);
}

// $order er rutens plads i den samlede liste (sorteret efter titel). Spor og
// ruter står i hver sin fane, så "Gruppér valgte" bruger $order til at lægge
// de valgte etaper i samme rækkefølge, uanset hvilken fane de er valgt i.
function render_route_item(array $route, int $order): void
{
    $key = 'route:' . $route['public_id'];
    ?>
    <li data-item="<?= dash_h($key) ?>">
        <input type="checkbox" class="route-select" data-select-route="<?= dash_h($route['public_id']) ?>" data-order="<?= $order ?>" aria-label="Vælg <?= dash_h($route['title']) ?> til gruppering">
        <button type="button" class="hub-item-main" data-select-item="<?= dash_h($key) ?>">
            <span class="route-title"><?= dash_h($route['title']) ?></span>
            <span class="hub-item-sub">
                <?= $route['route_type'] === 'spor' ? 'Spor' : 'Rute' ?> &middot; <?= $route['visibility'] === 'public' ? 'offentlig' : 'privat' ?>
                <?php if ($route['group_id'] !== null): ?> &middot; etape <?= (int) $route['group_position'] ?> i <?= dash_h($route['group_title']) ?><?php endif; ?>
            </span>
        </button>
    </li>
    <?php
}

function render_route_detail(array $route): void
{
    $id = $route['public_id'];
    $title = $route['title'];
    ?>
    <section class="detail-panel" data-detail="route:<?= dash_h($id) ?>" hidden>
        <h2><?= dash_h($title) ?></h2>
        <p class="detail-summary"><?= dash_h(dash_route_summary($route)) ?></p>
        <p class="detail-meta">
            Oprettet <?= dash_h(date('d.m.Y', strtotime((string) $route['created_at']))) ?>
            <?php if ($route['group_id'] !== null): ?>
                &middot; etape <?= (int) $route['group_position'] ?> i
                <button type="button" class="link-btn" data-goto="group:<?= dash_h($route['group_public_id']) ?>"><?= dash_h($route['group_title']) ?></button>
            <?php endif; ?>
        </p>

        <div class="detail-map" aria-label="Kort over ruten"<?php $markerUrls = marker_image_urls($route['activity_icon'] ?: null); ?><?= $markerUrls !== null ? ' data-marker-east="' . dash_h($markerUrls['east']) . '" data-marker-west="' . dash_h($markerUrls['west']) . '"' . ($markerUrls['mirror'] ? ' data-marker-mirror="1"' : '') : '' ?>></div>

        <div class="tool-list">
            <div class="tool-row">
                <a class="tool" href="route-view.php?id=<?= dash_h($id) ?>">Vis rute på kort</a>
                <?= dash_help_btn('view', 'Vis rute på kort') ?>
            </div>
            <div class="tool-row">
                <a class="tool" href="editor/?route=<?= dash_h($id) ?>">Rediger i GPX-editor</a>
                <?= dash_help_btn('editor', 'Rediger i GPX-editor') ?>
            </div>
            <div class="tool-row">
                <button type="button" class="tool" data-tool-fields="<?= dash_h($id) ?>" data-route-title="<?= dash_h($title) ?>">Visning af datafelter</button>
                <?= dash_help_btn('fields', 'Visning af datafelter') ?>
            </div>
            <div class="tool-row">
                <button type="button" class="tool" data-pick-marker="<?= dash_h($id) ?>" data-route-title="<?= dash_h($title) ?>" data-marker-current="<?= dash_h($route['marker_icon']) ?>" data-marker-activity="<?= dash_h($route['activity_marker']) ?>" data-marker-url="<?= dash_h(marker_image_urls($route['activity_marker'] ?: null)['east'] ?? '') ?>">Vælg markør</button>
                <?= dash_help_btn('marker', 'Vælg markør') ?>
            </div>
            <?php if ($route['visibility'] === 'private'): ?>
                <div class="tool-row">
                    <button type="button" class="tool" data-share-link="<?= dash_h($id) ?>" data-route-title="<?= dash_h($title) ?>" data-has-token="<?= $route['share_token'] !== null ? '1' : '0' ?>">Del-link</button>
                    <?= dash_help_btn('share', 'Del-link') ?>
                </div>
            <?php endif; ?>
            <?php if ($route['original_gpx_file_path'] !== null): ?>
                <div class="tool-row">
                    <button type="button" class="tool" data-restore-original="<?= dash_h($id) ?>" data-route-title="<?= dash_h($title) ?>">Gendan original fil</button>
                    <?= dash_help_btn('restore', 'Gendan original fil') ?>
                </div>
            <?php endif; ?>
            <div class="tool-row">
                <a class="tool" href="<?= dash_h(gpx_download_url($id)) ?>">Download GPX-fil</a>
                <?= dash_help_btn('download', 'Download GPX-fil') ?>
            </div>
            <div class="tool-row">
                <button type="button" class="tool danger" data-delete-route="<?= dash_h($id) ?>" data-route-title="<?= dash_h($title) ?>">Slet</button>
                <?= dash_help_btn('delete', 'Slet') ?>
            </div>
        </div>
    </section>
    <?php
}

function render_group_item(array $g): void
{
    $key = 'group:' . $g['public_id'];
    ?>
    <li data-item="<?= dash_h($key) ?>">
        <button type="button" class="hub-item-main" data-select-item="<?= dash_h($key) ?>">
            <span class="route-title"><?= dash_h($g['title']) ?></span>
            <span class="hub-item-sub"><?= (int) $g['stage_count'] ?> etaper &middot; <?= number_format($g['distance_meters'] / 1000, 1, ',', '.') ?> km &middot; <?= (int) $g['public_count'] ?> offentlige</span>
        </button>
    </li>
    <?php
}

function render_group_detail(array $g): void
{
    $id = $g['public_id'];
    ?>
    <section class="detail-panel" data-detail="group:<?= dash_h($id) ?>" hidden>
        <h2><?= dash_h($g['title']) ?></h2>
        <p class="detail-summary"><?= (int) $g['stage_count'] ?> etaper &middot; <?= number_format($g['distance_meters'] / 1000, 1, ',', '.') ?> km &middot; <?= (int) $g['public_count'] ?> offentlige</p>

        <div class="detail-map" aria-label="Kort over gruppen"></div>

        <div class="tool-list">
            <a class="tool" href="group-view.php?id=<?= dash_h($id) ?>">Vis gruppe på kort</a>
            <?php if (group_download_available()): ?>
            <a class="tool" href="<?= dash_h(group_download_url($id)) ?>">Download gruppen som én GPX</a>
            <?php endif; ?>
            <button type="button" class="tool" data-edit-group="<?= dash_h($id) ?>">Rediger gruppe</button>
            <button type="button" class="tool danger" data-delete-group="<?= dash_h($id) ?>" data-group-title="<?= dash_h($g['title']) ?>" data-stage-count="<?= (int) $g['stage_count'] ?>">Slet gruppen og alle etaper</button>
        </div>
    </section>
    <?php
}

const LIVE_STATUS_LABELS = ['planlagt' => 'Planlagt', 'live' => 'Live nu', 'pause' => 'Pause', 'afsluttet' => 'Afsluttet'];

function dash_live_summary(array $ev): string
{
    $parts = [LIVE_STATUS_LABELS[$ev['status']] ?? ''];
    if ($ev['planned_start_at'] !== null && $ev['status'] === 'planlagt') {
        $parts[] = 'start ' . date('d.m.Y H.i', strtotime((string) $ev['planned_start_at']));
    }
    $stages = (int) $ev['started_count'] + (int) $ev['planned_count'];
    if ($stages > 0) {
        $parts[] = $stages . ($stages === 1 ? ' etape' : ' etaper');
    }
    $parts[] = $ev['visibility'] === 'public' ? 'offentlig' : 'kun med link';
    if ((int) $ev['delay_minutes'] > 0) {
        $parts[] = (int) $ev['delay_minutes'] . ' min. forsinkelse';
    }

    return implode(' · ', $parts);
}

function render_live_item(array $ev): void
{
    $key = 'live:' . $ev['public_id'];
    ?>
    <li data-item="<?= dash_h($key) ?>">
        <button type="button" class="hub-item-main" data-select-item="<?= dash_h($key) ?>">
            <span class="route-title"><?= dash_h($ev['title']) ?></span>
            <span class="hub-item-sub"><?= dash_h(dash_live_summary($ev)) ?></span>
        </button>
    </li>
    <?php
}

function render_live_detail(array $ev): void
{
    $id = $ev['public_id'];
    $url = APP_BASE_URL . live_url($ev);
    $editData = [
        'id'               => $id,
        'title'            => $ev['title'],
        'description'      => (string) ($ev['description'] ?? ''),
        'activity_type_id' => (int) $ev['activity_type_id'],
        'visibility'       => $ev['visibility'],
        'delay_minutes'    => (int) $ev['delay_minutes'],
        'planned_start_at' => $ev['planned_start_at'] !== null ? date('Y-m-d\TH:i', strtotime((string) $ev['planned_start_at'])) : '',
        'planned_count'    => (int) $ev['planned_count'],
        'finished'         => $ev['status'] === 'afsluttet',
    ];
    ?>
    <section class="detail-panel" data-detail="live:<?= dash_h($id) ?>" hidden>
        <h2><?= dash_h($ev['title']) ?></h2>
        <p class="detail-summary"><?= dash_h(dash_live_summary($ev)) ?></p>
        <?php if ($ev['group_public_id'] !== null): ?>
            <p class="detail-meta">Etaperne ligger i gruppen
                <button type="button" class="link-btn" data-goto="group:<?= dash_h($ev['group_public_id']) ?>"><?= dash_h($ev['title']) ?></button>
            </p>
        <?php endif; ?>

        <div class="live-link">
            <label for="live-url-<?= dash_h($id) ?>">Link til livesiden<?= $ev['visibility'] === 'public' ? '' : ' (alle med linket kan se den)' ?></label>
            <div class="live-link-row">
                <input type="text" readonly value="<?= dash_h($url) ?>" id="live-url-<?= dash_h($id) ?>" data-live-url>
                <button type="button" class="icon-btn" data-live-copy title="Kopiér link" aria-label="Kopiér link"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg></button>
            </div>
        </div>

        <div class="tool-list">
            <a class="tool" href="<?= dash_h(live_url($ev)) ?>">Vis livesiden</a>
            <button type="button" class="tool" data-live-edit="<?= dash_h(json_encode($editData, JSON_UNESCAPED_UNICODE)) ?>">Rediger livesending</button>
            <?php if ($ev['visibility'] !== 'public'): ?>
                <button type="button" class="tool" data-live-action="new_link" data-live-id="<?= dash_h($id) ?>" data-live-confirm="Lav et nyt link? Det gamle link holder op med at virke.">Lav nyt link</button>
            <?php endif; ?>
            <?php if ($ev['status'] !== 'afsluttet'): ?>
                <button type="button" class="tool" data-live-action="finish" data-live-id="<?= dash_h($id) ?>" data-live-confirm="Afslut livesendingen? Appen kan derefter ikke sende flere etaper til den.">Afslut livesendingen</button>
            <?php endif; ?>
            <button type="button" class="tool danger" data-live-delete="<?= dash_h($id) ?>" data-live-title="<?= dash_h($ev['title']) ?>">Slet livesending</button>
        </div>
        <p class="hint">Afsendelsen startes i MitSpor-appen (kræver den nyeste version): vælg livesendingen, når du starter en optagelse. Hver optagelse bliver en etape.</p>
    </section>
    <?php
}

$ruteRoutes = array_filter($routes, static fn(array $r): bool => $r['route_type'] !== 'spor');
$sporRoutes = array_filter($routes, static fn(array $r): bool => $r['route_type'] === 'spor');
// Spor-fanen vises først, medmindre man kun har ruter.
$firstTab = (!$sporRoutes && $ruteRoutes) ? 'tab-rute' : 'tab-spor';

?>
<div class="container dashboard-container">

    <div class="hub" id="hub" data-tile-max-zoom="<?= (int) TILE_MAX_ZOOM ?>">
        <div class="hub-list card">
            <div class="tabs">
                <button type="button" class="tab-btn<?= $firstTab === 'tab-spor' ? ' active' : '' ?>" data-tab-target="tab-spor">Spor <span class="tab-count">(<?= count($sporRoutes) ?>)</span></button>
                <button type="button" class="tab-btn<?= $firstTab === 'tab-rute' ? ' active' : '' ?>" data-tab-target="tab-rute">Ruter <span class="tab-count">(<?= count($ruteRoutes) ?>)</span></button>
                <button type="button" class="tab-btn" data-tab-target="tab-grupper">Grupper <span class="tab-count">(<?= count($groups) ?>)</span></button>
                <button type="button" class="tab-btn" data-tab-target="tab-live">Live <span class="tab-count">(<?= count($liveEvents) ?>)</span></button>
            </div>
            <p class="hub-stats">
                Ruter: <?= count($routes) ?> / <?= (int) $user['route_quota'] ?> ruter
                · Billeder: <?= $imageCount ?>
                · Lagerplads: <?= $storageText ?>
            </p>

            <?php /* array_filter bevarer nøglerne, så $i er pladsen i den samlede liste. */ ?>
            <div id="tab-spor" class="tab-panel"<?= $firstTab === 'tab-spor' ? '' : ' hidden' ?>>
                <?php if (empty($sporRoutes)): ?>
                    <p class="empty-state">Du har endnu ikke uploadet nogen spor.</p>
                <?php else: ?>
                    <ul class="hub-items">
                        <?php foreach ($sporRoutes as $i => $route): render_route_item($route, $i); endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <div id="tab-rute" class="tab-panel"<?= $firstTab === 'tab-rute' ? '' : ' hidden' ?>>
                <?php if (empty($ruteRoutes)): ?>
                    <p class="empty-state">Du har endnu ikke oprettet nogen ruter.</p>
                <?php else: ?>
                    <ul class="hub-items">
                        <?php foreach ($ruteRoutes as $i => $route): render_route_item($route, $i); endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <div id="tab-grupper" class="tab-panel" hidden>
                <?php if (empty($groups)): ?>
                    <p class="empty-state">Du har ingen grupper endnu. Sæt kryds ved de ruter, der hører sammen (fx etaperne på en flerdagestur), og tryk "Gruppér valgte".</p>
                <?php else: ?>
                    <ul class="hub-items">
                        <?php foreach ($groups as $g): render_group_item($g); endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <div id="tab-live" class="tab-panel" hidden>
                <button type="button" class="btn btn-primary live-new-btn" data-live-edit="">Ny livesending</button>
                <?php if (empty($liveEvents)): ?>
                    <p class="empty-state">Med en livesending kan andre følge din tur på kortet, mens du er undervejs. Opret den på forhånd, og del linket. Selve afsendelsen startes i MitSpor-appen, når du optager.</p>
                <?php else: ?>
                    <ul class="hub-items">
                        <?php foreach ($liveEvents as $ev): render_live_item($ev); endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>

        <div class="hub-detail card">
            <button type="button" id="hub-back" class="link-btn hub-back">&larr; Tilbage til listen</button>

            <p id="hub-placeholder" class="empty-state">Vælg en rute, et spor eller en gruppe i listen for at se værktøjerne.</p>

            <?php foreach ($routes as $route): render_route_detail($route); endforeach; ?>
            <?php foreach ($groups as $g): render_group_detail($g); endforeach; ?>
            <?php foreach ($liveEvents as $ev): render_live_detail($ev); endforeach; ?>
        </div>
    </div>

</div>

<dialog id="tool-help-dialog" class="modal-dialog">
    <button type="button" id="tool-help-close" class="modal-close" aria-label="Luk">&times;</button>
    <h2 id="tool-help-title"></h2>
    <div id="tool-help-body"></div>
</dialog>

<dialog id="tool-fields-dialog" class="modal-dialog tool-wide">
    <button type="button" id="tool-fields-close" class="modal-close" aria-label="Luk">&times;</button>
    <h2>Visning af datafelter</h2>
    <p><strong id="tool-fields-title"></strong></p>

    <div id="tool-fields-errors" class="modal-errors" hidden></div>

    <div id="tool-fields-body" hidden>
        <h3 class="data-fields-heading">Visninger</h3>
        <p class="hint">Indbyggede visninger på rutens side, ud fra hvad GPX-filen indeholder.</p>
        <ul id="tool-functions-list" class="data-fields-list"></ul>
        <div id="tool-fields-raw" hidden>
            <h3 class="data-fields-heading">Øvrige felter</h3>
            <p class="hint">Felter uden en indbygget visning. De afkrydsede vises som tal på rutens side.</p>
            <ul id="tool-fields-list" class="data-fields-list"></ul>
        </div>
    </div>

    <p id="tool-fields-status" class="hint" hidden></p>
    <button type="button" id="tool-fields-save" class="btn btn-primary" disabled>💾 Gem</button>
</dialog>

<dialog id="marker-dialog" class="modal-dialog">
    <button type="button" id="marker-dialog-close" class="modal-close" aria-label="Luk">&times;</button>
    <h2>Vælg markør</h2>
    <p><strong id="marker-dialog-title"></strong></p>

    <div id="marker-dialog-errors" class="modal-errors" hidden></div>

    <form id="marker-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="route_id" id="marker-route-id">

        <?php render_marker_picker('marker-dialog-default'); ?>

        <button type="submit" class="btn btn-primary">Gem</button>
    </form>
</dialog>

<dialog id="upload-dialog" class="modal-dialog">
    <button type="button" id="upload-dialog-close" class="modal-close" aria-label="Luk">&times;</button>
    <h2>Upload GPX-fil(er)</h2>

    <div id="upload-errors" class="modal-errors" hidden></div>

    <form id="upload-form">
        <input type="hidden" name="csrf_token" id="upload-csrf-token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">

        <label>
            GPX-fil(er)
            <input type="file" name="gpx_file" id="upload-gpx-file" accept=".gpx" multiple required>
        </label>
        <p class="hint" id="upload-multi-hint">Vælg flere filer for at oprette dem som etaper i en ny gruppe (fx en flerdagestur).</p>

        <fieldset id="upload-split-fields" hidden>
            <legend id="upload-split-legend">Filen indeholder flere spor</legend>
            <label class="checkbox-field">
                <input type="radio" name="upload_split" value="1" id="upload-split-yes" checked>
                Opret en gruppe med én etape pr. spor
            </label>
            <label class="checkbox-field">
                <input type="radio" name="upload_split" value="0" id="upload-split-no">
                Upload som én rute
            </label>
        </fieldset>

        <label id="upload-title-label">
            Titel
            <input type="text" name="title" id="upload-title" required maxlength="200">
        </label>

        <div id="upload-group-fields" hidden>
            <p class="hint" id="upload-multi-note"></p>
            <label>
                Gruppens titel
                <input type="text" id="upload-group-title" maxlength="200">
            </label>
        </div>

        <label>
            Beskrivelse
            <textarea name="description" id="upload-description"></textarea>
        </label>

        <label>
            Aktivitetstype
            <select name="activity_type_id" id="upload-activity">
                <option value="">Vælg...</option>
                <?php foreach ($activityTypes as $type): ?>
                    <option value="<?= (int) $type['id'] ?>" data-marker-activity="<?= dash_h((string) $type['icon_filename']) ?>" data-marker-url="<?= dash_h(marker_image_urls((string) $type['icon_filename'])['east'] ?? '') ?>"><?= htmlspecialchars($type['name_da'], ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <?php render_marker_picker('upload-marker-default'); ?>

        <fieldset>
            <legend>Type</legend>
            <label class="checkbox-field">
                <input type="radio" name="route_type" value="rute" id="upload-route-type-rute" checked>
                Rute (planlagt — viser ikke tid/hastighed)
            </label>
            <label class="checkbox-field">
                <input type="radio" name="route_type" value="spor" id="upload-route-type-spor">
                Spor (GPS-optagelse — viser tid og hastighed)
            </label>
        </fieldset>

        <fieldset>
            <legend>Synlighed</legend>
            <label class="checkbox-field">
                <input type="radio" name="visibility" value="private" id="upload-visibility-private" checked>
                Privat (kun dig)
            </label>
            <label class="checkbox-field">
                <input type="radio" name="visibility" value="public" id="upload-visibility-public">
                Offentlig (alle kan se den)
            </label>
        </fieldset>

        <p class="notice-field" id="upload-public-notice" hidden>
            <label class="checkbox-field">
                <input type="checkbox" name="public_notice_ack" id="upload-public-notice-ack">
                Jeg forstår at start-/slutpunkter i en offentlig rute kan afsløre min bopæl (GDPR).
            </label>
        </p>

        <label class="checkbox-field">
            <input type="checkbox" name="allow_download" id="upload-allow-download">
            Tillad download af GPX-filen
        </label>

        <p class="notice-field" id="upload-time-in-rute-notice" hidden>
            Bemærk: Du har tilladt download af denne rute. Hvis denne fil indeholder tidsstempler, ligger de stadig i
            den fil, andre kan downloade. Vil du undgå det, kan du efter upload fjerne dem i editoren under "Slet datafelter".
        </p>

        <div id="upload-anonymous" hidden>
            <label class="checkbox-field">
                <input type="checkbox" name="hide_uploader_name" id="upload-hide-uploader-name">
                Vær anonym som uploader (skjul mit navn)
            </label>
        </div>

        <p class="hint" id="upload-progress" hidden></p>
        <button type="submit" class="btn btn-primary" id="upload-submit-btn">Upload</button>
    </form>
</dialog>

<dialog id="delete-route-dialog" class="modal-dialog">
    <button type="button" id="delete-route-close" class="modal-close" aria-label="Luk">&times;</button>
    <h2>Slet rute</h2>

    <div id="delete-route-errors" class="modal-errors" hidden></div>

    <p>
        Du er ved at slette <strong id="delete-route-title"></strong> permanent.
        GPX-filen fjernes fra serveren, og handlingen kan ikke fortrydes.
        Alle der har et link til ruten vil herefter se en besked om, at ruten er slettet.
    </p>

    <form id="delete-route-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="route_id" id="delete-route-id">

        <label>
            Indtast rutens navn for at bekræfte
            <input type="text" name="confirm_title" id="delete-route-confirm-title" required autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false">
        </label>
        <p class="hint" id="delete-route-mismatch" hidden>Navnet skal skrives præcis som ovenfor, med samme store og små bogstaver.</p>

        <button type="submit" class="btn btn-danger" id="delete-route-submit-btn" disabled>Slet ruten permanent</button>
    </form>
</dialog>

<dialog id="delete-group-dialog" class="modal-dialog">
    <button type="button" id="delete-group-close" class="modal-close" aria-label="Luk">&times;</button>
    <h2>Slet gruppe</h2>

    <div id="delete-group-errors" class="modal-errors" hidden></div>

    <p>
        Du er ved at slette gruppen <strong id="delete-group-title"></strong> og
        <strong id="delete-group-count"></strong> permanent, også de private.
        GPX-filerne fjernes fra serveren, og handlingen kan ikke fortrydes.
    </p>
    <p class="hint">Vil du kun fjerne gruppen og beholde ruterne, så brug i stedet "Opløs gruppen" under "Rediger gruppe".</p>

    <form id="delete-group-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="group_id" id="delete-group-id">

        <label>
            Indtast gruppens navn for at bekræfte
            <input type="text" name="confirm_title" id="delete-group-confirm-title" required autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false">
        </label>
        <p class="hint" id="delete-group-mismatch" hidden>Navnet skal skrives præcis som ovenfor, med samme store og små bogstaver.</p>

        <button type="submit" class="btn btn-danger" id="delete-group-submit-btn" disabled>Slet gruppen og etaperne permanent</button>
    </form>
</dialog>

<dialog id="share-link-dialog" class="modal-dialog">
    <button type="button" id="share-link-close" class="modal-close" aria-label="Luk">&times;</button>
    <h2>Del-link</h2>

    <div id="share-link-errors" class="modal-errors" hidden></div>

    <p>
        <strong id="share-link-route-title"></strong> er privat, men et delbart link giver adgang
        til denne ene rute uden login.
    </p>

    <div id="share-link-active">
        <label>
            Link
            <input type="text" id="share-link-url" readonly>
        </label>
        <div class="inline-edit-actions" style="margin-top: 8px;">
            <button type="button" id="share-link-copy-btn" class="btn">Kopiér</button>
            <button type="button" id="share-link-revoke-btn" class="btn btn-danger">Slet link</button>
        </div>
    </div>
</dialog>

<dialog id="group-create-dialog" class="modal-dialog">
    <button type="button" id="group-create-close" class="modal-close" aria-label="Luk">&times;</button>
    <h2>Gruppér valgte ruter</h2>

    <div id="group-create-errors" class="modal-errors" hidden></div>

    <p class="hint" id="group-create-summary"></p>

    <form id="group-create-form">
        <fieldset>
            <legend>Hvortil?</legend>
            <label class="checkbox-field">
                <input type="radio" name="group_mode" value="new" checked>
                Ny gruppe
            </label>
            <label class="checkbox-field">
                <input type="radio" name="group_mode" value="existing" id="group-mode-existing"<?= empty($groups) ? ' disabled' : '' ?>>
                Føj til en eksisterende gruppe
            </label>
        </fieldset>

        <div id="group-create-new">
            <label>
                Titel
                <input type="text" id="group-create-title" maxlength="200">
            </label>
            <label>
                Beskrivelse
                <textarea id="group-create-description"></textarea>
            </label>
        </div>

        <div id="group-create-existing" hidden>
            <label>
                Gruppe
                <select id="group-create-existing-select">
                    <?php foreach ($groups as $g): ?>
                        <option value="<?= htmlspecialchars($g['public_id'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($g['title'], ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>

        <p class="hint">Har alle ruterne tidsstempler, sorteres de automatisk efter tid. Ellers kan du selv trække dem på plads under "Rediger gruppe".</p>
        <button type="submit" class="btn btn-primary">Gruppér</button>
    </form>
</dialog>

<dialog id="live-edit-dialog" class="modal-dialog">
    <button type="button" id="live-edit-close" class="modal-close" aria-label="Luk">&times;</button>
    <h2 id="live-edit-heading">Ny livesending</h2>

    <div id="live-edit-errors" class="modal-errors" hidden></div>

    <form id="live-edit-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="event_id" id="live-edit-id">

        <label>
            Titel
            <input type="text" name="title" id="live-edit-title" required maxlength="200">
        </label>
        <label>
            Beskrivelse
            <textarea name="description" id="live-edit-description"></textarea>
        </label>
        <label>
            Aktivitet
            <select name="activity_type_id" id="live-edit-activity" required>
                <?php foreach ($activityTypes as $type): ?>
                    <option value="<?= (int) $type['id'] ?>"><?= dash_h($type['name_da']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            Planlagt start (valgfri)
            <input type="datetime-local" name="planned_start_at" id="live-edit-start">
        </label>

        <label>
            Plan (vises stiplet på livesiden)
            <select name="plan" id="live-edit-plan">
                <option value="keep" id="live-edit-plan-keep">Behold den nuværende plan</option>
                <option value="none">Ingen plan</option>
                <?php if ($groups): ?>
                    <optgroup label="Gruppe (én etape pr. rute)">
                        <?php foreach ($groups as $g): ?>
                            <option value="group:<?= dash_h($g['public_id']) ?>"><?= dash_h($g['title']) ?> (<?= (int) $g['stage_count'] ?> etaper)</option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endif; ?>
                <?php if ($routes): ?>
                    <optgroup label="Én rute">
                        <?php foreach ($routes as $r): ?>
                            <option value="route:<?= dash_h($r['public_id']) ?>"><?= dash_h($r['title']) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endif; ?>
            </select>
        </label>
        <p class="hint" id="live-edit-plan-hint">Etaper, der allerede er startet, røres ikke.</p>

        <label>
            Forsinkelse
            <select name="delay_minutes" id="live-edit-delay">
                <?php foreach (LIVE_DELAY_CHOICES as $minutes): ?>
                    <option value="<?= $minutes ?>"><?= $minutes === 0 ? 'Ingen' : $minutes . ' minutter' ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <p class="hint">Andre ser din position så mange minutter efter. Du ser selv alt med det samme.</p>

        <fieldset>
            <legend>Hvem kan se livesiden?</legend>
            <label class="checkbox-field">
                <input type="radio" name="visibility" value="private" id="live-vis-private" checked>
                Kun personer med linket
            </label>
            <label class="checkbox-field">
                <input type="radio" name="visibility" value="public" id="live-vis-public">
                Alle (offentlig)
            </label>
        </fieldset>

        <p class="notice-field" id="live-vis-notice" hidden>
            <label class="checkbox-field">
                <input type="checkbox" name="public_notice_ack" value="1" id="live-vis-ack">
                Jeg forstår, at alle kan følge min position, mens livesendingen er i gang.
            </label>
        </p>

        <button type="submit" class="btn btn-primary btn-full">Gem</button>
    </form>
</dialog>

<dialog id="live-delete-dialog" class="modal-dialog">
    <button type="button" id="live-delete-close" class="modal-close" aria-label="Luk">&times;</button>
    <h2>Slet livesending</h2>

    <div id="live-delete-errors" class="modal-errors" hidden></div>

    <p>
        Du er ved at slette livesendingen <strong id="live-delete-title"></strong>. Livesiden og de
        rå punkter slettes. Etapernes færdige spor og gruppen bliver stående.
    </p>

    <form id="live-delete-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="event_id" id="live-delete-id">

        <label>
            Indtast livesendingens navn for at bekræfte
            <input type="text" name="confirm_title" id="live-delete-confirm-title" required autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false">
        </label>

        <button type="submit" class="btn btn-danger" id="live-delete-submit-btn" disabled>Slet livesendingen</button>
    </form>
</dialog>

<dialog id="group-edit-dialog" class="modal-dialog">
    <button type="button" id="group-edit-close" class="modal-close" aria-label="Luk">&times;</button>
    <h2>Rediger gruppe</h2>

    <div id="group-edit-errors" class="modal-errors" hidden></div>

    <form id="group-edit-form">
        <label>
            Titel
            <input type="text" id="group-edit-title" required maxlength="200">
        </label>
        <label>
            Beskrivelse
            <textarea id="group-edit-description"></textarea>
        </label>

        <div class="dialog-section">
            <h3>Etaper</h3>
            <p class="hint">Træk i etaperne (eller brug pilene) for at ændre rækkefølgen. × fjerner en etape fra gruppen; ruten bliver stående som almindelig rute.</p>
            <ol id="group-order-list" class="group-order-list"></ol>
            <button type="button" class="btn" id="group-add-toggle" aria-expanded="false">+ Tilføj etaper</button>
            <div id="group-add-box" class="group-add-box" hidden>
                <input type="search" id="group-add-filter" placeholder="Søg i dine ruter" aria-label="Søg i dine ruter">
                <ul id="group-add-list" class="group-add-list"></ul>
                <p class="hint">Nye etaper kommer sidst; træk dem på plads. En rute, der står i en anden gruppe, flyttes hertil.</p>
            </div>
        </div>

        <fieldset>
            <legend>Synlighed (alle etaper)</legend>
            <label class="checkbox-field">
                <input type="radio" name="group_visibility" value="private" id="group-vis-private">
                Privat (kun dig)
            </label>
            <label class="checkbox-field">
                <input type="radio" name="group_visibility" value="public" id="group-vis-public">
                Offentlig (alle kan se dem)
            </label>
            <p class="hint" id="group-vis-mixed" hidden>Etaperne har forskellig synlighed. Vælg én, hvis alle etaper skal have den samme. Ellers bliver de, som de er.</p>
        </fieldset>

        <p class="notice-field" id="group-vis-notice" hidden>
            <label class="checkbox-field">
                <input type="checkbox" id="group-vis-ack">
                Jeg forstår at start-/slutpunkter i en offentlig rute kan afsløre min bopæl (GDPR).
            </label>
        </p>

        <button type="submit" class="btn btn-primary btn-full">Gem</button>
        <button type="button" id="group-dissolve" class="link-btn danger group-dissolve">Opløs gruppen (ruterne bevares)</button>
    </form>
</dialog>

<?php // Alle brugerens ruter, som "Tilføj etaper" i "Rediger gruppe" vælger imellem. ?>
<script type="application/json" id="group-route-catalog"><?= json_encode(array_map(static fn(array $r): array => [
    'id'         => $r['public_id'],
    'title'      => $r['title'],
    'visibility' => $r['visibility'],
    'group'      => $r['group_public_id'],
    'groupTitle' => $r['group_title'],
], $routes), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script src="vendor/leaflet/leaflet.js"></script>
<script src="vendor/sortablejs/Sortable.min.js"></script>
<script src="assets/route-meta-dialog.js?v=2"></script>
<script src="assets/gpx-field-labels.js?v=4"></script>
<script src="assets/gpx-tools.js?v=3"></script>
<script src="assets/topbar-menu.js?v=1"></script>
<script src="assets/genindlaes.js?v=1"></script>
<script src="assets/dashboard.js?v=28"></script>
<script src="assets/dashboard-tools.js?v=9"></script>
<script src="assets/activity-marker.js?v=3"></script>
<script src="assets/dashboard-map.js?v=7"></script>
<script src="assets/dashboard-live.js?v=2"></script>
<script src="assets/videoer.js?v=1"></script>

</body>
</html>
