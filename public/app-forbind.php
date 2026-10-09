<?php
declare(strict_types=1);

// Mellemside efter login på sitet i appens WebView.
//
// login-ajax.php sender appen hertil som /app-forbind.php?til=<sti>#kode=<kode>.
// Siden gør intet med koden: appen ser adressen, tager koden efter # og
// veksler den til et token (api/v1/exchange-code.php). Koden når aldrig
// serveren her og havner derfor ikke i adgangsloggen. Efter et øjeblik
// går siden videre til <sti>.

require_once __DIR__ . '/../app/app_login.php';

$til = app_safe_path((string) ($_GET['til'] ?? '/'));

header('Cache-Control: no-store');
?>
<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="refresh" content="1;url=<?= htmlspecialchars($til, ENT_QUOTES, 'UTF-8') ?>">
    <link rel="icon" type="image/svg+xml" href="/grafik/favicon.svg">
    <link rel="icon" type="image/png" sizes="32x32" href="/grafik/favicon-32.png">
    <meta name="robots" content="noindex, nofollow">
    <title>Logger ind …</title>
</head>
<body>
    <p>Logger ind i appen …</p>
</body>
</html>
