<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/tile_cache.php';

$z = (int) ($_GET['z'] ?? -1);
$x = (int) ($_GET['x'] ?? -1);
$y = (int) ($_GET['y'] ?? -1);

if (!tile_coords_valid($z, $x, $y)) {
    http_response_code(400);
    exit;
}

$result = tile_fetch($z, $x, $y);

if ($result === null) {
    http_response_code(502);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Kunne ikke hente korttile.';
    exit;
}

header('Content-Type: image/png');
header('Cache-Control: public, max-age=' . TILE_CACHE_MAX_AGE_SECONDS);
header('X-Tile-Cache: ' . ($result['from_cache'] ? 'HIT' : 'MISS'));
header('Content-Length: ' . strlen($result['data']));
echo $result['data'];
