<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/route_deletion.php';

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
$stmt = $pdo->prepare(
    "SELECT * FROM routes WHERE public_id = :public_id AND user_id = :user_id AND status = 'active' LIMIT 1"
);
$stmt->execute(['public_id' => $routePublicId, 'user_id' => $userId]);
$route = $stmt->fetch();

if (!$route) {
    http_response_code(404);
    echo 'Ruten blev ikke fundet, eller tilhører dig ikke.';
    exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Din session er udløbet. Genindlæs siden og prøv igen.';
    }

    $password = (string) ($_POST['password'] ?? '');

    if (empty($errors)) {
        $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        $passwordHash = $stmt->fetchColumn();

        if (!password_verify($password, (string) $passwordHash)) {
            $errors[] = 'Forkert adgangskode.';
        }
    }

    if (empty($errors)) {
        try {
            delete_route($pdo, $route, $userId);
            header('Location: dashboard.php');
            exit;
        } catch (Throwable $e) {
            $errors[] = 'Der skete en fejl under sletning. Prøv igen.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/svg+xml" href="/grafik/favicon.svg">
    <link rel="icon" type="image/png" sizes="32x32" href="/grafik/favicon-32.png">
    <meta name="robots" content="noindex, nofollow">
    <title>Slet rute</title>
</head>
<body>

<h1>Slet rute</h1>

<p>
    Du er ved at slette <strong><?= htmlspecialchars($route['title'], ENT_QUOTES, 'UTF-8') ?></strong> permanent.
    GPX-filen fjernes fra serveren, og handlingen kan ikke fortrydes.
    Alle der har et link til ruten vil herefter se en besked om, at ruten er slettet.
</p>

<?php if (!empty($errors)): ?>
    <ul>
        <?php foreach ($errors as $error): ?>
            <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<form method="post" action="delete-route.php?id=<?= htmlspecialchars($route['public_id'], ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="route_id" value="<?= htmlspecialchars($route['public_id'], ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">

    <label>
        Indtast din adgangskode for at bekræfte
        <input type="password" name="password" required autofocus>
    </label>

    <button type="submit">Slet ruten permanent</button>
</form>

<p><a href="dashboard.php">Annullér</a></p>

</body>
</html>
