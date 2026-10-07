<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Tjek om der må gøres endnu et loginforsøg — blokerer hvis enten
 * e-mailen ELLER IP-adressen har for mange mislykkede forsøg inden
 * for tidsvinduet. (Begge tjekkes, så en angriber ikke kan omgå
 * beskyttelsen blot ved at skifte IP, eller ved at forsøge sig frem
 * mod mange forskellige konti fra samme IP.)
 */
function login_rate_limit_allow(string $email, string $ipAddress, int $maxAttempts, int $windowSeconds): bool
{
    $pdo = get_db();

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS attempts
         FROM login_attempts
         WHERE success = 0
           AND attempted_at >= (NOW() - INTERVAL :window SECOND)
           AND (email = :email OR ip_address = :ip)'
    );
    $stmt->execute([
        'window' => $windowSeconds,
        'email'  => $email,
        'ip'     => inet_pton($ipAddress),
    ]);

    $row = $stmt->fetch();

    return (int) $row['attempts'] < $maxAttempts;
}

/**
 * Log et loginforsøg (succes eller fejl).
 */
function login_record_attempt(string $email, string $ipAddress, bool $success): void
{
    $pdo = get_db();

    $stmt = $pdo->prepare(
        'INSERT INTO login_attempts (email, ip_address, success)
         VALUES (:email, :ip, :success)'
    );
    $stmt->execute([
        'email'   => $email,
        'ip'      => inet_pton($ipAddress),
        'success' => $success ? 1 : 0,
    ]);
}

/**
 * Samlet login-forsøg: rate limiting + credential-tjek + logning.
 * Bruges af login-ajax.php. Sætter IKKE selv sessionen — det er op
 * til kalderen at gøre ved success (session_regenerate_id + gemme
 * user_id), så funktionen er ren og let at teste/genbruge.
 *
 * @return array{success: bool, error?: string, user_id?: int}
 */
function attempt_login(string $email, string $password, string $ipAddress, int $maxAttempts, int $windowSeconds): array
{
    if (!login_rate_limit_allow($email, $ipAddress, $maxAttempts, $windowSeconds)) {
        return ['success' => false, 'error' => 'For mange loginforsøg. Prøv igen om lidt.'];
    }

    $pdo = get_db();
    $stmt = $pdo->prepare(
        'SELECT id, password_hash, status, email_verified_at
         FROM users
         WHERE email = :email
         LIMIT 1'
    );
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();

    // Bevidst ÉN generisk fejlbesked ved forkert e-mail/adgangskode/status,
    // så en angriber ikke kan bruge fejlbeskeden til at afgøre om
    // e-mailen findes i systemet eller hvilken tilstand kontoen er i.
    $genericError = 'Forkert e-mail eller adgangskode.';

    if (!$user || !password_verify($password, $user['password_hash'])) {
        login_record_attempt($email, $ipAddress, false);
        return ['success' => false, 'error' => $genericError];
    }

    if ($user['status'] !== 'active') {
        login_record_attempt($email, $ipAddress, false);
        return ['success' => false, 'error' => $genericError];
    }

    if ($user['email_verified_at'] === null) {
        // Denne besked ER specifik, fordi brugeren allerede har
        // bevist ejerskab af adgangskoden — der er ingen oplysning
        // at lække her, kun en handling brugeren skal foretage.
        login_record_attempt($email, $ipAddress, false);
        return ['success' => false, 'error' => 'Du skal bekræfte din e-mailadresse, før du kan logge ind. Tjek din indbakke.'];
    }

    login_record_attempt($email, $ipAddress, true);

    return ['success' => true, 'user_id' => (int) $user['id']];
}
