<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../app/db.php';

$status = 'invalid'; // invalid | expired | already_used | success | email_taken

$rawToken = (string) ($_GET['token'] ?? '');

if ($rawToken !== '') {
    $tokenHash = hash('sha256', $rawToken);
    $pdo = get_db();

    $stmt = $pdo->prepare(
        'SELECT id, user_id, new_email, expires_at, used_at
         FROM email_change_tokens
         WHERE token_hash = :token_hash
         LIMIT 1'
    );
    $stmt->execute(['token_hash' => $tokenHash]);
    $tokenRow = $stmt->fetch();

    if ($tokenRow) {
        if ($tokenRow['used_at'] !== null) {
            $status = 'already_used';
        } elseif (strtotime($tokenRow['expires_at']) < time()) {
            $status = 'expired';
        } else {
            // Tjek igen lige nu, om nogen anden har taget adressen i
            // mellemtiden (den blev tjekket ved anmodningen, men kan
            // teoretisk være optaget siden).
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email AND status = 'active' LIMIT 1");
            $stmt->execute(['email' => $tokenRow['new_email']]);

            if ($stmt->fetch()) {
                $status = 'email_taken';
            } else {
                try {
                    $pdo->beginTransaction();

                    $stmt = $pdo->prepare('UPDATE users SET email = :email WHERE id = :user_id');
                    $stmt->execute(['email' => $tokenRow['new_email'], 'user_id' => $tokenRow['user_id']]);

                    $stmt = $pdo->prepare('UPDATE email_change_tokens SET used_at = NOW() WHERE id = :id');
                    $stmt->execute(['id' => $tokenRow['id']]);

                    // Ugyldiggør evt. andre udestående e-mailskift for
                    // samme bruger.
                    $stmt = $pdo->prepare(
                        'UPDATE email_change_tokens
                         SET used_at = NOW()
                         WHERE user_id = :user_id AND used_at IS NULL'
                    );
                    $stmt->execute(['user_id' => $tokenRow['user_id']]);

                    $pdo->commit();
                    $status = 'success';
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    error_log('E-mailskift fejlede: ' . $e->getMessage());
                    $status = 'invalid';
                }
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
    <title>Bekræft e-mailskift</title>
    <link rel="stylesheet" href="assets/style.css?v=35">
</head>
<body>

<div class="container">
    <div class="card">

        <?php if ($status === 'success'): ?>
            <h1>Din e-mailadresse er ændret</h1>
            <p>Du kan nu logge ind med din nye e-mailadresse.</p>
            <p><a href="index.php">Til forsiden</a></p>

        <?php elseif ($status === 'already_used'): ?>
            <h1>Linket er allerede brugt</h1>
            <p>E-mailskiftet er allerede gennemført.</p>
            <p><a href="index.php">Til forsiden</a></p>

        <?php elseif ($status === 'expired'): ?>
            <h1>Linket er udløbet</h1>
            <p>Log ind, og anmod om et nyt e-mailskift fra din profil.</p>
            <p><a href="index.php">Til forsiden</a></p>

        <?php elseif ($status === 'email_taken'): ?>
            <h1>Adressen er nu i brug</h1>
            <p>Den ønskede e-mailadresse er blevet taget af en anden konto, siden du anmodede om skiftet. Prøv en anden adresse.</p>
            <p><a href="index.php">Til forsiden</a></p>

        <?php else: ?>
            <h1>Ugyldigt link</h1>
            <p>Linket er ugyldigt. Tjek at du har kopieret hele linket fra mailen.</p>
            <p><a href="index.php">Til forsiden</a></p>
        <?php endif; ?>

    </div>
</div>

</body>
</html>
