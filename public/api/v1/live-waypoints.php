<?php
declare(strict_types=1);

// API: send waypoints til en etape, der er startet med live-stage.php.
// POST JSON: { event, client_upload_id, waypoints: [{ t: tid i ms, lat,
//              lon, ele|null, name, desc, images: [billedsti, …] }, …] }
// images er "url" fra upload-image.php. Et waypoint kendes på sin tid;
// sendes det igen (fx når flere billeder er uploadet), opdateres det.
//
// Svar:
//   200 { success, received }
//   404 livesendingen eller etapen findes ikke
//   413 for stor forespørgsel
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

$body = file_get_contents('php://input', false, null, 0, 512 * 1024 + 1);
if ($body === false || strlen($body) > 512 * 1024) {
    api_error('For stor forespørgsel.', 413);
}

$data = json_decode($body, true);
if (!is_array($data) || !isset($data['waypoints']) || !is_array($data['waypoints']) || !array_is_list($data['waypoints'])) {
    api_error('Ugyldig forespørgsel.', 422);
}

$clientUploadId = strtolower(trim((string) ($data['client_upload_id'] ?? '')));
if (!live_valid_client_upload_id($clientUploadId)) {
    api_error('client_upload_id skal være et UUID.', 422);
}

$event = live_event_find_owned($pdo, $userId, (string) ($data['event'] ?? ''));
if ($event === null) {
    api_error('Livesendingen blev ikke fundet.', 404);
}

$stage = live_stage_find($pdo, (int) $event['id'], $clientUploadId);
if ($stage === null) {
    api_error('Etapen blev ikke fundet.', 404);
}

$result = live_waypoints_save($pdo, $stage, $userId, $data['waypoints']);
if (!$result['success']) {
    api_error($result['errors'][0], 422);
}

api_json([
    'success'  => true,
    'received' => $result['received'],
]);
