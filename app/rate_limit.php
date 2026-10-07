<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Tjek om en given handling (fx 'register') er inden for rate-grænsen
 * for den givne IP-adresse. Kaldes FØR handlingen udføres.
 *
 * @return bool true hvis handlingen må udføres, false hvis grænsen er nået.
 */
function rate_limit_allow(string $action, string $ipAddress, int $maxAttempts, int $windowSeconds): bool
{
    $pdo = get_db();

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS attempts
         FROM rate_limit_log
         WHERE action = :action
           AND ip_address = :ip
           AND created_at >= (NOW() - INTERVAL :window SECOND)'
    );
    $stmt->execute([
        'action' => $action,
        'ip'     => inet_pton($ipAddress),
        'window' => $windowSeconds,
    ]);

    $row = $stmt->fetch();

    return (int) $row['attempts'] < $maxAttempts;
}

/**
 * Registrér et forsøg i loggen. Kaldes uanset om forsøget lykkedes,
 * for at forsøg (også mislykkede) tæller med i rate-grænsen.
 */
function rate_limit_record(string $action, string $ipAddress, ?string $identifier = null): void
{
    $pdo = get_db();

    $stmt = $pdo->prepare(
        'INSERT INTO rate_limit_log (action, ip_address, identifier)
         VALUES (:action, :ip, :identifier)'
    );
    $stmt->execute([
        'action'     => $action,
        'ip'         => inet_pton($ipAddress),
        'identifier' => $identifier,
    ]);
}

/**
 * Bekvem hjælpefunktion: hent brugerens IP-adresse.
 * Bemærk: hvis I kører bag en reverse proxy/load balancer, skal denne
 * justeres til at læse X-Forwarded-For fra en BETROET proxy — ellers
 * kan brugere forfalske deres IP og omgå rate limiting.
 */
function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}
