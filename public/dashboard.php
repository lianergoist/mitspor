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
    'SELECT id, name_da FROM activity_types WHERE is_active = 1 ORDER BY (slug IN (\'water_other\',\'land_other\')) DESC, name_da ASC'
)->fetchAll();

$stmt = $pdo->prepare(
    "SELECT r.public_id, r.title, r.visibility, r.route_type, r.created_at, r.original_gpx_file_path, r.share_token,
            r.group_id, r.group_position, g.public_id AS group_public_id, g.title AS group_title,
            r.distance_meters, r.allow_download, r.has_time_data, a.name_da AS activity_name, a.icon_filename AS activity_icon
     FROM routes r
     LEFT JOIN route_groups g ON g.id = r.group_id
     LEFT JOIN activity_types a ON a.id = r.activity_type_id
     WHERE r.user_id = :user_id AND r.status = 'active'
     ORDER BY r.title ASC"
);
$stmt->execute(['user_id' => $userId]);
$routes = $stmt->fetchAll();

$groups = group_list_for_user($pdo, $userId);
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
    <link rel="stylesheet" href="assets/style.css?v=22">
    <link rel="stylesheet" href="vendor/leaflet/leaflet.css">
    <link rel="stylesheet" href="assets/dashboard.css?v=10">
</head>
<body>

<div class="topbar" id="dashboard-topbar">
    <a href="index.php" class="brand" title="Til forsiden" aria-label="Til forsiden">
        <span class="back-arrow" aria-hidden="true">&larr;</span>
    </a>
    <div class="topbar-filters">
        <div class="action-group" role="group" aria-label="Ruter">
            <button type="button" id="btn-open-upload" class="action-group-btn"><svg class="tb-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M17 8l-5-5-5 5"/><path d="M12 3v12"/></svg>Upload<span class="label-long"> GPX</span></button>
            <a href="editor/?new=1" class="action-group-btn"><svg class="tb-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>Tegn<span class="label-long"> ny</span> rute</a>
            <button type="button" id="btn-group-selected" class="action-group-btn" disabled title="Sæt kryds ved ruter i listen for at gruppere dem"><svg class="tb-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 3l9 5-9 5-9-5z"/><path d="M3 13l9 5 9-5"/></svg>Gruppér<span class="label-long"> valgte</span><span id="group-selected-count" hidden></span></button>
        </div>
    </div>
    <div class="topbar-actions">
        <button type="button" id="btn-open-profile" class="icon-btn" title="Profil og konto" aria-label="Profil og konto"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></button>
        <form method="post" action="logout.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
            <button type="submit" class="btn" title="Log ud" aria-label="Log ud"><svg class="tb-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="9" cy="7" r="4"/><path d="M2 21v-2a4 4 0 0 1 4-4h6a4 4 0 0 1 4 4v2"/><path d="M16 11h6"/><path d="M19 8l3 3-3 3"/></svg><span class="tb-label">Log ud</span></button>
        </form>
        <button type="button" id="btn-open-dashboard-help" class="btn" title="Hjælp" aria-label="Hjælp"><span class="help-icon" aria-hidden="true">?</span><span class="tb-label">Hjælp</span></button>
    </div>
</div>

<dialog id="dashboard-help-dialog">
    <div class="dialog-header">
        <h2>Hjælp til kontrolpanelet</h2>
        <button type="button" id="dashboard-help-close" class="close-btn" aria-label="Luk">&times;</button>
    </div>
    <div class="dialog-content">
        <p><strong>Sådan er kontrolpanelet bygget op:</strong> Til venstre ser du alle dine ruter, spor og grupper i faneblade (Alle, Spor, Ruter og Grupper). Vælg et element, så vises et kort og dets værktøjer til højre. Ved siden af hver knap står et <strong>?</strong>, der forklarer, hvad knappen gør. På en mobil vises først listen, og værktøjerne kommer frem, når du vælger noget — med "Tilbage til listen" øverst.</p>

        <p><strong>Kom i gang:</strong> Tryk "Upload GPX" for at lægge en fil op fra din computer, eller "Tegn ny rute" for at tegne en helt ny rute i GPX-editoren. En ny rute oprettes først, når du trykker Gem i editoren.</p>

        <p><strong>Rute eller Spor?</strong> Vælg "Spor", hvis filen er optaget med en GPS-enhed og indeholder tidsstempler — så vises tid og hastighed på rutens side. Vælg "Rute", hvis det er en planlagt/tegnet rute — den viser ikke tid, selv hvis filen skulle indeholde tidsstempler.</p>

        <p><strong>Offentlig eller privat?</strong> Offentlige ruter kan findes af alle via søgning og oversigten på forsiden. Private ruter kan kun du se — med mindre du opretter et <strong>del-link</strong> til den enkelte rute (knappen "Del-link" blandt rutens værktøjer).</p>

        <p><strong>Rutens værktøjer:</strong></p>
        <ul>
            <li><strong>Vis rute på kort</strong> åbner rutens egen side — den, andre ser, hvis ruten er offentlig eller delt med et link.</li>
            <li><strong>Rediger i GPX-editor</strong> åbner editoren, hvor du flytter punkter og indsætter waypoints med billeder og kommentarer.</li>
            <li><strong>Rediger metadata</strong> ændrer titel, beskrivelse, aktivitet, type (rute/spor), synlighed og download-tilladelse uden at åbne editoren.</li>
            <li><strong>Visning og datafelter</strong> vælger, hvad rutens side viser (fx højdeprofil, hastighed og puls). Her kan du også slette et felts data fra filen, fx tidsstempler eller højdedata, og hente højdedata fra internettet.</li>
            <li><strong>Udtynd punkter</strong> gør filen mindre ved at beholde færre punkter. Knappen findes kun for ruter med tidsstempler.</li>
            <li><strong>Grupper</strong> sætter ruten i en gruppe (se nedenfor).</li>
            <li><strong>Del-link</strong> (kun private ruter), <strong>Download GPX-fil</strong> og <strong>Slet</strong>.</li>
            <li><strong>Gendan original fil</strong> vises kun, hvis ruten er blevet udtyndet — den henter den oprindelige, fulde fil tilbage og kasserer ændringer, du har lavet siden.</li>
        </ul>

        <p><strong>Grupper:</strong> En gruppe samler flere ruter (etaper) til én tur, fx en flerdagestur. Sæt kryds ved ruterne i listen og tryk "Gruppér valgte", brug "Grupper" på en enkelt rute, eller vælg flere GPX-filer på én gang, når du uploader. Under fanen Grupper vælger du en gruppe for at åbne den med "Vis gruppe på kort" eller ændre den med "Rediger gruppe". Etaperne vises som én tur på forsiden, med "Forrige" og "Næste" på hver etape. Synligheden bestemmer du stadig pr. rute, men under "Rediger gruppe" kan du gøre hele gruppen offentlig eller privat med ét klik.</p>

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

        <dt>Lagerplads</dt>
        <dd>
            <?= number_format($user['storage_used_bytes'] / 1024 / 1024, 1) ?> MB
            / <?= number_format($user['storage_quota_bytes'] / 1024 / 1024, 1) ?> MB
        </dd>
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
        <a href="export-data.php">Download alle mine data</a>
        <a href="delete-account.php" class="danger">Slet min konto</a>
    </nav>
</dialog>

<?php
function dash_h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
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

function render_route_item(array $route): void
{
    $key = 'route:' . $route['public_id'];
    ?>
    <li data-item="<?= dash_h($key) ?>">
        <input type="checkbox" class="route-select" data-select-route="<?= dash_h($route['public_id']) ?>" aria-label="Vælg <?= dash_h($route['title']) ?> til gruppering">
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

        <div class="detail-map" aria-label="Kort over ruten"<?= !empty($route['activity_icon']) ? ' data-activity-icon="' . dash_h($route['activity_icon']) . '"' : '' ?>></div>

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
                <button type="button" class="tool" data-edit-meta="<?= dash_h($id) ?>">Rediger metadata</button>
                <?= dash_help_btn('meta', 'Rediger metadata') ?>
            </div>
            <div class="tool-row">
                <button type="button" class="tool" data-tool-fields="<?= dash_h($id) ?>" data-route-title="<?= dash_h($title) ?>">Visning og datafelter</button>
                <?= dash_help_btn('fields', 'Visning og datafelter') ?>
            </div>
            <?php if (!empty($route['has_time_data'])): ?>
                <div class="tool-row">
                    <button type="button" class="tool" data-tool-thin="<?= dash_h($id) ?>" data-route-title="<?= dash_h($title) ?>">Udtynd punkter</button>
                    <?= dash_help_btn('thin', 'Udtynd punkter') ?>
                </div>
            <?php endif; ?>
            <div class="tool-row">
                <button type="button" class="tool" data-add-to-group="<?= dash_h($id) ?>" data-route-title="<?= dash_h($title) ?>">Grupper</button>
                <?= dash_help_btn('group', 'Grupper') ?>
            </div>
            <?php if ($route['visibility'] === 'private'): ?>
                <div class="tool-row">
                    <button type="button" class="tool" data-share-link="<?= dash_h($id) ?>" data-route-title="<?= dash_h($title) ?>" data-has-token="<?= $route['share_token'] !== null ? '1' : '0' ?>">Del-link</button>
                    <?= dash_help_btn('share', 'Del-link') ?>
                </div>
            <?php endif; ?>
            <div class="tool-row">
                <a class="tool" href="route-file.php?id=<?= dash_h($id) ?>&amp;mode=download">Download GPX-fil</a>
                <?= dash_help_btn('download', 'Download GPX-fil') ?>
            </div>
            <?php if ($route['original_gpx_file_path'] !== null): ?>
                <div class="tool-row">
                    <button type="button" class="tool" data-restore-original="<?= dash_h($id) ?>" data-route-title="<?= dash_h($title) ?>">Gendan original fil</button>
                    <?= dash_help_btn('restore', 'Gendan original fil') ?>
                </div>
            <?php endif; ?>
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
            <button type="button" class="tool" data-edit-group="<?= dash_h($id) ?>">Rediger gruppe (titel, rækkefølge, synlighed)</button>
        </div>
    </section>
    <?php
}

$ruteRoutes = array_filter($routes, static fn(array $r): bool => $r['route_type'] !== 'spor');
$sporRoutes = array_filter($routes, static fn(array $r): bool => $r['route_type'] === 'spor');

?>
<div class="container dashboard-container">

    <div class="hub" id="hub" data-tile-max-zoom="<?= (int) TILE_MAX_ZOOM ?>">
        <div class="hub-list card">
            <div class="tabs">
                <button type="button" class="tab-btn active" data-tab-target="tab-alle">Alle (<?= count($routes) ?>)</button>
                <button type="button" class="tab-btn" data-tab-target="tab-spor">Spor (<?= count($sporRoutes) ?>)</button>
                <button type="button" class="tab-btn" data-tab-target="tab-rute">Ruter (<?= count($ruteRoutes) ?>)</button>
                <button type="button" class="tab-btn" data-tab-target="tab-grupper">Grupper (<?= count($groups) ?>)</button>
            </div>

            <div id="tab-alle" class="tab-panel">
                <?php if (empty($routes)): ?>
                    <p class="empty-state">Du har endnu ikke oprettet nogen ruter eller spor.</p>
                <?php else: ?>
                    <ul class="hub-items">
                        <?php foreach ($routes as $route): render_route_item($route); endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <div id="tab-spor" class="tab-panel" hidden>
                <?php if (empty($sporRoutes)): ?>
                    <p class="empty-state">Du har endnu ikke uploadet nogen spor.</p>
                <?php else: ?>
                    <ul class="hub-items">
                        <?php foreach ($sporRoutes as $route): render_route_item($route); endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <div id="tab-rute" class="tab-panel" hidden>
                <?php if (empty($ruteRoutes)): ?>
                    <p class="empty-state">Du har endnu ikke oprettet nogen ruter.</p>
                <?php else: ?>
                    <ul class="hub-items">
                        <?php foreach ($ruteRoutes as $route): render_route_item($route); endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <div id="tab-grupper" class="tab-panel" hidden>
                <?php if (empty($groups)): ?>
                    <p class="empty-state">Du har ingen grupper endnu. Sæt kryds ved to eller flere ruter og tryk "Gruppér valgte", eller vælg "Grupper" på en rute — fx en flerdagestur.</p>
                <?php else: ?>
                    <ul class="hub-items">
                        <?php foreach ($groups as $g): render_group_item($g); endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>

        <div class="hub-detail card">
            <button type="button" id="hub-back" class="link-btn hub-back">&larr; Tilbage til listen</button>

            <p id="hub-placeholder" class="empty-state">Vælg en rute, et spor eller en gruppe i listen for at se værktøjerne.</p>

            <?php foreach ($routes as $route): render_route_detail($route); endforeach; ?>
            <?php foreach ($groups as $g): render_group_detail($g); endforeach; ?>
        </div>
    </div>

</div>

<dialog id="tool-help-dialog" class="modal-dialog">
    <button type="button" id="tool-help-close" class="modal-close" aria-label="Luk">&times;</button>
    <h2 id="tool-help-title"></h2>
    <div id="tool-help-body"></div>
</dialog>

<dialog id="tool-thin-dialog" class="modal-dialog">
    <button type="button" id="tool-thin-close" class="modal-close" aria-label="Luk">&times;</button>
    <h2>Udtynd punkter</h2>
    <p><strong id="tool-thin-title"></strong></p>

    <div id="tool-thin-errors" class="modal-errors" hidden></div>

    <p class="hint">Behold højst ét punkt pr. valgt antal sekunder — nyttigt, hvis GPS-enheden har logget meget hyppigt. Første gang gemmes den oprindelige fil, så den kan gendannes.</p>
    <div class="tool-thin-buttons">
        <button type="button" class="btn" data-thin-interval="10">10 sek.</button>
        <button type="button" class="btn" data-thin-interval="20">20 sek.</button>
        <button type="button" class="btn" data-thin-interval="30">30 sek.</button>
        <button type="button" class="btn" data-thin-interval="60">60 sek.</button>
    </div>
    <p id="tool-thin-status" class="hint" hidden></p>
</dialog>

<dialog id="tool-fields-dialog" class="modal-dialog tool-wide">
    <button type="button" id="tool-fields-close" class="modal-close" aria-label="Luk">&times;</button>
    <h2>Visning og datafelter</h2>
    <p><strong id="tool-fields-title"></strong></p>

    <div id="tool-fields-errors" class="modal-errors" hidden></div>

    <div id="tool-fields-body" hidden>
        <div class="data-fields-tabs" role="tablist">
            <button type="button" class="data-fields-tab active" data-fields-tab="functions">Visning</button>
            <button type="button" class="data-fields-tab" data-fields-tab="fields">Felter</button>
        </div>
        <div data-fields-panel="functions">
            <p class="hint">Indbyggede visninger på rutens side, ud fra hvad GPX-filen indeholder.</p>
            <ul id="tool-functions-list" class="data-fields-list"></ul>
        </div>
        <div data-fields-panel="fields" hidden>
            <p class="hint">Alle datafelter i filen. Felter uden en indbygget visning vises som rå tal på rutens side, hvis de er afkrydset. 🗑 sletter et felts data fra selve filen, 🌐 henter højdedata fra internettet. Ændringer gemmes først, når du trykker Gem.</p>
            <ul id="tool-fields-list" class="data-fields-list"></ul>
        </div>
    </div>

    <p id="tool-fields-status" class="hint" hidden></p>
    <button type="button" id="tool-fields-save" class="btn btn-primary" disabled>💾 Gem</button>
</dialog>

<dialog id="edit-meta-dialog" class="modal-dialog">
    <button type="button" id="edit-meta-close" class="modal-close" aria-label="Luk">&times;</button>
    <h2>Rediger metadata</h2>

    <div id="edit-meta-errors" class="modal-errors" hidden></div>

    <form id="edit-meta-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="route_id" id="edit-meta-route-id">

        <label>
            Titel
            <input type="text" name="title" id="edit-meta-title" required maxlength="200">
        </label>

        <label>
            Beskrivelse
            <textarea name="description" id="edit-meta-description"></textarea>
        </label>

        <label>
            Aktivitetstype
            <select name="activity_type_id" id="edit-meta-activity">
                <?php foreach ($activityTypes as $type): ?>
                    <option value="<?= (int) $type['id'] ?>"><?= htmlspecialchars($type['name_da'], ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <?php if (!empty($groups)): ?>
        <label>
            Gruppe (etape-tur)
            <select name="group_id" id="edit-meta-group">
                <option value="">Ingen gruppe</option>
                <?php foreach ($groups as $g): ?>
                    <option value="<?= htmlspecialchars($g['public_id'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($g['title'], ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php endif; ?>

        <fieldset>
            <legend>Type</legend>
            <label class="checkbox-field">
                <input type="radio" name="route_type" value="rute" id="edit-meta-route-type-rute" checked>
                Rute (planlagt — viser ikke tid/hastighed)
            </label>
            <label class="checkbox-field">
                <input type="radio" name="route_type" value="spor" id="edit-meta-route-type-spor">
                Spor (GPS-optagelse — viser tid og hastighed)
            </label>
        </fieldset>

        <fieldset>
            <legend>Synlighed</legend>
            <label class="checkbox-field">
                <input type="radio" name="visibility" value="private" id="edit-meta-visibility-private">
                Privat (kun dig)
            </label>
            <label class="checkbox-field">
                <input type="radio" name="visibility" value="public" id="edit-meta-visibility-public">
                Offentlig (alle kan se den)
            </label>
        </fieldset>

        <p class="notice-field" id="edit-meta-public-notice" hidden>
            <label class="checkbox-field">
                <input type="checkbox" name="public_notice_ack" id="edit-meta-public-notice-ack">
                Jeg forstår at start-/slutpunkter i en offentlig rute kan afsløre min bopæl (GDPR).
            </label>
        </p>

        <label class="checkbox-field">
            <input type="checkbox" name="allow_download" id="edit-meta-allow-download">
            Tillad download af GPX-filen
        </label>

        <p class="notice-field" id="edit-meta-time-in-rute-notice" hidden>
            Bemærk: Du har tilladt download af denne rute. Hvis denne fil indeholder tidsstempler, ligger de stadig i
            den fil, andre kan downloade. Vil du undgå det, kan du fjerne dem under "Visning og datafelter" (fanen Felter).
        </p>

        <div id="edit-meta-anonymous" hidden>
            <label class="checkbox-field">
                <input type="checkbox" name="hide_uploader_name" id="edit-meta-hide-uploader-name">
                Vær anonym som uploader (skjul mit navn)
            </label>
        </div>

        <button type="submit" class="btn btn-primary">Gem ændringer</button>
    </form>
</dialog>

<dialog id="upload-dialog" class="modal-dialog">
    <button type="button" id="upload-dialog-close" class="modal-close" aria-label="Luk">&times;</button>
    <h2>Upload GPX-fil(er)</h2>

    <div id="upload-errors" class="modal-errors" hidden></div>

    <div id="upload-speed-warning" hidden>
        <p id="upload-speed-warning-text"></p>
        <div class="dialog-nav">
            <button type="button" id="upload-speed-keep" class="btn">Behold tiderne alligevel</button>
            <button type="button" id="upload-speed-strip" class="btn">Fjern alle tidsstempler</button>
            <button type="button" id="upload-speed-cancel" class="btn">Annullér</button>
        </div>
    </div>

    <div id="upload-thinning-suggest" hidden>
        <p id="upload-thinning-suggest-text"></p>
        <div class="dialog-nav">
            <button type="button" class="btn" data-upload-thin-interval="10">Udtynd til hvert 10. sekund</button>
            <button type="button" class="btn" data-upload-thin-interval="20">Udtynd til hvert 20. sekund</button>
            <button type="button" class="btn" data-upload-thin-interval="30">Udtynd til hvert 30. sekund</button>
            <button type="button" class="btn" data-upload-thin-interval="60">Udtynd til hvert 60. sekund</button>
            <button type="button" id="upload-thinning-keep" class="btn">Behold som den er</button>
            <button type="button" id="upload-thinning-cancel" class="btn">Annullér</button>
        </div>
    </div>

    <form id="upload-form">
        <input type="hidden" name="csrf_token" id="upload-csrf-token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">

        <label>
            GPX-fil(er)
            <input type="file" name="gpx_file" id="upload-gpx-file" accept=".gpx" multiple required>
        </label>
        <p class="hint" id="upload-multi-hint">Vælg flere filer for at oprette dem som etaper i en ny gruppe (fx en flerdagestur).</p>

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
                    <option value="<?= (int) $type['id'] ?>"><?= htmlspecialchars($type['name_da'], ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
        </label>

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
            den fil, andre kan downloade. Vil du undgå det, kan du efter upload fjerne dem under "Visning og datafelter" (fanen Felter).
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
            <input type="text" name="confirm_title" id="delete-route-confirm-title" required autocomplete="off">
        </label>

        <button type="submit" class="btn btn-danger" id="delete-route-submit-btn" disabled>Slet ruten permanent</button>
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
        <button type="submit" class="btn btn-primary">Gem titel og beskrivelse</button>
    </form>

    <div class="dialog-section">
        <h3>Etaper</h3>
        <p class="hint">Træk i etaperne (eller brug pilene) for at ændre rækkefølgen. Fjern en etape fra gruppen via "Rediger metadata" på ruten.</p>
        <ol id="group-order-list" class="group-order-list"></ol>
        <div class="inline-edit-actions">
            <button type="button" id="group-order-save" class="btn btn-primary">Gem rækkefølge</button>
            <button type="button" id="group-order-sort-time" class="btn">Sortér efter tid</button>
        </div>
    </div>

    <div class="dialog-section">
        <h3>Synlighed</h3>
        <p class="hint">Sætter alle etaperne på én gang. Du kan stadig ændre hver etape for sig.</p>
        <p class="notice-field">
            <label class="checkbox-field">
                <input type="checkbox" id="group-vis-ack">
                Jeg forstår at start-/slutpunkter i en offentlig rute kan afsløre min bopæl (GDPR).
            </label>
        </p>
        <div class="inline-edit-actions">
            <button type="button" id="group-make-public" class="btn">Gør hele gruppen offentlig</button>
            <button type="button" id="group-make-private" class="btn">Gør hele gruppen privat</button>
        </div>
    </div>

    <div class="dialog-section">
        <button type="button" id="group-dissolve" class="link-btn danger">Opløs gruppen (ruterne bevares)</button>
    </div>
</dialog>

<script src="vendor/leaflet/leaflet.js"></script>
<script src="vendor/sortablejs/Sortable.min.js"></script>
<script src="assets/route-meta-dialog.js?v=2"></script>
<script src="assets/gpx-field-labels.js?v=3"></script>
<script src="assets/gpx-tools.js?v=1"></script>
<script src="assets/dashboard.js?v=7"></script>
<script src="assets/dashboard-tools.js?v=2"></script>
<script src="assets/activity-marker.js?v=1"></script>
<script src="assets/dashboard-map.js?v=5"></script>

</body>
</html>
