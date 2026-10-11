<?php
declare(strict_types=1);

/*
 * Knap tilbage til mobilappen.
 *
 * Fra MitSporApp/3 åbner appen sitet uden sin egen topbar med "Luk", så
 * sitet viser selv en lille knap. Den fører til /app-luk.php, og når den
 * side indlæses, lukker appen WebView'et. Ældre app-versioner har stadig
 * topbaren og får ikke knappen.
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
 * Udskriv knappen (lige efter <body>). Gør intet uden for appen.
 * Stilen er med her, så knappen ser ens ud på alle sider, også dem
 * uden assets/style.css.
 */
function render_app_back_button(): void
{
    if (app_webview_version() < 3) {
        return;
    }
    ?>
<style>
    .app-tilbage {
        position: fixed;
        left: calc(env(safe-area-inset-left) + 10px);
        bottom: calc(env(safe-area-inset-bottom) + 12px);
        /* Over Leaflets knapper (z-index 1000), under dialoger. */
        z-index: 1001;
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 7px 12px 7px 9px;
        border: 1px solid #e2e8f0;
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.94);
        box-shadow: 0 2px 8px rgba(15, 23, 42, 0.18);
        color: #1e293b;
        font: 600 14px/1 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        text-decoration: none;
        -webkit-tap-highlight-color: transparent;
    }
    .app-tilbage span {
        font-size: 20px;
        line-height: 14px;
    }
    .app-tilbage:active {
        background: #e2e8f0;
    }
    @media print {
        .app-tilbage { display: none; }
    }
</style>
<a href="/app-luk.php" class="app-tilbage"><span aria-hidden="true">‹</span>Appen</a>
    <?php
}
