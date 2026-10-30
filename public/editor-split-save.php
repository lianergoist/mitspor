<?php
declare(strict_types=1);

// =====================================================================
// Gemmer en opdeling fra editorens Opdel-tilstand. Den opdelte rute
// bliver etape 1 (beholder sit id, sine links og sin markør), og hver af
// de øvrige etaper oprettes som en ny rute med samme aktivitet, type,
// synlighed, download-tilladelse og markør. Etaperne samles i en gruppe:
// er ruten allerede i en gruppe, sættes de nye etaper ind lige efter den,
// ellers oprettes en ny gruppe.
//
//   POST csrf_token, route_id,
//        stages: JSON [{title, gpx}, ...] (mindst 2; den første er ruten selv),
//        group_title, group_description (kun brugt, når der oprettes en gruppe)
//
// Fejler noget, før alle etaper er gemt, slettes de nye ruter igen, og
// den opdelte rute er urørt.
// =====================================================================

session_start();

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/route_creation.php';
require_once __DIR__ . '/../app/route_update.php';
require_once __DIR__ . '/../app/route_groups.php';
require_once __DIR__ . '/../app/markers.php';

header('Content-Type: application/json; charset=UTF-8');

const SPLIT_MAX_STAGES = 50;

function json_fail_split(array $errors, int $httpCode = 400): never
{
    http_response_code($httpCode);
    echo json_encode(['success' => false, 'errors' => $errors]);
    exit;
}

/**
 * Fjern ruter, der netop er oprettet af denne forespørgsel (rækken, filen
 * og lagerforbruget). Ingen tombesten: ruterne har aldrig været vist.
 *
 * @param int[] $routeIds
 */
function split_remove_created(PDO $pdo, int $userId, array $routeIds): void
{
    foreach ($routeIds as $routeId) {
        $stmt = $pdo->prepare('SELECT gpx_file_path, file_size_bytes FROM routes WHERE id = :id AND user_id = :uid');
        $stmt->execute(['id' => $routeId, 'uid' => $userId]);
        $row = $stmt->fetch();
        if (!$row) {
            continue;
        }
        $pdo->prepare('DELETE FROM routes WHERE id = :id AND user_id = :uid')->execute(['id' => $routeId, 'uid' => $userId]);
        $pdo->prepare('UPDATE users SET storage_used_bytes = GREATEST(0, storage_used_bytes - :size) WHERE id = :uid')
            ->execute(['size' => (int) $row['file_size_bytes'], 'uid' => $userId]);
        @unlink(gpx_full_path((string) $row['gpx_file_path']));
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_fail_split(['Ugyldig forespørgsel.'], 405);
}

if (empty($_SESSION['user_id'])) {
    json_fail_split(['Du skal være logget ind.'], 401);
}

if (!csrf_validate($_POST['csrf_token'] ?? null)) {
    json_fail_split(['Ugyldig eller udløbet session. Genindlæs siden.'], 403);
}

$userId = (int) $_SESSION['user_id'];
$user = current_user($userId);
if ($user === null) {
    json_fail_split(['Kontoen findes ikke længere.'], 401);
}

$pdo = get_db();

$stmt = $pdo->prepare(
    "SELECT id, public_id, title, description, activity_type_id, route_type, visibility, allow_download,
            hide_uploader_name, enabled_data_fields, enabled_functions, marker_icon, file_size_bytes, group_id
     FROM routes WHERE public_id = :public_id AND user_id = :user_id AND status = 'active' LIMIT 1"
);
$stmt->execute(['public_id' => (string) ($_POST['route_id'] ?? ''), 'user_id' => $userId]);
$route = $stmt->fetch();
if (!$route) {
    json_fail_split(['Ruten blev ikke fundet, eller tilhører dig ikke.'], 404);
}

$stages = json_decode((string) ($_POST['stages'] ?? ''), true);
if (!is_array($stages) || count($stages) < 2) {
    json_fail_split(['Ruten skal deles i mindst to etaper.']);
}
if (count($stages) > SPLIT_MAX_STAGES) {
    json_fail_split(['Ruten kan højst deles i ' . SPLIT_MAX_STAGES . ' etaper.']);
}

$totalBytes = 0;
foreach ($stages as $i => $stage) {
    $n = $i + 1;
    if (!is_array($stage) || !is_string($stage['title'] ?? null) || !is_string($stage['gpx'] ?? null)) {
        json_fail_split(['Etape ' . $n . ' mangler data.']);
    }
    $len = mb_strlen(trim($stage['title']));
    if ($len < 2 || $len > 200) {
        json_fail_split(['Titlen på etape ' . $n . ' skal være mellem 2 og 200 tegn.']);
    }
    if (strlen($stage['gpx']) > MAX_UPLOAD_SIZE_BYTES) {
        json_fail_split(['Etape ' . $n . ' er for stor (maks ' . (MAX_UPLOAD_SIZE_BYTES / 1024 / 1024) . ' MB).']);
    }
    $totalBytes += strlen($stage['gpx']);
}

$groupTitle = (string) ($_POST['group_title'] ?? '');
$groupDescription = (string) ($_POST['group_description'] ?? '');
if ($route['group_id'] === null) {
    $errors = group_validate_text(trim($groupTitle), trim($groupDescription));
    if ($errors) {
        json_fail_split($errors);
    }
}

// Kvoterne tjekkes for hele opdelingen på én gang, så den ikke stopper halvvejs.
$stmt = $pdo->prepare("SELECT COUNT(*) FROM routes WHERE user_id = :uid AND status = 'active'");
$stmt->execute(['uid' => $userId]);
if ((int) $stmt->fetchColumn() + count($stages) - 1 > (int) $user['route_quota']) {
    json_fail_split(['Du har ikke plads til ' . (count($stages) - 1) . ' nye ruter (grænse for antal ruter).']);
}
if ((int) $user['storage_used_bytes'] + $totalBytes - (int) $route['file_size_bytes'] > (int) $user['storage_quota_bytes']) {
    json_fail_split(['Du har ikke nok ledig lagerplads til etaperne.']);
}

$visibility = (string) $route['visibility'];
// En offentlig rute er allerede bekræftet (GDPR-notitsen), så de nye etaper må arve det.
$publicAck = $visibility === 'public';
$markerIcon = $route['marker_icon'] !== null ? (string) $route['marker_icon'] : null;

// 1) De nye etaper (2..n). Brugeren hentes igen før hver, fordi lagerforbruget ændres.
$createdIds = [];
$createdPublicIds = [];
foreach (array_slice($stages, 1) as $i => $stage) {
    $result = create_route(
        $pdo,
        current_user($userId) ?? $user,
        $stage['gpx'],
        $stage['title'],
        '',
        (int) $route['activity_type_id'],
        $visibility,
        (bool) $route['allow_download'],
        $publicAck,
        (bool) $route['hide_uploader_name'],
        (string) $route['route_type'],
        $route['enabled_data_fields'],
        $route['enabled_functions']
    );
    if (!$result['success']) {
        split_remove_created($pdo, $userId, $createdIds);
        $errors = $result['errors'] ?? ['Der skete en fejl. Prøv igen.'];
        json_fail_split(array_map(static fn($e) => 'Etape ' . ($i + 2) . ': ' . $e, $errors));
    }
    $createdIds[] = (int) $result['route_id'];
    $createdPublicIds[] = (string) $result['public_id'];
    if ($markerIcon !== null) {
        marker_save($pdo, (int) $result['route_id'], $userId, $markerIcon);
    }
}

// 2) Den opdelte rute bliver etape 1. Metadata ud over titlen er uændret.
$result = update_route(
    $pdo,
    current_user($userId) ?? $user,
    (int) $route['id'],
    $stages[0]['gpx'],
    $stages[0]['title'],
    (string) ($route['description'] ?? ''),
    (int) $route['activity_type_id'],
    $visibility,
    (bool) $route['allow_download'],
    $publicAck,
    (bool) $route['hide_uploader_name'],
    null,
    (string) $route['route_type'],
    null,
    null
);
if (!$result['success']) {
    split_remove_created($pdo, $userId, $createdIds);
    json_fail_split(array_map(static fn($e) => 'Etape 1: ' . $e, $result['errors'] ?? ['Der skete en fejl. Prøv igen.']));
}

// 3) Gruppen. Ruterne er gemt nu, så en fejl her rulles ikke tilbage, men meldes.
$group = group_insert_split($pdo, $userId, (int) $route['id'], $createdIds, $groupTitle, $groupDescription);
if (!$group['success']) {
    json_fail_split(['Etaperne er gemt som ruter, men kunne ikke samles i en gruppe. Saml dem i kontrolpanelet.'], 500);
}

echo json_encode([
    'success'         => true,
    'route_id'        => $route['public_id'],
    'new_route_ids'   => $createdPublicIds,
    'group_id'        => $group['group_public_id'],
    'group_created'   => $group['created'],
]);
