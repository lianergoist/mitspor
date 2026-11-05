<?php
declare(strict_types=1);

// =====================================================================
// "Datafelter og markør for alle etaper" i kontrolpanelet (fanen Grupper).
// Kun gruppens ejer.
//
//   GET  ?group_id=<8 cifre>
//        -> gruppens etaper med aktivitet og nuværende markør. Dialogen
//           henter derefter hver etapes GPX via editor-load.php for at
//           finde felterne.
//
//   POST csrf_token, group_id
//        apply_fields=1        enabled_functions (JSON-liste),
//                              enabled_data_fields (JSON-liste, valgfri:
//                              udeladt = etapernes øvrige felter røres ikke)
//        apply_markers=1       markers (JSON: {"<activity_type_id>": "<mappe>/<navn>" eller ""})
//                              "" = aktivitetens standardmarkør.
//
// Valgene OVERSKRIVER hver etapes egne valg. Det er en engangshandling:
// gruppen husker ikke valget, så en etape, der tilføjes senere, beholder
// sine egne indstillinger. GPX-filerne røres ikke.
// =====================================================================

session_start();

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/route_groups.php';
require_once __DIR__ . '/../app/markers.php';
require_once __DIR__ . '/../app/og_image.php';

header('Content-Type: application/json; charset=UTF-8');

function gds_fail(array $errors, int $httpCode = 400): never
{
    http_response_code($httpCode);
    echo json_encode(['success' => false, 'errors' => $errors], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * JSON-liste af feltnavne/funktionsnøgler fra POST, renset som i
 * editor-save.php. Null, hvis parameteren mangler eller er ugyldig.
 */
function gds_post_list(string $key): ?string
{
    $raw = $_POST[$key] ?? null;
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || count($decoded) > 200) {
        return null;
    }
    $clean = array_values(array_unique(array_filter(array_map(
        static fn($v) => is_string($v) ? mb_substr($v, 0, 100) : null,
        $decoded
    ))));

    return json_encode($clean);
}

if (empty($_SESSION['user_id'])) {
    gds_fail(['Du skal være logget ind.'], 401);
}
$user = current_user((int) $_SESSION['user_id']);
if ($user === null) {
    gds_fail(['Kontoen findes ikke længere.'], 401);
}
$userId = (int) $user['id'];
$pdo = get_db();

$groupPublicId = (string) ($_SERVER['REQUEST_METHOD'] === 'GET' ? ($_GET['group_id'] ?? '') : ($_POST['group_id'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_validate($_POST['csrf_token'] ?? null)) {
    gds_fail(['Din session er udløbet. Genindlæs siden og prøv igen.'], 403);
}

$group = group_find_owned($pdo, $userId, $groupPublicId);
if ($group === null) {
    gds_fail(['Gruppen blev ikke fundet, eller tilhører dig ikke.'], 404);
}

// Etaperne (også private og live-etaper). LEFT JOIN, så en etape med en
// aktivitet, der ikke længere findes, stadig kommer med.
$stmt = $pdo->prepare(
    "SELECT r.id, r.public_id, r.title, r.activity_type_id, r.marker_icon,
            a.name_da AS activity_name, a.icon_filename AS activity_folder
     FROM routes r
     LEFT JOIN activity_types a ON a.id = r.activity_type_id
     WHERE r.group_id = :gid AND r.user_id = :uid AND r.status = 'active'
     ORDER BY r.group_position ASC, r.id ASC"
);
$stmt->execute(['gid' => (int) $group['id'], 'uid' => $userId]);
$stages = $stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode([
        'success'  => true,
        'group_id' => $group['public_id'],
        'title'    => $group['title'],
        'stages'   => array_map(static fn(array $s): array => [
            'id'              => $s['public_id'],
            'title'           => $s['title'],
            'activity_id'     => (int) $s['activity_type_id'],
            'activity_name'   => (string) ($s['activity_name'] ?? ''),
            'activity_folder' => (string) ($s['activity_folder'] ?? ''),
            // Aktivitetens standardmarkør (vises ved "Standard"), '' = ingen.
            'activity_marker_url' => (string) (marker_image_urls(($s['activity_folder'] ?? '') ?: null)['east'] ?? ''),
            'marker_icon'     => (string) ($s['marker_icon'] ?? ''),
        ], $stages),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    gds_fail(['Ugyldig forespørgsel.'], 405);
}

if (!$stages) {
    gds_fail(['Gruppen har ingen etaper.']);
}

$applyFields = (string) ($_POST['apply_fields'] ?? '') === '1';
$applyMarkers = (string) ($_POST['apply_markers'] ?? '') === '1';
if (!$applyFields && !$applyMarkers) {
    gds_fail(['Vælg, om datafelterne, markøren eller begge skal ændres.']);
}

$enabledFunctions = null;
$enabledDataFields = null;
if ($applyFields) {
    $enabledFunctions = gds_post_list('enabled_functions');
    if ($enabledFunctions === null) {
        gds_fail(['Datafelterne mangler. Genindlæs siden og prøv igen.']);
    }
    $enabledDataFields = gds_post_list('enabled_data_fields'); // null = røres ikke
}

// Markør pr. aktivitet. Kun aktiviteter, der findes i gruppen, bruges.
$markers = [];
if ($applyMarkers) {
    $decoded = json_decode((string) ($_POST['markers'] ?? ''), true);
    if (!is_array($decoded)) {
        gds_fail(['Markørvalget mangler. Genindlæs siden og prøv igen.']);
    }
    $groupActivities = array_unique(array_map(static fn(array $s): int => (int) $s['activity_type_id'], $stages));
    foreach ($groupActivities as $activityId) {
        $marker = $decoded[(string) $activityId] ?? null;
        if (!is_string($marker)) {
            gds_fail(['Vælg en markør for hver aktivitet i gruppen.']);
        }
        if (!marker_is_valid($marker)) {
            gds_fail(['Ukendt markør.']);
        }
        $markers[$activityId] = $marker;
    }
}

$pdo->beginTransaction();
try {
    if ($applyFields) {
        if ($enabledDataFields !== null) {
            $stmt = $pdo->prepare(
                "UPDATE routes SET enabled_functions = :fn, enabled_data_fields = :df
                 WHERE group_id = :gid AND user_id = :uid AND status = 'active'"
            );
            $stmt->execute(['fn' => $enabledFunctions, 'df' => $enabledDataFields, 'gid' => (int) $group['id'], 'uid' => $userId]);
        } else {
            $stmt = $pdo->prepare(
                "UPDATE routes SET enabled_functions = :fn
                 WHERE group_id = :gid AND user_id = :uid AND status = 'active'"
            );
            $stmt->execute(['fn' => $enabledFunctions, 'gid' => (int) $group['id'], 'uid' => $userId]);
        }
    }
    if ($applyMarkers) {
        foreach ($stages as $s) {
            marker_save($pdo, (int) $s['id'], $userId, $markers[(int) $s['activity_type_id']]);
        }
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('Gruppens datafelter/markør kunne ikke gemmes: ' . $e->getMessage());
    gds_fail(['Der skete en fejl, og intet blev ændret. Prøv igen.'], 500);
}

// Markøren indgår i delingsbillederne. De laves igen, efter svaret er sendt
// (og_refresh() tager også gruppens billede, én gang).
if ($applyMarkers) {
    foreach ($stages as $s) {
        og_refresh($pdo, (string) $s['public_id']);
    }
}

echo json_encode(['success' => true, 'stages' => count($stages)], JSON_UNESCAPED_UNICODE);
