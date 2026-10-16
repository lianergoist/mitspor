<?php
declare(strict_types=1);

require_once __DIR__ . '/gpx.php';
require_once __DIR__ . '/og_image.php';
require_once __DIR__ . '/route_groups.php';

/**
 * Sletter én rute permanent fra brugerens synspunkt:
 * - markeres status = 'deleted' (raden bevares som en "tombesten", så
 *   andre der har et link til ruten kan mødes med en forklarende besked
 *   i stedet for en generisk 404) — men ALT indhold på raden ryddes:
 *   titel, beskrivelse, delingslink, start-/områdekoordinater og
 *   rutens midtpunkt, så tombestenen ikke længere fortæller noget om
 *   ruten
 * - BÅDE den redigerede GPX-fil OG den evt. gemte original-fil (backup
 *   fra udtynding) fjernes fra disk
 * - det genererede delingsbillede (og:image) slettes fra og-cache
 * - ruten forlader sin gruppe (etape-tur); bliver gruppen tom, slettes den
 * - likes/kommentarer på ruten slettes
 * - brugerens lagerforbrug nedjusteres
 * - der logges en revisionsrække i deletion_requests
 *
 * $route skal være hele raden fra routes-tabellen (id, user_id,
 * gpx_file_path, file_size_bytes som minimum). original_gpx_file_path
 * slås op, hvis kalderen ikke har medtaget den.
 */
function delete_route(PDO $pdo, array $route, int $requestedByUserId): void
{
    if (!array_key_exists('public_id', $route)) {
        $stmt = $pdo->prepare('SELECT public_id FROM routes WHERE id = :id');
        $stmt->execute(['id' => $route['id']]);
        $route['public_id'] = (string) $stmt->fetchColumn();
    }

    // Gruppe (etape-tur), ruten evt. hører til — ruten forlader den, og er
    // den nu tom, slettes gruppen (se nedenfor).
    if (!array_key_exists('group_id', $route)) {
        $stmt = $pdo->prepare('SELECT group_id FROM routes WHERE id = :id');
        $stmt->execute(['id' => $route['id']]);
        $route['group_id'] = $stmt->fetchColumn() ?: null;
    }
    $oldGroupId = $route['group_id'] !== null ? (int) $route['group_id'] : null;
    $oldGroupPublicId = null;
    if ($oldGroupId !== null) {
        $stmt = $pdo->prepare('SELECT public_id FROM route_groups WHERE id = :id');
        $stmt->execute(['id' => $oldGroupId]);
        $oldGroupPublicId = $stmt->fetchColumn() ?: null;
    }

    if (!array_key_exists('original_gpx_file_path', $route)) {
        $stmt = $pdo->prepare('SELECT original_gpx_file_path FROM routes WHERE id = :id');
        $stmt->execute(['id' => $route['id']]);
        $route['original_gpx_file_path'] = $stmt->fetchColumn() ?: null;
    }

    $filesToRemove = [gpx_full_path($route['gpx_file_path'])];
    if (!empty($route['original_gpx_file_path'])) {
        $filesToRemove[] = gpx_full_path($route['original_gpx_file_path']);
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            "UPDATE routes SET
                status = 'deleted',
                title = '',
                description = NULL,
                share_token = NULL,
                original_gpx_file_path = NULL,
                group_id = NULL, group_position = NULL,
                start_lat = NULL, start_lon = NULL,
                min_lat = NULL, max_lat = NULL, min_lon = NULL, max_lon = NULL,
                centroid = ST_GeomFromText('POINT(0 0)', 4326)
             WHERE id = :id"
        );
        $stmt->execute(['id' => $route['id']]);

        $stmt = $pdo->prepare('DELETE FROM route_likes WHERE route_id = :id');
        $stmt->execute(['id' => $route['id']]);

        $stmt = $pdo->prepare('DELETE FROM route_comments WHERE route_id = :id');
        $stmt->execute(['id' => $route['id']]);

        $stmt = $pdo->prepare(
            'UPDATE users SET storage_used_bytes = GREATEST(0, storage_used_bytes - :size) WHERE id = :id'
        );
        $stmt->execute(['size' => (int) $route['file_size_bytes'], 'id' => $route['user_id']]);

        $stmt = $pdo->prepare(
            "INSERT INTO deletion_requests
                (subject_type, subject_id, requested_by_user_id, requested_at, confirmed_at, status, purged_at)
             VALUES ('route', :route_id, :requested_by, NOW(), NOW(), 'purged', NOW())"
        );
        $stmt->execute(['route_id' => $route['id'], 'requested_by' => $requestedByUserId]);

        if ($oldGroupId !== null) {
            group_renumber($pdo, $oldGroupId);
            group_delete_if_empty($pdo, $oldGroupId);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('Rutesletning fejlede: ' . $e->getMessage());
        throw $e;
    }

    // Filerne fjernes FØRST efter succesfuld commit.
    foreach ($filesToRemove as $file) {
        @unlink($file);
    }

    // Også det automatisk genererede delingsbillede (kortudsnit af ruten).
    og_delete_images((string) $route['public_id']);

    // Og gruppens cachede kort-geometri, som kan indeholde denne rute.
    if ($oldGroupPublicId !== null) {
        group_invalidate_cache((string) $oldGroupPublicId);
    }
}

/**
 * Sletter en hel gruppe: alle dens aktive etaper (også de private) slettes
 * med delete_route(), og når den sidste er væk, slettes gruppen af
 * group_delete_if_empty(). Kun ejeren kan slette, og $confirmTitle skal
 * være gruppens titel (mellemrum før og efter ignoreres).
 *
 * Hver etape slettes i sin egen transaktion (filerne fjernes efter hver
 * commit). Fejler en etape undervejs, står gruppen tilbage med de etaper,
 * der ikke nåede at blive slettet, så handlingen kan prøves igen.
 *
 * @return array{success: bool, deleted?: int, errors?: string[]}
 */
function group_delete_with_routes(PDO $pdo, int $userId, string $groupPublicId, string $confirmTitle): array
{
    $group = group_find_owned($pdo, $userId, $groupPublicId);
    if ($group === null) {
        return ['success' => false, 'errors' => ['Gruppen blev ikke fundet, eller tilhører dig ikke.']];
    }
    if (trim($confirmTitle) === '' || trim($confirmTitle) !== trim((string) $group['title'])) {
        return ['success' => false, 'errors' => ['Det indtastede navn matcher ikke gruppens titel.']];
    }

    $stmt = $pdo->prepare(
        "SELECT * FROM routes WHERE group_id = :gid AND user_id = :uid AND status = 'active' ORDER BY group_position ASC, id ASC"
    );
    $stmt->execute(['gid' => $group['id'], 'uid' => $userId]);
    $routes = $stmt->fetchAll();

    $deleted = 0;
    try {
        foreach ($routes as $route) {
            delete_route($pdo, $route, $userId);
            $deleted++;
        }
        // Normalt er gruppen allerede væk (den sidste etape tømte den), men
        // en gruppe uden aktive etaper ryddes også op her.
        group_delete_if_empty($pdo, (int) $group['id']);
    } catch (Throwable $e) {
        error_log('Gruppesletning fejlede: ' . $e->getMessage());

        return ['success' => false, 'errors' => [
            $deleted > 0
                ? 'Der skete en fejl, efter ' . $deleted . ' af ' . count($routes) . ' etaper var slettet. Prøv igen.'
                : 'Der skete en fejl under sletning. Prøv igen.',
        ]];
    }

    return ['success' => true, 'deleted' => $deleted];
}
