<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/csrf.php';
require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/moderation.php';

$userId = require_login();
$user = current_user($userId);

if ($user === null) {
    $_SESSION = [];
    session_destroy();
    header('Location: ../login.php');
    exit;
}

if (!$user['is_admin']) {
    http_response_code(403);
    echo 'Ingen adgang.';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_validate($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    echo 'Ugyldig forespørgsel.';
    exit;
}

$pdo = get_db();
$reportId = (int) ($_POST['report_id'] ?? 0);
$action = (string) ($_POST['action'] ?? '');

switch ($action) {
    case 'dismiss':
        admin_dismiss_report($pdo, $reportId, $userId);
        break;
    case 'reviewed':
        admin_mark_reviewed($pdo, $reportId, $userId);
        break;
    case 'remove_route':
        admin_remove_reported_route($pdo, $reportId, $userId);
        break;
    default:
        http_response_code(400);
        echo 'Ukendt handling.';
        exit;
}

header('Location: index.php');
