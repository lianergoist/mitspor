<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/auth.php';

$userId = require_login();
$user = current_user($userId);

if ($user === null) {
    $_SESSION = [];
    session_destroy();
    header('Location: ../login.php');
    exit;
}

if (!$user['is_admin']) {
    http_response_code(403);
    echo 'Ingen adgang.';
    exit;
}

$pdo = get_db();

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function tal(int|float $n, int $decimaler = 0): string
{
    return number_format((float) $n, $decimaler, ',', '.');
}

// Tal lige nu. Slettede brugere og ruter tælles ikke med her.
$nu = $pdo->query(
    "SELECT
        (SELECT COUNT(*) FROM users WHERE status = 'active') AS brugere,
        (SELECT COUNT(*) FROM users WHERE status = 'active' AND email_verified_at IS NOT NULL) AS brugere_bekraeftet,
        (SELECT COUNT(*) FROM users WHERE status = 'active' AND last_active_at >= NOW() - INTERVAL 30 DAY) AS brugere_aktive_30,
        (SELECT COUNT(DISTINCT user_id) FROM routes WHERE status = 'active') AS brugere_med_ruter,
        (SELECT COUNT(*) FROM routes WHERE status = 'active') AS ruter,
        (SELECT COUNT(*) FROM routes WHERE status = 'active' AND visibility = 'public') AS ruter_offentlige,
        (SELECT COALESCE(SUM(distance_meters), 0) FROM routes WHERE status = 'active') AS meter,
        (SELECT COUNT(*) FROM route_groups) AS grupper,
        (SELECT COUNT(*) FROM route_comments WHERE status = 'visible') AS kommentarer,
        (SELECT COUNT(*) FROM route_likes) AS likes,
        (SELECT COUNT(*) FROM user_images) AS billeder,
        (SELECT COUNT(*) FROM live_events) AS live_events,
        (SELECT COUNT(*) FROM api_tokens WHERE revoked_at IS NULL AND (expires_at IS NULL OR expires_at > NOW())) AS app_enheder,
        (SELECT COALESCE(SUM(storage_used_bytes), 0) FROM users WHERE status = 'active') AS lager"
)->fetch();

// Nye i en periode. Her tælles alt, der er oprettet, også det der siden er slettet.
$perioder = [
    '7 dage' => 7,
    '30 dage' => 30,
    '365 dage' => 365,
];
$nyeStmt = $pdo->prepare(
    "SELECT
        (SELECT COUNT(*) FROM users WHERE created_at >= NOW() - INTERVAL :d1 DAY) AS brugere,
        (SELECT COUNT(*) FROM routes WHERE created_at >= NOW() - INTERVAL :d2 DAY) AS ruter,
        (SELECT COUNT(*) FROM route_comments WHERE created_at >= NOW() - INTERVAL :d3 DAY) AS kommentarer,
        (SELECT COUNT(*) FROM live_events WHERE created_at >= NOW() - INTERVAL :d4 DAY) AS live_events"
);
$nye = [];
foreach ($perioder as $navn => $dage) {
    $nyeStmt->execute([':d1' => $dage, ':d2' => $dage, ':d3' => $dage, ':d4' => $dage]);
    $nye[$navn] = $nyeStmt->fetch();
}

// Pr. måned de seneste 12 måneder (inkl. den indeværende).
$maaneder = [];
$start = new DateTimeImmutable('first day of this month 00:00');
for ($i = 11; $i >= 0; $i--) {
    $m = $start->modify("-{$i} months")->format('Y-m');
    $maaneder[$m] = ['brugere' => 0, 'ruter' => 0, 'meter' => 0];
}
$fra = $start->modify('-11 months')->format('Y-m-d H:i:s');

$stmt = $pdo->prepare(
    "SELECT DATE_FORMAT(created_at, '%Y-%m') AS m, COUNT(*) AS n
     FROM users WHERE created_at >= :fra GROUP BY m"
);
$stmt->execute([':fra' => $fra]);
foreach ($stmt->fetchAll() as $r) {
    if (isset($maaneder[$r['m']])) {
        $maaneder[$r['m']]['brugere'] = (int) $r['n'];
    }
}

$stmt = $pdo->prepare(
    "SELECT DATE_FORMAT(created_at, '%Y-%m') AS m, COUNT(*) AS n, COALESCE(SUM(distance_meters), 0) AS meter
     FROM routes WHERE created_at >= :fra GROUP BY m"
);
$stmt->execute([':fra' => $fra]);
foreach ($stmt->fetchAll() as $r) {
    if (isset($maaneder[$r['m']])) {
        $maaneder[$r['m']]['ruter'] = (int) $r['n'];
        $maaneder[$r['m']]['meter'] = (int) $r['meter'];
    }
}

$maxBrugere = max(1, max(array_column($maaneder, 'brugere')));
$maxRuter = max(1, max(array_column($maaneder, 'ruter')));

$maanedNavne = ['jan', 'feb', 'mar', 'apr', 'maj', 'jun', 'jul', 'aug', 'sep', 'okt', 'nov', 'dec'];

// Aktive ruter fordelt på aktivitet.
$aktiviteter = $pdo->query(
    "SELECT COALESCE(a.name_da, 'Ingen aktivitet') AS navn, COUNT(*) AS n, COALESCE(SUM(r.distance_meters), 0) AS meter
     FROM routes r
     LEFT JOIN activity_types a ON a.id = r.activity_type_id
     WHERE r.status = 'active'
     GROUP BY navn
     ORDER BY n DESC, navn"
)->fetchAll();

// Brugere med flest aktive ruter.
$topBrugere = $pdo->query(
    "SELECT u.display_name, COUNT(*) AS n, COALESCE(SUM(r.distance_meters), 0) AS meter
     FROM routes r
     JOIN users u ON u.id = r.user_id
     WHERE r.status = 'active' AND u.status = 'active'
     GROUP BY u.id, u.display_name
     ORDER BY n DESC
     LIMIT 10"
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="UTF-8">
    <title>Admin — statistik</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 960px; margin: 1rem auto; padding: 0 1rem; }
        table { border-collapse: collapse; margin-bottom: 1.5rem; }
        th, td { padding: .25rem .6rem; text-align: left; border-bottom: 1px solid #ddd; }
        td.tal, th.tal { text-align: right; font-variant-numeric: tabular-nums; }
        .bjaelke { display: inline-block; height: .7rem; background: #2e7d32; vertical-align: middle; }
        .bjaelke.ruter { background: #1565c0; }
        .lille { color: #666; font-size: .9em; }
    </style>
</head>
<body>

<p><a href="/admin/index.php">← Til admin</a></p>

<h1>Statistik</h1>
<p class="lille">Opdateret <?= h(date('d-m-Y H:i')) ?>.</p>

<h2>Lige nu</h2>
<table>
    <tr><td>Brugere</td><td class="tal"><?= tal((int) $nu['brugere']) ?></td>
        <td class="lille"><?= tal((int) $nu['brugere_bekraeftet']) ?> med bekræftet e-mail</td></tr>
    <tr><td>Aktive de seneste 30 dage</td><td class="tal"><?= tal((int) $nu['brugere_aktive_30']) ?></td><td></td></tr>
    <tr><td>Brugere med mindst én rute</td><td class="tal"><?= tal((int) $nu['brugere_med_ruter']) ?></td><td></td></tr>
    <tr><td>Ruter</td><td class="tal"><?= tal((int) $nu['ruter']) ?></td>
        <td class="lille"><?= tal((int) $nu['ruter_offentlige']) ?> offentlige</td></tr>
    <tr><td>Samlet distance</td><td class="tal"><?= tal($nu['meter'] / 1000) ?> km</td><td></td></tr>
    <tr><td>Grupper</td><td class="tal"><?= tal((int) $nu['grupper']) ?></td><td></td></tr>
    <tr><td>Kommentarer</td><td class="tal"><?= tal((int) $nu['kommentarer']) ?></td><td></td></tr>
    <tr><td>Likes</td><td class="tal"><?= tal((int) $nu['likes']) ?></td><td></td></tr>
    <tr><td>Billeder</td><td class="tal"><?= tal((int) $nu['billeder']) ?></td><td></td></tr>
    <tr><td>Live-events</td><td class="tal"><?= tal((int) $nu['live_events']) ?></td><td></td></tr>
    <tr><td>Enheder logget ind i appen</td><td class="tal"><?= tal((int) $nu['app_enheder']) ?></td><td></td></tr>
    <tr><td>Brugt lager</td><td class="tal"><?= tal($nu['lager'] / 1024 / 1024, 1) ?> MB</td><td></td></tr>
</table>

<h2>Nye</h2>
<p class="lille">Alt, der er oprettet i perioden, også det der siden er slettet.</p>
<table>
    <tr><th></th><?php foreach ($perioder as $navn => $_): ?><th class="tal"><?= h($navn) ?></th><?php endforeach; ?></tr>
    <?php foreach (['brugere' => 'Brugere', 'ruter' => 'Ruter', 'kommentarer' => 'Kommentarer', 'live_events' => 'Live-events'] as $felt => $label): ?>
        <tr>
            <td><?= h($label) ?></td>
            <?php foreach ($perioder as $navn => $_): ?>
                <td class="tal"><?= tal((int) $nye[$navn][$felt]) ?></td>
            <?php endforeach; ?>
        </tr>
    <?php endforeach; ?>
</table>

<h2>Pr. måned</h2>
<table>
    <tr><th>Måned</th><th class="tal">Nye brugere</th><th></th><th class="tal">Nye ruter</th><th></th><th class="tal">Km</th></tr>
    <?php foreach (array_reverse($maaneder, true) as $m => $v): ?>
        <?php [$aar, $mdr] = explode('-', $m); ?>
        <tr>
            <td><?= h($maanedNavne[(int) $mdr - 1] . ' ' . $aar) ?></td>
            <td class="tal"><?= tal($v['brugere']) ?></td>
            <td><span class="bjaelke" style="width: <?= round($v['brugere'] / $maxBrugere * 120) ?>px"></span></td>
            <td class="tal"><?= tal($v['ruter']) ?></td>
            <td><span class="bjaelke ruter" style="width: <?= round($v['ruter'] / $maxRuter * 120) ?>px"></span></td>
            <td class="tal"><?= tal($v['meter'] / 1000) ?></td>
        </tr>
    <?php endforeach; ?>
</table>

<h2>Ruter pr. aktivitet</h2>
<table>
    <tr><th>Aktivitet</th><th class="tal">Ruter</th><th class="tal">Km</th></tr>
    <?php foreach ($aktiviteter as $a): ?>
        <tr>
            <td><?= h($a['navn']) ?></td>
            <td class="tal"><?= tal((int) $a['n']) ?></td>
            <td class="tal"><?= tal($a['meter'] / 1000) ?></td>
        </tr>
    <?php endforeach; ?>
</table>

<h2>Flest ruter</h2>
<table>
    <tr><th>Bruger</th><th class="tal">Ruter</th><th class="tal">Km</th></tr>
    <?php foreach ($topBrugere as $b): ?>
        <tr>
            <td><?= h($b['display_name']) ?></td>
            <td class="tal"><?= tal((int) $b['n']) ?></td>
            <td class="tal"><?= tal($b['meter'] / 1000) ?></td>
        </tr>
    <?php endforeach; ?>
</table>

</body>
</html>
