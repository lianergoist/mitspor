<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/rate_limit.php';
require_once __DIR__ . '/../app/mailer.php';

header('Content-Type: application/json; charset=UTF-8');

function json_fail_email(array $errors, int $httpCode = 400): never
{
    http_response_code($httpCode);
    echo json_encode(['success' => false, 'errors' => $errors]);
    exit;
}

if (empty($_SESSION['user_id'])) {
    json_fail_email(['Du skal være logget ind.'], 401);
}

if (!csrf_validate($_POST['csrf_token'] ?? null)) {
    json_fail_email(['Din session er udløbet. Genindlæs siden og prøv igen.'], 403);
}

$userId = (int) $_SESSION['user_id'];
$ip = client_ip();

if (!rate_limit_allow('email_change', $ip, EMAIL_CHANGE_RATE_LIMIT_MAX_ATTEMPTS, EMAIL_CHANGE_RATE_LIMIT_WINDOW_SECONDS)) {
    json_fail_email(['For mange forsøg. Prøv igen senere.']);
}
rate_limit_record('email_change', $ip, null);

$currentPassword = (string) ($_POST['current_password'] ?? '');
$newEmail = mb_strtolower(trim((string) ($_POST['new_email'] ?? '')));

if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
    json_fail_email(['Indtast en gyldig e-mailadresse.']);
}

$pdo = get_db();

$stmt = $pdo->prepare('SELECT email, password_hash FROM users WHERE id = :id');
$stmt->execute(['id' => $userId]);
$user = $stmt->fetch();

if (!$user || !password_verify($currentPassword, $user['password_hash'])) {
    json_fail_email(['Forkert adgangskode.']);
}

if ($newEmail === $user['email']) {
    json_fail_email(['Det er allerede din nuværende e-mailadresse.']);
}

// Tjek om adressen allerede er i brug af en anden (aktiv) konto.
$stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email AND status = 'active' LIMIT 1");
$stmt->execute(['email' => $newEmail]);
if ($stmt->fetch()) {
    // Bevidst generisk fejl — afslør ikke om adressen findes.
    json_fail_email(['Kunne ikke skifte til denne adresse. Prøv en anden e-mailadresse.']);
}

$rawToken  = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $rawToken);
$expiresAt = (new DateTimeImmutable('+' . EMAIL_CHANGE_TTL_HOURS . ' hours'))->format('Y-m-d H:i:s');

$stmt = $pdo->prepare(
    'INSERT INTO email_change_tokens (user_id, new_email, token_hash, expires_at)
     VALUES (:user_id, :new_email, :token_hash, :expires_at)'
);
$stmt->execute([
    'user_id'    => $userId,
    'new_email'  => $newEmail,
    'token_hash' => $tokenHash,
    'expires_at' => $expiresAt,
]);

send_email_change_confirmation($newEmail, $rawToken);
send_email_change_notice($user['email'], $newEmail);

echo json_encode(['success' => true]);
