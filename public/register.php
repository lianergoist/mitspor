<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/rate_limit.php';
require_once __DIR__ . '/../app/mailer.php';
require_once __DIR__ . '/../app/app_tilbage.php';

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ip = client_ip();

    // --- CSRF ---
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Din session er udløbet. Genindlæs siden og prøv igen.';
    }

    // --- Rate limiting (pr. IP) ---
    if (empty($errors) && !rate_limit_allow('register', $ip, REGISTER_RATE_LIMIT_MAX_ATTEMPTS, REGISTER_RATE_LIMIT_WINDOW_SECONDS)) {
        $errors[] = 'Der er lavet for mange oprettelsesforsøg fra denne forbindelse. Prøv igen senere.';
    }

    // --- Input ---
    $email       = trim((string) ($_POST['email'] ?? ''));
    $email       = mb_strtolower($email);
    $password    = (string) ($_POST['password'] ?? '');
    $displayName = trim((string) ($_POST['display_name'] ?? ''));
    $acceptedPolicy = isset($_POST['accept_policy']);

    if (empty($errors)) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Indtast en gyldig e-mailadresse.';
        }

        if (mb_strlen($password) < 10) {
            $errors[] = 'Adgangskoden skal være mindst 10 tegn.';
        }

        if (mb_strlen($displayName) < 2 || mb_strlen($displayName) > 100) {
            $errors[] = 'Navnet skal være mellem 2 og 100 tegn.';
        }

        if (!$acceptedPolicy) {
            $errors[] = 'Du skal acceptere privatlivspolitikken for at oprette en konto.';
        }
    }

    // Registrér forsøget i rate-loggen uanset udfald, FØR vi evt. stopper.
    rate_limit_record('register', $ip, $email !== '' ? $email : null);

    if (empty($errors)) {
        $pdo = get_db();

        // Tjek om e-mailen allerede er i brug
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);

        if ($stmt->fetch()) {
            // Bevidst generisk fejl — afslør ikke om e-mailen findes,
            // for at undgå at andre kan bruge formularen til at "scanne"
            // hvilke e-mails der allerede er oprettet.
            $errors[] = 'Kunne ikke oprette kontoen. Tjek om du allerede har en konto, eller prøv en anden e-mailadresse.';
        } else {
            try {
                $pdo->beginTransaction();

                $passwordHash = password_hash($password, PASSWORD_DEFAULT);

                $stmt = $pdo->prepare(
                    'INSERT INTO users (email, password_hash, display_name)
                     VALUES (:email, :password_hash, :display_name)'
                );
                $stmt->execute([
                    'email'         => $email,
                    'password_hash' => $passwordHash,
                    'display_name'  => $displayName,
                ]);

                $userId = (int) $pdo->lastInsertId();

                // Generér verifikationstoken: det RÅ token sendes i mailen,
                // kun hashen gemmes i databasen.
                $rawToken  = bin2hex(random_bytes(32));
                $tokenHash = hash('sha256', $rawToken);
                $expiresAt = (new DateTimeImmutable('+' . EMAIL_VERIFICATION_TTL_HOURS . ' hours'))
                    ->format('Y-m-d H:i:s');

                $stmt = $pdo->prepare(
                    'INSERT INTO email_verification_tokens (user_id, token_hash, expires_at)
                     VALUES (:user_id, :token_hash, :expires_at)'
                );
                $stmt->execute([
                    'user_id'    => $userId,
                    'token_hash' => $tokenHash,
                    'expires_at' => $expiresAt,
                ]);

                $pdo->commit();

                // Mail sendes EFTER commit, så vi ikke mister en oprettet
                // bruger, hvis mailafsendelse fejler.
                send_verification_email($email, $rawToken);

                $success = true;
            } catch (Throwable $e) {
                $pdo->rollBack();
                error_log('Registrering fejlede: ' . $e->getMessage());
                $errors[] = 'Der skete en fejl. Prøv igen senere.';
            }
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
    <title>Opret konto</title>
</head>
<body>
<?php render_app_back_button(); ?>

<?php if ($success): ?>

    <h1>Tjek din indbakke</h1>
    <p>
        Vi har sendt en bekræftelsesmail til den angivne e-mailadresse.
        Klik på linket i mailen for at aktivere din konto.
    </p>

<?php else: ?>

    <h1>Opret konto</h1>

    <?php if (!empty($errors)): ?>
        <ul>
            <?php foreach ($errors as $error): ?>
                <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <form method="post" action="register.php">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">

        <label>
            E-mail
            <input type="email" name="email" required
                   value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
        </label>

        <label>
            Adgangskode (mindst 10 tegn)
            <input type="password" name="password" required minlength="10">
        </label>

        <label>
            Vis-navn
            <input type="text" name="display_name" required
                   value="<?= htmlspecialchars($_POST['display_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
        </label>

        <label>
            <input type="checkbox" name="accept_policy" required>
            Jeg accepterer <a href="/privatlivspolitik.php">privatlivspolitikken</a>
        </label>

        <button type="submit">Opret konto</button>
    </form>

<?php endif; ?>

</body>
</html>
