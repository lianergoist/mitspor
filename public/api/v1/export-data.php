<?php
declare(strict_types=1);

// API: hent alle brugerens data som ZIP til appen ("Download alle mine data"
// på sitet i appens WebView, der ikke selv kan hente filer; se
// public/app-hent.php).
// GET, header X-Api-Token
// Svar: ZIP-filen som download, eller JSON-fejl.

require_once __DIR__ . '/../../../app/api_auth.php';
require_once __DIR__ . '/../../../app/rate_limit.php';
require_once __DIR__ . '/../../../app/data_export.php';

// Standardværdier, hvis config.php endnu ikke har konstanterne
// (se app/config.example.php).
if (!defined('API_EXPORT_RATE_LIMIT_MAX_ATTEMPTS')) {
    define('API_EXPORT_RATE_LIMIT_MAX_ATTEMPTS', 10);
}
if (!defined('API_EXPORT_RATE_LIMIT_WINDOW_SECONDS')) {
    define('API_EXPORT_RATE_LIMIT_WINDOW_SECONDS', 3600);
}

api_bootstrap(['GET']);

$ip = client_ip();
if (!rate_limit_allow('api_export_data', $ip, API_EXPORT_RATE_LIMIT_MAX_ATTEMPTS, API_EXPORT_RATE_LIMIT_WINDOW_SECONDS)) {
    api_error('For mange forsøg. Prøv igen senere.', 429);
}
rate_limit_record('api_export_data', $ip);

$user = api_require_token();

try {
    $tmpZipPath = data_export_create_zip(get_db(), $user);
} catch (RuntimeException $e) {
    api_error($e->getMessage(), 500);
}

data_export_send($tmpZipPath);
