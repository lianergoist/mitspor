<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/videoer.php';

$h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

/** Varighed som "1:10" ud fra mp4-filens mvhd-boks (uden ffprobe). */
function video_duration_label(string $file): string
{
    $data = @file_get_contents($file, false, null, 0, 4096);
    $pos = $data === false ? false : strpos($data, 'mvhd');
    if ($pos === false) {
        return '';
    }
    $version = ord($data[$pos + 4]);
    if ($version === 1) {
        $scale = unpack('N', substr($data, $pos + 24, 4))[1];
        $dur = unpack('J', substr($data, $pos + 28, 8))[1];
    } else {
        $scale = unpack('N', substr($data, $pos + 16, 4))[1];
        $dur = unpack('N', substr($data, $pos + 20, 4))[1];
    }
    if ($scale <= 0) {
        return '';
    }
    $sec = (int) round($dur / $scale);
    return sprintf('%d:%02d', intdiv($sec, 60), $sec % 60);
}
?>
<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/svg+xml" href="/grafik/favicon.svg">
    <link rel="icon" type="image/png" sizes="32x32" href="/grafik/favicon-32.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Videoer – MitSpor</title>
    <meta name="description" content="Korte videoer, der viser, hvordan du bruger MitSpor: find ruter, upload, editoren, grupper, del-link, live-tracking og appen.">
    <link rel="canonical" href="<?= APP_BASE_URL ?>/videoer.php">
    <link rel="stylesheet" href="/assets/style.css?v=35">
    <link rel="stylesheet" href="/assets/videoer.css?v=1">
</head>
<body>

<header class="topbar">
    <a href="/index.php" class="brand brand-navn"><span class="brand-text">MitSpor</span></a>
    <a href="/index.php" class="btn">Til forsiden</a>
</header>

<main class="container videos-page">
    <h1>Videoer</h1>
    <p class="videos-intro">Korte videoer, der viser, hvordan du bruger MitSpor. De fleste varer omkring et minut og har tekst, så de kan ses uden lyd.</p>

    <?php foreach (instruction_videos() as $section => $videos): ?>
        <h2><?= $h($section) ?></h2>
        <div class="video-grid<?= $section === 'Appen' ? ' video-grid-portrait' : '' ?>">
            <?php foreach ($videos as $key => [$title, $desc]): ?>
                <?php $time = video_duration_label(__DIR__ . '/videoer/' . $key . '.mp4'); ?>
                <button type="button" class="video-card" data-video="<?= $h($key) ?>" data-title="<?= $h($title) ?>"<?= instruction_video_portrait($key) ? ' data-portrait="1"' : '' ?>>
                    <span class="video-card-img"><img src="/videoer/<?= $h($key) ?>.jpg" alt="" loading="lazy"></span>
                    <span class="video-card-text">
                        <span class="video-card-title"><?= $h($title) ?></span>
                        <span class="video-card-desc"><?= $h($desc) ?></span>
                        <?php if ($time !== ''): ?><span class="video-card-time"><?= $h($time) ?> min</span><?php endif; ?>
                    </span>
                </button>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
</main>

<script src="/assets/videoer.js?v=1"></script>
</body>
</html>
