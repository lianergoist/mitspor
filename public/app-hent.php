<?php
declare(strict_types=1);

/*
 * Mål for download-links i appen (app/app_hent.php). Appen opdager, at
 * siden indlæses, henter selv filen via API'et og viser del-arket. Siden
 * sender straks brugeren tilbage til den side, linket stod på.
 * Uden for appen sendes man videre til den almindelige download.
 */

require_once __DIR__ . '/../app/app_hent.php';

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

$type = (string) ($_GET['type'] ?? '');
$publicId = (string) ($_GET['id'] ?? '');
$shareToken = (string) ($_GET['share'] ?? '');

if (!app_can_fetch_files()) {
    $url = $type === 'export' ? '/export-data.php' : '/route-file.php?id=' . rawurlencode($publicId) . '&mode=download'
        . ($shareToken !== '' ? '&share=' . rawurlencode($shareToken) : '');
    header('Location: ' . $url, true, 302);
    exit;
}

// Tilbage til siden med linket: kun en sti på dette site, ellers forsiden.
$back = '/';
$referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
$parts = parse_url($referer);
if (is_array($parts)
    && ($parts['host'] ?? '') === ($_SERVER['HTTP_HOST'] ?? '')
    && isset($parts['path'])
    && str_starts_with($parts['path'], '/')
    && $parts['path'] !== '/app-hent.php') {
    $back = $parts['path'] . (isset($parts['query']) ? '?' . $parts['query'] : '');
}
$backJson = json_encode($back, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
?>
<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>Henter filen – MitSpor</title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
    <main class="container" style="text-align: center; padding-top: 48px;">
        <p>Henter filen …</p>
        <p><a href="<?= htmlspecialchars($back, ENT_QUOTES, 'UTF-8') ?>">‹ Tilbage</a></p>
    </main>
    <script>
        // Appen henter filen selv; gå tilbage uden at efterlade denne side i historikken.
        setTimeout(function () { window.location.replace(<?= $backJson ?>); }, 600);
    </script>
</body>
</html>
