<?php
declare(strict_types=1);

// API: send punkter til en etape, der er startet med live-stage.php.
// POST JSON: { event, client_upload_id, points: [[lat, lon, højde|null,
//              tid i ms, nøjagtighed i m|null], ...] }
// Appen sender de punkter, serveren ikke har bekræftet endnu. Punkter,
// der allerede er modtaget (samme tid), springes over. En tom liste er et
// livstegn: appen er i kontakt, men har ingen ny position (står stille).
//
// Svar:
//   200 { success, received, point_count, last_ms }
//       last_ms: tiden på det seneste punkt, serveren har, i ms
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

// Ca. 60 bytes pr. punkt; 1 MB giver god plads til LIVE_POINTS_MAX_PER_REQUEST.
$body = file_get_contents('php://input', false, null, 0, 1024 * 1024 + 1);
if ($body === false || strlen($body) > 1024 * 1024) {
    api_error('For stor forespørgsel.', 413);
}

$data = json_decode($body, true);
if (!is_array($data) || !isset($data['points']) || !is_array($data['points']) || !array_is_list($data['points'])) {
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

$result = live_points_add($pdo, $stage, $data['points']);
if (!$result['success']) {
    api_error($result['errors'][0], 422);
}

api_json([
    'success'     => true,
    'received'    => $result['received'],
    'point_count' => $result['point_count'],
    'last_ms'     => $result['last_ms'],
]);
