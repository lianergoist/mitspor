<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/moderation.php';

$userId = require_login();
$user = current_user($userId);

if ($user === null) {
    $_SESSION = [];
    session_destroy();
    header('Location: login.php');
    exit;
}

$routePublicId = (string) ($_GET['id'] ?? $_POST['route_id'] ?? '');

$pdo = get_db();
$stmt = $pdo->prepare("SELECT id, public_id, title, user_id FROM routes WHERE public_id = :public_id AND status = 'active' LIMIT 1");
$stmt->execute(['public_id' => $routePublicId]);
$route = $stmt->fetch();

if (!$route) {
    http_response_code(404);
    echo 'Ruten blev ikke fundet.';
    exit;
}

if ((int) $route['user_id'] === $userId) {
    echo 'Du kan ikke rapportere din egen rute.';
    exit;
}

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Din session er udløbet. Genindlæs siden og prøv igen.';
    }

    $reason = (string) ($_POST['reason'] ?? '');

    if (empty($errors)) {
        $result = submit_report($pdo, $userId, (int) $route['id'], null, $reason);
        if ($result['success']) {
            $success = true;
        } else {
            $errors = $result['errors'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="grafik/mitspor_50x50.png">
    <meta name="robots" content="noindex, nofollow">
    <title>Rapportér rute</title>
</head>
<body>

<h1>Rapportér "<?= htmlspecialchars($route['title'], ENT_QUOTES, 'UTF-8') ?>"</h1>

<?php if ($success): ?>

    <p>Tak — din rapport er modtaget og bliver gennemgået.</p>
    <p><a href="route-view.php?id=<?= htmlspecialchars($route['public_id'], ENT_QUOTES, 'UTF-8') ?>">Tilbage til ruten</a></p>

<?php else: ?>

    <?php if (!empty($errors)): ?>
        <ul>
            <?php foreach ($errors as $error): ?>
                <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <form method="post" action="report-route.php?id=<?= htmlspecialchars($route['public_id'], ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="route_id" value="<?= htmlspecialchars($route['public_id'], ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">

        <label>
            Hvorfor rapporterer du denne rute?
            <textarea name="reason" required minlength="5" maxlength="2000"></textarea>
        </label>

        <button type="submit">Send rapport</button>
    </form>

    <p><a href="route-view.php?id=<?= htmlspecialchars($route['public_id'], ENT_QUOTES, 'UTF-8') ?>">Annullér</a></p>

<?php endif; ?>

</body>
</html>
