<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/csrf.php';

header('Content-Type: application/json; charset=UTF-8');

function json_fail_name(array $errors, int $httpCode = 400): never
{
    http_response_code($httpCode);
    echo json_encode(['success' => false, 'errors' => $errors]);
    exit;
}

if (empty($_SESSION['user_id'])) {
    json_fail_name(['Du skal være logget ind.'], 401);
}

if (!csrf_validate($_POST['csrf_token'] ?? null)) {
    json_fail_name(['Din session er udløbet. Genindlæs siden og prøv igen.'], 403);
}

$displayName = trim((string) ($_POST['display_name'] ?? ''));

if (mb_strlen($displayName) < 2 || mb_strlen($displayName) > 100) {
    json_fail_name(['Navnet skal være mellem 2 og 100 tegn.']);
}

$pdo = get_db();
$stmt = $pdo->prepare("UPDATE users SET display_name = :name WHERE id = :id AND status = 'active'");
$stmt->execute(['name' => $displayName, 'id' => (int) $_SESSION['user_id']]);

echo json_encode(['success' => true, 'display_name' => $displayName]);
