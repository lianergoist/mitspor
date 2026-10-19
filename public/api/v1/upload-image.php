<?php
declare(strict_types=1);

// API: upload et billede fra appen (fx til et waypoint).
// POST multipart: image, client_upload_id (UUID)
//
// Billedet behandles som på sitet (upload_user_image): filtypen tjekkes ud
// fra indholdet, EXIF/GPS fjernes, og det tæller med i lagerpladsen.
// Appen sætter bagefter "url" ind som <link> på waypointet i GPX-filen.
//
// Svar:
//   201 { success, duplicate: false, image }  billedet er gemt
//   200 { success, duplicate: true,  image }  client_upload_id er allerede
//                                              uploadet — det eksisterende billede
//   413 billedet er for stort
//   422 valideringsfejl (filtype, lagerplads) — se "errors"
//   429 for mange uploads
//   500 serverfejl (appen bør prøve igen senere)
//
// Sletter brugeren billedet på sitet, slettes rækken, og et senere
// genforsøg med samme client_upload_id gemmer det igen.

require_once __DIR__ . '/../../../app/api_auth.php';
require_once __DIR__ . '/../../../app/rate_limit.php';
require_once __DIR__ . '/../../../app/image_upload.php';

/**
 * Hent billedet med det givne client_upload_id for brugeren, eller null.
 */
function api_find_client_image(PDO $pdo, int $userId, string $clientUploadId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, relative_path, thumbnail_relative_path, mime_type
         FROM user_images
         WHERE user_id = :uid AND client_upload_id = :cuid
         LIMIT 1'
    );
    $stmt->execute(['uid' => $userId, 'cuid' => $clientUploadId]);
    $image = $stmt->fetch();

    return $image ?: null;
}

/**
 * Billed-rækken som JSON til appen. Stierne er relative til webroden,
 * som de URL'er, editoren sætter ind i GPX-filer.
 */
function api_image_json(array $image, string $clientUploadId): array
{
    return [
        'image_id'         => (int) $image['id'],
        'client_upload_id' => $clientUploadId,
        'url'              => IMAGE_PUBLIC_URL_BASE . '/' . $image['relative_path'],
        'thumbnail_url'    => $image['thumbnail_relative_path'] !== null
            ? IMAGE_PUBLIC_URL_BASE . '/' . $image['thumbnail_relative_path']
            : null,
        'mime_type'        => $image['mime_type'],
    ];
}

api_bootstrap(['POST']);

// For stor forespørgsel (over post_max_size): PHP tømmer $_POST og
// $_FILES, så det skal fanges, før vi kigger efter felterne.
$postMaxBytes = ini_parse_quantity((string) ini_get('post_max_size'));
if ($postMaxBytes > 0 && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $postMaxBytes) {
    api_error('Billedet er for stort (maks ' . (IMAGE_MAX_BYTES / 1024 / 1024) . ' MB).', 413);
}

$user   = api_require_token();
$userId = (int) $user['id'];
$pdo    = get_db();

$clientUploadId = strtolower(trim((string) ($_POST['client_upload_id'] ?? '')));
if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $clientUploadId)) {
    api_error('client_upload_id skal være et UUID.', 422);
}

// Gentaget upload (fx efter netværksfejl): svar med det eksisterende billede.
// Tjekkes før rate limiting, så gentagelser ikke bruger af kvoten.
$existing = api_find_client_image($pdo, $userId, $clientUploadId);
if ($existing !== null) {
    api_json(['success' => true, 'duplicate' => true, 'image' => api_image_json($existing, $clientUploadId)], 200);
}

if (!rate_limit_allow_identifier('api_image', (string) $userId, API_IMAGE_RATE_LIMIT_MAX_ATTEMPTS, API_IMAGE_RATE_LIMIT_WINDOW_SECONDS)) {
    api_error('For mange billeder. Prøv igen senere.', 429);
}
rate_limit_record('api_image', client_ip(), (string) $userId);

$file = $_FILES['image'] ?? null;
if (!is_array($file) || !isset($file['error']) || is_array($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
    api_error('Intet billede modtaget (feltet "image" mangler).', 422);
}
if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE || $file['size'] > IMAGE_MAX_BYTES) {
    api_error('Billedet er for stort (maks ' . (IMAGE_MAX_BYTES / 1024 / 1024) . ' MB).', 413);
}

$result = upload_user_image($pdo, $user, $file, $clientUploadId);

if ($result['success']) {
    $image = api_find_client_image($pdo, $userId, $clientUploadId);
    api_json(['success' => true, 'duplicate' => false, 'image' => api_image_json($image, $clientUploadId)], 201);
}

// Samme client_upload_id blev gemt af en samtidig forespørgsel.
if (!empty($result['duplicate_client_upload'])) {
    $existing = api_find_client_image($pdo, $userId, $clientUploadId);
    if ($existing !== null) {
        api_json(['success' => true, 'duplicate' => true, 'image' => api_image_json($existing, $clientUploadId)], 200);
    }
}

$errors = $result['errors'] ?? ['Billedet kunne ikke gemmes.'];

if (!empty($result['server_error'])) {
    api_error($errors[0], 500, ['errors' => $errors]);
}

// Validering af filtype og lagerplads.
api_error($errors[0], 422, ['errors' => $errors]);
