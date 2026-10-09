<?php
declare(strict_types=1);

// API: veksl appens hemmelighed til et token, når brugeren har logget ind
// på sitet i appens WebView (sitet har koblet hemmelighedens hash, "par" i
// user-agent, til kontoen; se app/app_login.php).
// Appen spørger efter hver sidevisning, mens den ikke er logget ind; indtil
// brugeren logger ind på sitet, er svaret 401.
// POST: code (hemmeligheden), device_name
// Svar: { success, token, expires_at, display_name }

require_once __DIR__ . '/../../../app/api_auth.php';
require_once __DIR__ . '/../../../app/rate_limit.php';
require_once __DIR__ . '/../../../app/app_login.php';

api_bootstrap(['POST']);

$ip = client_ip();
if (!rate_limit_allow('app_exchange_code', $ip, APP_EXCHANGE_RATE_LIMIT_MAX_ATTEMPTS, APP_LOGIN_CODE_RATE_LIMIT_WINDOW_SECONDS)) {
    api_error('For mange forsøg. Prøv igen senere.', 429);
}
rate_limit_record('app_exchange_code', $ip);

$pdo    = get_db();
$userId = app_login_code_consume($pdo, (string) ($_POST['code'] ?? ''), 'web_til_app');
$user   = $userId !== null ? current_user($userId) : null;

if ($user === null) {
    api_error('Ikke logget ind på sitet.', 401);
}

$created = api_token_create($pdo, (int) $user['id'], (string) ($_POST['device_name'] ?? ''));

api_json([
    'success'      => true,
    'token'        => $created['token'],
    'expires_at'   => $created['expires_at'],
    'display_name' => $user['display_name'],
]);
