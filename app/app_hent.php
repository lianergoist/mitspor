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
 */

/**
 * Kan appen hente filer selv?
 */
function app_can_fetch_files(): bool
{
    return app_webview_version() >= 4;
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
        return '/app-hent.php?type=gpx&id=' . rawurlencode($publicId) . $share;
    }

    return '/route-file.php?id=' . rawurlencode($publicId) . '&mode=download' . $share;
}

/**
 * Link til "Download alle mine data".
 */
function data_export_url(): string
{
    return app_can_fetch_files() ? '/app-hent.php?type=export' : '/export-data.php';
}
