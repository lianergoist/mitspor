<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/gpx.php';
require_once __DIR__ . '/../app/image_storage.php';

$userId = require_login();
$user = current_user($userId);

if ($user === null) {
    $_SESSION = [];
    session_destroy();
    header('Location: login.php');
    exit;
}

if (!class_exists('ZipArchive')) {
    http_response_code(500);
    echo 'Serveren mangler PHP-udvidelsen "zip". Kontakt administrator.';
    exit;
}

$pdo = get_db();

$stmt = $pdo->prepare(
    "SELECT id, title, description, activity_type_id, visibility, allow_download,
            distance_meters, elevation_gain_meters, elevation_loss_meters,
            gpx_file_path, file_size_bytes, created_at, group_id, group_position
     FROM routes
     WHERE user_id = :uid AND status = 'active'
     ORDER BY created_at"
);
$stmt->execute(['uid' => $userId]);
$routes = $stmt->fetchAll();

$stmt = $pdo->prepare(
    'SELECT id, title, description, created_at
     FROM route_groups
     WHERE user_id = :uid
     ORDER BY created_at'
);
$stmt->execute(['uid' => $userId]);
$groups = $stmt->fetchAll();

$stmt = $pdo->prepare(
    'SELECT relative_path, original_filename, size_bytes, created_at
     FROM user_images
     WHERE user_id = :uid
     ORDER BY created_at'
);
$stmt->execute(['uid' => $userId]);
$images = $stmt->fetchAll();

$tmpZipPath = tempnam(sys_get_temp_dir(), 'gpx_export_');
if ($tmpZipPath === false) {
    http_response_code(500);
    echo 'Kunne ikke oprette en midlertidig fil. Prøv igen.';
    exit;
}

$zip = new ZipArchive();
if ($zip->open($tmpZipPath, ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    echo 'Kunne ikke oprette ZIP-arkivet.';
    exit;
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

$zip->addFromString('data.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

$zip->close();

$downloadName = 'gpx-data-eksport-' . date('Y-m-d') . '.zip';

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('Content-Length: ' . filesize($tmpZipPath));
readfile($tmpZipPath);

unlink($tmpZipPath);
