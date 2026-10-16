<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/group_file.php';
require_once __DIR__ . '/../app/route_file.php';

// Download af en hel gruppe som én GPX-fil. Adgangsreglerne står i
// app/group_file.php (group_download_plan).

$pdo = get_db();
$group = group_find($pdo, (string) ($_GET['id'] ?? ''));

$isOwner = $group !== null
    && !empty($_SESSION['user_id'])
    && (int) $_SESSION['user_id'] === (int) $group['user_id'];

$members = $group !== null ? group_members($pdo, (int) $group['id'], $isOwner) : [];

// Samme regel som group-view.php: en gruppe uden synlige etaper findes ikke
// for andre end ejeren.
if ($group === null || $members === []) {
    http_response_code(404);
    exit;
}

$plan = group_download_plan($members, $isOwner);

if ($plan['included'] === []) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Ingen af etaperne i denne gruppe må downloades.';
    exit;
}

$content = group_file_build($group, $plan['included'], $isOwner);
if ($content === null) {
    http_response_code(500);
    exit;
}

header('Content-Type: application/gpx+xml; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header(route_file_attachment_header(route_file_download_name($group)));
echo $content;
