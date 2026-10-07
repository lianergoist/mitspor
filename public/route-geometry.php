<?php
declare(strict_types=1);

// =====================================================================
// Forenklet geometri til mini-kortet i kontrolpanelet. Kun ejerens egne
// ruter og grupper (også private) — kræver login.
//
//   GET route-geometry.php?route=12345678
//       -> { "success": true, "points": [[lat, lon], ...] }
//   GET route-geometry.php?group=ABCDEF
//       -> { "success": true, "stages": [{ "id", "title", "points": [[lat, lon], ...] }, ...] }
// =====================================================================

session_start();

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/gpx.php';
require_once __DIR__ . '/../app/route_groups.php';

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');

function geometry_fail(string $message, int $code): never
{
    http_response_code($code);
    echo json_encode(['success' => false, 'errors' => [$message]]);
    exit;
}

if (empty($_SESSION['user_id'])) {
    geometry_fail('Du skal være logget ind.', 401);
}
$userId = (int) $_SESSION['user_id'];
$pdo = get_db();

$routeId = (string) ($_GET['route'] ?? '');
$groupId = (string) ($_GET['group'] ?? '');

if ($routeId !== '') {
    $stmt = $pdo->prepare(
        "SELECT gpx_file_path FROM routes
         WHERE public_id = :public_id AND user_id = :user_id AND status = 'active' LIMIT 1"
    );
    $stmt->execute(['public_id' => $routeId, 'user_id' => $userId]);
    $route = $stmt->fetch();
    if (!$route) {
        geometry_fail('Ruten blev ikke fundet, eller tilhører dig ikke.', 404);
    }

    $full = gpx_full_path((string) $route['gpx_file_path']);
    $points = is_file($full) ? og_read_points($full) : [];

    echo json_encode(['success' => true, 'points' => group_simplify_for_map($points)]);
    exit;
}

if ($groupId !== '') {
    if (!group_valid_public_id($groupId)) {
        geometry_fail('Ugyldigt gruppe-id.', 400);
    }
    $group = group_find_owned($pdo, $userId, $groupId);
    if ($group === null) {
        geometry_fail('Gruppen blev ikke fundet, eller tilhører dig ikke.', 404);
    }

    $members = group_members($pdo, (int) $group['id'], true);
    echo json_encode(['success' => true, 'stages' => group_geometry($group, $members, true)]);
    exit;
}

geometry_fail('Angiv route eller group.', 400);
