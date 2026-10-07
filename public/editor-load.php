<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/gpx.php';

header('Content-Type: application/json; charset=UTF-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'errors' => ['Du skal være logget ind.']]);
    exit;
}

$publicId = (string) ($_GET['id'] ?? '');

$pdo = get_db();
$stmt = $pdo->prepare(
    "SELECT r.id, r.public_id, r.title, r.description, r.activity_type_id, r.route_type, r.visibility, r.allow_download, r.hide_uploader_name, r.gpx_file_path, r.enabled_data_fields, r.enabled_functions,
            g.public_id AS group_public_id
     FROM routes r
     LEFT JOIN route_groups g ON g.id = r.group_id
     WHERE r.public_id = :public_id AND r.user_id = :user_id AND r.status = 'active'
     LIMIT 1"
);
$stmt->execute(['public_id' => $publicId, 'user_id' => (int) $_SESSION['user_id']]);
$route = $stmt->fetch();

if (!$route) {
    http_response_code(404);
    echo json_encode(['success' => false, 'errors' => ['Ruten blev ikke fundet, eller tilhører dig ikke.']]);
    exit;
}

$fullPath = gpx_full_path($route['gpx_file_path']);
if (!is_file($fullPath)) {
    http_response_code(404);
    echo json_encode(['success' => false, 'errors' => ['GPX-filen mangler på serveren.']]);
    exit;
}

$enabledDataFields = $route['enabled_data_fields'] !== null
    ? json_decode($route['enabled_data_fields'], true)
    : null;
if (!is_array($enabledDataFields)) {
    $enabledDataFields = null;
}

$enabledFunctions = $route['enabled_functions'] !== null
    ? json_decode($route['enabled_functions'], true)
    : null;
if (!is_array($enabledFunctions)) {
    $enabledFunctions = null;
}

echo json_encode([
    'success'          => true,
    'route_id'         => $route['public_id'],
    'title'            => $route['title'],
    'description'      => $route['description'],
    'activity_type_id' => (int) $route['activity_type_id'],
    'route_type'        => $route['route_type'],
    'visibility'       => $route['visibility'],
    'allow_download'   => (bool) $route['allow_download'],
    'hide_uploader_name' => (bool) $route['hide_uploader_name'],
    'group_id'         => $route['group_public_id'] ?? '',
    'enabled_data_fields' => $enabledDataFields, // liste af feltnavne, eller null = "ikke sat endnu"
    'enabled_functions'   => $enabledFunctions,   // liste af funktionsnøgler, eller null = "ikke sat endnu"
    'gpx_content'      => file_get_contents($fullPath),
]);
