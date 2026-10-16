<?php
declare(strict_types=1);

// =====================================================================
// Samlet gem-endpoint for den kombinerede editor. Kaldes via fetch():
//
//   fetch('editor-save.php', {
//       method: 'POST',
//       body: new URLSearchParams({
//           csrf_token: csrfToken,
//           route_id: routeId,           // tom streng = opret ny rute
//           gpx_data: gpxXmlString,
//           time_resolution: 'ask',      // 'ask' | 'keep' | 'strip'
//           // Metadata KUN ved oprettelse (route_id tom):
//           title: 'Min tur',
//           description: '',
//           activity_type_id: '1',
//           visibility: 'private',
//           allow_download: '1',         // udelades/valgfri
//           public_notice_ack: '1',      // kun nødvendigt første gang
//       }),
//   }).then(r => r.json());
//
// Ved en EKSISTERENDE rute (route_id sat) udelader editoren metadata:
// hvis 'title' ikke er med i POST, bruges de værdier, der allerede ligger
// i databasen (titel, beskrivelse, aktivitet, type, synlighed, download,
// anonym) — metadata ændres i kontrolpanelet, ikke i editoren. Gruppe
// røres kun, hvis 'group_id' sendes med.
//
// Svar ved usandsynlig hastighed: HTTP 409 med
//   { success: false, speed_warning: true, max_speed_kmh, threshold_kmh }
// — editoren skal da spørge brugeren og gensende med time_resolution
// sat til 'keep' eller 'strip' (gpx_data er allerede i browseren, så
// intet andet skal sendes med igen).
// =====================================================================

session_start();

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/route_creation.php';
require_once __DIR__ . '/../app/route_update.php';
require_once __DIR__ . '/../app/route_groups.php';
require_once __DIR__ . '/../app/markers.php';

header('Content-Type: application/json; charset=UTF-8');

function json_fail_editor(array $errors, int $httpCode = 400): never
{
    http_response_code($httpCode);
    echo json_encode(['success' => false, 'errors' => $errors]);
    exit;
}

if (empty($_SESSION['user_id'])) {
    json_fail_editor(['Du skal være logget ind.'], 401);
}

if (!csrf_validate($_POST['csrf_token'] ?? null)) {
    json_fail_editor(['Ugyldig eller udløbet session. Genindlæs siden.'], 403);
}

$user = current_user((int) $_SESSION['user_id']);
if ($user === null) {
    json_fail_editor(['Kontoen findes ikke længere.'], 401);
}

$routePublicId  = (string) ($_POST['route_id'] ?? '');
$gpxContent     = (string) ($_POST['gpx_data'] ?? '');
$title          = (string) ($_POST['title'] ?? '');
$description    = (string) ($_POST['description'] ?? '');
$activityTypeId = (int) ($_POST['activity_type_id'] ?? 0);
$routeType = (string) ($_POST['route_type'] ?? 'rute');
$visibility     = (string) ($_POST['visibility'] ?? 'private');
$allowDownload  = isset($_POST['allow_download']) && $_POST['allow_download'] !== '0' && $_POST['allow_download'] !== '';
$hideUploaderName = isset($_POST['hide_uploader_name']) && $_POST['hide_uploader_name'] !== '0' && $_POST['hide_uploader_name'] !== '';
$publicAck      = isset($_POST['public_notice_ack']) && $_POST['public_notice_ack'] !== '0' && $_POST['public_notice_ack'] !== '';
$timeResolution = (string) ($_POST['time_resolution'] ?? 'ask');
$thinInterval = isset($_POST['thin_interval']) && $_POST['thin_interval'] !== '' ? (int) $_POST['thin_interval'] : null;
// Markør: kun hvis kalderen sender feltet med (upload-dialogen). Tom = aktivitetens standard.
$markerIcon = array_key_exists('marker_icon', $_POST) ? (string) $_POST['marker_icon'] : null;
if ($markerIcon !== null && !marker_is_valid($markerIcon)) {
    json_fail_editor(['Ukendt markør.']);
}

// Hvilke GPX-datafelter (puls, kadence, watt, osv.) ejeren har valgt
// skal kunne vises/bruges — sat via "Visning og datafelter" i
// kontrolpanelet. Sendes som en JSON-liste af feltnavne; null/tom = ikke rørt
// i denne gemning (update_route() beholder så den eksisterende værdi).
$enabledDataFields = null;
$enabledDataFieldsRaw = $_POST['enabled_data_fields'] ?? null;
if ($enabledDataFieldsRaw !== null && $enabledDataFieldsRaw !== '') {
    $decoded = json_decode((string) $enabledDataFieldsRaw, true);
    if (is_array($decoded)) {
        $clean = array_values(array_filter(array_map(
            static fn($v) => is_string($v) ? mb_substr($v, 0, 100) : null,
            $decoded
        )));
        $enabledDataFields = json_encode($clean);
    }
}

// Hvilke INDBYGGEDE VISNINGS-FUNKTIONER (højdeprofil, tid/hastighed,
// osv.) ejeren har valgt skal være aktive — adskilt fra ovenstående,
// som kun dækker rå felter uden en dedikeret funktion.
$enabledFunctions = null;
$enabledFunctionsRaw = $_POST['enabled_functions'] ?? null;
if ($enabledFunctionsRaw !== null && $enabledFunctionsRaw !== '') {
    $decoded = json_decode((string) $enabledFunctionsRaw, true);
    if (is_array($decoded)) {
        $clean = array_values(array_filter(array_map(
            static fn($v) => is_string($v) ? mb_substr($v, 0, 100) : null,
            $decoded
        )));
        $enabledFunctions = json_encode($clean);
    }
}

// settings_only=1 (kun for en EKSISTERENDE rute): GPX-filen røres ikke — kun
// de felter, der er sendt med (fx enabled_data_fields/enabled_functions fra
// kontrolpanelets datafelt-værktøj) opdateres.
$settingsOnly = (string) ($_POST['settings_only'] ?? '') === '1';

if ($settingsOnly && $routePublicId === '') {
    json_fail_editor(['Kun en eksisterende rute kan opdateres uden GPX-data.']);
}

if ($gpxContent === '' && !$settingsOnly) {
    json_fail_editor(['Ingen GPX-data modtaget fra editoren.']);
}

if (strlen($gpxContent) > MAX_UPLOAD_SIZE_BYTES) {
    json_fail_editor(['Ruten er for stor (maks ' . (MAX_UPLOAD_SIZE_BYTES / 1024 / 1024) . ' MB).']);
}

$pdo = get_db();

if ($routePublicId !== '') {
    $stmt = $pdo->prepare(
        "SELECT id FROM routes WHERE public_id = :public_id AND user_id = :user_id AND status = 'active' LIMIT 1"
    );
    $stmt->execute(['public_id' => $routePublicId, 'user_id' => $user['id']]);
    $internalRouteId = $stmt->fetchColumn();

    if ($internalRouteId === false) {
        json_fail_editor(['Ruten blev ikke fundet, eller tilhører dig ikke.'], 404);
    }

    // Editoren sender ikke metadata for en eksisterende rute — hent de
    // nuværende værdier, så update_route() kun ændrer selve GPX-filen.
    if (!array_key_exists('title', $_POST)) {
        $stmt = $pdo->prepare(
            'SELECT title, description, activity_type_id, route_type, visibility, allow_download, hide_uploader_name
             FROM routes WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $internalRouteId]);
        $current = $stmt->fetch();
        if (!$current) {
            json_fail_editor(['Ruten blev ikke fundet, eller tilhører dig ikke.'], 404);
        }

        $title            = (string) $current['title'];
        $description      = (string) ($current['description'] ?? '');
        $activityTypeId   = (int) $current['activity_type_id'];
        $routeType        = (string) $current['route_type'];
        $visibility       = (string) $current['visibility'];
        $allowDownload    = (bool) $current['allow_download'];
        $hideUploaderName = (bool) $current['hide_uploader_name'];
        // En allerede offentlig rute er tidligere bekræftet (GDPR-notitsen),
        // og synligheden ændres ikke her.
        $publicAck        = $visibility === 'public';
    }
} else {
    $internalRouteId = 0;
}

if ($internalRouteId > 0) {
    $result = update_route(
        $pdo,
        $user,
        (int) $internalRouteId,
        $settingsOnly ? null : $gpxContent,
        $title,
        $description,
        $activityTypeId,
        $visibility,
        $allowDownload,
        $publicAck,
        $timeResolution,
        $hideUploaderName,
        $thinInterval,
        $routeType,
        $enabledDataFields,
        $enabledFunctions
    );
} else {
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
        $timeResolution,
        $hideUploaderName,
        $thinInterval,
        $routeType,
        $enabledDataFields,
        $enabledFunctions
    );
}

if (!empty($result['speed_warning'])) {
    http_response_code(409);
    echo json_encode([
        'success'       => false,
        'speed_warning' => true,
        'max_speed_kmh' => $result['max_speed_kmh'],
        'threshold_kmh' => $result['threshold_kmh'],
    ]);
    exit;
}

if (!empty($result['thinning_suggested'])) {
    http_response_code(409);
    echo json_encode([
        'success'                   => false,
        'thinning_suggested'        => true,
        'detected_interval_seconds' => $result['detected_interval_seconds'],
    ]);
    exit;
}

if (!$result['success']) {
    json_fail_editor($result['errors']);
}

// Ved opdatering kender vi allerede public_id (det ændrer sig aldrig);
// ved oprettelse kommer det fra create_route()'s svar.
$responsePublicId = $result['public_id'] ?? $routePublicId;

if ($markerIcon !== null) {
    marker_save($pdo, (int) $result['route_id'], (int) $user['id'], $markerIcon);
}

// Gruppe (etape-tur): kun hvis kalderen sender group_id med (tom = ingen
// gruppe). Nye ruter lægges sidst i gruppen.
if (array_key_exists('group_id', $_POST)) {
    $groupResult = group_assign_route_by_public_ids($pdo, (int) $user['id'], (string) $responsePublicId, (string) $_POST['group_id']);
    if (!$groupResult['success']) {
        json_fail_editor($groupResult['errors']);
    }
}

echo json_encode(['success' => true, 'route_id' => $responsePublicId]);
