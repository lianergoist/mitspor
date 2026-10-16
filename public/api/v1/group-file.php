<?php
declare(strict_types=1);

// API: hent en hel gruppe som én GPX-fil til appen (download fra sitet i
// appens WebView, der ikke selv kan hente filer; se public/app-hent.php).
// GET: id (gruppens public_id)
// Header X-Api-Token er valgfri: uden den gælder kun offentlige etaper, der
// må downloades; med den får ejeren alle sine etaper.
// Svar: GPX-filen som download, eller JSON-fejl.

require_once __DIR__ . '/../../../app/api_auth.php';
require_once __DIR__ . '/../../../app/rate_limit.php';
require_once __DIR__ . '/../../../app/group_file.php';

// Standardværdier, hvis config.php endnu ikke har konstanterne
// (se app/config.example.php). Samme grænse som api/v1/route-file.php.
if (!defined('API_DOWNLOAD_RATE_LIMIT_MAX_ATTEMPTS')) {
    define('API_DOWNLOAD_RATE_LIMIT_MAX_ATTEMPTS', 120);
}
if (!defined('API_DOWNLOAD_RATE_LIMIT_WINDOW_SECONDS')) {
    define('API_DOWNLOAD_RATE_LIMIT_WINDOW_SECONDS', 3600);
}

api_bootstrap(['GET']);

$ip = client_ip();
if (!rate_limit_allow('api_group_file', $ip, API_DOWNLOAD_RATE_LIMIT_MAX_ATTEMPTS, API_DOWNLOAD_RATE_LIMIT_WINDOW_SECONDS)) {
    api_error('For mange forsøg. Prøv igen senere.', 429);
}
rate_limit_record('api_group_file', $ip);

$viewerId = null;
if (isset($_SERVER['HTTP_X_API_TOKEN'])) {
    $viewerId = (int) api_require_token()['id'];
}

$result = group_file_find(get_db(), (string) ($_GET['id'] ?? ''), $viewerId);

$errors = [
    403 => 'Ingen af etaperne i denne gruppe må downloades.',
    404 => 'Gruppen findes ikke.',
];
if ($result['status'] !== 200) {
    api_error($errors[$result['status']] ?? 'Gruppen kunne ikke hentes.', $result['status']);
}

if (!group_file_send($result['group'], $result['included'], $result['is_owner'])) {
    api_error('Gruppen kunne ikke hentes.', 500);
}
