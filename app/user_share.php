<?php
declare(strict_types=1);

/*
 * "Del alle mine ruter": ét link pr. bruger (kort.php?alle=<nøgle>), der
 * giver adgang til alle brugerens aktive ruter, spor og grupper, også de
 * private. Nøglen ligger i users.all_share_token.
 *
 * Når linket åbnes med en gyldig nøgle, gemmes nøglen i den besøgendes
 * session (user_share_remember()). Derefter kan den besøgende åbne
 * brugerens ruter, gruppesider og GPX-filer uden nøglen i hver URL.
 * user_share_grants() tjekker nøglen mod databasen ved hvert opslag, så
 * adgangen stopper med det samme, når linket slettes eller udskiftes.
 *
 * Kun sitet (med session) bruger dette; API'et starter ingen session.
 */

const USER_SHARE_SESSION_KEY = 'alle_del';

/**
 * Find den aktive bruger, som nøglen tilhører.
 *
 * @return array{id: int, display_name: string}|null
 */
function user_share_find_owner(PDO $pdo, string $token): ?array
{
    if (!preg_match('/^[0-9a-f]{48}$/', $token)) {
        return null;
    }

    $stmt = $pdo->prepare(
        "SELECT id, display_name FROM users
         WHERE all_share_token = :token AND status = 'active'
         LIMIT 1"
    );
    $stmt->execute(['token' => $token]);
    $user = $stmt->fetch();

    return $user ? ['id' => (int) $user['id'], 'display_name' => (string) $user['display_name']] : null;
}

/** Gem en gyldig nøgle i den besøgendes session. */
function user_share_remember(int $ownerId, string $token): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    $grants = $_SESSION[USER_SHARE_SESSION_KEY] ?? [];
    if (!is_array($grants)) {
        $grants = [];
    }
    $grants[$ownerId] = $token;
    $_SESSION[USER_SHARE_SESSION_KEY] = $grants;
}

/**
 * Har den besøgende (via et "Del alle mine ruter"-link i sessionen) adgang
 * til alle ruter fra denne ejer?
 */
function user_share_grants(PDO $pdo, int $ownerId): bool
{
    static $cache = [];

    if (session_status() !== PHP_SESSION_ACTIVE) {
        return false;
    }
    $token = $_SESSION[USER_SHARE_SESSION_KEY][$ownerId] ?? null;
    if (!is_string($token) || $token === '') {
        return false;
    }
    if (isset($cache[$ownerId])) {
        return $cache[$ownerId];
    }

    $stmt = $pdo->prepare(
        "SELECT all_share_token FROM users WHERE id = :id AND status = 'active' LIMIT 1"
    );
    $stmt->execute(['id' => $ownerId]);
    $current = $stmt->fetchColumn();

    $ok = is_string($current) && hash_equals($current, $token);
    if (!$ok) {
        // Linket er slettet eller udskiftet: glem den gamle nøgle.
        unset($_SESSION[USER_SHARE_SESSION_KEY][$ownerId]);
    }

    return $cache[$ownerId] = $ok;
}

/** URL'en til brugerens "Del alle mine ruter"-link. */
function user_share_url(string $token): string
{
    return APP_BASE_URL . '/kort.php?alle=' . urlencode($token);
}
