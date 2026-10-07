<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/route_deletion.php';

header('Content-Type: application/json; charset=UTF-8');

function json_fail_delete(array $errors, int $httpCode = 400): never
{
    http_response_code($httpCode);
    echo json_encode(['success' => false, 'errors' => $errors]);
    exit;
}

if (empty($_SESSION['user_id'])) {
    json_fail_delete(['Du skal være logget ind.'], 401);
}

if (!csrf_validate($_POST['csrf_token'] ?? null)) {
    json_fail_delete(['Din session er udløbet. Genindlæs siden og prøv igen.'], 403);
}

$userId = (int) $_SESSION['user_id'];
$routePublicId = (string) ($_POST['route_id'] ?? '');
$confirmTitle = trim((string) ($_POST['confirm_title'] ?? ''));

$pdo = get_db();

$stmt = $pdo->prepare(
    "SELECT * FROM routes WHERE public_id = :public_id AND user_id = :user_id AND status = 'active' LIMIT 1"
);
$stmt->execute(['public_id' => $routePublicId, 'user_id' => $userId]);
$route = $stmt->fetch();

if (!$route) {
    json_fail_delete(['Ruten blev ikke fundet, eller tilhører dig ikke.'], 404);
}

if ($confirmTitle === '' || $confirmTitle !== $route['title']) {
    json_fail_delete(['Det indtastede navn matcher ikke rutens titel.']);
}

try {
    delete_route($pdo, $route, $userId);
} catch (Throwable $e) {
    json_fail_delete(['Der skete en fejl under sletning. Prøv igen.'], 500);
}

echo json_encode(['success' => true]);
