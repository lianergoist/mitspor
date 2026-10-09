<?php
declare(strict_types=1);

// API: engangskode, så appens WebView kan logges ind på sitet med det
// login, appen allerede har. Appen sætter koden i WebView'ets user-agent
// og åbner /app-login.php?til=<sti> (se app/app_login.php).
// POST (ingen felter). Svar: { success, code, expires_in }

require_once __DIR__ . '/../../../app/api_auth.php';
require_once __DIR__ . '/../../../app/rate_limit.php';
require_once __DIR__ . '/../../../app/app_login.php';

api_bootstrap(['POST']);

$user   = api_require_token();
$userId = (int) $user['id'];

if (!rate_limit_allow_identifier('app_login_code', (string) $userId, APP_LOGIN_CODE_RATE_LIMIT_MAX_ATTEMPTS, APP_LOGIN_CODE_RATE_LIMIT_WINDOW_SECONDS)) {
    api_error('For mange forsøg. Prøv igen senere.', 429);
}
rate_limit_record('app_login_code', client_ip(), (string) $userId);

$code = app_login_code_create(get_db(), $userId);

api_json([
    'success'    => true,
    'code'       => $code,
    'expires_in' => APP_LOGIN_CODE_TTL_SECONDS,
]);
