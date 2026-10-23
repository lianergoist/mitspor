<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/auth_dialogs.php';
require_once __DIR__ . '/../app/app_login.php';
require_once __DIR__ . '/../app/app_tilbage.php';
require_once __DIR__ . '/../app/videoer.php';

$loggedIn = !empty($_SESSION['user_id']);
$displayName = null;

if ($loggedIn) {
    require_once __DIR__ . '/../app/auth.php';
    $user = current_user((int) $_SESSION['user_id']);
    if ($user === null) {
        $_SESSION = [];
        session_destroy();
        $loggedIn = false;
    } else {
        $displayName = $user['display_name'];
    }
}

// I appen viser forsiden stadig kortet for gæster: appen har sin egen intro,
// og dens startside lover, at "Åbn mitspor.dk" viser alle offentlige ruter.
if (!$loggedIn && is_app_webview()) {
    $query = (string) ($_SERVER['QUERY_STRING'] ?? '');
    header('Location: /kort.php' . ($query !== '' ? '?' . $query : ''));
    exit;
}

$ownRouteCount = 0;
if ($loggedIn) {
    $stmt = get_db()->prepare("SELECT COUNT(*) FROM routes WHERE user_id = :uid AND status = 'active'");
    $stmt->execute(['uid' => (int) $_SESSION['user_id']]);
    $ownRouteCount = (int) $stmt->fetchColumn();
}

// Introvideoen vises på forsiden, når den ligger i public/grafik/intro.mp4.
$introVideoFile = __DIR__ . '/grafik/intro.mp4';
$showVideo = !$loggedIn && is_file($introVideoFile);

$h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/svg+xml" href="/grafik/favicon.svg">
    <link rel="icon" type="image/png" sizes="32x32" href="/grafik/favicon-32.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MitSpor</title>

    <meta name="description" content="MitSpor er et gratis dansk website til at dele og redigere ruter og spor. Upload dine spor, tegn ruter direkte på kortet, og del dem med andre. Ingen tracking, ingen sporings- eller reklamecookies, ingen reklamer.">
    <link rel="canonical" href="<?= APP_BASE_URL ?>/">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="MitSpor">
    <meta property="og:title" content="MitSpor — Del og redigér ruter og spor">
    <meta property="og:description" content="Gratis dansk website til at dele og redigere ruter og spor. Upload dine spor, tegn ruter direkte på kortet, og del dem med andre.">
    <meta property="og:url" content="<?= APP_BASE_URL ?>/">
    <meta property="og:image" content="<?= APP_BASE_URL ?>/grafik/mitspor_full.png">
    <meta name="twitter:card" content="summary_large_image">

    <link rel="stylesheet" href="/assets/style.css?v=32">
    <link rel="stylesheet" href="/assets/videoer.css?v=1">
    <link rel="stylesheet" href="/assets/forside.css?v=2">
</head>
<body class="forside">

<header class="topbar" id="forside-topbar">
    <?php if (show_app_back_link()): ?>
    <a href="/app-luk.php" class="app-tilbage"><span aria-hidden="true">‹</span>Appen</a>
    <?php else: ?>
    <a href="/" class="brand brand-navn"><img src="/grafik/favicon.svg" alt="" width="28" height="28"><span class="brand-text">MitSpor</span></a>
    <?php endif; ?>

    <div class="topbar-actions">
        <?php if ($loggedIn): ?>
            <a href="/dashboard.php" class="btn" title="Kontrolpanel" aria-label="Kontrolpanel"><svg class="tb-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="6" cy="19" r="3"/><path d="M9 19h8.5a3.5 3.5 0 0 0 0-7h-11a3.5 3.5 0 0 1 0-7H15"/><circle cx="18" cy="5" r="3"/></svg><span class="tb-label">Kontrolpanel</span></a>
            <form method="post" action="/logout.php">
                <input type="hidden" name="csrf_token" value="<?= $h(csrf_token()) ?>">
                <button type="submit" class="btn" title="Log ud" aria-label="Log ud"><svg class="tb-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="9" cy="7" r="4"/><path d="M2 21v-2a4 4 0 0 1 4-4h6a4 4 0 0 1 4 4v2"/><path d="M16 11h6"/><path d="M19 8l3 3-3 3"/></svg><span class="tb-label">Log ud</span></button>
            </form>
        <?php else: ?>
            <button type="button" id="btn-open-register" class="btn" title="Opret konto" aria-label="Opret konto"><svg class="tb-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="9" cy="7" r="4"/><path d="M2 21v-2a4 4 0 0 1 4-4h6a4 4 0 0 1 4 4v2"/><path d="M19 8v6"/><path d="M16 11h6"/></svg><span class="tb-label">Opret konto</span></button>
            <button type="button" id="btn-open-login" class="btn" title="Log ind" aria-label="Log ind"><svg class="tb-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="9" cy="7" r="4"/><path d="M2 21v-2a4 4 0 0 1 4-4h6a4 4 0 0 1 4 4v2"/><path d="M22 11h-6"/><path d="M19 8l-3 3 3 3"/></svg><span class="tb-label">Log ind</span></button>
        <?php endif; ?>
        <button type="button" id="btn-open-about" class="btn" title="Hjælp" aria-label="Hjælp"><span class="help-icon" aria-hidden="true">?</span><span class="tb-label">Hjælp</span></button>
    </div>
</header>

<dialog id="about-dialog">
    <div class="dialog-header">
        <h2>Om MitSpor</h2>
        <button type="button" id="about-dialog-close" class="close-btn" aria-label="Luk">&times;</button>
    </div>
    <div class="dialog-content">
        <p>Formålet med MitSpor er at dele spor og ruter. Man kan uploade sine spor (eller tegne en rute) og få den vist på et kort man kan linke til og dele med andre. Man kan også redigere spor og ruter: i den indbyggede GPX-editor kan man flytte punkter og indsætte kommentarer og billeder, og i kontrolpanelet kan man fx udtynde punkter, hente højdedata og vælge, hvad rutens side viser.</p>
        <p>Man kan markere spor og ruter som offentlige eller private.</p>
        <ul>
            <li><strong>Offentlig</strong> betyder, at alle kan se ruten. Man kan søge i titel og beskrivelse, og få vist alle de offentlige spor og ruter der matcher.</li>
            <li><strong>Privat</strong> betyder, at det kun er dig, der kan se sporet/ruten — med mindre du laver et del-link, der giver adgang til netop den rute.</li>
        </ul>
        <p>MitSpor er gratis at bruge. Alt det kræver er en emailadresse, og så er du i gang. Du kan downloade dine data og slette din profil som det passer dig. Ingen tracking, ingen sporings- eller reklamecookies, ingen deling af dine data med andre. Ægte gratis.</p>
        <p>Har du spørgsmål eller kommentarer, er du velkommen til at skrive til <a href="mailto:info@mitspor.dk">info@mitspor.dk</a>.</p>
        <p>Læs <a href="/privatlivspolitik.php">privatlivspolitikken</a>.</p>
    </div>
</dialog>

<?php if ($loggedIn): ?>
<main class="forside-main">
    <section class="hjem-hilsen">
        <h1>Hej, <?= $h((string) $displayName) ?></h1>
        <p><?= $ownRouteCount === 0
            ? 'Du har ingen ruter endnu. Tegn din første rute, eller upload et GPX-spor i kontrolpanelet.'
            : 'Du har ' . $ownRouteCount . ' ' . ($ownRouteCount === 1 ? 'rute eller spor' : 'ruter og spor') . '. Hvad vil du nu?' ?></p>
    </section>

    <nav class="hjem-valg" aria-label="Vælg">
        <a href="/kort.php" class="hjem-kort">
            <svg class="hjem-ikon tb-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3a14 14 0 0 1 0 18a14 14 0 0 1 0-18z"/></svg>
            <span class="hjem-titel">Offentlige ruter</span>
            <span class="hjem-tekst">Se alle offentlige ruter og spor på kortet, og søg i dem.</span>
        </a>
        <a href="/kort.php?mine=1" class="hjem-kort">
            <svg class="hjem-ikon tb-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M9 4 3 6v14l6-2 6 2 6-2V4l-6 2z"/><path d="M9 4v14"/><path d="M15 6v14"/></svg>
            <span class="hjem-titel">Mine ruter på kort</span>
            <span class="hjem-tekst">Alle dine egne ruter og spor på ét kort, også de private.</span>
        </a>
        <a href="/dashboard.php" class="hjem-kort">
            <svg class="hjem-ikon tb-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="6" cy="19" r="3"/><path d="M9 19h8.5a3.5 3.5 0 0 0 0-7h-11a3.5 3.5 0 0 1 0-7H15"/><circle cx="18" cy="5" r="3"/></svg>
            <span class="hjem-titel">Kontrolpanel</span>
            <span class="hjem-tekst">Upload GPX, del, redigér og saml ruter i grupper.</span>
        </a>
        <a href="/editor/?new=1" class="hjem-kort">
            <svg class="hjem-ikon tb-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>
            <span class="hjem-titel">Tegn ny rute</span>
            <span class="hjem-tekst">Klik ruten ind på kortet i editoren, og gem den.</span>
        </a>
    </nav>
</main>

<?php else: ?>
<main class="forside-main">
    <?php if (isset($_GET['deleted'])): ?>
    <p class="forside-besked" role="status">Din konto er blevet slettet.</p>
    <?php endif; ?>

    <section class="forside-hero">
        <h1>Del dine ruter og spor, til vands og til lands</h1>
        <p class="forside-lead">Upload dine GPX-spor, tegn nye ruter på kortet, og del dem med andre. MitSpor er gratis og uden reklamer.</p>
        <div class="forside-knapper">
            <button type="button" class="btn btn-primary" data-aabn="register">Opret gratis konto</button>
            <a href="/kort.php" class="btn">Se offentlige ruter</a>
        </div>
    </section>

    <?php if ($showVideo): ?>
    <section class="forside-video" aria-labelledby="forside-video-titel">
        <h2 id="forside-video-titel">Se, hvordan det virker <span class="forside-varighed">(1 min)</span></h2>
        <div class="forside-video-ramme">
            <video src="/grafik/intro.mp4?v=<?= (int) filemtime($introVideoFile) ?>" controls preload="none" playsinline<?php if (is_file(__DIR__ . '/grafik/intro-plakat.jpg')): ?> poster="/grafik/intro-plakat.jpg?v=<?= (int) filemtime(__DIR__ . '/grafik/intro-plakat.jpg') ?>"<?php endif; ?>>
                <?php if (is_file(__DIR__ . '/grafik/intro.vtt')): ?>
                <?php /* Teksten er brændt ind i videoen; sporet kan slås til i afspilleren, fx til skærmlæsere. */ ?>
                <track kind="subtitles" src="/grafik/intro.vtt?v=<?= (int) filemtime(__DIR__ . '/grafik/intro.vtt') ?>" srclang="da" label="Dansk">
                <?php endif; ?>
            </video>
        </div>
    </section>
    <?php endif; ?>

    <section class="forside-sektion" aria-labelledby="forside-kan-titel">
        <h2 id="forside-kan-titel">Det kan du med MitSpor</h2>
        <ul class="forside-funktioner">
            <li>
                <svg class="tb-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 15V3"/><path d="m7 8 5-5 5 5"/><path d="M5 15v4a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-4"/></svg>
                <div><h3>Upload dine spor</h3><p>Læg GPX-filer fra uret, telefonen eller GPS'en op, og se dem på kortet med afstand og højdeprofil.</p></div>
            </li>
            <li>
                <svg class="tb-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>
                <div><h3>Tegn og redigér ruter</h3><p>Planlæg en ny tur i editoren, flyt punkter, og tilføj kommentarer og billeder undervejs.</p></div>
            </li>
            <li>
                <svg class="tb-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="m8.6 13.5 6.8 4"/><path d="m15.4 6.5-6.8 4"/></svg>
                <div><h3>Del med andre</h3><p>Gør en rute offentlig, eller hold den privat og send et link til dem, der skal se den.</p></div>
            </li>
            <li>
                <svg class="tb-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect x="3" y="4" width="7" height="7" rx="1"/><rect x="14" y="4" width="7" height="7" rx="1"/><rect x="3" y="15" width="7" height="5" rx="1"/><rect x="14" y="15" width="7" height="5" rx="1"/></svg>
                <div><h3>Saml etaper i grupper</h3><p>En flerdagestur bliver til én tur med alle etaperne, og man kan bladre fra etape til etape.</p></div>
            </li>
            <li>
                <svg class="tb-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect x="7" y="2" width="10" height="20" rx="2"/><path d="M11 18h2"/></svg>
                <div><h3>Optag og send live med appen</h3><p>Optag turen med MitSpor-appen på telefonen. Send den live, så familien kan følge med på kortet.</p></div>
            </li>
            <li>
                <svg class="tb-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.35-4.35"/></svg>
                <div><h3>Find nye ture</h3><p>Se andres offentlige ruter på kortet uden at logge ind, og søg efter sted eller navn.</p></div>
            </li>
        </ul>
    </section>

    <section class="forside-sektion forside-gratis" aria-labelledby="forside-gratis-titel">
        <h2 id="forside-gratis-titel">Gratis, og dine data er dine</h2>
        <ul class="forside-tjek">
            <li>Ingen reklamer, ingen sporings- eller reklamecookies.</li>
            <li>Dine data deles ikke med andre.</li>
            <li>Du bestemmer, om en rute er offentlig eller privat.</li>
            <li>Du kan hente alle dine data og slette din konto, når du vil.</li>
        </ul>
    </section>

    <section class="forside-sektion" aria-labelledby="forside-start-titel">
        <h2 id="forside-start-titel">Kom i gang</h2>
        <ol class="forside-trin">
            <li><strong>Opret en konto.</strong> Det kræver kun en e-mailadresse.</li>
            <li><strong>Upload et spor, eller tegn en rute.</strong></li>
            <li><strong>Del linket</strong>, eller gør ruten offentlig.</li>
        </ol>
        <div class="forside-knapper">
            <button type="button" class="btn btn-primary" data-aabn="register">Opret gratis konto</button>
            <button type="button" class="btn" data-aabn="login">Jeg har en konto</button>
        </div>
    </section>
</main>
<?php endif; ?>

<footer class="forside-fod">
    <a href="/privatlivspolitik.php">Privatlivspolitik</a>
    <a href="mailto:info@mitspor.dk">info@mitspor.dk</a>
</footer>

<?php if (!$loggedIn) { render_auth_dialogs(); } ?>

<script>
(function () {
    "use strict";
    var aboutDialog = document.getElementById('about-dialog');
    document.getElementById('btn-open-about').addEventListener('click', function () {
        aboutDialog.showModal();
    });
    document.getElementById('about-dialog-close').addEventListener('click', function () {
        aboutDialog.close();
    });
    aboutDialog.addEventListener('click', function (ev) {
        if (ev.target === aboutDialog) aboutDialog.close();
    });

    // Knapperne i teksten åbner de samme dialoger som topbarens knapper.
    document.querySelectorAll('[data-aabn]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var target = document.getElementById('btn-open-' + btn.getAttribute('data-aabn'));
            if (target) target.click();
        });
    });
})();
</script>
<?php if (!$loggedIn): ?>
<script src="/assets/genindlaes.js?v=1"></script>
<script src="/assets/auth-dialogs.js?v=3"></script>
<script>MitSporAuth.init({ handleUrl: true });</script>
<?php endif; ?>
<script src="/assets/videoer.js?v=1"></script>

</body>
</html>
