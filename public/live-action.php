<?php
declare(strict_types=1);

// =====================================================================
// Handlinger på livesendinger fra kontrolpanelet. Alle er POST med
// csrf_token og en "action":
//
//   save        [event_id], title, description, activity_type_id, visibility,
//               delay_minutes, planned_start_at, plan, public_notice_ack
//               -> opretter (uden event_id) eller retter en livesending.
//               plan: keep | none | route:<id> | group:<id>
//   finish      event_id               -> afslut livesendingen
//   new_link    event_id               -> ny delingsnøgle (gammelt link virker ikke mere)
//   delete      event_id, confirm_title -> slet livesendingen (sporene bevares)
//
// event_id er livesendingens 8-cifrede OFFENTLIGE id.
// =====================================================================

session_start();

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/live.php';

header('Content-Type: application/json; charset=UTF-8');

function live_json_fail(array $errors, int $httpCode = 400): never
{
    http_response_code($httpCode);
    echo json_encode(['success' => false, 'errors' => $errors], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    live_json_fail(['Ugyldig forespørgsel.'], 405);
}
if (empty($_SESSION['user_id'])) {
    live_json_fail(['Du skal være logget ind.'], 401);
}
if (!csrf_validate($_POST['csrf_token'] ?? null)) {
    live_json_fail(['Din session er udløbet. Genindlæs siden og prøv igen.'], 403);
}

$user = current_user((int) $_SESSION['user_id']);
if ($user === null) {
    live_json_fail(['Kontoen findes ikke længere.'], 401);
}
$userId = (int) $user['id'];
$pdo = get_db();

$action = (string) ($_POST['action'] ?? '');
$eventPublicId = (string) ($_POST['event_id'] ?? '');

$event = null;
if ($eventPublicId !== '') {
    $event = live_event_find_owned($pdo, $userId, $eventPublicId);
    if ($event === null) {
        live_json_fail(['Livesendingen blev ikke fundet, eller tilhører dig ikke.'], 404);
    }
} elseif ($action !== 'save') {
    live_json_fail(['Livesendingen blev ikke fundet, eller tilhører dig ikke.'], 404);
}

switch ($action) {
    case 'save':
        $result = live_event_save(
            $pdo,
            $userId,
            $event,
            $_POST,
            ($_POST['public_notice_ack'] ?? '') === '1',
            (string) ($_POST['plan'] ?? 'keep')
        );
        if (!$result['success']) {
            live_json_fail($result['errors']);
        }
        echo json_encode(['success' => true, 'event_id' => $result['public_id']]);
        break;

    case 'finish':
        live_event_finish($pdo, $event);
        echo json_encode(['success' => true]);
        break;

    case 'new_link':
        live_event_regenerate_token($pdo, $event);
        echo json_encode(['success' => true]);
        break;

    case 'delete':
        if (trim((string) ($_POST['confirm_title'] ?? '')) !== $event['title']) {
            live_json_fail(['Det indtastede navn matcher ikke livesendingens titel.']);
        }
        try {
            live_event_delete($pdo, $event);
        } catch (Throwable $e) {
            error_log('Live: sletning fejlede: ' . $e->getMessage());
            live_json_fail(['Der skete en fejl under sletning. Prøv igen.'], 500);
        }
        echo json_encode(['success' => true]);
        break;

    default:
        live_json_fail(['Ugyldig handling.']);
}
