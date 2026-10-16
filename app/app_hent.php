<?php
declare(strict_types=1);

require_once __DIR__ . '/app_tilbage.php';

/*
 * Download i appen.
 *
 * Appens WebView kan ikke hente filer. Fra MitSporApp/4 peger download-links
 * derfor på /app-hent.php. Når den side indlæses, henter appen selv filen
 * via API'et (api/v1/route-file.php, export-data.php) og viser telefonens
 * del-ark. Uden for appen (og i ældre app-versioner) er links uændrede.
 *
 * Linket fortæller selv, hvilken side det står på (tilbage=), så
 * app-hent.php kan sende brugeren tilbage dertil. Referer kan ikke bruges:
 * Androids InAppBrowser indlæser links med loadUrl() uden Referer-header.
 */

/**
 * Kan appen hente filer selv?
 */
function app_can_fetch_files(): bool
{
    return app_webview_version() >= 4;
}

/**
 * Er stien en sikker side at gå tilbage til: en sti på dette site (ikke
 * //host eller /\host), og ikke app-hent.php selv?
 */
function app_hent_safe_back(string $path): bool
{
    if ($path === '' || $path[0] !== '/' || strlen($path) > 2000) {
        return false;
    }
    if (isset($path[1]) && ($path[1] === '/' || $path[1] === '\\')) {
        return false;
    }
    if (preg_match('/[\x00-\x1f\x7f\\\\]/', $path)) {
        return false;
    }
    $parts = parse_url($path);
    if (!is_array($parts) || isset($parts['scheme']) || isset($parts['host']) || !isset($parts['path'])) {
        return false;
    }
    return $parts['path'] !== '/app-hent.php';
}

/**
 * tilbage=-parameteren til app-hent.php: den side, linket står på.
 */
function app_hent_back_param(): string
{
    $current = (string) ($_SERVER['REQUEST_URI'] ?? '');
    return app_hent_safe_back($current) ? '&tilbage=' . rawurlencode($current) : '';
}

/**
 * Link til download af en rutes GPX-fil.
 *
 * @param string $shareToken del-linkets token ('' hvis intet)
 */
function gpx_download_url(string $publicId, string $shareToken = ''): string
{
    $share = $shareToken !== '' ? '&share=' . rawurlencode($shareToken) : '';

    if (app_can_fetch_files()) {
        return '/app-hent.php?type=gpx&id=' . rawurlencode($publicId) . $share . app_hent_back_param();
    }

    return '/route-file.php?id=' . rawurlencode($publicId) . '&mode=download' . $share;
}

/**
 * Kan appen hente en hel gruppe (type=gruppe i mobile/www/hent.js)?
 * Fra MitSporApp/6. Ældre apps ignorerer typen, så der vises ingen knap.
 */
function app_can_fetch_groups(): bool
{
    return app_webview_version() >= 6;
}

/**
 * Skal "Download gruppen" vises? Altid uden for appen; i appen kun, når
 * appen selv kan hente gruppen (WebView'et kan ikke hente filer).
 */
function group_download_available(): bool
{
    return app_webview_version() === 0 || app_can_fetch_groups();
}

/**
 * Link til download af en hel gruppe som én GPX-fil.
 */
function group_download_url(string $groupPublicId): string
{
    if (app_can_fetch_groups()) {
        return '/app-hent.php?type=gruppe&id=' . rawurlencode($groupPublicId) . app_hent_back_param();
    }

    return '/group-file.php?id=' . rawurlencode($groupPublicId);
}

/**
 * Link til "Download alle mine data".
 */
function data_export_url(): string
{
    return app_can_fetch_files() ? '/app-hent.php?type=export' . app_hent_back_param() : '/export-data.php';
}
