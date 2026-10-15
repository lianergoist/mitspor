<?php
declare(strict_types=1);

// API: hent en rutes GPX-fil til appen (download fra sitet i appens WebView,
// der ikke selv kan hente filer; se public/app-hent.php).
// GET: id (rutens public_id), share (del-linkets token, valgfri)
// Header X-Api-Token er valgfri: uden den gælder kun offentlige ruter og
// del-links, med den også brugerens egne ruter.
// Svar: GPX-filen som download, eller JSON-fejl.

require_once __DIR__ . '/../../../app/api_auth.php';
require_once __DIR__ . '/../../../app/rate_limit.php';
require_once __DIR__ . '/../../../app/route_file.php';

// Standardværdier, hvis config.php endnu ikke har konstanterne
// (se app/config.example.php).
if (!defined('API_DOWNLOAD_RATE_LIMIT_MAX_ATTEMPTS')) {
    define('API_DOWNLOAD_RATE_LIMIT_MAX_ATTEMPTS', 120);
}
if (!defined('API_DOWNLOAD_RATE_LIMIT_WINDOW_SECONDS')) {
    define('API_DOWNLOAD_RATE_LIMIT_WINDOW_SECONDS', 3600);
}

api_bootstrap(['GET']);

$ip = client_ip();
if (!rate_limit_allow('api_route_file', $ip, API_DOWNLOAD_RATE_LIMIT_MAX_ATTEMPTS, API_DOWNLOAD_RATE_LIMIT_WINDOW_SECONDS)) {
    api_error('For mange forsøg. Prøv igen senere.', 429);
}
rate_limit_record('api_route_file', $ip);

$viewerId = null;
if (isset($_SERVER['HTTP_X_API_TOKEN'])) {
    $viewerId = (int) api_require_token()['id'];
}

$result = route_file_find(get_db(), (string) ($_GET['id'] ?? ''), $viewerId, (string) ($_GET['share'] ?? ''), true);

$errors = [
    403 => 'Du har ikke adgang til at hente denne rute.',
    404 => 'Ruten findes ikke.',
    410 => 'Ruten er slettet.',
];
if ($result['status'] !== 200) {
    api_error($errors[$result['status']] ?? 'Ruten kunne ikke hentes.', $result['status']);
}

route_file_send($result['route'], true, $result['is_owner']);
