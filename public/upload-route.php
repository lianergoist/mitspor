<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/route_creation.php';

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
        if (!isset($_FILES['gpx_file']) || $_FILES['gpx_file']['error'] === UPLOAD_ERR_NO_FILE) {
            $errors[] = 'Vælg en GPX-fil.';
        } elseif ($_FILES['gpx_file']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Filen kunne ikke uploades (fejlkode ' . $_FILES['gpx_file']['error'] . ').';
        } elseif ($_FILES['gpx_file']['size'] > MAX_UPLOAD_SIZE_BYTES) {
            $errors[] = 'Filen er for stor (maks ' . (MAX_UPLOAD_SIZE_BYTES / 1024 / 1024) . ' MB).';
        } elseif (!str_ends_with(strtolower($_FILES['gpx_file']['name']), '.gpx')) {
            $errors[] = 'Filen skal have endelsen .gpx.';
        }
    }

    if (empty($errors)) {
        if (!is_uploaded_file($_FILES['gpx_file']['tmp_name'])) {
            $errors[] = 'Ugyldig filupload.';
            $gpxContent = null;
        } else {
            $gpxContent = file_get_contents($_FILES['gpx_file']['tmp_name']);
        }

        if (empty($errors) && $gpxContent !== null) {
            $result = create_route(
                $pdo,
                $user,
                $gpxContent,
                $title,
                $description,
                $activityTypeId,
                $visibility,
                $allowDownload,
                $publicAck,
                $hideUploaderName,
                $routeType
            );

            if ($result['success']) {
                $success = true;
                header('Location: route-view.php?id=' . $result['public_id']);
                exit;
            }

            $errors = $result['errors'];
        }
    }
}

?>
<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/svg+xml" href="/grafik/favicon.svg">
    <link rel="icon" type="image/png" sizes="32x32" href="/grafik/favicon-32.png">
    <meta name="robots" content="noindex, nofollow">
    <title>Upload rute</title>
</head>
<body>

<h1>Upload en GPX-fil</h1>

<?php if (!empty($errors)): ?>
    <ul>
        <?php foreach ($errors as $error): ?>
            <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php if ($success): ?>

    <p>Ruten er oprettet.</p>

<?php else: ?>

    <form method="post" action="upload-route.php" enctype="multipart/form-data" id="upload-form">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">

    <label>
        GPX-fil
        <input type="file" name="gpx_file" accept=".gpx" required>
    </label>

    <label>
        Titel
        <input type="text" name="title" required maxlength="200"
               value="<?= htmlspecialchars($_POST['title'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
    </label>

    <label>
        Beskrivelse (valgfri)
        <textarea name="description"><?= htmlspecialchars($_POST['description'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
    </label>

    <label>
        Aktivitetstype
        <select name="activity_type_id" required>
            <option value="">Vælg...</option>
            <?php foreach ($activityTypes as $type): ?>
                <option value="<?= (int) $type['id'] ?>">
                    <?= htmlspecialchars($type['name_da'], ENT_QUOTES, 'UTF-8') ?>
                </option>
            <?php endforeach; ?>
        </select>
    </label>

    <fieldset>
        <legend>Type</legend>
        <label>
            <input type="radio" name="route_type" value="rute" id="route-type-rute" checked> Rute (planlagt — viser ikke tid/hastighed)
        </label>
        <label>
            <input type="radio" name="route_type" value="spor" id="route-type-spor"> Spor (GPS-optagelse — viser tid og hastighed)
        </label>
    </fieldset>

    <fieldset>
        <legend>Synlighed</legend>
        <label>
            <input type="radio" name="visibility" value="private" id="visibility-private" checked> Privat (kun dig)
        </label>
        <label>
            <input type="radio" name="visibility" value="public" id="visibility-public"> Offentlig (alle kan se den)
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
            <input type="checkbox" name="hide_uploader_name">
            Vær anonym som uploader (skjul mit navn)
        </label>
    </p>

    <script>
        (function () {
            var noticeField = document.getElementById('public-notice-field');
            var anonField = document.getElementById('anonymous-field');
            var publicRadio = document.getElementById('visibility-public');
            var privateRadio = document.getElementById('visibility-private');
            var form = document.getElementById('upload-form');

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
                        alert('Husk at krydse boksen af om, at du forstår at start-/slutpunkter kan afsløre din bopæl (GDPR), før du kan uploade ruten som offentlig.');
                    }
                }
            });
        })();
    </script>

    <label>
        <input type="checkbox" name="allow_download" id="allow-download-checkbox">
        Tillad at andre kan downloade selve GPX-filen (gælder kun hvis ruten er offentlig)
    </label>

    <p id="time-in-rute-notice" hidden>
        Denne fil indeholder tidsstempler, men er markeret som en <strong>Rute</strong>, og download er tilladt —
        tidsstemplerne ligger derfor stadig i den fil, andre kan downloade, selvom de ikke vises på rutens side.
        Vil du undgå det, kan du fjerne dem bagefter i kontrolpanelet under "Slet datafelter".
    </p>

    <script>
        (function () {
            var fileInput = document.querySelector('input[name="gpx_file"]');
            var routeTypeRute = document.getElementById('route-type-rute');
            var routeTypeSpor = document.getElementById('route-type-spor');
            var allowDownload = document.getElementById('allow-download-checkbox');
            var notice = document.getElementById('time-in-rute-notice');
            var fileHasTimeData = false;
            var userChoseType = false;

            [routeTypeRute, routeTypeSpor].forEach(function (r) {
                r.addEventListener('change', function () { userChoseType = true; updateNotice(); });
            });
            allowDownload.addEventListener('change', updateNotice);

            function updateNotice() {
                notice.hidden = !(routeTypeRute.checked && allowDownload.checked && fileHasTimeData);
            }

            fileInput.addEventListener('change', function () {
                if (!fileInput.files[0]) return;
                var reader = new FileReader();
                reader.onload = function () {
                    fileHasTimeData = /<time[>\s]/.test(reader.result);
                    // Foreslå kun en type — rør ikke ved det, hvis brugeren
                    // allerede selv har valgt.
                    if (!userChoseType) {
                        routeTypeSpor.checked = fileHasTimeData;
                        routeTypeRute.checked = !fileHasTimeData;
                    }
                    updateNotice();
                };
                reader.readAsText(fileInput.files[0]);
            });
        })();
    </script>

    <button type="submit">Upload</button>
</form>

<?php endif; ?>

</body>
</html>
