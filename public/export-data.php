<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/data_export.php';

$userId = require_login();
$user = current_user($userId);

if ($user === null) {
    $_SESSION = [];
    session_destroy();
    header('Location: login.php');
    exit;
}

// Indholdet bygges i app/data_export.php (deles med api/v1/export-data.php).
try {
    $tmpZipPath = data_export_create_zip(get_db(), $user);
} catch (RuntimeException $e) {
    http_response_code(500);
    echo $e->getMessage();
    exit;
}

data_export_send($tmpZipPath);
