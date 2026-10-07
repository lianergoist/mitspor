<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/config.php';

header('Content-Type: application/json; charset=UTF-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'errors' => ['Du skal være logget ind.']]);
    exit;
}

$pdo = get_db();
$stmt = $pdo->prepare(
    'SELECT id, relative_path, thumbnail_relative_path, original_filename, mime_type
     FROM user_images
     WHERE user_id = :user_id
     ORDER BY created_at DESC
     LIMIT 100'
);
$stmt->execute(['user_id' => (int) $_SESSION['user_id']]);
$rows = $stmt->fetchAll();

$images = array_map(static function (array $row): array {
    $thumbRelative = $row['thumbnail_relative_path'];
    return [
        'image_id'      => (int) $row['id'],
        'url'           => IMAGE_PUBLIC_URL_BASE . '/' . $row['relative_path'],
        'thumbnail_url' => $thumbRelative !== null ? IMAGE_PUBLIC_URL_BASE . '/' . $thumbRelative : null,
        'original_filename' => $row['original_filename'],
        'mime_type'         => $row['mime_type'],
    ];
}, $rows);

echo json_encode(['success' => true, 'images' => $images]);
