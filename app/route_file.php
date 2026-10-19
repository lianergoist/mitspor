<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/gpx.php';
require_once __DIR__ . '/image_links.php';

/*
 * Udlevering af en rutes GPX-fil. Bruges af route-file.php (sitet, med
 * session) og api/v1/route-file.php (appen, med token), så adgangsreglerne
 * kun står ét sted.
 */

/**
 * Find ruten og tjek adgangen.
 *
 * @param int|null $viewerId   den indloggede bruger (session eller token), ellers null
 * @param string   $shareToken del-linkets token fra URL'en ('' hvis intet)
 * @param bool     $download   true ved download (kræver ejer eller allow_download)
 * @return array{status: int, route: ?array, is_owner: bool} status 200 med
 *         ruten, ellers HTTP-koden der skal svares med (404, 410 eller 403)
 */
function route_file_find(PDO $pdo, string $publicId, ?int $viewerId, string $shareToken, bool $download): array
{
    if ($publicId === '') {
        return ['status' => 404, 'route' => null, 'is_owner' => false];
    }

    $stmt = $pdo->prepare(
        "SELECT id, user_id, title, visibility, status, allow_download, gpx_file_path, share_token, route_type
         FROM routes
         WHERE public_id = :public_id
         LIMIT 1"
    );
    $stmt->execute(['public_id' => $publicId]);
    $route = $stmt->fetch();

    if (!$route) {
        return ['status' => 404, 'route' => null, 'is_owner' => false];
    }

    if ($route['status'] === 'deleted') {
        return ['status' => 410, 'route' => null, 'is_owner' => false];
    }

    if ($route['status'] !== 'active') {
        return ['status' => 404, 'route' => null, 'is_owner' => false];
    }

    $isOwner = $viewerId !== null && $viewerId === (int) $route['user_id'];

    $hasValidShareToken = $route['share_token'] !== null
        && $shareToken !== ''
        && hash_equals($route['share_token'], $shareToken);

    if ($route['visibility'] === 'private' && !$isOwner && !$hasValidShareToken) {
        return ['status' => 403, 'route' => null, 'is_owner' => false];
    }

    if ($download && !$isOwner && !$route['allow_download']) {
        return ['status' => 403, 'route' => null, 'is_owner' => false];
    }

    if (!is_file(gpx_full_path($route['gpx_file_path']))) {
        return ['status' => 404, 'route' => null, 'is_owner' => false];
    }

    return ['status' => 200, 'route' => $route, 'is_owner' => $isOwner];
}

/**
 * Filnavnet ved download: rutens titel + .gpx.
 */
function route_file_download_name(array $route): string
{
    return str_replace(["\r", "\n", '"', '\\'], '', $route['title']) . '.gpx';
}

/**
 * Content-Disposition for en download med æ/ø/å i filnavnet.
 */
function route_file_attachment_header(string $rawFilename): string
{
    // Moderne filsystemer håndterer æ/ø/å og mellemrum fint — det er
    // http-headeren, der historisk kun er defineret til ASCII/Latin-1.
    // Løsningen er ikke at fjerne tegnene, men at sende BÅDE et rent
    // ASCII-fallback-navn OG det rigtige UTF-8-navn i den udvidede
    // filename*=-syntaks (RFC 5987) — moderne browsere bruger den,
    // ældre/skrappere klienter falder tilbage til ASCII-udgaven.
    $asciiFallback = str_replace(
        ['æ', 'ø', 'å', 'Æ', 'Ø', 'Å'],
        ['ae', 'oe', 'aa', 'Ae', 'Oe', 'Aa'],
        $rawFilename
    );
    $asciiFallback = preg_replace('/[^\x20-\x7E]/', '_', $asciiFallback); // resterende ikke-ASCII (fx andre sprogs bogstaver)

    return 'Content-Disposition: attachment; filename="' . $asciiFallback . '"; ' .
        "filename*=UTF-8''" . rawurlencode($rawFilename);
}

/**
 * Send GPX-filen og afslut.
 *
 * Billedlinks (se app/image_links.php): billedlinks til andre brugeres
 * billeder end rutens ejer fjernes altid, også i ejerens egen download.
 * Downloader en anden end ejeren, fjernes alle billedlinks.
 *
 * Er filen markeret som rute (ikke spor), fjernes alle tidsstempler, både
 * ved download og i visningsfilen (rutesiden bruger ikke tider for ruter),
 * også for ejeren. En rute er en planlagt vej, og tider i filen får nogle
 * GPS-enheder og apps til at behandle den som et spor. Dataeksporten giver
 * stadig ejeren den gemte fil uændret.
 */
function route_file_send(array $route, bool $download, bool $isOwner): never
{
    $raw = file_get_contents(gpx_full_path($route['gpx_file_path']));
    $keepOwnerId = ($download && !$isOwner) ? null : (int) $route['user_id'];
    $content = $raw === false ? null : gpx_remove_image_links($raw, $keepOwnerId);
    if ($content === null) {
        error_log('GPX kunne ikke renses for billedlinks: rute ' . $route['id']);
        http_response_code(500);
        exit;
    }
    if ($route['route_type'] === 'rute') {
        $content = gpx_strip_all_times($content);
    }

    header('Content-Type: application/gpx+xml; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');

    if ($download) {
        header(route_file_attachment_header(route_file_download_name($route)));
    } else {
        header('Content-Disposition: inline');
    }

    // Bevidst INGEN manuel Content-Length-header her: hvis Apache
    // komprimerer outputtet (mod_deflate/gzip), passer et forudberegnet
    // Content-Length (baseret på den ukomprimerede filstørrelse) ikke
    // længere med det faktisk sendte antal bytes — det kan få browseren
    // til at afskære store filer midt i overførslen. Lad Apache/PHP selv
    // sætte den korrekte header ud fra hvad der rent faktisk sendes.

    // Ryd evt. aktive output-buffere, så indholdet sendes direkte,
    // uden at en buffer utilsigtet ændrer eller forsinker outputtet.
    while (ob_get_level() > 0) {
        ob_end_flush();
    }

    echo $content;
    exit;
}
