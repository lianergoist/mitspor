<?php
declare(strict_types=1);

// =====================================================================
// Data til livesiden (live.php), hentet med fetch() hvert halve minut.
//
//   GET live-data.php?id=12345678[&k=nøgle][&v=version][&since=punkt-id]
//
// Første gang (eller når versionen er ændret) kommer alt: etaperne med
// færdige spor, plan og rå punkter. Ellers kun nye punkter efter "since".
// Forsinkelsen håndhæves i live_view_payload() (app/live_view.php).
// =====================================================================

session_start();

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/live_view.php';

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');

$pdo = get_db();
$event = live_event_find($pdo, (string) ($_GET['id'] ?? ''));
$sessionUserId = !empty($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
// Sessionen bruges kun til at genkende ejeren; slip låsen, så samtidige
// forespørgsler fra samme browser ikke venter på hinanden.
session_write_close();

if ($event === null || !live_can_view($event, $sessionUserId, (string) ($_GET['k'] ?? ''))) {
    http_response_code(404);
    echo json_encode(['success' => false, 'errors' => ['Livesendingen blev ikke fundet.']], JSON_UNESCAPED_UNICODE);
    exit;
}

$isOwner = $sessionUserId === (int) $event['user_id'];
$version = (string) ($_GET['v'] ?? '');
$since = max(0, (int) ($_GET['since'] ?? 0));

echo json_encode(live_view_payload($pdo, $event, $isOwner, $version, $since), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
