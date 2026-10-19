<?php
declare(strict_types=1);

// API: start eller afslut en etape i en livesending.
// POST: event (livesendingens public_id), client_upload_id (optagelsens
//       UUID), action ('start' eller 'end'), finish_event (1 = afslut også
//       hele livesendingen; kun ved 'end')
//
// Svar:
//   200 { success, stage: { id, position, title, status, planned } }
//       'start' kan gentages og giver samme etape.
//   404 livesendingen eller etapen findes ikke
//   409 livesendingen er afsluttet
//   422 valideringsfejl
//   429 for mange forespørgsler

require_once __DIR__ . '/../../../app/api_auth.php';
require_once __DIR__ . '/../../../app/rate_limit.php';
require_once __DIR__ . '/../../../app/live.php';

api_bootstrap(['POST']);

$user   = api_require_token();
$userId = (int) $user['id'];
$pdo    = get_db();

if (!rate_limit_allow_identifier('api_live', (string) $userId, API_LIVE_RATE_LIMIT_MAX_ATTEMPTS, API_LIVE_RATE_LIMIT_WINDOW_SECONDS)) {
    api_error('For mange forespørgsler. Prøv igen senere.', 429);
}
rate_limit_record('api_live', client_ip(), (string) $userId);

$action         = (string) ($_POST['action'] ?? '');
$clientUploadId = strtolower(trim((string) ($_POST['client_upload_id'] ?? '')));

if (!in_array($action, ['start', 'end'], true)) {
    api_error('Ugyldig handling.', 422);
}
if (!live_valid_client_upload_id($clientUploadId)) {
    api_error('client_upload_id skal være et UUID.', 422);
}

$event = live_event_find_owned($pdo, $userId, (string) ($_POST['event'] ?? ''));
if ($event === null) {
    api_error('Livesendingen blev ikke fundet.', 404);
}

if ($action === 'start') {
    $result = live_stage_start($pdo, $event, $clientUploadId);
    if (!$result['success']) {
        api_error($result['errors'][0], $event['status'] === 'afsluttet' ? 409 : 500);
    }
} else {
    $result = live_stage_end($pdo, $event, $clientUploadId, ($_POST['finish_event'] ?? '') === '1');
    if (!$result['success']) {
        api_error($result['errors'][0], 404);
    }
}

api_json(['success' => true, 'stage' => live_stage_json($result['stage'])]);
