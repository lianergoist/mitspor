<?php
declare(strict_types=1);

// Henter en hel gruppe til editoren (editor/?group=<id>): gruppens titel og
// alle etaper i rækkefølge med samme felter som editor-load.php. Kun ejeren.
// Editoren gemmer hver etape for sig via editor-save.php.

session_start();

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/gpx.php';
require_once __DIR__ . '/../app/route_groups.php';

header('Content-Type: application/json; charset=UTF-8');

// Hele gruppen sendes i ét svar, så den samlede størrelse holdes nede.
const GROUP_EDITOR_MAX_BYTES = 3 * MAX_UPLOAD_SIZE_BYTES;

function json_fail_group_load(array $errors, int $httpCode = 400): never
{
    http_response_code($httpCode);
    echo json_encode(['success' => false, 'errors' => $errors]);
    exit;
}

if (empty($_SESSION['user_id'])) {
    json_fail_group_load(['Du skal være logget ind.'], 401);
}
$userId = (int) $_SESSION['user_id'];

$pdo = get_db();
$group = group_find_owned($pdo, $userId, (string) ($_GET['id'] ?? ''));
if ($group === null) {
    json_fail_group_load(['Gruppen blev ikke fundet, eller tilhører dig ikke.'], 404);
}

$stmt = $pdo->prepare(
    "SELECT public_id, title, description, activity_type_id, route_type, visibility, allow_download,
            hide_uploader_name, gpx_file_path, file_size_bytes, enabled_data_fields, enabled_functions
     FROM routes
     WHERE group_id = :gid AND user_id = :uid AND status = 'active'
     ORDER BY group_position ASC, id ASC"
);
$stmt->execute(['gid' => $group['id'], 'uid' => $userId]);
$rows = $stmt->fetchAll();
if (!$rows) {
    json_fail_group_load(['Gruppen har ingen etaper.'], 404);
}

$totalBytes = array_sum(array_map(static fn($r) => (int) $r['file_size_bytes'], $rows));
if ($totalBytes > GROUP_EDITOR_MAX_BYTES) {
    json_fail_group_load(['Gruppen er for stor til at blive åbnet i editoren på én gang. Rediger etaperne hver for sig.']);
}

$decodeList = static function (?string $json): ?array {
    if ($json === null) {
        return null;
    }
    $list = json_decode($json, true);

    return is_array($list) ? $list : null;
};

$stages = [];
foreach ($rows as $route) {
    $fullPath = gpx_full_path($route['gpx_file_path']);
    if (!is_file($fullPath)) {
        json_fail_group_load(['GPX-filen til "' . $route['title'] . '" mangler på serveren.'], 404);
    }
    $stages[] = [
        'route_id'            => $route['public_id'],
        'title'               => $route['title'],
        'description'         => $route['description'],
        'activity_type_id'    => (int) $route['activity_type_id'],
        'route_type'          => $route['route_type'],
        'visibility'          => $route['visibility'],
        'allow_download'      => (bool) $route['allow_download'],
        'hide_uploader_name'  => (bool) $route['hide_uploader_name'],
        'group_id'            => $group['public_id'],
        'group_title'         => $group['title'],
        'enabled_data_fields' => $decodeList($route['enabled_data_fields']),
        'enabled_functions'   => $decodeList($route['enabled_functions']),
        'gpx_content'         => file_get_contents($fullPath),
    ];
}

echo json_encode([
    'success'     => true,
    'group_id'    => $group['public_id'],
    'title'       => $group['title'],
    'description' => $group['description'],
    'stages'      => $stages,
]);
