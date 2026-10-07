<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/csrf.php';

header('Content-Type: application/json; charset=UTF-8');

function json_fail_share(array $errors, int $httpCode = 400): never
{
    http_response_code($httpCode);
    echo json_encode(['success' => false, 'errors' => $errors]);
    exit;
}

if (empty($_SESSION['user_id'])) {
    json_fail_share(['Du skal være logget ind.'], 401);
}

if (!csrf_validate($_POST['csrf_token'] ?? null)) {
    json_fail_share(['Din session er udløbet. Genindlæs siden og prøv igen.'], 403);
}

$userId = (int) $_SESSION['user_id'];
$routePublicId = (string) ($_POST['route_id'] ?? '');
$action = (string) ($_POST['action'] ?? '');

if (!in_array($action, ['create', 'regenerate', 'revoke'], true)) {
    json_fail_share(['Ugyldig handling.']);
}

$pdo = get_db();
$stmt = $pdo->prepare(
    "SELECT id, public_id, share_token FROM routes
     WHERE public_id = :public_id AND user_id = :user_id AND status = 'active'
     LIMIT 1"
);
$stmt->execute(['public_id' => $routePublicId, 'user_id' => $userId]);
$route = $stmt->fetch();

if (!$route) {
    json_fail_share(['Ruten blev ikke fundet, eller tilhører dig ikke.'], 404);
}

if ($action === 'revoke') {
    $pdo->prepare('UPDATE routes SET share_token = NULL WHERE id = :id')
        ->execute(['id' => $route['id']]);
    echo json_encode(['success' => true, 'share_token' => null]);
    exit;
}

// 'create': behold det eksisterende token, hvis der allerede er ét.
// 'regenerate': lav altid et nyt (og dermed ugyldiggør det gamle link).
if ($action === 'create' && $route['share_token'] !== null) {
    echo json_encode(['success' => true, 'share_token' => $route['share_token']]);
    exit;
}

// Kryptografisk tilfældigt token — IKKE det gættelige 8-cifrede public_id.
$newToken = bin2hex(random_bytes(24));

$pdo->prepare('UPDATE routes SET share_token = :token WHERE id = :id')
    ->execute(['token' => $newToken, 'id' => $route['id']]);

echo json_encode(['success' => true, 'share_token' => $newToken]);
