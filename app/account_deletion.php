<?php
declare(strict_types=1);

require_once __DIR__ . '/route_deletion.php';
require_once __DIR__ . '/route_groups.php';
require_once __DIR__ . '/image_storage.php';

/**
 * Sletter en brugers konto permanent:
 * - alle brugerens aktive ruter slettes enkeltvis via delete_route()
 *   (samme tombesten-mekanisme som ved individuel rutesletning)
 * - alle uploadede billeder slettes (rækker + originalfiler + miniaturer)
 * - selve brugerrækken anonymiseres og markeres 'deleted' — raden
 *   bevares (i stedet for hård sletning), fordi de tombesten-markerede
 *   ruter stadig peger på den via user_id (fremmednøgle)
 */
function delete_account(PDO $pdo, array $user): void
{
    $userId = (int) $user['id'];

    // --- Slet alle aktive ruter ---
    $stmt = $pdo->prepare("SELECT * FROM routes WHERE user_id = :uid AND status = 'active'");
    $stmt->execute(['uid' => $userId]);
    foreach ($stmt->fetchAll() as $route) {
        delete_route($pdo, $route, $userId);
    }

    // --- Slet evt. resterende grupper (er normalt allerede væk, når de
    // sidste ruter er slettet ovenfor) og deres cachede kort-geometri ---
    $stmt = $pdo->prepare('SELECT public_id FROM route_groups WHERE user_id = :uid');
    $stmt->execute(['uid' => $userId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $groupPublicId) {
        group_invalidate_cache((string) $groupPublicId);
    }
    $pdo->prepare('DELETE FROM route_groups WHERE user_id = :uid')->execute(['uid' => $userId]);

    // --- Slet alle uploadede billeder (både original og miniature) ---
    $stmt = $pdo->prepare('SELECT id, relative_path, thumbnail_relative_path FROM user_images WHERE user_id = :uid');
    $stmt->execute(['uid' => $userId]);
    $images = $stmt->fetchAll();

    foreach ($images as $image) {
        $stmt = $pdo->prepare('DELETE FROM user_images WHERE id = :id');
        $stmt->execute(['id' => $image['id']]);
        @unlink(image_full_path($image['relative_path']));
        if (!empty($image['thumbnail_relative_path'])) {
            @unlink(image_full_path($image['thumbnail_relative_path']));
        }
    }

    // Ryd også evt. forældreløse filer i brugerens egen billedmappe
    // (mappen hører kun til denne bruger) og fjern selve mappen.
    $imageDir = IMAGE_PUBLIC_DIR . '/' . $userId;
    if (is_dir($imageDir)) {
        foreach (glob($imageDir . '/*') ?: [] as $leftover) {
            if (is_file($leftover)) {
                @unlink($leftover);
            }
        }
        @rmdir($imageDir);
    }

    // --- Slet engangs-links (bekræftelse/nulstilling/e-mailskift) ---
    // Login- og rate-limit-noter ryddes IKKE her, men automatisk efter
    // SECURITY_LOG_RETENTION_DAYS (se prune-security-logs.php) — ellers
    // kunne man nulstille sin egen spærring ved at slette sin konto.
    $pdo->prepare('DELETE FROM email_verification_tokens WHERE user_id = :uid')->execute(['uid' => $userId]);
    $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id = :uid')->execute(['uid' => $userId]);
    // Ønskede e-mailskift gemmer den NYE adresse — den skal ikke overleve kontoen.
    $pdo->prepare('DELETE FROM email_change_tokens WHERE user_id = :uid')->execute(['uid' => $userId]);

    // --- Anonymisér og deaktivér selve kontoen ---
    // E-mailen gøres unik med et suffiks, så adressen i teorien kan
    // bruges til at oprette en NY konto senere, uden at støde mod
    // UNIQUE-begrænsningen på den anonymiserede, slettede række.
    $anonymizedEmail = 'deleted-' . $userId . '-' . bin2hex(random_bytes(4)) . '@deleted.invalid';
    $invalidatedHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);

    $pdo->prepare(
        "UPDATE users SET
            email = :email,
            password_hash = :hash,
            display_name = 'Slettet bruger',
            storage_used_bytes = 0,
            status = 'deleted'
         WHERE id = :id"
    )->execute([
        'email' => $anonymizedEmail,
        'hash'  => $invalidatedHash,
        'id'    => $userId,
    ]);

    $pdo->prepare(
        "INSERT INTO deletion_requests
            (subject_type, subject_id, requested_by_user_id, requested_at, confirmed_at, status, purged_at)
         VALUES ('user_account', :subject_id, :requested_by, NOW(), NOW(), 'purged', NOW())"
    )->execute(['subject_id' => $userId, 'requested_by' => $userId]);
}
