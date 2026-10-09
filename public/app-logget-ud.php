<?php
declare(strict_types=1);

// Mellemside efter log ud på sitet i appens WebView (se logout.php).
// Appen ser adressen og logger også selv ud. Derefter går siden videre
// til forsiden.

header('Cache-Control: no-store');
?>
<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="refresh" content="1;url=/">
    <link rel="icon" type="image/svg+xml" href="/grafik/favicon.svg">
    <link rel="icon" type="image/png" sizes="32x32" href="/grafik/favicon-32.png">
    <meta name="robots" content="noindex, nofollow">
    <title>Logget ud</title>
</head>
<body>
    <p>Du er logget ud.</p>
</body>
</html>
