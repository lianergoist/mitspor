<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/rate_limit.php'; // client_ip()
require_once __DIR__ . '/../app/login_rate_limit.php';
require_once __DIR__ . '/../app/app_login.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Ugyldig forespørgsel.']);
    exit;
}

if (!csrf_validate($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Din session er udløbet. Genindlæs siden og prøv igen.']);
    exit;
}

$email    = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
$password = (string) ($_POST['password'] ?? '');
$ip       = client_ip();

$result = attempt_login($email, $password, $ip, LOGIN_RATE_LIMIT_MAX_ATTEMPTS, LOGIN_RATE_LIMIT_WINDOW_SECONDS);

if (!$result['success']) {
    echo json_encode(['success' => false, 'error' => $result['error']]);
    exit;
}

// Forny session-ID ved login for at undgå session fixation.
session_regenerate_id(true);
$_SESSION['user_id'] = $result['user_id'];

// I appens WebView logges appen også ind: appens hash (par i user-agent)
// kobles til kontoen, og appen henter selv et token (se app/app_login.php).
$pairHash = app_ua_param('par');
if ($pairHash !== null) {
    app_login_pair(get_db(), (int) $result['user_id'], $pairHash);
}

echo json_encode(['success' => true]);
