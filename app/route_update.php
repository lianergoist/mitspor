<?php
declare(strict_types=1);

require_once __DIR__ . '/gpx.php';
require_once __DIR__ . '/og_image.php';

/**
 * Opdaterer en eksisterende rute. Hvis $gpxContent er null, ændres kun
 * metadata (titel, beskrivelse, aktivitet, synlighed, download-tilladelse)
 * — selve GPX-filen og dens stats rører vi slet ikke. Hvis $gpxContent
 * er sat, revalideres og genberegnes alt (som ved create_route()), og
 * den gamle fil erstattes af den nye.
 *
 * Der tjekkes bevidst IKKE for usandsynlig hastighed eller hyppig
 * logningsfrekvens — se create_route() for begrundelsen.
 *
 * @return array{success: bool, errors?: string[], route_id?: int}
 */
function update_route(
    PDO $pdo,
    array $user,
    int $routeId,
    ?string $gpxContent,
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
    $stmt = $pdo->prepare(
        "SELECT * FROM routes WHERE id = :id AND user_id = :user_id AND status = 'active' LIMIT 1"
    );
    $stmt->execute(['id' => $routeId, 'user_id' => $user['id']]);
    $existingRoute = $stmt->fetch();

    if (!$existingRoute) {
        return ['success' => false, 'errors' => ['Ruten blev ikke fundet, eller tilhører dig ikke.']];
    }

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

    if (!empty($errors)) {
        return ['success' => false, 'errors' => $errors];
    }

    $newStored = null;
    $newOriginalStored = null;
    $originalGpxFilePath = $existingRoute['original_gpx_file_path']; // uændret, medmindre der udtyndes forneden
    $hasTimeData = (bool) $existingRoute['has_time_data']; // uændret, medmindre GPX-indholdet erstattes forneden

    if ($gpxContent === null) {
        // --- Kun metadata ændres — filen og dens stats er urørte ---
        $sizeBytes       = (int) $existingRoute['file_size_bytes'];
        $gpxFilePath     = $existingRoute['gpx_file_path'];
        $distanceMeters  = (int) $existingRoute['distance_meters'];
        $elevationGain   = (int) $existingRoute['elevation_gain_meters'];
        $elevationLoss   = (int) $existingRoute['elevation_loss_meters'];
        $minLat          = $existingRoute['min_lat'];
        $maxLat          = $existingRoute['max_lat'];
        $minLon          = $existingRoute['min_lon'];
        $maxLon          = $existingRoute['max_lon'];
        $startLat        = $existingRoute['start_lat'];
        $startLon        = $existingRoute['start_lon'];
        $centroidWkt     = null; // uændret — røres ikke i UPDATE-forespørgslen
    } else {
        // --- Selve ruten erstattes — fuld revalidering ---
        $parsed = gpx_validate_and_parse($gpxContent);
        if (!$parsed['valid']) {
            return ['success' => false, 'errors' => $parsed['errors']];
        }
        $stats = $parsed['stats'];
        $hasTimeData = (bool) $stats['has_time_data'];

        // Ved FØRSTE udtynding af denne rute: gem en kopi af den
        // hidtidige, fulde fil, før den overskrives af den udtyndede
        // version — ellers har "Gendan original fil" i kontrolpanelet
        // intet at gendanne til. Kun første gang (aldrig overskrevet
        // siden, jf. $existingRoute['original_gpx_file_path'] === null).
        if ($thinIntervalSeconds !== null && $existingRoute['original_gpx_file_path'] === null) {
            $previousContent = @file_get_contents(gpx_full_path($existingRoute['gpx_file_path']));
            if ($previousContent !== false) {
                $newOriginalStored = gpx_store_file((int) $user['id'], $previousContent);
                $originalGpxFilePath = $newOriginalStored['relative_path'];
            }
        }

        // Hastighedsplausibilitet og automatisk udtyndingsforslag er
        // bevidst fjernet herfra — de forvirrede mere end de hjalp.
        // Aktivitetstypen har nu udelukkende betydning for hvilken
        // markør ruten vises med. Vil en bruger fjerne tidsstempler
        // eller udtynde punkter, gøres det manuelt via værktøjskassen
        // i editoren.

        $sizeBytes = strlen($gpxContent);
        $backupSizeBytes = $newOriginalStored['size_bytes'] ?? 0;
        $storageDelta = ($sizeBytes + $backupSizeBytes) - (int) $existingRoute['file_size_bytes'];

        if (($user['storage_used_bytes'] + $storageDelta) > $user['storage_quota_bytes']) {
            if ($newOriginalStored !== null) {
                @unlink($newOriginalStored['full_path']);
            }
            return ['success' => false, 'errors' => ['Du har ikke nok ledig lagerplads til den opdaterede fil.']];
        }

        $newStored = gpx_store_file((int) $user['id'], $gpxContent);

        $gpxFilePath    = $newStored['relative_path'];
        $distanceMeters = $stats['distance_meters'];
        $elevationGain  = $stats['elevation_gain_meters'];
        $elevationLoss  = $stats['elevation_loss_meters'];
        $minLat         = $stats['min_lat'];
        $maxLat         = $stats['max_lat'];
        $minLon         = $stats['min_lon'];
        $maxLon         = $stats['max_lon'];
        $startLat       = $stats['start_lat'];
        $startLon       = $stats['start_lon'];
        $centroidWkt    = sprintf('POINT(%F %F)', $stats['centroid_lon'], $stats['centroid_lat']);
    }

    $storageSizeDelta = ($sizeBytes + ($newOriginalStored['size_bytes'] ?? 0)) - (int) $existingRoute['file_size_bytes'];
    $visibilityChanged = $visibility !== $existingRoute['visibility'];

    try {
        $pdo->beginTransaction();

        $centroidExpr = $centroidWkt !== null ? 'ST_GeomFromText(:centroid_wkt, 4326)' : 'centroid';

        $sql = "UPDATE routes SET
                    title = :title,
                    description = :description,
                    activity_type_id = :activity_type_id,
                    route_type = :route_type,
                    has_time_data = :has_time_data,
                    enabled_data_fields = :enabled_data_fields,
                    enabled_functions = :enabled_functions,
                    gpx_file_path = :gpx_file_path,
                    original_gpx_file_path = :original_gpx_file_path,
                    file_size_bytes = :file_size_bytes,
                    distance_meters = :distance_meters,
                    elevation_gain_meters = :elevation_gain_meters,
                    elevation_loss_meters = :elevation_loss_meters,
                    min_lat = :min_lat,
                    max_lat = :max_lat,
                    min_lon = :min_lon,
                    max_lon = :max_lon,
                    start_lat = :start_lat,
                    start_lon = :start_lon,
                    centroid = $centroidExpr,
                    visibility = :visibility,
                    allow_download = :allow_download,
                    hide_uploader_name = :hide_uploader_name"
                . ($visibilityChanged ? ', visibility_changed_at = NOW()' : '')
                . ' WHERE id = :id';

        $stmt = $pdo->prepare($sql);
        $params = [
            'title'                 => $title,
            'description'           => $description !== '' ? $description : null,
            'activity_type_id'      => $activityTypeId,
            'route_type'            => $routeType,
            'has_time_data'         => $hasTimeData ? 1 : 0,
            'enabled_data_fields'   => $enabledDataFields !== null ? $enabledDataFields : $existingRoute['enabled_data_fields'],
            'enabled_functions'     => $enabledFunctions !== null ? $enabledFunctions : $existingRoute['enabled_functions'],
            'gpx_file_path'         => $gpxFilePath,
            'original_gpx_file_path' => $originalGpxFilePath,
            'file_size_bytes'       => $sizeBytes,
            'distance_meters'       => $distanceMeters,
            'elevation_gain_meters' => $elevationGain,
            'elevation_loss_meters' => $elevationLoss,
            'min_lat'               => $minLat,
            'max_lat'               => $maxLat,
            'min_lon'               => $minLon,
            'max_lon'               => $maxLon,
            'start_lat'             => $startLat,
            'start_lon'             => $startLon,
            'visibility'            => $visibility,
            'allow_download'        => $allowDownload ? 1 : 0,
            'hide_uploader_name'    => $hideUploaderName ? 1 : 0,
            'id'                    => $routeId,
        ];
        if ($centroidWkt !== null) {
            $params['centroid_wkt'] = $centroidWkt;
        }
        $stmt->execute($params);

        if ($storageSizeDelta !== 0) {
            $stmt = $pdo->prepare(
                'UPDATE users SET storage_used_bytes = storage_used_bytes + :delta WHERE id = :id'
            );
            $stmt->execute(['delta' => $storageSizeDelta, 'id' => $user['id']]);
        }

        if ($visibility === 'public' && $user['public_route_notice_ack_at'] === null) {
            $stmt = $pdo->prepare('UPDATE users SET public_route_notice_ack_at = NOW() WHERE id = :id');
            $stmt->execute(['id' => $user['id']]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        // Kun oprydning af NYE filer ved fejl — de gamle filer er urørte
        // og forbliver de gyldige, gemte versioner.
        if ($newStored !== null) {
            @unlink($newStored['full_path']);
        }
        if ($newOriginalStored !== null) {
            @unlink($newOriginalStored['full_path']);
        }
        error_log('Ruteopdatering fejlede: ' . $e->getMessage());
        return ['success' => false, 'errors' => ['Der skete en fejl under opdatering af ruten. Prøv igen.']];
    }

    // Den gamle fil slettes FØRST efter succesfuld commit, og kun hvis
    // den rent faktisk er blevet erstattet af en ny.
    if ($newStored !== null) {
        @unlink(gpx_full_path($existingRoute['gpx_file_path']));
    }

    // Nyt delingsbillede efter ændringen — eller slet det, hvis ruten
    // ikke længere er offentlig.
    og_refresh($pdo, (string) $existingRoute['public_id']);

    return ['success' => true, 'route_id' => $routeId];
}
