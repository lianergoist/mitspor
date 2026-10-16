<?php
declare(strict_types=1);

// =====================================================================
// Samlet endpoint til handlinger på grupper (etape-ture). Kaldes med
// fetch() fra kontrolpanelet og editoren. Alle handlinger er POST med
// csrf_token og en "action":
//
//   create        title, description, route_ids[]   -> opretter gruppe (evt. med ruter)
//   update        group_id, title, description      -> ændrer titel/beskrivelse
//   add_routes    group_id, route_ids[]             -> tilføjer ruter sidst i gruppen
//   assign_route  route_id, group_id (tom = ingen)  -> flytter én rute ind/ud af gruppe
//   reorder       group_id, route_ids[]             -> ny rækkefølge (alle etaper)
//   sort_by_time  group_id                          -> sortér efter tidsstempler
//   set_visibility group_id, visibility, public_notice_ack -> hele gruppen offentlig/privat
//   dissolve      group_id                          -> opløs gruppen (ruter bevares)
//   delete        group_id, confirm_title           -> slet gruppen OG alle etaperne
//   details       group_id                          -> GET: gruppens etaper til omordning
//
// group_id / route_id er de 8-cifrede OFFENTLIGE id'er (ikke interne id'er).
// =====================================================================

session_start();

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/route_groups.php';
require_once __DIR__ . '/../app/route_deletion.php';

header('Content-Type: application/json; charset=UTF-8');

function group_json_fail(array $errors, int $httpCode = 400): never
{
    http_response_code($httpCode);
    echo json_encode(['success' => false, 'errors' => $errors], JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($_SESSION['user_id'])) {
    group_json_fail(['Du skal være logget ind.'], 401);
}

$user = current_user((int) $_SESSION['user_id']);
if ($user === null) {
    group_json_fail(['Kontoen findes ikke længere.'], 401);
}
$userId = (int) $user['id'];
$pdo = get_db();

/** Liste af strenge fra en POST-parameter (route_ids[]=… eller kommasepareret). */
function group_post_list(string $key): array
{
    $raw = $_POST[$key] ?? [];
    if (is_string($raw)) {
        $raw = $raw === '' ? [] : explode(',', $raw);
    }
    if (!is_array($raw)) {
        return [];
    }

    return array_values(array_filter(array_map(static fn($v) => trim((string) $v), $raw), static fn($v) => $v !== ''));
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Kun læsning: gruppens etaper (til omordnings-dialogen).
    $group = group_find_owned($pdo, $userId, (string) ($_GET['group_id'] ?? ''));
    if ($group === null) {
        group_json_fail(['Gruppen blev ikke fundet, eller tilhører dig ikke.'], 404);
    }
    $members = group_members($pdo, (int) $group['id'], true);
    echo json_encode([
        'success'     => true,
        'group_id'    => $group['public_id'],
        'title'       => $group['title'],
        'description' => $group['description'],
        'routes'      => array_map(static fn(array $m): array => [
            'id'              => $m['public_id'],
            'title'           => $m['title'],
            'visibility'      => $m['visibility'],
            'distance_meters' => (int) $m['distance_meters'],
        ], $members),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    group_json_fail(['Ugyldig forespørgsel.'], 405);
}

if (!csrf_validate($_POST['csrf_token'] ?? null)) {
    group_json_fail(['Din session er udløbet. Genindlæs siden og prøv igen.'], 403);
}

$action = (string) ($_POST['action'] ?? '');
$groupPublicId = (string) ($_POST['group_id'] ?? '');

switch ($action) {
    case 'create':
        $created = group_create($pdo, $userId, (string) ($_POST['title'] ?? ''), (string) ($_POST['description'] ?? ''));
        if (!$created['success']) {
            group_json_fail($created['errors']);
        }
        $routeIds = group_post_list('route_ids');
        if ($routeIds) {
            $added = group_add_routes($pdo, $userId, $created['public_id'], $routeIds);
            if (!$added['success']) {
                // Ingen halve grupper: ryd den tomme gruppe op igen.
                group_dissolve($pdo, $userId, $created['public_id']);
                group_json_fail($added['errors']);
            }
            // Ruter uden tidsstempler bevarer den valgte rækkefølge; med tid
            // sorteres de automatisk (fejler stille, hvis nogen mangler tid).
            if (($_POST['sort_by_time'] ?? '1') !== '0') {
                group_sort_by_time($pdo, $userId, $created['public_id']);
            }
        }
        echo json_encode(['success' => true, 'group_id' => $created['public_id']]);
        break;

    case 'update':
        $r = group_update_text($pdo, $userId, $groupPublicId, (string) ($_POST['title'] ?? ''), (string) ($_POST['description'] ?? ''));
        $r['success'] ? print(json_encode(['success' => true])) : group_json_fail($r['errors']);
        break;

    case 'add_routes':
        $r = group_add_routes($pdo, $userId, $groupPublicId, group_post_list('route_ids'));
        $r['success'] ? print(json_encode(['success' => true])) : group_json_fail($r['errors']);
        break;

    case 'assign_route':
        $r = group_assign_route_by_public_ids($pdo, $userId, (string) ($_POST['route_id'] ?? ''), $groupPublicId);
        $r['success'] ? print(json_encode(['success' => true])) : group_json_fail($r['errors']);
        break;

    case 'reorder':
        $r = group_reorder($pdo, $userId, $groupPublicId, group_post_list('route_ids'));
        $r['success'] ? print(json_encode(['success' => true])) : group_json_fail($r['errors']);
        break;

    case 'sort_by_time':
        $r = group_sort_by_time($pdo, $userId, $groupPublicId);
        $r['success'] ? print(json_encode(['success' => true])) : group_json_fail($r['errors']);
        break;

    case 'set_visibility':
        $ack = isset($_POST['public_notice_ack']) && $_POST['public_notice_ack'] !== '0' && $_POST['public_notice_ack'] !== '';
        $r = group_set_visibility($pdo, $user, $groupPublicId, (string) ($_POST['visibility'] ?? ''), $ack);
        $r['success'] ? print(json_encode(['success' => true, 'changed' => $r['changed']])) : group_json_fail($r['errors']);
        break;

    case 'dissolve':
        $r = group_dissolve($pdo, $userId, $groupPublicId);
        $r['success'] ? print(json_encode(['success' => true])) : group_json_fail($r['errors']);
        break;

    case 'delete':
        $r = group_delete_with_routes($pdo, $userId, $groupPublicId, (string) ($_POST['confirm_title'] ?? ''));
        $r['success'] ? print(json_encode(['success' => true, 'deleted' => $r['deleted']])) : group_json_fail($r['errors']);
        break;

    default:
        group_json_fail(['Ukendt handling.']);
}
