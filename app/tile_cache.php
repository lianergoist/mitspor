<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * Validerer at z/x/y er gyldige tile-koordinater. Forhindrer både
 * ugyldige forespørgsler og at nogen kan bruge endpointet til at
 * cache vilkårligt mange tiles ved at bede om urimeligt høje zoom-niveauer.
 */
function tile_coords_valid(int $z, int $x, int $y): bool
{
    if ($z < 0 || $z > TILE_MAX_ZOOM) {
        return false;
    }
    $max = 2 ** $z;
    return $x >= 0 && $x < $max && $y >= 0 && $y < $max;
}

function tile_cache_path(int $z, int $x, int $y): string
{
    return TILE_CACHE_DIR . "/{$z}/{$x}/{$y}.png";
}

/**
 * Henter en tile — fra disk-cache hvis den findes og er frisk nok,
 * ellers fra OSM (som herefter caches til næste gang).
 *
 * @return array{data: string, from_cache: bool}|null null ved fejl
 */
function tile_fetch(int $z, int $x, int $y): ?array
{
    $path = tile_cache_path($z, $x, $y);

    if (is_file($path) && (time() - filemtime($path)) < TILE_CACHE_MAX_AGE_SECONDS) {
        $data = file_get_contents($path);
        if ($data !== false) {
            return ['data' => $data, 'from_cache' => true];
        }
    }

    return tile_fetch_and_store($z, $x, $y, $path);
}

/**
 * Henter tilen fra upstream (OSM) og gemmer den i cachen. Bruger en
 * simpel fillås, så mange samtidige forespørgsler efter den SAMME
 * ucachede tile ikke sender flere parallelle kald til OSM.
 */
function tile_fetch_and_store(int $z, int $x, int $y, string $path): ?array
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $lockPath = $path . '.lock';
    $lockHandle = fopen($lockPath, 'c');
    if ($lockHandle === false) {
        return tile_fetch_upstream_only($z, $x, $y);
    }

    flock($lockHandle, LOCK_EX);

    // En anden proces kan have udfyldt cachen, mens vi ventede på låsen.
    if (is_file($path) && (time() - filemtime($path)) < TILE_CACHE_MAX_AGE_SECONDS) {
        $data = file_get_contents($path);
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
        if ($data !== false) {
            return ['data' => $data, 'from_cache' => true];
        }
    }

    $result = tile_fetch_upstream_only($z, $x, $y);

    if ($result !== null) {
        $tmpPath = $path . '.' . getmypid() . '.tmp';
        if (file_put_contents($tmpPath, $result['data']) !== false) {
            rename($tmpPath, $path);
        }
    }

    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    @unlink($lockPath);

    return $result;
}

function tile_fetch_upstream_only(int $z, int $x, int $y): ?array
{
    $subdomain = TILE_UPSTREAM_SUBDOMAINS[array_rand(TILE_UPSTREAM_SUBDOMAINS)];
    $url = str_replace(['{s}', '{z}', '{x}', '{y}'], [$subdomain, (string) $z, (string) $x, (string) $y], TILE_UPSTREAM_TEMPLATE);

    $context = stream_context_create([
        'http' => [
            'method'        => 'GET',
            'header'        => "User-Agent: " . TILE_USER_AGENT . "\r\n",
            'timeout'       => 8,
            'ignore_errors' => true,
        ],
    ]);

    $data = @file_get_contents($url, false, $context);

    if ($data === false || $data === '') {
        return null;
    }

    // Tjek at upstream faktisk svarede 200 OK (ignore_errors ovenfor
    // gør at fx en 404 ellers bare ville give os fejlsidens indhold).
    $statusLine = $http_response_header[0] ?? '';
    if (!str_contains($statusLine, '200')) {
        return null;
    }

    return ['data' => $data, 'from_cache' => false];
}
