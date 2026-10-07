<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/gpx.php';

$publicId = (string) ($_GET['id'] ?? '');
$mode = ($_GET['mode'] ?? 'view') === 'download' ? 'download' : 'view';

if ($publicId === '') {
    http_response_code(404);
    exit;
}

$pdo = get_db();
$stmt = $pdo->prepare(
    "SELECT id, user_id, title, visibility, status, allow_download, gpx_file_path, share_token
     FROM routes
     WHERE public_id = :public_id
     LIMIT 1"
);
$stmt->execute(['public_id' => $publicId]);
$route = $stmt->fetch();

if (!$route) {
    http_response_code(404);
    exit;
}

if ($route['status'] === 'deleted') {
    http_response_code(410);
    exit;
}

if ($route['status'] !== 'active') {
    http_response_code(404);
    exit;
}

$isOwner = !empty($_SESSION['user_id']) && (int) $_SESSION['user_id'] === (int) $route['user_id'];

$shareToken = (string) ($_GET['share'] ?? '');
$hasValidShareToken = $route['share_token'] !== null
    && $shareToken !== ''
    && hash_equals($route['share_token'], $shareToken);

if ($route['visibility'] === 'private' && !$isOwner && !$hasValidShareToken) {
    http_response_code(403);
    exit;
}

if ($mode === 'download' && !$isOwner && !$route['allow_download']) {
    http_response_code(403);
    exit;
}

$fullPath = gpx_full_path($route['gpx_file_path']);

if (!is_file($fullPath)) {
    http_response_code(404);
    exit;
}

header('Content-Type: application/gpx+xml; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

if ($mode === 'download') {
    // Moderne filsystemer håndterer æ/ø/å og mellemrum fint — det er
    // http-headeren, der historisk kun er defineret til ASCII/Latin-1.
    // Løsningen er ikke at fjerne tegnene, men at sende BÅDE et rent
    // ASCII-fallback-navn OG det rigtige UTF-8-navn i den udvidede
    // filename*=-syntaks (RFC 5987) — moderne browsere bruger den,
    // ældre/skrappere klienter falder tilbage til ASCII-udgaven.
    $rawFilename = str_replace(["\r", "\n", '"', '\\'], '', $route['title']) . '.gpx';

    $asciiFallback = str_replace(
        ['æ', 'ø', 'å', 'Æ', 'Ø', 'Å'],
        ['ae', 'oe', 'aa', 'Ae', 'Oe', 'Aa'],
        $rawFilename
    );
    $asciiFallback = preg_replace('/[^\x20-\x7E]/', '_', $asciiFallback); // resterende ikke-ASCII (fx andre sprogs bogstaver)

    header(
        'Content-Disposition: attachment; filename="' . $asciiFallback . '"; ' .
        "filename*=UTF-8''" . rawurlencode($rawFilename)
    );
} else {
    header('Content-Disposition: inline');
}

// Bevidst INGEN manuel Content-Length-header her: hvis Apache
// komprimerer outputtet (mod_deflate/gzip), passer et forudberegnet
// Content-Length (baseret på den ukomprimerede filstørrelse) ikke
// længere med det faktisk sendte antal bytes — det kan få browseren
// til at afskære store filer midt i overførslen. Lad Apache/PHP selv
// sætte den korrekte header ud fra hvad der rent faktisk sendes.

// Ryd evt. aktive output-buffere, så readfile() strømmer direkte,
// uden at en buffer utilsigtet ændrer eller forsinker outputtet.
while (ob_get_level() > 0) {
    ob_end_flush();
}

readfile($fullPath);
