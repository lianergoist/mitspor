<?php
declare(strict_types=1);

// Logger appens WebView ind på sitet med en engangskode fra appen
// (se app/app_login.php og api/v1/web-login-code.php).
//
// Appen åbner /app-login.php#kode=<kode>&til=<sti>. Koden står efter #,
// så den ikke sendes med GET-forespørgslen og ikke havner i adgangsloggen.
// Siden poster koden (med CSRF-token) og sendes derefter videre til <sti>.

session_start();

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/app_login.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');

    $til = app_safe_path((string) ($_POST['til'] ?? '/'));

    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'til' => $til]);
        exit;
    }

    // Kun i appen. Uden for appen er der ingen grund til at bruge en kode.
    $userId = is_app_request()
        ? app_login_code_consume(get_db(), (string) ($_POST['kode'] ?? ''), 'app_til_web')
        : null;

    if ($userId === null || current_user($userId) === null) {
        echo json_encode(['success' => false, 'til' => $til]);
        exit;
    }

    // Forny session-ID ved login for at undgå session fixation.
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;

    echo json_encode(['success' => true, 'til' => $til]);
    exit;
}

header('Cache-Control: no-store');
?>
<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/svg+xml" href="/grafik/favicon.svg">
    <link rel="icon" type="image/png" sizes="32x32" href="/grafik/favicon-32.png">
    <meta name="robots" content="noindex, nofollow">
    <title>Logger ind …</title>
</head>
<body>
    <p>Logger ind …</p>
    <form id="app-login" hidden>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    </form>
    <script src="/assets/app-login.js"></script>
</body>
</html>
