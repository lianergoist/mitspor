<?php
declare(strict_types=1);

// Logger appens WebView ind på sitet med en engangskode fra appen og
// omdirigerer straks videre, så der ikke vises en side og intet kommer i
// historikken (se app/app_login.php).
//
// Appen åbner /app-login.php?til=<sti> med koden i user-agent ("kode=…").
// Uden gyldig kode sendes brugeren bare videre uden at være logget ind.

session_start();

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/app_login.php';

$til  = app_safe_path((string) ($_GET['til'] ?? '/'));
$kode = app_ua_param('kode');

if ($kode !== null) {
    $userId = app_login_code_consume(get_db(), $kode, 'app_til_web');
    if ($userId !== null && current_user($userId) !== null) {
        // Forny session-ID ved login for at undgå session fixation.
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
    }
}

header('Cache-Control: no-store');
header('Location: ' . $til, true, 302);
