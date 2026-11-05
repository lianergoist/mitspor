<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/app_tilbage.php';

/*
 * Vejledningen (public/vejledning/): en manual i hverdagssprog, bygget op
 * om situationer ("Du skal ud og løbe …"). Én side pr. kapitel.
 *
 * Kapitlerne står i vejledning_kapitler() i den rækkefølge, de læses.
 * Et kapitel med 'klar' => false vises i oversigten som "kommer snart"
 * og har ingen side endnu.
 *
 * Skærmbillederne ligger i public/vejledning/billeder/ og laves med
 * værktøjet i /root/mitspor-arkiv/vejledning (start.sh).
 */

/**
 * @return array<string, array{titel: string, seo: string, kort: string, enhed: string, klar: bool}>
 *         enhed: 'mobil', 'computer' eller 'begge'
 *         seo: titlen til søgemaskiner og deling (<title>, og:title), med de ord folk søger på.
 *         Den synlige overskrift er 'titel'.
 */
function vejledning_kapitler(): array
{
    return [
        'kom-i-gang' => [
            'titel' => 'Kom i gang på 5 minutter',
            'seo'   => 'Kom i gang: hent appen, og opret en gratis konto',
            'kort'  => 'Hent appen, opret en konto, og lær startsiden at kende.',
            'enhed' => 'mobil', 'klar' => true,
        ],
        'foerste-tur' => [
            'titel' => 'Din første tur',
            'seo'   => 'Optag din løbetur med mobilen, og se den på kortet',
            'kort'  => 'Du skal ud og løbe. Optag turen, og se den på kortet, når du kommer hjem.',
            'enhed' => 'mobil', 'klar' => true,
        ],
        'del-turen' => [
            'titel' => 'Del turen med vennerne',
            'seo'   => 'Del din tur eller rute med et link',
            'kort'  => 'Send et link til turen, eller vis den for alle.',
            'enhed' => 'mobil', 'klar' => true,
        ],
        'farligt-sted' => [
            'titel' => 'Indsæt waypoint',
            'seo'   => 'Sæt waypoints med tekst og billeder på din tur',
            'kort'  => 'Markér stedet på kortet med et billede, og ret det bagefter.',
            'enhed' => 'begge', 'klar' => true,
        ],
        'foelg-med' => [
            'titel' => 'Send live',
            'seo'   => 'Live-tracking: lad familien følge din tur på kortet',
            'kort'  => 'Send turen live, så de derhjemme kan se, hvor du er.',
            'enhed' => 'mobil', 'klar' => true,
        ],
        'glemte-stop' => [
            'titel' => 'Rediger en optagelse',
            'seo'   => 'Glemte du at stoppe? Ret i din GPS-optagelse',
            'kort'  => 'Fjern bilturen hjem fra slutningen af turen.',
            'enhed' => 'computer', 'klar' => true,
        ],
        'ferie' => [
            'titel' => 'Grupper og etaper',
            'seo'   => 'Saml en flerdagestur i etaper på ét kort',
            'kort'  => 'Saml dagene til én tur med etaper.',
            'enhed' => 'begge', 'klar' => true,
        ],
        'alle-ture' => [
            'titel' => 'Del alle dine ruter',
            'seo'   => 'Del alle dine ture og ruter med ét link',
            'kort'  => 'Ét link til alle dine ture, også de private.',
            'enhed' => 'begge', 'klar' => true,
        ],
        'planlaeg' => [
            'titel' => 'Tegn en rute',
            'seo'   => 'Tegn en rute på kortet, og hent den som GPX til dit ur',
            'kort'  => 'Tegn en rute på kortet, og send den til dem, du skal af sted med.',
            'enhed' => 'begge', 'klar' => true,
        ],
        'find-tur' => [
            'titel' => 'Offentlige ruter',
            'seo'   => 'Find offentlige ruter, og hent dem som GPX til dit ur',
            'kort'  => 'Kig på andres ture, og hent en til dit ur.',
            'enhed' => 'begge', 'klar' => true,
        ],
        'puls' => [
            'titel' => 'Pulsbælte m.m.',
            'seo'   => 'Brug pulsbælte, kadence og watt med MitSpor-appen',
            'kort'  => 'Gem puls, kadence og watt, og vælg, hvad der vises.',
            'enhed' => 'begge', 'klar' => true,
        ],
        'uden-net' => [
            'titel' => 'På tur uden net',
            'seo'   => 'Optag din tur uden mobildækning (offline)',
            'kort'  => 'Appen optager uden net og sender turen, når du er tilbage.',
            'enhed' => 'mobil', 'klar' => true,
        ],
        'udseende' => [
            'titel' => 'Metadata og markør',
            'seo'   => 'Giv din tur titel, beskrivelse, aktivitet og markør',
            'kort'  => 'Titel, beskrivelse, aktivitet og figuren på kortet.',
            'enhed' => 'begge', 'klar' => true,
        ],
        'datafelter' => [
            'titel' => 'Datafelter',
            'seo'   => 'Vælg datafelter, og fjern puls eller tid fra GPX-filen',
            'kort'  => 'Vælg, hvad turens side viser, og fjern fx tid eller puls fra filen.',
            'enhed' => 'begge', 'klar' => true,
        ],
        'ordbog' => [
            'titel' => 'Lille ordbog',
            'seo'   => 'Ordbog: spor, rute, waypoint og GPX forklaret',
            'kort'  => 'Spor, rute, waypoint og de andre ord forklaret kort.',
            'enhed' => 'begge', 'klar' => true,
        ],
        'naar-noget-driller' => [
            'titel' => 'Når noget driller',
            'seo'   => 'Hjælp til GPS, optagelse, upload og login',
            'kort'  => 'GPS\'en finder dig ikke, turen stoppede, eller den kom ikke op.',
            'enhed' => 'begge', 'klar' => true,
        ],
    ];
}

const VJ_APP_STORE = 'https://apps.apple.com/dk/app/mitspor/id6821337231';
const VJ_GOOGLE_PLAY = 'https://play.google.com/store/apps/details?id=dk.mitspor.app';

/** Knapper til appen i App Store og Google Play. */
function vj_butikker(): string
{
    return '<p class="vj-butikker">'
        . '<a class="btn btn-primary" href="' . VJ_APP_STORE . '">App Store (iPhone)</a>'
        . '<a class="btn btn-primary" href="' . VJ_GOOGLE_PLAY . '">Google Play (Android)</a>'
        . '</p>';
}

function vj_h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/** Lille mærke: Mobil, Computer eller Mobil og computer. */
function vj_enhed(string $enhed): string
{
    $tekst = ['mobil' => 'Mobil', 'computer' => 'Computer', 'begge' => 'Mobil og computer'][$enhed] ?? '';

    return '<span class="vj-enhed vj-enhed--' . vj_h($enhed) . '">' . vj_h($tekst) . '</span>';
}

/**
 * Sidens begyndelse. $slug er kapitlets nøgle, eller '' for oversigten.
 */
function vejledning_start(string $slug, string $titel, string $beskrivelse): void
{
    $url = APP_BASE_URL . '/vejledning/' . ($slug === '' ? '' : $slug . '.php');
    $oversigtUrl = APP_BASE_URL . '/vejledning/';

    // Oversigten får en fuld titel; kapitlerne deres søgetitel ('seo') + "MitSpor".
    $kapitel = $slug === '' ? null : (vejledning_kapitler()[$slug] ?? null);
    $sideTitel = $kapitel === null
        ? 'Vejledning til MitSpor – optag, se og del dine ture'
        : ($kapitel['seo'] ?? $titel) . ' – MitSpor';

    // Delingsbillede: og-<slug>.jpg for kapitlet, ellers det fælles
    // og-vejledning.jpg. Laves med /root/mitspor-arkiv/vejledning-og
    // (lav.sh og lav-kapitler.php). ?v= er filens ændringstid.
    $ogNavn = 'og-' . ($slug === '' ? 'vejledning' : $slug) . '.jpg';
    $billedMappe = __DIR__ . '/../public/vejledning/billeder/';
    if (!is_file($billedMappe . $ogNavn)) {
        $ogNavn = 'og-vejledning.jpg';
    }
    $ogBillede = APP_BASE_URL . '/vejledning/billeder/' . $ogNavn . '?v=' . (int) @filemtime($billedMappe . $ogNavn);
    $ogAlt = $kapitel === null
        ? 'Vejledning til MitSpor: sådan optager, ser og deler du dine ture, trin for trin.'
        : 'Vejledning til MitSpor: ' . $titel . '. ' . $kapitel['kort'];

    // Strukturerede data: brødkrummesti (Forside › Vejledning › Kapitel).
    $krummer = [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'MitSpor', 'item' => APP_BASE_URL . '/'],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'Vejledning', 'item' => $oversigtUrl],
    ];
    if ($slug !== '') {
        $krummer[] = ['@type' => 'ListItem', 'position' => 3, 'name' => $titel, 'item' => $url];
    }
    $jsonLd = [
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => $krummer,
    ];
    $jsonFlag = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP;
    ?>
<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/svg+xml" href="/grafik/favicon.svg">
    <link rel="icon" type="image/png" sizes="32x32" href="/grafik/favicon-32.png">
    <title><?= vj_h($sideTitel) ?></title>
    <meta name="description" content="<?= vj_h($beskrivelse) ?>">
    <link rel="canonical" href="<?= vj_h($url) ?>">
    <meta property="og:type" content="<?= $slug === '' ? 'website' : 'article' ?>">
    <meta property="og:site_name" content="MitSpor">
    <meta property="og:locale" content="da_DK">
    <meta property="og:title" content="<?= vj_h($sideTitel) ?>">
    <meta property="og:description" content="<?= vj_h($beskrivelse) ?>">
    <meta property="og:url" content="<?= vj_h($url) ?>">
    <meta property="og:image" content="<?= vj_h($ogBillede) ?>">
    <meta property="og:image:type" content="image/jpeg">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="<?= vj_h($ogAlt) ?>">
    <meta name="twitter:card" content="summary_large_image">
    <script type="application/ld+json"><?= json_encode($jsonLd, $jsonFlag) ?></script>
    <script src="/assets/tema.js?v=1"></script>
    <link rel="stylesheet" href="/assets/style.css?v=42">
    <link rel="stylesheet" href="/assets/vejledning.css?v=6">
</head>
<body>

<header class="topbar">
    <?php if (show_app_back_link()): ?>
    <a href="/app-luk.php" class="app-tilbage"><span aria-hidden="true">‹</span>Appen</a>
    <?php else: ?>
    <a href="/" class="brand brand-navn"><img class="brand-logo" src="/grafik/favicon.svg" alt="" width="28" height="28"><span class="brand-text">MitSpor</span></a>
    <?php endif; ?>
    <a href="/vejledning/" class="btn">Vejledning</a>
</header>

<main class="container vejledning">
<?php
}

/** Sidens slutning med links til forrige og næste kapitel. */
function vejledning_slut(string $slug): void
{
    $klar = array_filter(vejledning_kapitler(), static fn(array $k): bool => $k['klar']);
    $slugs = array_keys($klar);
    $i = array_search($slug, $slugs, true);
    $forrige = ($i !== false && $i > 0) ? $slugs[$i - 1] : null;
    $naeste = ($i !== false && $i < count($slugs) - 1) ? $slugs[$i + 1] : null;
    ?>
    <?php if ($slug !== ''): ?>
    <nav class="vj-blad" aria-label="Kapitler">
        <?php if ($forrige !== null): ?>
            <a class="vj-blad-forrige" href="/vejledning/<?= vj_h($forrige) ?>.php"><span>Forrige</span><?= vj_h($klar[$forrige]['titel']) ?></a>
        <?php else: ?>
            <a class="vj-blad-forrige" href="/vejledning/"><span>Tilbage</span>Oversigten</a>
        <?php endif; ?>
        <?php if ($naeste !== null): ?>
            <a class="vj-blad-naeste" href="/vejledning/<?= vj_h($naeste) ?>.php"><span>Næste</span><?= vj_h($klar[$naeste]['titel']) ?></a>
        <?php endif; ?>
    </nav>
    <?php endif; ?>

    <p class="vj-fod">
        Har du spørgsmål, eller mangler der noget i vejledningen? Skriv til
        <a href="mailto:info@mitspor.dk">info@mitspor.dk</a>.
    </p>
</main>

</body>
</html>
<?php
}

/** Kapitlets overskrift med mærket Mobil/Computer. */
function vj_overskrift(string $slug): string
{
    $k = vejledning_kapitler()[$slug];

    return '<header class="vj-hoved"><p class="vj-sti"><a href="/vejledning/">Vejledning</a></p>'
        . '<h1>' . vj_h($k['titel']) . '</h1>' . vj_enhed($k['enhed']) . '</header>';
}

/**
 * Et skærmbillede. $type 'mobil' får en telefonramme, 'computer' en
 * browserramme. $fil er filnavnet i public/vejledning/billeder/.
 */
function vj_billede(string $fil, string $alt, string $tekst = '', string $type = 'mobil'): string
{
    // Bredde og højde reserverer pladsen, før billedet er hentet (loading="lazy").
    // ?v= er filens ændringstid, så et nyt skærmbillede altid hentes.
    $sti = __DIR__ . '/../public/vejledning/billeder/' . $fil;
    $maal = @getimagesize($sti);
    $str = $maal ? ' width="' . (int) $maal[0] . '" height="' . (int) $maal[1] . '"' : '';
    $html = '<figure class="vj-billede vj-billede--' . vj_h($type) . '">'
        . '<img src="/vejledning/billeder/' . vj_h($fil) . '?v=' . (int) @filemtime($sti) . '" alt="' . vj_h($alt) . '"' . $str . ' loading="lazy">';
    if ($tekst !== '') {
        $html .= '<figcaption>' . $tekst . '</figcaption>';
    }

    return $html . '</figure>';
}

/**
 * Pladsholder for et skærmbillede, der skal tages på en rigtig telefon
 * (fx telefonens egne dialoger). $beskrivelse siger, hvad billedet viser.
 */
function vj_mangler(string $beskrivelse): string
{
    return '<figure class="vj-billede vj-billede--mobil vj-billede--mangler"><div>'
        . '<strong>Billede fra telefonen kommer</strong>' . vj_h($beskrivelse) . '</div></figure>';
}

/** "Godt at vide"-boks. $html skal allerede være escapet. */
function vj_godt(string $titel, string $html): string
{
    return '<aside class="vj-godt"><p class="vj-godt-titel">Godt at vide: ' . vj_h($titel) . '</p>' . $html . '</aside>';
}

/**
 * Et nummereret trin: overskrift og tekst, evt. med et billede ved siden af
 * (vj_billede() eller vj_mangler()). Trinene skal stå i en
 * <div class="vj-trinliste">, der tæller dem. $html skal være escapet.
 */
function vj_trin(string $titel, string $html, string $billede = ''): string
{
    $klasse = $billede === '' ? ' vj-trin--uden-billede'
        : (str_contains($billede, 'vj-billede--computer') ? ' vj-trin--computer' : '');

    return '<section class="vj-trin' . $klasse . '">'
        . '<div class="vj-trin-tekst"><h3>' . vj_h($titel) . '</h3>' . $html . '</div>'
        . $billede . '</section>';
}
