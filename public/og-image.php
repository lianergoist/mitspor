<?php
declare(strict_types=1);

/**
 * Delingsbillede (Open Graph):
 *
 *   /og-image.php?id=12345678      en offentlig rute
 *   /og-image.php?group=12345678   en gruppe (etape-tur) med mindst én offentlig etape
 *
 * - Kun offentlige, aktive ruter (og grupper med offentlige etaper) får et kort-billede. Alt andet (privat,
 *   slettet, ukendt id, generering fejlede) får det generiske MitSpor-billede,
 *   så endpointet ikke afslører noget om private ruter.
 * - Billedet genereres første gang, det efterspørges, og gemmes derefter
 *   som fil (uden for webroot). Se app/og_image.php.
 * - Ingen cookies, ingen session, ingen skrivning til databasen.
 */

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/og_image.php';

function og_serve_fallback(): never
{
    $file = __DIR__ . '/grafik/mitspor_full.png?v=3';
    header('Content-Type: image/png');
    header('X-Content-Type-Options: nosniff');
    // Kort cache: næste forsøg må gerne lykkes med et rigtigt billede.
    header('Cache-Control: public, max-age=300');
    header('Content-Length: ' . (string) filesize($file));
    readfile($file);
    exit;
}

function og_serve_file(string $path): never
{
    $etag = '"' . basename($path, '.jpg') . '"';
    header('ETag: ' . $etag);
    header('X-Content-Type-Options: nosniff');
    // URL'en indeholder ?v=<version>, så et langt cache-liv er sikkert.
    header('Cache-Control: public, max-age=86400');

    if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
        http_response_code(304);
        exit;
    }

    header('Content-Type: image/jpeg');
    header('Content-Length: ' . (string) filesize($path));
    readfile($path);
    exit;
}

$pdo = get_db();

// --- Gruppe (etape-tur) ---
if (isset($_GET['group'])) {
    $data = og_load_public_group($pdo, (string) $_GET['group']);
    if ($data === null) {
        og_serve_fallback();
    }
    @set_time_limit(40);
    @ignore_user_abort(true);
    $path = og_get_group_image($data['group'], $data['members']);
    if ($path === null) {
        og_serve_fallback();
    }
    og_serve_file($path);
}

// --- Enkelt rute ---
$publicId = (string) ($_GET['id'] ?? '');
if (!preg_match('/^[0-9]{8}$/', $publicId)) {
    og_serve_fallback();
}

$route = og_load_public_route($pdo, $publicId);

if (!$route) {
    og_serve_fallback();
}

@set_time_limit(40);
@ignore_user_abort(true); // lad genereringen blive færdig, selv hvis crawleren giver op

$path = og_get_image($route);
if ($path === null) {
    og_serve_fallback();
}

og_serve_file($path);
