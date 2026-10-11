<?php
declare(strict_types=1);

/*
 * Mål for knappen "‹ Appen" (app/app_tilbage.php). Appen lukker sit
 * WebView, så snart denne side indlæses, så brugeren ser den højst et
 * øjeblik. Uden for appen vises et link til forsiden.
 */

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
?>
<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>Tilbage til appen – MitSpor</title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
    <main class="container" style="text-align: center; padding-top: 48px;">
        <p>Går tilbage til appen …</p>
        <p><a href="/">Gå til forsiden</a></p>
    </main>
</body>
</html>
