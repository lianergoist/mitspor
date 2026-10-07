<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/image_upload.php';

header('Content-Type: application/json; charset=UTF-8');

function json_fail_image(array $errors, int $httpCode = 400): never
{
    http_response_code($httpCode);
    echo json_encode(['success' => false, 'errors' => $errors]);
    exit;
}

if (empty($_SESSION['user_id'])) {
    json_fail_image(['Du skal være logget ind.'], 401);
}

if (!csrf_validate($_POST['csrf_token'] ?? null)) {
    json_fail_image(['Ugyldig eller udløbet session. Genindlæs siden.'], 403);
}

$user = current_user((int) $_SESSION['user_id']);
if ($user === null) {
    json_fail_image(['Kontoen findes ikke længere.'], 401);
}

if (!isset($_FILES['image'])) {
    json_fail_image(['Intet billede modtaget (feltet "image" mangler).']);
}

$pdo = get_db();
$result = upload_user_image($pdo, $user, $_FILES['image']);

if (!$result['success']) {
    json_fail_image($result['errors']);
}

echo json_encode([
    'success'       => true,
    'url'           => $result['url'],
    'thumbnail_url' => $result['thumbnail_url'],
    'image_id'      => $result['image_id'],
]);
