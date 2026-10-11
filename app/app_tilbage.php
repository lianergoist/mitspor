<?php
declare(strict_types=1);

/*
 * Link tilbage til mobilappen.
 *
 * Fra MitSporApp/3 åbner appen sitet uden sin egen topbar med "Luk". I
 * stedet står "‹ Appen" i forsidens topbar, hvor logoet ellers er. Linket
 * fører til /app-luk.php, og når den side indlæses, lukker appen
 * WebView'et. Ældre app-versioner har stadig topbaren og får ikke linket.
 */

/**
 * Appens version fra user-agent ("MitSporApp/<n>"), eller 0 uden for appen.
 * Kun til visning, ikke til adgang.
 */
function app_webview_version(): int
{
    if (!preg_match('/MitSporApp\/(\d+)/', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), $m)) {
        return 0;
    }

    return (int) $m[1];
}

/**
 * Skal forsiden vise "‹ Appen" i stedet for logoet?
 * Stilen (.app-tilbage) ligger i assets/style.css.
 */
function show_app_back_link(): bool
{
    return app_webview_version() >= 3;
}
