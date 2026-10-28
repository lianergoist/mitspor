<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/simple_page.php';

$referringPublicId = (string) ($_GET['route'] ?? '');

$pdo = get_db();

// Find uploaderen via den rute, linket kom fra — og kun hvis DEN rute
// selv har navnet synligt. Er den rute anonym, findes der ingen legal
// indgang til at se, hvem uploaderen er, via denne side.
$stmt = $pdo->prepare(
    "SELECT r.user_id, u.display_name
     FROM routes r
     JOIN users u ON u.id = r.user_id
     WHERE r.public_id = :public_id
       AND r.status = 'active'
       AND r.visibility = 'public'
       AND r.hide_uploader_name = 0
     LIMIT 1"
);
$stmt->execute(['public_id' => $referringPublicId]);
$referring = $stmt->fetch();

if (!$referring) {
    http_response_code(404);
    simple_message_page('Siden blev ikke fundet', 'Der er intet at vise her.');
    exit;
}

$stmt = $pdo->prepare(
    "SELECT r.public_id, r.title, a.name_da AS activity_name, r.distance_meters
     FROM routes r
     JOIN activity_types a ON a.id = r.activity_type_id
     WHERE r.user_id = :user_id
       AND r.status = 'active'
       AND r.visibility = 'public'
       AND r.hide_uploader_name = 0
     ORDER BY r.title ASC"
);
$stmt->execute(['user_id' => $referring['user_id']]);
$routes = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/svg+xml" href="/grafik/favicon.svg">
    <link rel="icon" type="image/png" sizes="32x32" href="/grafik/favicon-32.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($referring['display_name'], ENT_QUOTES, 'UTF-8') ?>s offentlige ruter</title>
    <link rel="stylesheet" href="assets/style.css?v=35">
</head>
<body>

<div class="topbar">
    <a href="index.php" class="brand">&larr; MitSpor</a>
</div>

<div class="container">
    <div class="card">
        <h1><?= htmlspecialchars($referring['display_name'], ENT_QUOTES, 'UTF-8') ?>s offentlige ruter</h1>

        <?php if (empty($routes)): ?>
            <p class="empty-state">Ingen offentlige ruter at vise.</p>
        <?php else: ?>
            <ul class="route-list">
                <?php foreach ($routes as $route): ?>
                    <li>
                        <div>
                            <a class="route-title" href="route-view.php?id=<?= htmlspecialchars($route['public_id'], ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($route['title'], ENT_QUOTES, 'UTF-8') ?>
                            </a>
                            <span class="route-visibility">
                                <?= htmlspecialchars($route['activity_name'], ENT_QUOTES, 'UTF-8') ?>
                                &middot; <?= number_format($route['distance_meters'] / 1000, 1) ?> km
                            </span>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>

</body>
</html>
