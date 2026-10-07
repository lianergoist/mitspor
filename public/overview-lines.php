<?php
declare(strict_types=1);

/**
 * Forenklede ruter til oversigtskortet på forsiden.
 *
 *   GET overview-lines.php?ids=12345678,87654321&level=1
 *
 *   ids    højst 60 offentlige rute-id'er (kommasepareret)
 *   level  0 = grov, 1 = mellem, 2 = fin (vælges efter kortets zoom)
 *
 * Svar: { "lines": { "<id>": [[lat, lon], ...] }, "pending": ["<id>", ...] }
 *
 * "pending" er ruter, hvis linje endnu ikke er beregnet (første gang efter
 * en upload eller en rettelse); browseren prøver igen om et øjeblik. Der
 * beregnes højst et par stykker pr. forespørgsel, så en pludselig
 * strøm af nye ruter ikke belaster serveren.
 *
 * Kun aktive, OFFENTLIGE ruter udleveres. Ingen cookies, ingen session,
 * ingen skrivning til databasen.
 */

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/overview_lines.php';

const LINES_MAX_IDS = 60;
const LINES_MAX_COMPUTE = 10;      // nye linjer, der beregnes pr. forespørgsel
const LINES_TIME_BUDGET = 6.0;     // sekunder

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

$ids = [];
foreach (explode(',', (string) ($_GET['ids'] ?? '')) as $id) {
    $id = trim($id);
    if (preg_match('/^[0-9]{8}$/', $id)) {
        $ids[$id] = true;
    }
    if (count($ids) >= LINES_MAX_IDS) {
        break;
    }
}
$ids = array_keys($ids);
$level = max(0, min(2, (int) ($_GET['level'] ?? 1)));

if (!$ids) {
    echo json_encode(['lines' => new stdClass(), 'pending' => []]);
    exit;
}

$placeholders = implode(',', array_fill(0, count($ids), '?'));
$stmt = get_db()->prepare(
    "SELECT public_id, gpx_file_path
     FROM routes
     WHERE public_id IN ($placeholders) AND visibility = 'public' AND status = 'active'"
);
$stmt->execute($ids);
$routes = $stmt->fetchAll();

@set_time_limit(30);
$deadline = microtime(true) + LINES_TIME_BUDGET;
$computed = 0;
$lines = [];
$pending = [];

foreach ($routes as $route) {
    $line = line_get($route, false);
    if ($line === null) {
        if ($computed >= LINES_MAX_COMPUTE || microtime(true) >= $deadline) {
            $pending[] = $route['public_id']; // browseren prøver igen om lidt
            continue;
        }
        $computed++;
        $line = line_get($route, true);
        if ($line === null) {
            continue; // GPX-filen kan ikke bruges — ingen linje, og intet nyt forsøg
        }
    }
    $lines[$route['public_id']] = line_for_level($line, $level);
}

// Ruter kan ændres; en kort cache-tid holder serveren afkølet uden at vise gamle spor for længe.
// Er noget endnu ikke klar, må svaret ikke caches.
header($pending ? 'Cache-Control: no-store' : 'Cache-Control: public, max-age=120');
echo json_encode(['lines' => (object) $lines, 'pending' => $pending]);
