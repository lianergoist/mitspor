<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/rate_limit.php';
require_once __DIR__ . '/../app/mailer.php';
require_once __DIR__ . '/../app/afvent_bekraeftelse.php';

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

if (!rate_limit_allow('register', $ip, REGISTER_RATE_LIMIT_MAX_ATTEMPTS, REGISTER_RATE_LIMIT_WINDOW_SECONDS)) {
    echo json_encode(['success' => false, 'errors' => ['Der er lavet for mange oprettelsesforsøg fra denne forbindelse. Prøv igen senere.']]);
    exit;
}

$email          = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
$password       = (string) ($_POST['password'] ?? '');
$displayName    = trim((string) ($_POST['display_name'] ?? ''));
$acceptedPolicy = isset($_POST['accept_policy']) && $_POST['accept_policy'] !== '0' && $_POST['accept_policy'] !== '';

$errors = [];

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Indtast en gyldig e-mailadresse.';
}
if (mb_strlen($password) < 10) {
    $errors[] = 'Adgangskoden skal være mindst 10 tegn.';
}
if (mb_strlen($displayName) < 2 || mb_strlen($displayName) > 100) {
    $errors[] = 'Navnet skal være mellem 2 og 100 tegn.';
}
if (!$acceptedPolicy) {
    $errors[] = 'Du skal acceptere privatlivspolitikken for at oprette en konto.';
}

// Registrér forsøget i rate-loggen uanset udfald, FØR vi evt. stopper.
rate_limit_record('register', $ip, $email !== '' ? $email : null);

if (!empty($errors)) {
    echo json_encode(['success' => false, 'errors' => $errors]);
    exit;
}

$pdo = get_db();

$stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
$stmt->execute(['email' => $email]);

if ($stmt->fetch()) {
    // Bevidst generisk fejl — afslør ikke om e-mailen findes.
    echo json_encode(['success' => false, 'errors' => ['Kunne ikke oprette kontoen. Tjek om du allerede har en konto, eller prøv en anden e-mailadresse.']]);
    exit;
}

try {
    $pdo->beginTransaction();

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $pdo->prepare(
        'INSERT INTO users (email, password_hash, display_name)
         VALUES (:email, :password_hash, :display_name)'
    );
    $stmt->execute([
        'email'         => $email,
        'password_hash' => $passwordHash,
        'display_name'  => $displayName,
    ]);

    $userId = (int) $pdo->lastInsertId();

    $rawToken  = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);
    $expiresAt = (new DateTimeImmutable('+' . EMAIL_VERIFICATION_TTL_HOURS . ' hours'))
        ->format('Y-m-d H:i:s');

    $stmt = $pdo->prepare(
        'INSERT INTO email_verification_tokens (user_id, token_hash, expires_at)
         VALUES (:user_id, :token_hash, :expires_at)'
    );
    $stmt->execute([
        'user_id'    => $userId,
        'token_hash' => $tokenHash,
        'expires_at' => $expiresAt,
    ]);

    $pdo->commit();

    // Mail sendes EFTER commit, så vi ikke mister en oprettet bruger,
    // hvis mailafsendelse fejler.
    send_verification_email($email, $rawToken);

    // I appen logges WebView'et (og dermed appen) ind, når mailen er
    // bekræftet i browseren (se app/afvent_bekraeftelse.php).
    if (is_app_webview()) {
        afvent_bekraeftelse_start($userId, $passwordHash);
    }
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('Registrering fejlede: ' . $e->getMessage());
    echo json_encode(['success' => false, 'errors' => ['Der skete en fejl. Prøv igen senere.']]);
    exit;
}

echo json_encode(['success' => true, 'afventer' => is_app_webview()]);
