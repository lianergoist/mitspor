<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/tile_cache.php';
require_once __DIR__ . '/gpx.php';
require_once __DIR__ . '/og_image.php';      // og_read_points()
require_once __DIR__ . '/route_groups.php';  // group_simplify()

/**
 * Forenklede ruter (linjer) til oversigtskortet på forsiden.
 *
 * En rute kan have titusindvis af punkter; oversigtskortet får i stedet en
 * kraftigt udtyndet udgave (ca. 5 m tolerance, højst ~2000 punkter), som
 * gemmes som lille JSON-fil UDEN FOR webroot og udtyndes yderligere efter
 * zoom, når den sendes til browseren.
 *
 * Filnavn: {public_id}-{nøgle}.json. Nøglen ændres, når GPX-filen ændres
 * (sti, størrelse, tidsstempel), så en redigeret rute automatisk får en ny
 * linje — de gamle filer ryddes, når den nye laves. Filerne kan altid
 * genskabes fra GPX-filerne, så de skal ikke med i backup.
 *
 * Kun OFFENTLIGE ruter må sendes ud (det tjekker overview-lines.php).
 */

/** Zoom-niveauer → tolerance i grader (0 = den gemte, fine udgave). */
const LINE_LEVEL_TOLERANCE = [0.003, 0.0006, 0.0];

function line_cache_dir(): string
{
    if (defined('LINE_CACHE_DIR')) {
        return LINE_CACHE_DIR;
    }

    return dirname(TILE_CACHE_DIR) . '/line-cache';
}

/** Nøgle, der ændres, når rutens GPX-fil ændres. */
function line_route_key(array $route): string
{
    $path = (string) ($route['gpx_file_path'] ?? '');
    $full = gpx_full_path($path);
    $mtime = is_file($full) ? (int) filemtime($full) : 0;
    $size = is_file($full) ? (int) filesize($full) : 0;

    return substr(sha1($path . '|' . $mtime . '|' . $size), 0, 10);
}

function line_cache_path(array $route): string
{
    return line_cache_dir() . '/' . $route['public_id'] . '-' . line_route_key($route) . '.json';
}

/** Slet alle gemte linjer (alle versioner) for én rute. */
function line_delete(string $publicId): void
{
    if (!preg_match('/^[0-9]{8}$/', $publicId)) {
        return;
    }
    foreach (glob(line_cache_dir() . '/' . $publicId . '-*.json') ?: [] as $file) {
        @unlink($file);
    }
}

/**
 * Den fine, forenklede linje for en rute: [[lat, lon], ...] — fra cache,
 * eller (hvis $compute) beregnet fra GPX-filen og gemt. null hvis den ikke
 * findes og ikke må/kan beregnes.
 *
 * @param array $route  skal have public_id og gpx_file_path
 * @return array<int, array{0: float, 1: float}>|null
 */
function line_get(array $route, bool $compute = true): ?array
{
    $cache = line_cache_path($route);
    if (is_file($cache)) {
        $data = json_decode((string) file_get_contents($cache), true);
        if (is_array($data)) {
            return $data;
        }
    }
    if (!$compute) {
        return null;
    }

    $full = gpx_full_path((string) ($route['gpx_file_path'] ?? ''));
    if (!is_file($full)) {
        return null;
    }
    $points = og_read_points($full);
    if (count($points) < 2) {
        return null;
    }
    $line = group_simplify_for_map($points, 2000);

    $dir = line_cache_dir();
    if (is_dir($dir) || @mkdir($dir, 0755, true) || is_dir($dir)) {
        $tmp = $cache . '.' . getmypid() . '.tmp';
        if (file_put_contents($tmp, json_encode($line)) !== false) {
            rename($tmp, $cache);
            foreach (glob($dir . '/' . $route['public_id'] . '-*.json') ?: [] as $old) {
                if ($old !== $cache) {
                    @unlink($old);
                }
            }
        }
    }

    return $line;
}

/**
 * Udtynd en fin linje til et zoom-niveau (0 = grovest, 2 = fin).
 *
 * @param array<int, array{0: float, 1: float}> $line
 * @return array<int, array{0: float, 1: float}>
 */
function line_for_level(array $line, int $level): array
{
    $level = max(0, min(2, $level));
    $tol = LINE_LEVEL_TOLERANCE[$level];
    if ($tol <= 0.0 || count($line) <= 2) {
        return $line;
    }

    return group_simplify($line, $tol);
}
