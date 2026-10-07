<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/csrf.php';

header('Content-Type: application/json; charset=UTF-8');

function json_fail_reset(array $errors, int $httpCode = 400): never
{
    http_response_code($httpCode);
    echo json_encode(['success' => false, 'errors' => $errors]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_fail_reset(['Ugyldig forespørgsel.'], 405);
}

if (!csrf_validate($_POST['csrf_token'] ?? null)) {
    json_fail_reset(['Din session er udløbet. Genindlæs siden og prøv igen.'], 403);
}

$rawToken = (string) ($_POST['token'] ?? '');
$password = (string) ($_POST['password'] ?? '');
$passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

if ($rawToken === '') {
    json_fail_reset(['Linket er ugyldigt. Anmod om et nyt.']);
}

$pdo = get_db();
$tokenHash = hash('sha256', $rawToken);

$stmt = $pdo->prepare(
    'SELECT id, user_id, expires_at, used_at
     FROM password_reset_tokens
     WHERE token_hash = :token_hash
     LIMIT 1'
);
$stmt->execute(['token_hash' => $tokenHash]);
$tokenRow = $stmt->fetch();

if (!$tokenRow || $tokenRow['used_at'] !== null || strtotime($tokenRow['expires_at']) < time()) {
    json_fail_reset(['Linket er ugyldigt eller udløbet. Anmod om et nyt.']);
}

if (mb_strlen($password) < 10) {
    json_fail_reset(['Adgangskoden skal være mindst 10 tegn.']);
}
if ($password !== $passwordConfirm) {
    json_fail_reset(['De to adgangskoder er ikke ens.']);
}

try {
    $pdo->beginTransaction();

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :user_id');
    $stmt->execute([
        'hash'    => $passwordHash,
        'user_id' => $tokenRow['user_id'],
    ]);

    $stmt = $pdo->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE id = :id');
    $stmt->execute(['id' => $tokenRow['id']]);

    // Ugyldiggør evt. andre udestående reset-tokens for samme bruger,
    // så et gammelt link ikke stadig kan bruges.
    $stmt = $pdo->prepare(
        'UPDATE password_reset_tokens
         SET used_at = NOW()
         WHERE user_id = :user_id AND used_at IS NULL'
    );
    $stmt->execute(['user_id' => $tokenRow['user_id']]);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('Password reset fejlede: ' . $e->getMessage());
    json_fail_reset(['Der skete en fejl. Prøv igen senere.'], 500);
}

echo json_encode(['success' => true]);
