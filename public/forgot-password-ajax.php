<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/rate_limit.php';
require_once __DIR__ . '/../app/mailer.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'errors' => ['Ugyldig forespørgsel.']]);
    exit;
}

$ip = client_ip();

if (!csrf_validate($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'errors' => ['Din session er udløbet. Genindlæs siden og prøv igen.']]);
    exit;
}

if (!rate_limit_allow('password_reset', $ip, PASSWORD_RESET_RATE_LIMIT_MAX_ATTEMPTS, PASSWORD_RESET_RATE_LIMIT_WINDOW_SECONDS)) {
    echo json_encode(['success' => false, 'errors' => ['For mange forsøg. Prøv igen senere.']]);
    exit;
}

$email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'errors' => ['Indtast en gyldig e-mailadresse.']]);
    exit;
}

rate_limit_record('password_reset', $ip, $email);

$pdo = get_db();
$stmt = $pdo->prepare(
    "SELECT id FROM users WHERE email = :email AND status = 'active' LIMIT 1"
);
$stmt->execute(['email' => $email]);
$user = $stmt->fetch();

// Send KUN mail hvis brugeren findes — men returnér samme svar uanset
// udfald, så endpointet ikke kan bruges til at afsløre hvilke
// e-mailadresser der findes i systemet.
if ($user) {
    $rawToken  = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);
    $expiresAt = (new DateTimeImmutable('+' . PASSWORD_RESET_TTL_HOURS . ' hours'))
        ->format('Y-m-d H:i:s');

    $stmt = $pdo->prepare(
        'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at)
         VALUES (:user_id, :token_hash, :expires_at)'
    );
    $stmt->execute([
        'user_id'    => $user['id'],
        'token_hash' => $tokenHash,
        'expires_at' => $expiresAt,
    ]);

    send_password_reset_email($email, $rawToken);
}

echo json_encode(['success' => true]);
