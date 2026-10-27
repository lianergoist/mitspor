<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/rate_limit.php';
require_once __DIR__ . '/../app/mailer.php';
require_once __DIR__ . '/../app/simple_page.php';

$errors = [];
$submitted = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ip = client_ip();

    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Din session er udløbet. Genindlæs siden og prøv igen.';
    }

    if (empty($errors) && !rate_limit_allow('password_reset', $ip, PASSWORD_RESET_RATE_LIMIT_MAX_ATTEMPTS, PASSWORD_RESET_RATE_LIMIT_WINDOW_SECONDS)) {
        $errors[] = 'For mange forsøg. Prøv igen senere.';
    }

    $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));

    if (empty($errors) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Indtast en gyldig e-mailadresse.';
    }

    if (empty($errors)) {
        rate_limit_record('password_reset', $ip, $email);

        $pdo = get_db();
        $stmt = $pdo->prepare(
            "SELECT id FROM users WHERE email = :email AND status = 'active' LIMIT 1"
        );
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        // Send KUN mail hvis brugeren findes — men vis samme besked
        // uanset udfald (se HTML nedenfor), så formularen ikke kan
        // bruges til at afsløre hvilke e-mails der er i systemet.
        if ($user) {
            $rawToken  = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $rawToken);
            $expiresAt = (new DateTimeImmutable('+' . PASSWORD_RESET_TTL_HOURS . ' hours'))
                ->format('Y-m-d H:i:s');

            $stmt = $pdo->prepare(
                'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at)
                 VALUES (:user_id, :token_hash, :expires_at)'
            );
            $stmt->execute([
                'user_id'    => $user['id'],
                'token_hash' => $tokenHash,
                'expires_at' => $expiresAt,
            ]);

            send_password_reset_email($email, $rawToken);
        }

        $submitted = true;
    }
}
?>
<?php simple_page_start('Glemt adgangskode'); ?>

<?php if ($submitted): ?>

    <h1>Tjek din indbakke</h1>
    <p>
        Hvis e-mailadressen findes i vores system, har vi sendt et link til
        at nulstille adgangskoden.
    </p>

<?php else: ?>

    <h1>Glemt adgangskode</h1>

    <?php if (!empty($errors)): ?>
        <div class="modal-errors">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post" action="/forgot-password.php">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">

        <label>
            E-mail
            <input type="email" name="email" required
                   value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
        </label>

        <button type="submit" class="btn btn-primary">Send nulstillingslink</button>
    </form>

<?php endif; ?>

<?php simple_page_end(); ?>
