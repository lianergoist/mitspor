<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/*
 * Forbinder login i mobilappen med login på sitet i appens WebView.
 *
 * app_til_web: appen (med API-token) beder om en engangskode og åbner
 *              /app-login.php med koden; siden veksler den til en session.
 * web_til_app: når brugeren logger ind på sitet i appen, laver sitet en
 *              engangskode og viser /app-forbind.php med koden; appen ser
 *              adressen og veksler koden til et API-token.
 *
 * Koden sendes altid efter # i adressen, så den aldrig når serverens
 * adgangslog. Kun SHA-256 af koden gemmes, og den kan bruges én gang.
 */

// Standardværdier, hvis config.php endnu ikke har konstanterne
// (se app/config.example.php).
if (!defined('APP_LOGIN_CODE_TTL_SECONDS')) {
    define('APP_LOGIN_CODE_TTL_SECONDS', 60);
}
if (!defined('APP_LOGIN_CODE_RATE_LIMIT_MAX_ATTEMPTS')) {
    define('APP_LOGIN_CODE_RATE_LIMIT_MAX_ATTEMPTS', 60);
}
if (!defined('APP_LOGIN_CODE_RATE_LIMIT_WINDOW_SECONDS')) {
    define('APP_LOGIN_CODE_RATE_LIMIT_WINDOW_SECONDS', 3600);
}

/**
 * Kører siden i MitSpor-appens WebView? Appen tilføjer "MitSporApp" til
 * user-agent. Det kan forfalskes og bruges kun til at vælge flow, aldrig
 * som adgangskontrol.
 */
function is_app_request(): bool
{
    return str_contains((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 'MitSporApp');
}

/**
 * Opret en engangskode til brugeren. Returnerer selve koden (64 hex-tegn).
 * Udløbne og brugte koder ryddes samtidig op.
 */
function app_login_code_create(PDO $pdo, int $userId, string $direction): string
{
    $pdo->exec('DELETE FROM app_login_codes WHERE expires_at < NOW() - INTERVAL 1 DAY');

    $code = bin2hex(random_bytes(32));
    $stmt = $pdo->prepare(
        'INSERT INTO app_login_codes (user_id, code_hash, direction, expires_at)
         VALUES (:uid, :hash, :direction, NOW() + INTERVAL :ttl SECOND)'
    );
    $stmt->execute([
        'uid'       => $userId,
        'hash'      => hash('sha256', $code),
        'direction' => $direction,
        'ttl'       => APP_LOGIN_CODE_TTL_SECONDS,
    ]);

    return $code;
}

/**
 * Brug en engangskode. Returnerer brugerens id, eller null hvis koden er
 * ukendt, udløbet, allerede brugt eller hører til den anden retning.
 * UPDATE'en gør koden brugt atomisk, så den ikke kan bruges to gange.
 */
function app_login_code_consume(PDO $pdo, string $code, string $direction): ?int
{
    if (!preg_match('/^[0-9a-f]{64}$/', $code)) {
        return null;
    }

    $hash = hash('sha256', $code);
    $stmt = $pdo->prepare(
        'UPDATE app_login_codes
         SET used_at = NOW()
         WHERE code_hash = :hash
           AND direction = :direction
           AND used_at IS NULL
           AND expires_at > NOW()'
    );
    $stmt->execute(['hash' => $hash, 'direction' => $direction]);
    if ($stmt->rowCount() !== 1) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT user_id FROM app_login_codes WHERE code_hash = :hash');
    $stmt->execute(['hash' => $hash]);
    $userId = $stmt->fetchColumn();

    return $userId !== false ? (int) $userId : null;
}

/**
 * En rodrelativ sti på sitet (fx /route-view.php?id=abc) eller "/".
 * Afviser fulde adresser og "//andet-site", så der ikke kan omdirigeres væk.
 */
function app_safe_path(string $path): string
{
    if ($path === '' || $path[0] !== '/' || str_starts_with($path, '//') || str_contains($path, '\\')
        || preg_match('/[\x00-\x1F\x7F]/', $path)) {
        return '/';
    }

    return $path;
}
