<?php
declare(strict_types=1);

// API: log ind med e-mail og adgangskode og få et token til appen.
// POST: email, password, device_name
// Svar: { success, token, expires_at, display_name }

require_once __DIR__ . '/../../../app/api_auth.php';
require_once __DIR__ . '/../../../app/rate_limit.php'; // client_ip()
require_once __DIR__ . '/../../../app/login_rate_limit.php';

api_bootstrap(['POST']);

$email      = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
$password   = (string) ($_POST['password'] ?? '');
$deviceName = (string) ($_POST['device_name'] ?? '');

if ($email === '' || $password === '') {
    api_error('Udfyld e-mail og adgangskode.', 400);
}

// Samme rate limiting og logning af forsøg som login på sitet.
$result = attempt_login($email, $password, client_ip(), LOGIN_RATE_LIMIT_MAX_ATTEMPTS, LOGIN_RATE_LIMIT_WINDOW_SECONDS);

if (!$result['success']) {
    api_error($result['error'], 401);
}

$pdo  = get_db();
$user = current_user($result['user_id']);
if ($user === null) {
    api_error('Forkert e-mail eller adgangskode.', 401);
}

$created = api_token_create($pdo, (int) $user['id'], $deviceName);

api_json([
    'success'      => true,
    'token'        => $created['token'],
    'expires_at'   => $created['expires_at'],
    'display_name' => $user['display_name'],
]);
