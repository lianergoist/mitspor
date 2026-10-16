<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/group_file.php';

// Download af en hel gruppe som én GPX-fil. Adgangsreglerne står i
// app/group_file.php (deles med api/v1/group-file.php).

$viewerId = !empty($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
$result = group_file_find(get_db(), (string) ($_GET['id'] ?? ''), $viewerId);

if ($result['status'] === 403) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Ingen af etaperne i denne gruppe må downloades.';
    exit;
}
if ($result['status'] !== 200) {
    http_response_code($result['status']);
    exit;
}

if (!group_file_send($result['group'], $result['included'], $result['is_owner'])) {
    http_response_code(500);
}
