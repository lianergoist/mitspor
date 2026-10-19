<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/route_update.php';

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

    if (empty($errors)) {
        // Bestem om selve GPX-filen skal erstattes:
        // - en ny uploadet fil betyder "brugeren valgte at erstatte filen"
        // - ellers: null = kun metadata ændres, filen er urørt
        $gpxContent = null;

        if (isset($_FILES['gpx_file']) && $_FILES['gpx_file']['error'] !== UPLOAD_ERR_NO_FILE) {
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
                $hideUploaderName,
                null, // ingen udtynding
                $routeType
            );

            if ($result['success']) {
                header('Location: route-view.php?id=' . $route['public_id']);
                exit;
            }

            $errors = $result['errors'];
        }
    }
}


// Værdier til formularen: enten det brugeren lige har indsendt (ved fejl),
// eller de gemte værdier fra databasen (ved første visning).
$formTitle       = $_POST['title'] ?? $route['title'];
$formDescription = $_POST['description'] ?? $route['description'];
$formActivityId  = (int) ($_POST['activity_type_id'] ?? $route['activity_type_id']);
$formRouteType   = $_POST['route_type'] ?? $route['route_type'];
$formVisibility  = $_POST['visibility'] ?? $route['visibility'];
$formAllowDl = $_SERVER['REQUEST_METHOD'] === 'POST' ? isset($_POST['allow_download']) : (bool) $route['allow_download'];
$formAnonym  = $_SERVER['REQUEST_METHOD'] === 'POST' ? isset($_POST['hide_uploader_name']) : (bool) $route['hide_uploader_name'];
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

<h1>Rediger rute</h1>

<?php if (!empty($errors)): ?>
    <ul>
        <?php foreach ($errors as $error): ?>
            <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

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

</body>
</html>
