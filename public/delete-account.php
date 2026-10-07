<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/account_deletion.php';

$userId = require_login();
$user = current_user($userId);

if ($user === null) {
    $_SESSION = [];
    session_destroy();
    header('Location: login.php');
    exit;
}

$pdo = get_db();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM routes WHERE user_id = :uid AND status = 'active'");
$stmt->execute(['uid' => $userId]);
$activeRouteCount = (int) $stmt->fetchColumn();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Din session er udløbet. Genindlæs siden og prøv igen.';
    }

    $password = (string) ($_POST['password'] ?? '');
    $confirmationText = (string) ($_POST['confirmation_text'] ?? '');

    if (empty($errors) && strtolower(trim($confirmationText)) !== 'slet min konto') {
        $errors[] = 'Du skal skrive "slet min konto" for at bekræfte.';
    }

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
            delete_account($pdo, $user);

            $_SESSION = [];
            session_destroy();
            header('Location: login.php?deleted=1');
            exit;
        } catch (Throwable $e) {
            error_log('Kontosletning fejlede: ' . $e->getMessage());
            $errors[] = 'Der skete en fejl under sletning. Prøv igen, eller kontakt support.';
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
    <title>Slet konto</title>
</head>
<body>

<h1>Slet din konto</h1>

<p>
    Dette sletter din konto <strong>permanent</strong>. Det kan ikke fortrydes.
</p>

<ul>
    <li>Alle dine <?= $activeRouteCount ?> rute(r) slettes, inklusiv GPX-filerne.</li>
    <li>Alle dine uploadede billeder slettes.</li>
    <li>Alle der har et link til en af dine tidligere offentlige ruter vil se en besked om, at ruten er slettet.</li>
    <li>Din e-mailadresse frigives, så den kan bruges til at oprette en ny konto senere, hvis du ønsker det.</li>
</ul>

<p>
    Overvejer du i stedet bare at eksportere dine data, uden at slette kontoen?
    <a href="export-data.php">Download alle mine data</a>
</p>

<?php if (!empty($errors)): ?>
    <ul>
        <?php foreach ($errors as $error): ?>
            <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<form method="post" action="delete-account.php">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">

    <label>
        Skriv "slet min konto" for at bekræfte
        <input type="text" name="confirmation_text" required autocomplete="off">
    </label>

    <label>
        Indtast din adgangskode
        <input type="password" name="password" required>
    </label>

    <button type="submit">Slet min konto permanent</button>
</form>

<p><a href="dashboard.php">Annullér</a></p>

</body>
</html>
