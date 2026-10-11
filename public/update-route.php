<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/route_update.php';
require_once __DIR__ . '/../app/app_tilbage.php';

$userId = require_login();
$user = current_user($userId);

if ($user === null) {
    $_SESSION = [];
    session_destroy();
    header('Location: login.php');
    exit;
}

$routePublicId = (string) ($_GET['id'] ?? $_POST['route_id'] ?? '');

$pdo = get_db();
$activityTypes = $pdo->query(
    'SELECT id, name_da FROM activity_types WHERE is_active = 1 ORDER BY (slug IN (\'water_other\',\'land_other\')) DESC, name_da ASC'
)->fetchAll();

$stmt = $pdo->prepare(
    "SELECT id, public_id, title, description, activity_type_id, route_type, visibility, allow_download, hide_uploader_name
     FROM routes
     WHERE public_id = :public_id AND user_id = :user_id AND status = 'active'
     LIMIT 1"
);
$stmt->execute(['public_id' => $routePublicId, 'user_id' => $userId]);
$route = $stmt->fetch();

if (!$route) {
    http_response_code(404);
    echo 'Ruten blev ikke fundet, eller tilhører dig ikke.';
    exit;
}

$errors = [];
$success = false;
$speedWarning = null;
$thinningSuggest = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Din session er udløbet. Genindlæs siden og prøv igen.';
    }

    $title          = (string) ($_POST['title'] ?? '');
    $description    = (string) ($_POST['description'] ?? '');
    $activityTypeId = (int) ($_POST['activity_type_id'] ?? 0);
    $routeType      = (string) ($_POST['route_type'] ?? 'rute');
    $visibility     = (string) ($_POST['visibility'] ?? 'private');
    $allowDownload  = isset($_POST['allow_download']);
    $hideUploaderName = isset($_POST['hide_uploader_name']);
    $publicAck      = isset($_POST['public_notice_ack']);
    $timeResolution = (string) ($_POST['time_resolution'] ?? 'ask');
    $thinInterval   = isset($_POST['thin_interval']) && $_POST['thin_interval'] !== '' ? (int) $_POST['thin_interval'] : null;
    $gpxContentOverride = (string) ($_POST['gpx_content_override'] ?? '');

    if (empty($errors)) {
        // Bestem om selve GPX-filen skal erstattes:
        // - gpx_content_override betyder "resubmit efter speed_warning"
        // - en ny uploadet fil betyder "brugeren valgte at erstatte filen"
        // - ellers: null = kun metadata ændres, filen er urørt
        $gpxContent = null;

        if ($gpxContentOverride !== '') {
            $gpxContent = $gpxContentOverride;
        } elseif (isset($_FILES['gpx_file']) && $_FILES['gpx_file']['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($_FILES['gpx_file']['error'] !== UPLOAD_ERR_OK) {
                $errors[] = 'Filen kunne ikke uploades (fejlkode ' . $_FILES['gpx_file']['error'] . ').';
            } elseif ($_FILES['gpx_file']['size'] > MAX_UPLOAD_SIZE_BYTES) {
                $errors[] = 'Filen er for stor (maks ' . (MAX_UPLOAD_SIZE_BYTES / 1024 / 1024) . ' MB).';
            } elseif (!str_ends_with(strtolower($_FILES['gpx_file']['name']), '.gpx')) {
                $errors[] = 'Filen skal have endelsen .gpx.';
            } elseif (!is_uploaded_file($_FILES['gpx_file']['tmp_name'])) {
                $errors[] = 'Ugyldig filupload.';
            } else {
                $gpxContent = file_get_contents($_FILES['gpx_file']['tmp_name']);
            }
        }

        if (empty($errors)) {
            $result = update_route(
                $pdo,
                $user,
                (int) $route['id'],
                $gpxContent,
                $title,
                $description,
                $activityTypeId,
                $visibility,
                $allowDownload,
                $publicAck,
                $timeResolution,
                $hideUploaderName,
                $thinInterval,
                $routeType
            );

            if ($result['success']) {
                header('Location: route-view.php?id=' . $route['public_id']);
                exit;
            }

            if (!empty($result['speed_warning'])) {
                $speedWarning = [
                    'max_speed_kmh'   => $result['max_speed_kmh'],
                    'threshold_kmh'   => $result['threshold_kmh'],
                    'gpx_content'     => $gpxContent,
                    'title'           => $title,
                    'description'     => $description,
                    'activity_type_id' => $activityTypeId,
                    'route_type'      => $routeType,
                    'visibility'      => $visibility,
                    'allow_download'  => $allowDownload,
                    'hide_uploader_name' => $hideUploaderName,
                    'public_ack'      => $publicAck,
                ];
            } elseif (!empty($result['thinning_suggested'])) {
                $thinningSuggest = [
                    'detected_interval_seconds' => $result['detected_interval_seconds'],
                    'gpx_content'     => $gpxContent,
                    'title'           => $title,
                    'description'     => $description,
                    'activity_type_id' => $activityTypeId,
                    'route_type'      => $routeType,
                    'visibility'      => $visibility,
                    'allow_download'  => $allowDownload,
                    'hide_uploader_name' => $hideUploaderName,
                    'public_ack'      => $publicAck,
                ];
            } else {
                $errors = $result['errors'];
            }
        }
    }
}


// Værdier til formularen: enten det brugeren lige har indsendt (ved fejl),
// eller de gemte værdier fra databasen (ved første visning).
$formTitle       = $speedWarning['title'] ?? $_POST['title'] ?? $route['title'];
$formDescription = $speedWarning['description'] ?? $_POST['description'] ?? $route['description'];
$formActivityId  = $speedWarning['activity_type_id'] ?? (int) ($_POST['activity_type_id'] ?? $route['activity_type_id']);
$formRouteType   = $speedWarning['route_type'] ?? $_POST['route_type'] ?? $route['route_type'];
$formVisibility  = $speedWarning['visibility'] ?? $_POST['visibility'] ?? $route['visibility'];
$formAllowDl = $speedWarning['allow_download']
    ?? ($_SERVER['REQUEST_METHOD'] === 'POST' ? isset($_POST['allow_download']) : (bool) $route['allow_download']);
$formAnonym = $speedWarning['hide_uploader_name']
    ?? ($_SERVER['REQUEST_METHOD'] === 'POST' ? isset($_POST['hide_uploader_name']) : (bool) $route['hide_uploader_name']);
?>
<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/svg+xml" href="/grafik/favicon.svg">
    <link rel="icon" type="image/png" sizes="32x32" href="/grafik/favicon-32.png">
    <meta name="robots" content="noindex, nofollow">
    <title>Rediger rute</title>
</head>
<body>
<?php render_app_back_button(); ?>

<h1>Rediger rute</h1>

<?php if (!empty($errors)): ?>
    <ul>
        <?php foreach ($errors as $error): ?>
            <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php if ($speedWarning): ?>

    <h2>Usandsynlig hastighed fundet</h2>
    <p>
        Nogle af rutens tidsstempler antyder en hastighed på op til
        <strong><?= htmlspecialchars((string) $speedWarning['max_speed_kmh'], ENT_QUOTES, 'UTF-8') ?> km/t</strong>,
        hvilket er over den forventede grænse på
        <?= htmlspecialchars((string) $speedWarning['threshold_kmh'], ENT_QUOTES, 'UTF-8') ?> km/t
        for den valgte aktivitetstype. Vælg hvordan du vil fortsætte:
    </p>

    <form method="post" action="update-route.php?id=<?= htmlspecialchars($route['public_id'], ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="route_id" value="<?= htmlspecialchars($route['public_id'], ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="title" value="<?= htmlspecialchars($speedWarning['title'], ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="description" value="<?= htmlspecialchars($speedWarning['description'], ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="activity_type_id" value="<?= (int) $speedWarning['activity_type_id'] ?>">
        <input type="hidden" name="route_type" value="<?= htmlspecialchars($speedWarning['route_type'], ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="visibility" value="<?= htmlspecialchars($speedWarning['visibility'], ENT_QUOTES, 'UTF-8') ?>">
        <?php if ($speedWarning['allow_download']): ?>
            <input type="hidden" name="allow_download" value="1">
        <?php endif; ?>
        <?php if ($speedWarning['hide_uploader_name']): ?>
            <input type="hidden" name="hide_uploader_name" value="1">
        <?php endif; ?>
        <?php if ($speedWarning['public_ack']): ?>
            <input type="hidden" name="public_notice_ack" value="1">
        <?php endif; ?>
        <textarea name="gpx_content_override" style="display:none" readonly><?= htmlspecialchars($speedWarning['gpx_content'], ENT_QUOTES, 'UTF-8') ?></textarea>

        <button type="submit" name="time_resolution" value="keep">Behold tiderne alligevel</button>
        <button type="submit" name="time_resolution" value="strip">Fjern alle tidsstempler fra ruten</button>
    </form>

    <p><a href="update-route.php?id=<?= htmlspecialchars($route['public_id'], ENT_QUOTES, 'UTF-8') ?>">Annullér og start forfra</a></p>

<?php elseif ($thinningSuggest): ?>

    <h2>Mange punkter fundet</h2>
    <p>
        Ruten logger i gennemsnit hvert
        <strong><?= htmlspecialchars((string) $thinningSuggest['detected_interval_seconds'], ENT_QUOTES, 'UTF-8') ?> sekund</strong>.
        Det er ofte flere punkter, end der reelt er brug for. Vil du udtynde ruten, før den gemmes?
    </p>

    <form method="post" action="update-route.php?id=<?= htmlspecialchars($route['public_id'], ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="route_id" value="<?= htmlspecialchars($route['public_id'], ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="title" value="<?= htmlspecialchars($thinningSuggest['title'], ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="description" value="<?= htmlspecialchars($thinningSuggest['description'], ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="activity_type_id" value="<?= (int) $thinningSuggest['activity_type_id'] ?>">
        <input type="hidden" name="route_type" value="<?= htmlspecialchars($thinningSuggest['route_type'], ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="visibility" value="<?= htmlspecialchars($thinningSuggest['visibility'], ENT_QUOTES, 'UTF-8') ?>">
        <?php if ($thinningSuggest['allow_download']): ?>
            <input type="hidden" name="allow_download" value="1">
        <?php endif; ?>
        <?php if ($thinningSuggest['hide_uploader_name']): ?>
            <input type="hidden" name="hide_uploader_name" value="1">
        <?php endif; ?>
        <?php if ($thinningSuggest['public_ack']): ?>
            <input type="hidden" name="public_notice_ack" value="1">
        <?php endif; ?>
        <textarea name="gpx_content_override" style="display:none" readonly><?= htmlspecialchars($thinningSuggest['gpx_content'], ENT_QUOTES, 'UTF-8') ?></textarea>

        <button type="submit" name="thin_interval" value="10">Udtynd til hvert 10. sekund</button>
        <button type="submit" name="thin_interval" value="20">Udtynd til hvert 20. sekund</button>
        <button type="submit" name="thin_interval" value="30">Udtynd til hvert 30. sekund</button>
        <button type="submit" name="thin_interval" value="60">Udtynd til hvert 60. sekund</button>
        <button type="submit" name="thin_interval" value="0">Behold som den er</button>
    </form>

    <p><a href="update-route.php?id=<?= htmlspecialchars($route['public_id'], ENT_QUOTES, 'UTF-8') ?>">Annullér og start forfra</a></p>

<?php else: ?>

    <form method="post" action="update-route.php?id=<?= htmlspecialchars($route['public_id'], ENT_QUOTES, 'UTF-8') ?>" enctype="multipart/form-data" id="update-form">
        <input type="hidden" name="route_id" value="<?= htmlspecialchars($route['public_id'], ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">

        <label>
            Erstat GPX-fil (valgfrit — lad stå tom for kun at ændre titel/synlighed osv.)
            <input type="file" name="gpx_file" accept=".gpx">
        </label>

        <label>
            Titel
            <input type="text" name="title" required maxlength="200"
                   value="<?= htmlspecialchars($formTitle, ENT_QUOTES, 'UTF-8') ?>">
        </label>

        <label>
            Beskrivelse (valgfri)
            <textarea name="description"><?= htmlspecialchars($formDescription ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
        </label>

        <label>
            Aktivitetstype
            <select name="activity_type_id" required>
                <?php foreach ($activityTypes as $type): ?>
                    <option value="<?= (int) $type['id'] ?>" <?= ((int) $type['id'] === (int) $formActivityId) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($type['name_da'], ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <fieldset>
            <legend>Type</legend>
            <label>
                <input type="radio" name="route_type" value="rute" id="route-type-rute" <?= $formRouteType === 'rute' ? 'checked' : '' ?>>
                Rute (planlagt — viser ikke tid/hastighed)
            </label>
            <label>
                <input type="radio" name="route_type" value="spor" id="route-type-spor" <?= $formRouteType === 'spor' ? 'checked' : '' ?>>
                Spor (GPS-optagelse — viser tid og hastighed)
            </label>
        </fieldset>

        <fieldset>
            <legend>Synlighed</legend>
            <label>
                <input type="radio" name="visibility" value="private" id="visibility-private" <?= $formVisibility === 'private' ? 'checked' : '' ?>>
                Privat (kun dig)
            </label>
            <label>
                <input type="radio" name="visibility" value="public" id="visibility-public" <?= $formVisibility === 'public' ? 'checked' : '' ?>>
                Offentlig (alle kan se den)
            </label>
        </fieldset>

        <p id="public-notice-field">
            <label>
                <input type="checkbox" name="public_notice_ack">
                Jeg forstår at start-/slutpunkter i en offentlig rute kan afsløre min bopæl (GDPR).
            </label>
        </p>

        <p id="anonymous-field">
            <label>
                <input type="checkbox" name="hide_uploader_name" <?= $formAnonym ? 'checked' : '' ?>>
                Vær anonym som uploader (skjul mit navn)
            </label>
        </p>

        <script>
            (function () {
                var noticeField = document.getElementById('public-notice-field');
                var anonField = document.getElementById('anonymous-field');
                var publicRadio = document.getElementById('visibility-public');
                var privateRadio = document.getElementById('visibility-private');
                var form = document.getElementById('update-form');

                function update() {
                    noticeField.hidden = !publicRadio.checked;
                    anonField.hidden = !publicRadio.checked;
                }
                function handleChange() {
                    if (publicRadio.checked) {
                        var checkbox = noticeField.querySelector('input[name="public_notice_ack"]');
                        if (checkbox) checkbox.checked = false;
                    }
                    update();
                }
                publicRadio.addEventListener('change', handleChange);
                privateRadio.addEventListener('change', handleChange);
                update();

                form.addEventListener('submit', function (ev) {
                    if (publicRadio.checked) {
                        var checkbox = noticeField.querySelector('input[name="public_notice_ack"]');
                        if (checkbox && !checkbox.checked) {
                            ev.preventDefault();
                            alert('Husk at krydse boksen af om, at du forstår at start-/slutpunkter kan afsløre din bopæl (GDPR), før du kan gemme ruten som offentlig.');
                        }
                    }
                });
            })();
        </script>

        <label>
            <input type="checkbox" name="allow_download" id="allow-download-checkbox" <?= $formAllowDl ? 'checked' : '' ?>>
            Tillad at andre kan downloade selve GPX-filen (gælder kun hvis ruten er offentlig)
        </label>

        <p id="time-in-rute-notice" hidden>
            Bemærk: Hvis denne fil indeholder tidsstempler, ligger de stadig i den fil, andre kan downloade,
            selvom ruten er markeret som en <strong>Rute</strong> og derfor ikke viser dem. Vil du undgå det,
            kan du fjerne dem i kontrolpanelet under "Visning og datafelter" (fanen Felter).
        </p>

        <script>
            (function () {
                var routeTypeRute = document.getElementById('route-type-rute');
                var allowDownload = document.getElementById('allow-download-checkbox');
                var notice = document.getElementById('time-in-rute-notice');

                function updateNotice() {
                    notice.hidden = !(routeTypeRute.checked && allowDownload.checked);
                }
                document.querySelectorAll('input[name="route_type"]').forEach(function (r) {
                    r.addEventListener('change', updateNotice);
                });
                allowDownload.addEventListener('change', updateNotice);
                updateNotice();
            })();
        </script>

        <button type="submit">Gem ændringer</button>
    </form>

<?php endif; ?>

</body>
</html>
