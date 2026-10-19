<?php
declare(strict_types=1);

// API: brugerens livesendinger, der ikke er afsluttet (til valget
// "Send live" i optageren).
// Svar: { success, events: [{ public_id, title, activity_type_id, status,
//         delay_minutes, url, stages: [{ id, position, title, status, planned }] }] }

require_once __DIR__ . '/../../../app/api_auth.php';
require_once __DIR__ . '/../../../app/rate_limit.php';
require_once __DIR__ . '/../../../app/live.php';

api_bootstrap(['GET']);

$user   = api_require_token();
$userId = (int) $user['id'];

if (!rate_limit_allow_identifier('api_live', (string) $userId, API_LIVE_RATE_LIMIT_MAX_ATTEMPTS, API_LIVE_RATE_LIMIT_WINDOW_SECONDS)) {
    api_error('For mange forespørgsler. Prøv igen senere.', 429);
}
rate_limit_record('api_live', client_ip(), (string) $userId);

$events = array_map(
    static fn (array $event): array => [
        'public_id'        => $event['public_id'],
        'title'            => $event['title'],
        'activity_type_id' => (int) $event['activity_type_id'],
        'status'           => $event['status'],
        'delay_minutes'    => (int) $event['delay_minutes'],
        'url'              => '/live.php?id=' . $event['public_id'],
        'stages'           => array_map('live_stage_json', $event['stages']),
    ],
    live_events_open_for_user(get_db(), $userId)
);

api_json(['success' => true, 'events' => $events]);
