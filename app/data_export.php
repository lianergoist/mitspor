<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/gpx.php';
require_once __DIR__ . '/image_storage.php';
require_once __DIR__ . '/live.php';

/*
 * Eksport af alle brugerens data som ZIP (ruter, grupper, billeder,
 * livesendinger og data.json). Bruges af export-data.php (sitet) og api/v1/export-data.php
 * (appen), så indholdet kun bygges ét sted.
 */

/**
 * Byg ZIP-filen i en midlertidig fil og returnér stien. Kalderen sender
 * filen og sletter den bagefter.
 *
 * @param array $user række fra current_user()
 * @throws RuntimeException hvis filen ikke kan bygges
 */
function data_export_create_zip(PDO $pdo, array $user): string
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Serveren mangler PHP-udvidelsen "zip". Kontakt administrator.');
    }

    $stmt = $pdo->prepare(
        "SELECT id, title, description, activity_type_id, visibility, allow_download,
                distance_meters, elevation_gain_meters, elevation_loss_meters,
                gpx_file_path, file_size_bytes, created_at, group_id, group_position
         FROM routes
         WHERE user_id = :uid AND status = 'active'
         ORDER BY created_at"
    );
    $stmt->execute(['uid' => (int) $user['id']]);
    $routes = $stmt->fetchAll();

    $stmt = $pdo->prepare(
        'SELECT id, title, description, created_at
         FROM route_groups
         WHERE user_id = :uid
         ORDER BY created_at'
    );
    $stmt->execute(['uid' => (int) $user['id']]);
    $groups = $stmt->fetchAll();

    $stmt = $pdo->prepare(
        'SELECT relative_path, original_filename, size_bytes, created_at
         FROM user_images
         WHERE user_id = :uid
         ORDER BY created_at'
    );
    $stmt->execute(['uid' => (int) $user['id']]);
    $images = $stmt->fetchAll();

    $stmt = $pdo->prepare(
        'SELECT id, public_id, title, description, activity_type_id, status, visibility,
                delay_minutes, planned_start_at, created_at, ended_at
         FROM live_events
         WHERE user_id = :uid
         ORDER BY created_at'
    );
    $stmt->execute(['uid' => (int) $user['id']]);
    $liveEvents = $stmt->fetchAll();

    $tmpZipPath = tempnam(sys_get_temp_dir(), 'gpx_export_');
    if ($tmpZipPath === false) {
        throw new RuntimeException('Kunne ikke oprette en midlertidig fil. Prøv igen.');
    }

    $zip = new ZipArchive();
    if ($zip->open($tmpZipPath, ZipArchive::OVERWRITE) !== true) {
        unlink($tmpZipPath);
        throw new RuntimeException('Kunne ikke oprette ZIP-arkivet.');
    }

    // --- Profildata + rutemetadata som JSON ---
    $manifest = [
        'profile' => [
            'email'        => $user['email'],
            'display_name' => $user['display_name'],
            'created_at'   => $user['created_at'],
        ],
        'groups' => [],
        'routes' => [],
        'images' => [],
        'live_events' => [],
    ];

    $usedZipNames = [];

    foreach ($routes as $route) {
        // ZIP-arkiver understøtter UTF-8-filnavne fint i praksis (moderne
        // PHP/libzip sætter selv UTF-8-flaget) — kun / og \ skal undgås,
        // så en rute-titel ikke utilsigtet opretter en undermappe i arkivet.
        $safeTitle = str_replace(['/', '\\'], '-', $route['title']);
        $safeTitle = preg_replace('/[\x00-\x1F]/', '', $safeTitle); // fjern evt. kontroltegn
        $zipName = 'ruter/' . $route['id'] . '-' . $safeTitle . '.gpx';

        $fullPath = gpx_full_path($route['gpx_file_path']);
        if (is_file($fullPath)) {
            $zip->addFile($fullPath, $zipName);
        }

        $manifest['routes'][] = [
            'id'                     => (int) $route['id'],
            'title'                  => $route['title'],
            'description'            => $route['description'],
            'activity_type_id'       => (int) $route['activity_type_id'],
            'visibility'             => $route['visibility'],
            'allow_download'         => (bool) $route['allow_download'],
            'distance_meters'        => (int) $route['distance_meters'],
            'elevation_gain_meters'  => (int) $route['elevation_gain_meters'],
            'elevation_loss_meters'  => (int) $route['elevation_loss_meters'],
            'created_at'             => $route['created_at'],
            'gpx_file_in_export'     => $zipName,
            'group_id'               => $route['group_id'] !== null ? (int) $route['group_id'] : null,
            'group_position'         => $route['group_position'] !== null ? (int) $route['group_position'] : null,
        ];
    }

    foreach ($groups as $group) {
        $manifest['groups'][] = [
            'id'          => (int) $group['id'],
            'title'       => $group['title'],
            'description' => $group['description'],
            'created_at'  => $group['created_at'],
        ];
    }

    foreach ($images as $image) {
        $zipName = 'billeder/' . basename($image['relative_path']);

        $fullPath = image_full_path($image['relative_path']);
        if (is_file($fullPath)) {
            $zip->addFile($fullPath, $zipName);
        }

        $manifest['images'][] = [
            'original_filename' => $image['original_filename'],
            'size_bytes'        => (int) $image['size_bytes'],
            'created_at'         => $image['created_at'],
            'file_in_export'    => $zipName,
        ];
    }

    // Livesendinger: hver etapes rå punkter (som appen sendte dem) som GPX.
    // Delingsnøglen kommer ikke med, da den giver adgang til livesiden.
    $stageStmt = $pdo->prepare(
        'SELECT id, position, title, status, started_at, ended_at, point_count, route_id
         FROM live_stages
         WHERE event_id = :eid
         ORDER BY position'
    );
    foreach ($liveEvents as $event) {
        $stageStmt->execute(['eid' => $event['id']]);
        $stages = [];
        foreach ($stageStmt->fetchAll() as $stage) {
            $zipName = null;
            if ((int) $stage['point_count'] > 0) {
                $zipName = 'livesendinger/' . $event['public_id'] . '-etape-' . (int) $stage['position'] . '.gpx';
                $zip->addFromString($zipName, live_stage_gpx($pdo, (int) $stage['id'], $stage['title'] !== '' ? $event['title'] . ' – ' . $stage['title'] : (string) $event['title']));
            }
            $stages[] = [
                'position'         => (int) $stage['position'],
                'title'            => $stage['title'],
                'status'           => $stage['status'],
                'started_at'       => $stage['started_at'],
                'ended_at'         => $stage['ended_at'],
                'point_count'      => (int) $stage['point_count'],
                'route_id'         => $stage['route_id'] !== null ? (int) $stage['route_id'] : null,
                'gpx_file_in_export' => $zipName,
            ];
        }

        $manifest['live_events'][] = [
            'public_id'        => $event['public_id'],
            'title'            => $event['title'],
            'description'      => $event['description'],
            'activity_type_id' => (int) $event['activity_type_id'],
            'status'           => $event['status'],
            'visibility'       => $event['visibility'],
            'delay_minutes'    => (int) $event['delay_minutes'],
            'planned_start_at' => $event['planned_start_at'],
            'created_at'       => $event['created_at'],
            'ended_at'         => $event['ended_at'],
            'stages'           => $stages,
        ];
    }

    $zip->addFromString('data.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $zip->close();

    return $tmpZipPath;
}

/**
 * Filnavnet på eksporten.
 */
function data_export_filename(): string
{
    return 'gpx-data-eksport-' . date('Y-m-d') . '.zip';
}

/**
 * Send ZIP-filen, slet den midlertidige fil og afslut.
 */
function data_export_send(string $tmpZipPath): never
{
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . data_export_filename() . '"');
    header('Content-Length: ' . filesize($tmpZipPath));
    readfile($tmpZipPath);

    unlink($tmpZipPath);
    exit;
}
