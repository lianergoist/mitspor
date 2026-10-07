<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/db.php';

header('Content-Type: application/json; charset=UTF-8');

$q = trim((string) ($_GET['q'] ?? ''));
$category = (string) ($_GET['category'] ?? '');
$activityId = isset($_GET['activity']) ? (int) $_GET['activity'] : 0;

if (mb_strlen($q) < 2) {
    echo json_encode(['results' => []]);
    exit;
}

// Escape LIKE-wildcards i selve søgeteksten, så en bruger der søger
// efter fx "50%" eller "a_b" ikke rammer utilsigtede resultater.
$qEscaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);

$pdo = get_db();

// Filter (land/vand eller en bestemt aktivitet) — bruges på ruter, og på
// en gruppes etaper (en gruppe vises, hvis mindst én offentlig etape passer).
$filterSql = '';
$filterParams = [];
if ($category === 'land' || $category === 'water') {
    $filterSql = ' AND a.category = :category';
    $filterParams['category'] = $category;
} elseif ($activityId > 0) {
    $filterSql = ' AND r.activity_type_id = :activity_id';
    $filterParams['activity_id'] = $activityId;
}
$like = '%' . $qEscaped . '%';

try {
    // 1) Ruter, hvis titel/beskrivelse matcher. Ruter i en gruppe vises
    //    nedenfor som ÉN post (gruppen) i stedet for én pr. etape.
    $stmt = $pdo->prepare(
        "SELECT r.public_id, r.title, a.name_da AS activity_name, r.distance_meters, r.group_id
         FROM routes r
         JOIN activity_types a ON a.id = r.activity_type_id
         WHERE r.visibility = 'public' AND r.status = 'active'
           AND (r.title LIKE :q1 OR r.description LIKE :q2)" . $filterSql . "
         ORDER BY r.title ASC LIMIT 300"
    );
    $stmt->execute(['q1' => $like, 'q2' => $like] + $filterParams);
    $routeRows = $stmt->fetchAll();

    $groupIds = [];
    foreach ($routeRows as $row) {
        if ($row['group_id'] !== null) {
            $groupIds[(int) $row['group_id']] = true;
        }
    }

    // 2) Grupper, hvis EGEN titel/beskrivelse matcher.
    $stmt = $pdo->prepare(
        "SELECT g.id
         FROM route_groups g
         WHERE (g.title LIKE :q1 OR g.description LIKE :q2)
           AND EXISTS (
               SELECT 1 FROM routes r
               JOIN activity_types a ON a.id = r.activity_type_id
               WHERE r.group_id = g.id AND r.visibility = 'public' AND r.status = 'active'" . $filterSql . "
           )
         LIMIT 300"
    );
    $stmt->execute(['q1' => $like, 'q2' => $like] + $filterParams);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $gid) {
        $groupIds[(int) $gid] = true;
    }

    $results = [];
    foreach ($routeRows as $row) {
        if ($row['group_id'] === null) {
            $results[] = [
                'type'            => 'route',
                'public_id'       => $row['public_id'],
                'title'           => $row['title'],
                'activity_name'   => $row['activity_name'],
                'distance_meters' => (int) $row['distance_meters'],
            ];
        }
    }

    // 3) Antal (offentlige) etaper og samlet distance for de fundne grupper.
    if ($groupIds) {
        $ids = array_keys($groupIds);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = $ids;
        $sql = "SELECT g.public_id, g.title, COUNT(r.id) AS stages, COALESCE(SUM(r.distance_meters), 0) AS distance_meters
                FROM route_groups g
                JOIN routes r ON r.group_id = g.id AND r.visibility = 'public' AND r.status = 'active'
                JOIN activity_types a ON a.id = r.activity_type_id
                WHERE g.id IN ($placeholders)";
        if ($category === 'land' || $category === 'water') {
            $sql .= ' AND a.category = ?';
            $params[] = $category;
        } elseif ($activityId > 0) {
            $sql .= ' AND r.activity_type_id = ?';
            $params[] = $activityId;
        }
        $sql .= ' GROUP BY g.id';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $g) {
            $results[] = [
                'type'            => 'group',
                'public_id'       => $g['public_id'],
                'title'           => $g['title'],
                'stages'          => (int) $g['stages'],
                'distance_meters' => (int) $g['distance_meters'],
            ];
        }
    }

    usort($results, static fn(array $a, array $b): int => strcmp(mb_strtolower($a['title']), mb_strtolower($b['title'])));
    $results = array_slice($results, 0, 50);
} catch (Throwable $e) {
    error_log('Rute-søgning fejlede: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['results' => [], 'error' => 'Der skete en fejl under søgningen.']);
    exit;
}

echo json_encode(['results' => $results], JSON_UNESCAPED_UNICODE);
