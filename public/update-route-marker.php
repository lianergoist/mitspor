<?php
declare(strict_types=1);

// Gemmer kun rutens markør ("Vælg markør" i kontrolpanelet). Titel,
// aktivitet osv. ændres i editorens "Rediger metadata" (update-route-metadata.php).

session_start();

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/markers.php';
require_once __DIR__ . '/../app/og_image.php';

header('Content-Type: application/json; charset=UTF-8');

function json_fail_marker(array $errors, int $httpCode = 400): never
{
    http_response_code($httpCode);
    echo json_encode(['success' => false, 'errors' => $errors]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_fail_marker(['Ugyldig forespørgsel.'], 405);
}

if (empty($_SESSION['user_id'])) {
    json_fail_marker(['Du skal være logget ind.'], 401);
}

if (!csrf_validate($_POST['csrf_token'] ?? null)) {
    json_fail_marker(['Din session er udløbet. Genindlæs siden og prøv igen.'], 403);
}

$user = current_user((int) $_SESSION['user_id']);
if ($user === null) {
    json_fail_marker(['Kontoen findes ikke længere.'], 401);
}

$routePublicId = (string) ($_POST['route_id'] ?? '');

$pdo = get_db();
$stmt = $pdo->prepare(
    "SELECT id FROM routes WHERE public_id = :public_id AND user_id = :user_id AND status = 'active' LIMIT 1"
);
$stmt->execute(['public_id' => $routePublicId, 'user_id' => $user['id']]);
$internalRouteId = $stmt->fetchColumn();

if ($internalRouteId === false) {
    json_fail_marker(['Ruten blev ikke fundet, eller tilhører dig ikke.'], 404);
}

// Tom værdi = aktivitetens standard.
$markerIcon = (string) ($_POST['marker_icon'] ?? '');
if (!marker_is_valid($markerIcon)) {
    json_fail_marker(['Ukendt markør.']);
}

marker_save($pdo, (int) $internalRouteId, (int) $user['id'], $markerIcon);

// Delingsbilledet viser markøren, så det laves igen (efter svaret er sendt).
og_refresh($pdo, $routePublicId);

echo json_encode(['success' => true]);
