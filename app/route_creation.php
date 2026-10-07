<?php
declare(strict_types=1);

require_once __DIR__ . '/gpx.php';
require_once __DIR__ . '/og_image.php';

/**
 * Opretter en ny rute: validerer input og GPX-indhold, tjekker kvoter,
 * gemmer filen på disk, og indsætter raden i databasen. Al logik er
 * samlet ét sted, så upload-route.php og create-route.php (fra
 * editoren) opfører sig 100% ens.
 *
 * Der tjekkes bevidst IKKE for usandsynlig hastighed eller hyppig
 * logningsfrekvens ved upload/gem — aktivitetstypen har udelukkende
 * betydning for hvilken markør ruten vises med. Vil en bruger fjerne
 * tidsstempler eller udtynde punkter, gøres det manuelt via
 * værktøjskassen i editoren.
 *
 * @return array{success: bool, errors?: string[], route_id?: int}
 */
function create_route(
    PDO $pdo,
    array $user,
    string $gpxContent,
    string $title,
    string $description,
    int $activityTypeId,
    string $visibility,
    bool $allowDownload,
    bool $publicNoticeAcknowledged,
    string $timeConflictResolution = 'ask',
    bool $hideUploaderName = false,
    ?int $thinIntervalSeconds = null,
    string $routeType = 'rute',
    ?string $enabledDataFields = null,
    ?string $enabledFunctions = null
): array {
    $errors = [];

    $title = trim($title);
    $description = trim($description);

    if (mb_strlen($title) < 2 || mb_strlen($title) > 200) {
        $errors[] = 'Titel skal være mellem 2 og 200 tegn.';
    }

    if (!in_array($visibility, ['private', 'public'], true)) {
        $errors[] = 'Ugyldig synlighed valgt.';
    }
    if (!in_array($routeType, ['rute', 'spor'], true)) {
        $routeType = 'rute';
    }

    $stmt = $pdo->prepare(
        'SELECT id, max_plausible_speed_kmh FROM activity_types WHERE id = :id AND is_active = 1'
    );
    $stmt->execute(['id' => $activityTypeId]);
    $activityType = $stmt->fetch();
    if (!$activityType) {
        $errors[] = 'Ugyldig aktivitetstype valgt.';
    }

    if ($visibility === 'public' && !$publicNoticeAcknowledged) {
        $errors[] = 'Du skal bekræfte advarslen om offentlige ruter for at gøre ruten offentlig.';
    }

    // Rutekvote
    $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM routes WHERE user_id = :uid AND status = 'active'");
    $stmt->execute(['uid' => $user['id']]);
    if ((int) $stmt->fetch()['c'] >= (int) $user['route_quota']) {
        $errors[] = 'Du har nået din grænse for antal ruter.';
    }

    if (!empty($errors)) {
        return ['success' => false, 'errors' => $errors];
    }

    $parsed = gpx_validate_and_parse($gpxContent);
    if (!$parsed['valid']) {
        return ['success' => false, 'errors' => $parsed['errors']];
    }
    $stats = $parsed['stats'];

    // Hastighedsplausibilitet og automatisk udtyndingsforslag er
    // bevidst fjernet herfra — de forvirrede mere end de hjalp.
    // Aktivitetstypen har nu udelukkende betydning for hvilken markør
    // ruten vises med, ikke for om filen kan uploades. Ønsker en bruger
    // at fjerne tidsstempler eller udtynde punkter, gøres det manuelt
    // via værktøjskassen i editoren.
    $originalContentForBackup = null;

    $sizeBytes = strlen($gpxContent);

    if (($user['storage_used_bytes'] + $sizeBytes) > $user['storage_quota_bytes']) {
        return ['success' => false, 'errors' => ['Du har ikke nok ledig lagerplads til denne fil.']];
    }

    // Ingen funktions-valg fra ejeren (fx ved upload fra dashboardet): slå ALLE
    // funktioner til, som filen har data til — ligesom en ny rute i editoren.
    if ($enabledFunctions === null) {
        $enabledFunctions = json_encode(gpx_default_enabled_functions($gpxContent));
    }

    $stored = gpx_store_file((int) $user['id'], $gpxContent);
    $publicId = gpx_generate_unique_public_id($pdo);

    $originalStored = null;
    if ($originalContentForBackup !== null) {
        $originalStored = gpx_store_file((int) $user['id'], $originalContentForBackup);
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            'INSERT INTO routes
                (public_id, user_id, activity_type_id, route_type, has_time_data, enabled_data_fields, enabled_functions, title, description, gpx_file_path, original_gpx_file_path, file_size_bytes,
                 distance_meters, elevation_gain_meters, elevation_loss_meters,
                 min_lat, max_lat, min_lon, max_lon, start_lat, start_lon, centroid,
                 visibility, visibility_changed_at, allow_download, hide_uploader_name)
             VALUES
                (:public_id, :user_id, :activity_type_id, :route_type, :has_time_data, :enabled_data_fields, :enabled_functions, :title, :description, :gpx_file_path, :original_gpx_file_path, :file_size_bytes,
                 :distance_meters, :elevation_gain_meters, :elevation_loss_meters,
                 :min_lat, :max_lat, :min_lon, :max_lon, :start_lat, :start_lon, ST_GeomFromText(:centroid_wkt, 4326),
                 :visibility, NOW(), :allow_download, :hide_uploader_name)'
        );
        $stmt->execute([
            'public_id'              => $publicId,
            'user_id'                => $user['id'],
            'activity_type_id'       => $activityTypeId,
            'route_type'             => $routeType,
            'has_time_data'          => $stats['has_time_data'] ? 1 : 0,
            'enabled_data_fields'    => $enabledDataFields,
            'enabled_functions'      => $enabledFunctions,
            'title'                  => $title,
            'description'            => $description !== '' ? $description : null,
            'gpx_file_path'          => $stored['relative_path'],
            'original_gpx_file_path' => $originalStored['relative_path'] ?? null,
            'file_size_bytes'        => $sizeBytes,
            'distance_meters'        => $stats['distance_meters'],
            'elevation_gain_meters'  => $stats['elevation_gain_meters'],
            'elevation_loss_meters'  => $stats['elevation_loss_meters'],
            'min_lat'                => $stats['min_lat'],
            'max_lat'                => $stats['max_lat'],
            'min_lon'                => $stats['min_lon'],
            'max_lon'                => $stats['max_lon'],
            'start_lat'              => $stats['start_lat'],
            'start_lon'              => $stats['start_lon'],
            // WKT bruger lon lat rækkefølge (X Y), ikke lat lon
            'centroid_wkt'           => sprintf('POINT(%F %F)', $stats['centroid_lon'], $stats['centroid_lat']),
            'visibility'             => $visibility,
            'allow_download'         => $allowDownload ? 1 : 0,
            'hide_uploader_name'     => $hideUploaderName ? 1 : 0,
        ]);

        $routeId = (int) $pdo->lastInsertId();

        $totalSizeBytes = $sizeBytes + ($originalStored['size_bytes'] ?? 0);
        $stmt = $pdo->prepare(
            'UPDATE users SET storage_used_bytes = storage_used_bytes + :size WHERE id = :id'
        );
        $stmt->execute(['size' => $totalSizeBytes, 'id' => $user['id']]);

        if ($visibility === 'public' && $user['public_route_notice_ack_at'] === null) {
            $stmt = $pdo->prepare('UPDATE users SET public_route_notice_ack_at = NOW() WHERE id = :id');
            $stmt->execute(['id' => $user['id']]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        // Ryd op i de gemte filer, hvis DB-indsættelsen fejlede,
        // så vi ikke efterlader forældreløse filer på disk.
        @unlink($stored['full_path']);
        if ($originalStored !== null) {
            @unlink($originalStored['full_path']);
        }
        error_log('Ruteoprettelse fejlede: ' . $e->getMessage());
        return ['success' => false, 'errors' => ['Der skete en fejl under oprettelse af ruten. Prøv igen.']];
    }

    // Delingsbillede laves efter svaret er sendt (kun for offentlige ruter).
    og_refresh($pdo, $publicId);

    return ['success' => true, 'route_id' => $routeId, 'public_id' => $publicId];
}
