<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/route_update.php';

header('Content-Type: application/json; charset=UTF-8');

function json_fail_restore(array $errors, int $httpCode = 400): never
{
    http_response_code($httpCode);
    echo json_encode(['success' => false, 'errors' => $errors]);
    exit;
}

if (empty($_SESSION['user_id'])) {
    json_fail_restore(['Du skal være logget ind.'], 401);
}

if (!csrf_validate($_POST['csrf_token'] ?? null)) {
    json_fail_restore(['Din session er udløbet. Genindlæs siden og prøv igen.'], 403);
}

$userId = (int) $_SESSION['user_id'];
$user = current_user($userId);
if ($user === null) {
    json_fail_restore(['Kontoen findes ikke længere.'], 401);
}

$routePublicId = (string) ($_POST['route_id'] ?? '');

$pdo = get_db();
$stmt = $pdo->prepare(
    "SELECT id, original_gpx_file_path, title, description, activity_type_id, visibility, allow_download, hide_uploader_name
     FROM routes
     WHERE public_id = :public_id AND user_id = :user_id AND status = 'active'
     LIMIT 1"
);
$stmt->execute(['public_id' => $routePublicId, 'user_id' => $userId]);
$route = $stmt->fetch();

if (!$route) {
    json_fail_restore(['Ruten blev ikke fundet, eller tilhører dig ikke.'], 404);
}

if ($route['original_gpx_file_path'] === null) {
    json_fail_restore(['Der findes ingen oprindelig fil at gendanne for denne rute.']);
}

require_once __DIR__ . '/../app/gpx.php';
$originalPath = gpx_full_path($route['original_gpx_file_path']);

if (!is_file($originalPath)) {
    json_fail_restore(['Den oprindelige fil kunne ikke findes på serveren.']);
}

$originalContent = file_get_contents($originalPath);

$result = update_route(
    $pdo,
    $user,
    (int) $route['id'],
    $originalContent,
    $route['title'],
    (string) $route['description'],
    (int) $route['activity_type_id'],
    $route['visibility'],
    (bool) $route['allow_download'],
    true, // GDPR-boksen er allerede bekræftet tidligere for denne rute
    'keep', // spring hastighedstjek over — dette ER den originale, ubearbejdede fil
    (bool) $route['hide_uploader_name'],
    0 // ingen ny udtynding — gendan uden yderligere ændringer
);

if (!$result['success']) {
    json_fail_restore($result['errors'] ?? ['Der skete en fejl under gendannelse.']);
}

// Nu hvor den oprindelige fil er blevet den AKTIVE fil igen, giver det
// ikke længere mening at pege på "en original" — ryd feltet og fjern
// selve backup-filen (indholdet lever jo nu videre som gpx_file_path).
$pdo->prepare('UPDATE routes SET original_gpx_file_path = NULL WHERE id = :id')
    ->execute(['id' => $route['id']]);
@unlink($originalPath);

echo json_encode(['success' => true]);
