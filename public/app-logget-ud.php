<?php
declare(strict_types=1);

/*
 * Mål for log ud i appens WebView (fra MitSporApp/5, se logout.php). Når
 * siden indlæses, logger appen også ud: den tilbagekalder sit token og
 * glemmer det. Derfor skal appen ikke sende sit token (eller en hash af
 * det) til sitet. Siden går derefter videre til login som efter et
 * almindeligt log ud.
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
    <title>Logget ud – MitSpor</title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
    <main class="container" style="text-align: center; padding-top: 48px;">
        <p>Du er logget ud.</p>
        <p><a href="/login.php">Log ind igen</a></p>
    </main>
    <script>
        // Vent lidt, så appen når at se siden, før der gås videre.
        window.addEventListener('load', function () {
            setTimeout(function () { location.replace('/login.php'); }, 800);
        });
    </script>
</body>
</html>
