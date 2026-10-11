<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

/*
 * Token-baseret login til JSON-API'et (public/api/v1/), som mobilappen
 * bruger. Ingen session og ingen CSRF: appen sender sit token i headeren
 * X-Api-Token. (IKKE Authorization — dev ligger bag basic auth, som
 * optager den header.)
 *
 * Selve tokenet vises kun én gang (i login-svaret). I databasen gemmes
 * kun SHA-256 af det, så et læk af tabellen ikke giver adgang. Tokens
 * må aldrig logges.
 */

// Standardværdier, hvis config.php endnu ikke har konstanterne
// (se app/config.example.php).
if (!defined('API_TOKEN_TTL_DAYS')) {
    define('API_TOKEN_TTL_DAYS', 180);
}
if (!defined('API_UPLOAD_RATE_LIMIT_MAX_ATTEMPTS')) {
    define('API_UPLOAD_RATE_LIMIT_MAX_ATTEMPTS', 30);
}
if (!defined('API_UPLOAD_RATE_LIMIT_WINDOW_SECONDS')) {
    define('API_UPLOAD_RATE_LIMIT_WINDOW_SECONDS', 3600);
}
if (!defined('API_CORS_ALLOWED_ORIGINS')) {
    define('API_CORS_ALLOWED_ORIGINS', ['capacitor://localhost', 'https://localhost']);
}

/**
 * Fælles start for alle API-endpoints: JSON-header, CORS og svar på
 * OPTIONS (preflight). Afviser andre metoder end de tilladte med 405.
 *
 * @param string[] $allowedMethods fx ['POST'] eller ['GET']
 */
function api_bootstrap(array $allowedMethods): void
{
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');

    $methods = array_merge($allowedMethods, ['OPTIONS']);

    // CORS-headere sendes KUN til de tilladte app-origins.
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    header('Vary: Origin');
    if ($origin !== '' && in_array($origin, API_CORS_ALLOWED_ORIGINS, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Methods: ' . implode(', ', $methods));
        header('Access-Control-Allow-Headers: X-Api-Token, Content-Type');
        // Filnavnet ved download (api/v1/route-file.php, export-data.php).
        header('Access-Control-Expose-Headers: Content-Disposition');
        header('Access-Control-Max-Age: 600');
    }

    $method = $_SERVER['REQUEST_METHOD'] ?? '';
    if ($method === 'OPTIONS') {
        http_response_code(204);
        exit;
    }

    if (!in_array($method, $allowedMethods, true)) {
        header('Allow: ' . implode(', ', $methods));
        api_error('Ugyldig forespørgsel.', 405);
    }
}

/**
 * Send et JSON-svar og afslut.
 */
function api_json(array $data, int $httpCode = 200): never
{
    http_response_code($httpCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Send en JSON-fejl og afslut.
 */
function api_error(string $error, int $httpCode = 400, array $extra = []): never
{
    api_json(['success' => false, 'error' => $error] + $extra, $httpCode);
}

/**
 * Hash et token til opslag/lagring.
 */
function api_token_hash(string $token): string
{
    return hash('sha256', $token);
}

/**
 * Opret et nyt token til brugeren. Returnerer selve tokenet (vises kun
 * denne ene gang) og udløbstidspunktet.
 *
 * @return array{token: string, expires_at: string}
 */
function api_token_create(PDO $pdo, int $userId, string $deviceName): array
{
    $token = bin2hex(random_bytes(32));

    $deviceName = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $deviceName) ?? '');
    $deviceName = mb_substr($deviceName, 0, 100);

    $stmt = $pdo->prepare(
        'INSERT INTO api_tokens (user_id, token_hash, device_name, last_used_at, expires_at)
         VALUES (:uid, :hash, :device, NOW(), NOW() + INTERVAL :days DAY)'
    );
    $stmt->execute([
        'uid'    => $userId,
        'hash'   => api_token_hash($token),
        'device' => $deviceName,
        'days'   => API_TOKEN_TTL_DAYS,
    ]);

    $stmt = $pdo->prepare('SELECT ' . api_sql_utc('expires_at') . ' FROM api_tokens WHERE id = :id');
    $stmt->execute(['id' => (int) $pdo->lastInsertId()]);

    return ['token' => $token, 'expires_at' => api_datetime((string) $stmt->fetchColumn())];
}

/**
 * Id på det token, den aktuelle forespørgsel er godkendt med (sættes af
 * api_require_token()). Bruges fx af logout.php.
 */
function api_current_token_id(?int $set = null): ?int
{
    static $tokenId = null;

    if ($set !== null) {
        $tokenId = $set;
    }

    return $tokenId;
}

/**
 * Kræv et gyldigt token i headeren X-Api-Token. Tjekker udløb og
 * tilbagekaldelse, forlænger udløbet til API_TOKEN_TTL_DAYS efter
 * denne brug og returnerer brugeren via current_user().
 * Ved fejl sendes JSON med 401, og scriptet afsluttes.
 */
function api_require_token(): array
{
    $token = (string) ($_SERVER['HTTP_X_API_TOKEN'] ?? '');

    // Et gyldigt token er altid 64 hex-tegn — alt andet afvises uden opslag.
    if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
        api_error('Du skal være logget ind.', 401);
    }

    $pdo = get_db();
    $stmt = $pdo->prepare(
        'SELECT id, user_id
         FROM api_tokens
         WHERE token_hash = :hash
           AND revoked_at IS NULL
           AND expires_at > NOW()
         LIMIT 1'
    );
    $stmt->execute(['hash' => api_token_hash($token)]);
    $row = $stmt->fetch();

    if (!$row) {
        api_error('Dit login er udløbet. Log ind igen.', 401);
    }

    $user = current_user((int) $row['user_id']);
    if ($user === null) {
        api_error('Dit login er udløbet. Log ind igen.', 401);
    }

    // Glidende udløb: tokenet gælder API_TOKEN_TTL_DAYS fra sidste brug.
    $stmt = $pdo->prepare(
        'UPDATE api_tokens
         SET last_used_at = NOW(), expires_at = NOW() + INTERVAL :days DAY
         WHERE id = :id'
    );
    $stmt->execute(['days' => API_TOKEN_TTL_DAYS, 'id' => $row['id']]);

    api_current_token_id((int) $row['id']);

    return $user;
}

/**
 * Tilbagekald ét token (logout fra én enhed).
 */
function api_token_revoke(PDO $pdo, int $tokenId): void
{
    $stmt = $pdo->prepare('UPDATE api_tokens SET revoked_at = NOW() WHERE id = :id AND revoked_at IS NULL');
    $stmt->execute(['id' => $tokenId]);
}

/**
 * Tilbagekald alle brugerens tokens (fx efter skift af adgangskode).
 */
function api_tokens_revoke_all(PDO $pdo, int $userId): void
{
    $stmt = $pdo->prepare('UPDATE api_tokens SET revoked_at = NOW() WHERE user_id = :uid AND revoked_at IS NULL');
    $stmt->execute(['uid' => $userId]);
}

/**
 * SQL-udtryk, der omregner en DATETIME-kolonne fra databasens tidszone
 * til UTC. Databasen og PHP kører ikke nødvendigvis i samme tidszone,
 * så omregningen (inkl. sommertid) overlades til MySQL.
 */
function api_sql_utc(string $column): string
{
    return "CONVERT_TZ($column, @@session.time_zone, '+00:00')";
}

/**
 * UTC-datetime fra api_sql_utc() -> ISO 8601 (fx 2026-10-07T07:46:20Z),
 * så appen ikke skal gætte tidszonen.
 */
function api_datetime(?string $utcDatetime): ?string
{
    if ($utcDatetime === null || $utcDatetime === '') {
        return null;
    }

    $dt = new DateTimeImmutable($utcDatetime, new DateTimeZone('UTC'));

    return $dt->format('Y-m-d\TH:i:s\Z');
}
