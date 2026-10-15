<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/route_file.php';

// Adgangsreglerne står i app/route_file.php (deles med api/v1/route-file.php).

$publicId = (string) ($_GET['id'] ?? '');
$download = ($_GET['mode'] ?? 'view') === 'download';
$viewerId = !empty($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;

$result = route_file_find(get_db(), $publicId, $viewerId, (string) ($_GET['share'] ?? ''), $download);

if ($result['status'] !== 200) {
    http_response_code($result['status']);
    exit;
}

route_file_send($result['route'], $download, $result['is_owner']);
