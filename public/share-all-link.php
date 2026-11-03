<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/user_share.php';

// "Del alle mine ruter": opret, udskift eller slet brugerens link
// (kort.php?alle=<nøgle>). Se app/user_share.php.
//
//   POST action=create      -> det eksisterende link, ellers et nyt
//   POST action=regenerate  -> altid et nyt (det gamle stopper med at virke)
//   POST action=revoke      -> slet linket

header('Content-Type: application/json; charset=UTF-8');

function json_fail_share_all(array $errors, int $httpCode = 400): never
{
    http_response_code($httpCode);
    echo json_encode(['success' => false, 'errors' => $errors]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_fail_share_all(['Ugyldig forespørgsel.'], 405);
}

if (empty($_SESSION['user_id'])) {
    json_fail_share_all(['Du skal være logget ind.'], 401);
}

if (!csrf_validate($_POST['csrf_token'] ?? null)) {
    json_fail_share_all(['Din session er udløbet. Genindlæs siden og prøv igen.'], 403);
}

$userId = (int) $_SESSION['user_id'];
$action = (string) ($_POST['action'] ?? '');

if (!in_array($action, ['create', 'regenerate', 'revoke'], true)) {
    json_fail_share_all(['Ugyldig handling.']);
}

$pdo = get_db();
$stmt = $pdo->prepare("SELECT all_share_token FROM users WHERE id = :id AND status = 'active' LIMIT 1");
$stmt->execute(['id' => $userId]);
$row = $stmt->fetch();

if (!$row) {
    json_fail_share_all(['Kontoen blev ikke fundet.'], 404);
}

if ($action === 'revoke') {
    $pdo->prepare('UPDATE users SET all_share_token = NULL WHERE id = :id')
        ->execute(['id' => $userId]);
    echo json_encode(['success' => true, 'url' => null]);
    exit;
}

if ($action === 'create' && $row['all_share_token'] !== null) {
    echo json_encode(['success' => true, 'url' => user_share_url($row['all_share_token'])]);
    exit;
}

// Kryptografisk tilfældig nøgle (48 hex-tegn, som rutens del-link).
$newToken = bin2hex(random_bytes(24));

$pdo->prepare('UPDATE users SET all_share_token = :token WHERE id = :id')
    ->execute(['token' => $newToken, 'id' => $userId]);

echo json_encode(['success' => true, 'url' => user_share_url($newToken)]);
