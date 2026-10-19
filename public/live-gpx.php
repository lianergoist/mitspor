<?php
declare(strict_types=1);

// =====================================================================
// En livesendings etape som GPX, til route-view.php mens etapen er i gang.
//
//   GET live-gpx.php?id=12345678&etape=1[&k=nøgle]
//
// Samme adgang og forsinkelse som livesiden (app/live_view.php).
// =====================================================================

session_start();

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/live_view.php';

$pdo = get_db();
$event = live_event_find($pdo, (string) ($_GET['id'] ?? ''));
$sessionUserId = !empty($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
session_write_close();

if ($event === null || !live_can_view($event, $sessionUserId, (string) ($_GET['k'] ?? ''))) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Livesendingen blev ikke fundet.';
    exit;
}

$isOwner = $sessionUserId === (int) $event['user_id'];
$stage = live_stage_for_view($pdo, $event, (int) ($_GET['etape'] ?? 0), $isOwner);
if ($stage === null || !$stage['started_visible']) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Etapen blev ikke fundet.';
    exit;
}

header('Content-Type: application/gpx+xml; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
echo live_stage_gpx($pdo, $stage['id'], live_stage_name($event, $stage), $isOwner ? null : $stage['cutoff']);
