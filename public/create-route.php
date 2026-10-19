<?php
declare(strict_types=1);

// =====================================================================
// Modtager GPX-indhold fra jeres online-editor og opretter en rute.
// Kaldes fra editorens "Gem"-knap, fx via fetch():
//
//   fetch('create-route.php', {
//       method: 'POST',
//       body: new URLSearchParams({
//           csrf_token: csrfToken,       // hent fra en <meta>/hidden-felt på editor-siden
//           gpx_data: gpxXmlString,      // editorens GPX-output som tekst
//           title: 'Min tur',
//           description: '',
//           activity_type_id: '1',
//           visibility: 'private',
//       }),
//   })
//   .then(r => r.json())
//   .then(data => { if (data.success) location.href = 'route-view.php?id=' + data.route_id; });
//
// Editor-siden, der kalder dette endpoint, skal selv indeholde et
// gyldigt CSRF-token — indsæt fx <?= csrf_token() ?> i editor-sidens
// PHP, ligesom i de andre formularer.
// =====================================================================

session_start();

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/route_creation.php';

header('Content-Type: application/json; charset=UTF-8');

function json_fail(array $errors, int $httpCode = 400): never
{
    http_response_code($httpCode);
    echo json_encode(['success' => false, 'errors' => $errors]);
    exit;
}

if (empty($_SESSION['user_id'])) {
    json_fail(['Du skal være logget ind.'], 401);
}

if (!csrf_validate($_POST['csrf_token'] ?? null)) {
    json_fail(['Ugyldig eller udløbet session. Genindlæs siden.'], 403);
}

$user = current_user((int) $_SESSION['user_id']);
if ($user === null) {
    json_fail(['Kontoen findes ikke længere.'], 401);
}

$gpxContent     = (string) ($_POST['gpx_data'] ?? '');
$title          = (string) ($_POST['title'] ?? '');
$description    = (string) ($_POST['description'] ?? '');
$activityTypeId = (int) ($_POST['activity_type_id'] ?? 0);
$visibility     = (string) ($_POST['visibility'] ?? 'private');
$allowDownload  = isset($_POST['allow_download']) && $_POST['allow_download'] !== '0' && $_POST['allow_download'] !== '';
$publicAck      = isset($_POST['public_notice_ack']) && $_POST['public_notice_ack'] !== '0' && $_POST['public_notice_ack'] !== '';

if ($gpxContent === '') {
    json_fail(['Ingen GPX-data modtaget fra editoren.']);
}

if (strlen($gpxContent) > MAX_UPLOAD_SIZE_BYTES) {
    json_fail(['Ruten er for stor (maks ' . (MAX_UPLOAD_SIZE_BYTES / 1024 / 1024) . ' MB).']);
}

$pdo = get_db();

$result = create_route(
    $pdo,
    $user,
    $gpxContent,
    $title,
    $description,
    $activityTypeId,
    $visibility,
    $allowDownload,
    $publicAck
);

if (!$result['success']) {
    json_fail($result['errors']);
}

echo json_encode(['success' => true, 'route_id' => $result['public_id']]);
