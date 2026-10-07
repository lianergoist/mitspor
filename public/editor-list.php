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

$pdo = get_db();
$stmt = $pdo->prepare(
    "SELECT public_id AS id, title, visibility
     FROM routes
     WHERE user_id = :user_id AND status = 'active'
     ORDER BY created_at DESC"
);
$stmt->execute(['user_id' => (int) $_SESSION['user_id']]);

echo json_encode(['success' => true, 'routes' => $stmt->fetchAll()]);
