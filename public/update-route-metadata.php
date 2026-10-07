<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/route_update.php';
require_once __DIR__ . '/../app/route_groups.php';

header('Content-Type: application/json; charset=UTF-8');

function json_fail_meta(array $errors, int $httpCode = 400): never
{
    http_response_code($httpCode);
    echo json_encode(['success' => false, 'errors' => $errors]);
    exit;
}

if (empty($_SESSION['user_id'])) {
    json_fail_meta(['Du skal være logget ind.'], 401);
}

if (!csrf_validate($_POST['csrf_token'] ?? null)) {
    json_fail_meta(['Din session er udløbet. Genindlæs siden og prøv igen.'], 403);
}

$user = current_user((int) $_SESSION['user_id']);
if ($user === null) {
    json_fail_meta(['Kontoen findes ikke længere.'], 401);
}

$routePublicId = (string) ($_POST['route_id'] ?? '');

$pdo = get_db();
$stmt = $pdo->prepare(
    "SELECT id FROM routes WHERE public_id = :public_id AND user_id = :user_id AND status = 'active' LIMIT 1"
);
$stmt->execute(['public_id' => $routePublicId, 'user_id' => $user['id']]);
$internalRouteId = $stmt->fetchColumn();

if ($internalRouteId === false) {
    json_fail_meta(['Ruten blev ikke fundet, eller tilhører dig ikke.'], 404);
}

$result = update_route(
    $pdo,
    $user,
    (int) $internalRouteId,
    null, // ingen GPX-erstatning — kun metadata
    (string) ($_POST['title'] ?? ''),
    (string) ($_POST['description'] ?? ''),
    (int) ($_POST['activity_type_id'] ?? 0),
    (string) ($_POST['visibility'] ?? 'private'),
    isset($_POST['allow_download']) && $_POST['allow_download'] !== '0' && $_POST['allow_download'] !== '',
    isset($_POST['public_notice_ack']) && $_POST['public_notice_ack'] !== '0' && $_POST['public_notice_ack'] !== '',
    'ask',
    isset($_POST['hide_uploader_name']) && $_POST['hide_uploader_name'] !== '0' && $_POST['hide_uploader_name'] !== '',
    null,
    (string) ($_POST['route_type'] ?? 'rute')
);

if (!$result['success']) {
    json_fail_meta($result['errors']);
}

// Gruppe (etape-tur): kun hvis formularen har et gruppe-felt med.
// Tom værdi = ingen gruppe.
if (array_key_exists('group_id', $_POST)) {
    $groupResult = group_assign_route_by_public_ids($pdo, (int) $user['id'], $routePublicId, (string) $_POST['group_id']);
    if (!$groupResult['success']) {
        json_fail_meta($groupResult['errors']);
    }
}

echo json_encode(['success' => true]);
