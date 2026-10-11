<?php
declare(strict_types=1);

/*
 * Linje med link tilbage til mobilappen.
 *
 * Fra MitSporApp/3 åbner appen sitet uden sin egen topbar med "Luk", så
 * sitet viser selv en smal linje øverst med "‹ Appen". Linket fører til
 * /app-luk.php, og når den side indlæses, lukker appen WebView'et. Ældre
 * app-versioner har stadig topbaren og får ikke linjen.
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
 * Udskriv linjen (lige efter <body>). Gør intet uden for appen.
 *
 * Linjen ligger fast øverst, og siden skubbes ned med --app-linje (højden),
 * så intet skjules bag den. Fuldskærmskortene (.map-page i
 * assets/style.css) trækker samme højde fra. Stilen er med her, så linjen
 * ser ens ud på alle sider, også dem uden assets/style.css.
 */
function render_app_back_button(): void
{
    if (app_webview_version() < 3) {
        return;
    }
    ?>
<style>
    :root {
        --app-linje: 34px;
    }
    html {
        box-sizing: border-box;
        padding-top: var(--app-linje);
        scroll-padding-top: var(--app-linje);
    }
    .app-linje {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        /* Over Leaflets knapper (z-index 1000), under dialoger. */
        z-index: 1001;
        box-sizing: border-box;
        height: var(--app-linje);
        display: flex;
        align-items: center;
        padding: 0 calc(env(safe-area-inset-right) + 8px) 0 calc(env(safe-area-inset-left) + 8px);
        background: #f1f5f9;
        border-bottom: 1px solid #e2e8f0;
        font: 600 15px/1 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    }
    .app-linje a {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        height: 100%;
        padding: 0 8px;
        color: #2563eb;
        text-decoration: none;
        -webkit-tap-highlight-color: transparent;
    }
    .app-linje a span {
        font-size: 22px;
        line-height: 1;
        margin-top: -2px;
    }
    .app-linje a:active {
        opacity: 0.6;
    }
    @media print {
        html { padding-top: 0; }
        .app-linje { display: none; }
    }
</style>
<div class="app-linje"><a href="/app-luk.php"><span aria-hidden="true">‹</span>Appen</a></div>
    <?php
}
