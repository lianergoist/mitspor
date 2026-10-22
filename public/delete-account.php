<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/account_deletion.php';
require_once __DIR__ . '/../app/app_hent.php';
require_once __DIR__ . '/../app/simple_page.php';

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
<?php simple_page_start('Slet konto'); ?>

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
            <a href="<?= htmlspecialchars(data_export_url(), ENT_QUOTES, 'UTF-8') ?>">Download alle mine data</a>
        </p>

        <form method="post" action="/delete-account.php">
            <?php if (!empty($errors)): ?>
                <div class="modal-errors">
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">

            <label>
                Skriv "slet min konto" for at bekræfte
                <input type="text" name="confirmation_text" required autocomplete="off">
            </label>

            <label>
                Indtast din adgangskode
                <input type="password" name="password" required>
            </label>

            <div class="simple-page-actions">
                <button type="submit" class="btn btn-danger">Slet min konto permanent</button>
                <a href="/dashboard.php" class="btn">Annullér</a>
            </div>
        </form>

<?php simple_page_end(); ?>
