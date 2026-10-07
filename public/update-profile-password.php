<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/csrf.php';

header('Content-Type: application/json; charset=UTF-8');

function json_fail_pw(array $errors, int $httpCode = 400): never
{
    http_response_code($httpCode);
    echo json_encode(['success' => false, 'errors' => $errors]);
    exit;
}

if (empty($_SESSION['user_id'])) {
    json_fail_pw(['Du skal være logget ind.'], 401);
}

if (!csrf_validate($_POST['csrf_token'] ?? null)) {
    json_fail_pw(['Din session er udløbet. Genindlæs siden og prøv igen.'], 403);
}

$userId          = (int) $_SESSION['user_id'];
$currentPassword = (string) ($_POST['current_password'] ?? '');
$newPassword     = (string) ($_POST['new_password'] ?? '');
$newPasswordConfirm = (string) ($_POST['new_password_confirm'] ?? '');

$pdo = get_db();
$stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id');
$stmt->execute(['id' => $userId]);
$passwordHash = $stmt->fetchColumn();

if (!password_verify($currentPassword, (string) $passwordHash)) {
    json_fail_pw(['Forkert nuværende adgangskode.']);
}

if (mb_strlen($newPassword) < 10) {
    json_fail_pw(['Den nye adgangskode skal være mindst 10 tegn.']);
}

if ($newPassword !== $newPasswordConfirm) {
    json_fail_pw(['De to nye adgangskoder er ikke ens.']);
}

$newHash = password_hash($newPassword, PASSWORD_DEFAULT);

$stmt = $pdo->prepare("UPDATE users SET password_hash = :hash WHERE id = :id AND status = 'active'");
$stmt->execute(['hash' => $newHash, 'id' => $userId]);

// Forny session-ID efter en adgangskodeændring — god praksis.
session_regenerate_id(true);

echo json_encode(['success' => true]);
