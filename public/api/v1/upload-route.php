<?php
declare(strict_types=1);

// API: upload en GPX-fil fra appen som privat spor.
// POST multipart: gpx_file, title, activity_type_id, client_upload_id (UUID)
//
// Svar:
//   201 { success, duplicate: false, route }  ruten er oprettet
//   200 { success, duplicate: true,  route }  client_upload_id er allerede
//                                              uploadet — den eksisterende rute
//   409 ruten med dette client_upload_id er slettet
//   413 filen er for stor
//   422 valideringsfejl (fx ugyldig GPX, kvote, titel) — se "errors"
//   429 for mange uploads
//   500 serverfejl

require_once __DIR__ . '/../../../app/api_auth.php';
require_once __DIR__ . '/../../../app/rate_limit.php';
require_once __DIR__ . '/../../../app/route_creation.php';

/**
 * Hent ruten med det givne client_upload_id for brugeren, eller null.
 */
function api_find_client_upload(PDO $pdo, int $userId, string $clientUploadId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT public_id, title, activity_type_id, route_type, visibility, status,
                distance_meters, elevation_gain_meters,
                ' . api_sql_utc('created_at') . ' AS created_at
         FROM routes
         WHERE user_id = :uid AND client_upload_id = :cuid
         LIMIT 1'
    );
    $stmt->execute(['uid' => $userId, 'cuid' => $clientUploadId]);
    $route = $stmt->fetch();

    return $route ?: null;
}

/**
 * Rute-rækken som JSON til appen.
 */
function api_route_json(array $route, string $clientUploadId): array
{
    return [
        'public_id'             => $route['public_id'],
        'client_upload_id'      => $clientUploadId,
        'title'                 => $route['title'],
        'activity_type_id'      => (int) $route['activity_type_id'],
        'route_type'            => $route['route_type'],
        'visibility'            => $route['visibility'],
        'distance_meters'       => $route['distance_meters'] !== null ? (int) $route['distance_meters'] : null,
        'elevation_gain_meters' => $route['elevation_gain_meters'] !== null ? (int) $route['elevation_gain_meters'] : null,
        'created_at'            => api_datetime($route['created_at']),
        'url'                   => '/route-view.php?id=' . $route['public_id'],
    ];
}

/**
 * Svar for en allerede uploadet rute (gentaget forsøg fra appen).
 */
function api_duplicate_response(array $route, string $clientUploadId): never
{
    if ($route['status'] !== 'active') {
        api_error('Ruten er allerede uploadet, men er siden slettet.', 409, ['duplicate' => true]);
    }

    api_json(['success' => true, 'duplicate' => true, 'route' => api_route_json($route, $clientUploadId)], 200);
}

api_bootstrap(['POST']);

// For stor forespørgsel (over post_max_size): PHP tømmer $_POST og
// $_FILES, så det skal fanges, før vi kigger efter felterne.
$postMaxBytes = ini_parse_quantity((string) ini_get('post_max_size'));
if ($postMaxBytes > 0 && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $postMaxBytes) {
    api_error('Filen er for stor (maks ' . (MAX_UPLOAD_SIZE_BYTES / 1024 / 1024) . ' MB).', 413);
}

$user   = api_require_token();
$userId = (int) $user['id'];
$pdo    = get_db();

$clientUploadId = strtolower(trim((string) ($_POST['client_upload_id'] ?? '')));
if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $clientUploadId)) {
    api_error('client_upload_id skal være et UUID.', 422);
}

// Gentaget upload (fx efter netværksfejl): svar med den eksisterende rute.
// Tjekkes før rate limiting, så gentagelser ikke bruger af kvoten.
$existing = api_find_client_upload($pdo, $userId, $clientUploadId);
if ($existing !== null) {
    api_duplicate_response($existing, $clientUploadId);
}

if (!rate_limit_allow_identifier('api_upload', (string) $userId, API_UPLOAD_RATE_LIMIT_MAX_ATTEMPTS, API_UPLOAD_RATE_LIMIT_WINDOW_SECONDS)) {
    api_error('For mange uploads. Prøv igen senere.', 429);
}
rate_limit_record('api_upload', client_ip(), (string) $userId);

$title          = (string) ($_POST['title'] ?? '');
$activityTypeId = (int) ($_POST['activity_type_id'] ?? 0);
$file           = $_FILES['gpx_file'] ?? null;

if (!is_array($file) || !isset($file['error']) || is_array($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
    api_error('Vælg en GPX-fil.', 422);
}
if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE || $file['size'] > MAX_UPLOAD_SIZE_BYTES) {
    api_error('Filen er for stor (maks ' . (MAX_UPLOAD_SIZE_BYTES / 1024 / 1024) . ' MB).', 413);
}
if ($file['error'] !== UPLOAD_ERR_OK) {
    api_error('Filen kunne ikke uploades (fejlkode ' . (int) $file['error'] . ').', 400);
}
if (!str_ends_with(strtolower((string) $file['name']), '.gpx')) {
    api_error('Filen skal have endelsen .gpx.', 422);
}
if (!is_uploaded_file($file['tmp_name'])) {
    api_error('Ugyldig filupload.', 400);
}

$gpxContent = file_get_contents($file['tmp_name']);
if ($gpxContent === false) {
    api_error('Filen kunne ikke læses.', 500);
}

$result = create_route(
    $pdo,
    $user,
    $gpxContent,
    $title,
    '',            // beskrivelse
    $activityTypeId,
    'private',
    false,         // allow_download
    false,         // public_notice_ack (ikke relevant for private ruter)
    'ask',
    false,         // hide_uploader_name
    null,          // ingen udtynding
    'spor',
    null,
    null,
    $clientUploadId
);

if ($result['success']) {
    $route = api_find_client_upload($pdo, $userId, $clientUploadId);
    api_json(['success' => true, 'duplicate' => false, 'route' => api_route_json($route, $clientUploadId)], 201);
}

// Samme client_upload_id blev oprettet af en samtidig forespørgsel.
if (!empty($result['duplicate_client_upload'])) {
    $existing = api_find_client_upload($pdo, $userId, $clientUploadId);
    if ($existing !== null) {
        api_duplicate_response($existing, $clientUploadId);
    }
}

$errors = $result['errors'] ?? ['Ruten kunne ikke oprettes.'];

if (!empty($result['server_error'])) {
    api_error($errors[0], 500, ['errors' => $errors]);
}

// Validering af titel/aktivitetstype, GPX-indhold, rutekvote og lagerplads.
api_error($errors[0], 422, ['errors' => $errors]);
