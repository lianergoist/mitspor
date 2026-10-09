<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/app_login.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (csrf_validate($_POST['csrf_token'] ?? null)) {
        $_SESSION = [];

        // Ryd selve session-cookien, hvis browseren bruger cookies til sessionen
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();

        // I appens WebView: forbi en side, appen genkender, så appen også logger ud.
        if (is_app_request()) {
            header('Location: /app-logget-ud.php');
            exit;
        }
    }

    header('Location: login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/svg+xml" href="/grafik/favicon.svg">
    <link rel="icon" type="image/png" sizes="32x32" href="/grafik/favicon-32.png">
    <meta name="robots" content="noindex, nofollow">
    <title>Log ud</title>
</head>
<body>

<h1>Log ud</h1>
<p>Er du sikker på, at du vil logge ud?</p>

<form method="post" action="logout.php">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <button type="submit">Ja, log mig ud</button>
</form>

</body>
</html>
