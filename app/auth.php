<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Kræv at brugeren er logget ind. Sendes videre til login.php hvis ikke.
 * Kaldes øverst i enhver side under kontrolpanelet.
 */
function require_login(): int
{
    if (empty($_SESSION['user_id'])) {
        header('Location: /login.php');
        exit;
    }

    return (int) $_SESSION['user_id'];
}

/**
 * Hent den aktuelle brugers data. Returnerer null hvis kontoen af en
 * eller anden grund ikke længere er aktiv (fx slettet mens sessionen
 * stadig var gyldig) — kald require_login() FØR denne, og tjek for
 * null bagefter for at fange den situation.
 */
function current_user(int $userId): ?array
{
    $pdo = get_db();

    $stmt = $pdo->prepare(
        "SELECT id, email, display_name, email_verified_at, storage_quota_bytes,
                storage_used_bytes, route_quota, public_route_notice_ack_at, is_admin, created_at
         FROM users
         WHERE id = :id AND status = 'active'
         LIMIT 1"
    );
    $stmt->execute(['id' => $userId]);
    $user = $stmt->fetch();

    return $user ?: null;
}
