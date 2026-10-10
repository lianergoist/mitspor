<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/*
 * Forbinder login i mobilappen med login på sitet i appens WebView, uden
 * synlige mellemsider.
 *
 * Appen sender oplysningerne i WebView'ets user-agent, fx
 *   "... MitSporApp/2 (par=<hash>; tok=<hash>; kode=<kode>)"
 *
 * kode (app → site): engangskode fra api/v1/web-login-code.php. Appen åbner
 *      /app-login.php?til=<sti>, der logger ind og omdirigerer (ingen side).
 * par  (site → app): SHA-256 af en hemmelighed, som kun appen kender. Når
 *      brugeren logger ind på sitet, kobles hashen til kontoen (som kode
 *      web_til_app). Appen veksler derefter hemmeligheden til et token via
 *      api/v1/exchange-code.php.
 * tok  (log ud):     SHA-256 af appens token. Log ud på sitet tilbagekalder
 *      tokenet, hvis det hører til den bruger, der logger ud.
 *
 * user-agent kan forfalskes og kommer i adgangsloggen. Derfor sendes kun
 * hashes og en kode, der bruges ved første forespørgsel. Kun SHA-256 af
 * koder gemmes, og de kan bruges én gang.
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
// Appen spørger efter hver sidevisning, mens den ikke er logget ind, så
// grænsen for veksling er højere. Hemmeligheden er 256 bit og kan ikke gættes.
if (!defined('APP_EXCHANGE_RATE_LIMIT_MAX_ATTEMPTS')) {
    define('APP_EXCHANGE_RATE_LIMIT_MAX_ATTEMPTS', 600);
}

/**
 * Kører siden i appens WebView? Appen tilføjer altid "MitSporApp/<n>" til
 * user-agent. Kun til visning (fx at skjule webintroen), ikke til adgang.
 */
function is_app_webview(): bool
{
    return preg_match('/MitSporApp\/\d+/', (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')) === 1;
}

/**
 * Værdi fra appens user-agent ("MitSporApp/2 (navn=værdi; …)"), eller null.
 * Alle værdier er 64 hex-tegn; alt andet afvises.
 */
function app_ua_param(string $name): ?string
{
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    if (!preg_match('/MitSporApp\/\d+ \(([^)]*)\)/', $ua, $m)) {
        return null;
    }

    foreach (explode(';', $m[1]) as $part) {
        $pair = explode('=', trim($part), 2);
        if (count($pair) === 2 && $pair[0] === $name && preg_match('/^[0-9a-f]{64}$/', $pair[1])) {
            return $pair[1];
        }
    }

    return null;
}

/**
 * Ryd udløbne og brugte koder op.
 */
function app_login_codes_prune(PDO $pdo): void
{
    $pdo->exec('DELETE FROM app_login_codes WHERE expires_at < NOW() - INTERVAL 1 DAY');
}

/**
 * Opret en engangskode (app_til_web). Returnerer selve koden (64 hex-tegn).
 */
function app_login_code_create(PDO $pdo, int $userId): string
{
    app_login_codes_prune($pdo);

    $code = bin2hex(random_bytes(32));
    $stmt = $pdo->prepare(
        "INSERT INTO app_login_codes (user_id, code_hash, direction, expires_at)
         VALUES (:uid, :hash, 'app_til_web', NOW() + INTERVAL :ttl SECOND)"
    );
    $stmt->execute([
        'uid'  => $userId,
        'hash' => hash('sha256', $code),
        'ttl'  => APP_LOGIN_CODE_TTL_SECONDS,
    ]);

    return $code;
}

/**
 * Kobl appens hash (par) til brugeren, der netop har logget ind på sitet.
 * Logger brugeren ind igen i samme WebView (fx med en anden konto), gælder
 * den nyeste kobling.
 */
function app_login_pair(PDO $pdo, int $userId, string $pairHash): void
{
    app_login_codes_prune($pdo);

    $stmt = $pdo->prepare(
        "INSERT INTO app_login_codes (user_id, code_hash, direction, expires_at)
         VALUES (:uid, :hash, 'web_til_app', NOW() + INTERVAL :ttl SECOND)
         ON DUPLICATE KEY UPDATE
             user_id = IF(direction = 'web_til_app', VALUES(user_id), user_id),
             expires_at = IF(direction = 'web_til_app', VALUES(expires_at), expires_at),
             used_at = IF(direction = 'web_til_app', NULL, used_at)"
    );
    $stmt->execute([
        'uid'  => $userId,
        'hash' => $pairHash,
        'ttl'  => APP_LOGIN_CODE_TTL_SECONDS,
    ]);
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
 * Tilbagekald appens token ud fra dets hash (tok i user-agent), men kun
 * hvis tokenet hører til brugeren, der logger ud på sitet.
 */
function app_token_revoke_by_hash(PDO $pdo, int $userId, string $tokenHash): void
{
    $stmt = $pdo->prepare(
        'UPDATE api_tokens SET revoked_at = NOW()
         WHERE token_hash = :hash AND user_id = :uid AND revoked_at IS NULL'
    );
    $stmt->execute(['hash' => $tokenHash, 'uid' => $userId]);
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
