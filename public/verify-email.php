<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/app_tilbage.php';

$status = 'invalid'; // invalid | expired | success | already_used

$rawToken = $_GET['token'] ?? '';

if (is_string($rawToken) && $rawToken !== '') {
    $tokenHash = hash('sha256', $rawToken);
    $pdo = get_db();

    $stmt = $pdo->prepare(
        'SELECT id, user_id, expires_at, used_at
         FROM email_verification_tokens
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
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare(
                    'UPDATE users SET email_verified_at = NOW() WHERE id = :user_id'
                );
                $stmt->execute(['user_id' => $tokenRow['user_id']]);

                $stmt = $pdo->prepare(
                    'UPDATE email_verification_tokens SET used_at = NOW() WHERE id = :id'
                );
                $stmt->execute(['id' => $tokenRow['id']]);

                $pdo->commit();
                $status = 'success';

                // Log brugeren automatisk ind — de har jo netop bevist
                // ejerskab af både e-mailen og den oprindelige adgangskode
                // (kontoen kunne kun oprettes med den) — og send dem
                // direkte til kontrolpanelet i stedet for til login-siden.
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int) $tokenRow['user_id'];

                header('Location: dashboard.php');
                exit;
            } catch (Throwable $e) {
                $pdo->rollBack();
                error_log('E-mailverifikation fejlede: ' . $e->getMessage());
                $status = 'invalid';
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
    <title>Bekræft e-mail</title>
</head>
<body>
<?php render_app_back_button(); ?>

<?php if ($status === 'success'): ?>
    <h1>Din e-mail er bekræftet</h1>
    <p>Du kan nu logge ind.</p>

<?php elseif ($status === 'already_used'): ?>
    <h1>Linket er allerede brugt</h1>
    <p>Din konto er allerede bekræftet. Du kan logge ind.</p>

<?php elseif ($status === 'expired'): ?>
    <h1>Linket er udløbet</h1>
    <p>Log ind, og bed om at få tilsendt et nyt bekræftelseslink.</p>

<?php else: ?>
    <h1>Ugyldigt link</h1>
    <p>Linket er ugyldigt. Tjek at du har kopieret hele linket fra mailen.</p>

<?php endif; ?>

</body>
</html>
