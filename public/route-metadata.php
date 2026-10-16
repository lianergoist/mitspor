<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../app/db.php';

header('Content-Type: application/json; charset=UTF-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'errors' => ['Du skal være logget ind.']]);
    exit;
}

$publicId = (string) ($_GET['id'] ?? '');

$pdo = get_db();
$stmt = $pdo->prepare(
    "SELECT r.public_id, r.title, r.description, r.activity_type_id, r.route_type, r.has_time_data, r.visibility, r.allow_download, r.hide_uploader_name, r.marker_icon,
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

echo json_encode([
    'success'          => true,
    'public_id'        => $route['public_id'],
    'title'            => $route['title'],
    'description'      => $route['description'],
    'activity_type_id' => (int) $route['activity_type_id'],
    'route_type'        => $route['route_type'],
    'has_time_data'    => (bool) $route['has_time_data'],
    'visibility'       => $route['visibility'],
    'allow_download'   => (bool) $route['allow_download'],
    'hide_uploader_name' => (bool) $route['hide_uploader_name'],
    'group_id'         => $route['group_public_id'] ?? '',
    'marker_icon'      => $route['marker_icon'] ?? '',
]);
