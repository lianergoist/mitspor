<?php
declare(strict_types=1);

/**
 * Rydder op i korttile-cachen (data/tile-cache/): sletter tiles, der
 * ikke er blevet GENHENTET (og dermed reelt ikke efterspurgt) i mindst
 * TILE_CACHE_PRUNE_AGE_SECONDS — se kommentaren ved konstanten i
 * app/config.php for hvorfor grænsen bevidst er sat højere end
 * TILE_CACHE_MAX_AGE_SECONDS, som styrer almindelig genhentning.
 *
 * Rydder desuden forældreløse .lock/.tmp-filer op (fra evt. afbrudte
 * hentninger — se tile_fetch_and_store() i app/tile_cache.php), samt
 * tomme {z}/{x}-mapper, der er tilbage efter oprydningen.
 *
 * Beregnet til at køre periodisk via cron, IKKE som et web-endpoint:
 *
 *   php prune-tile-cache.php
 *
 * Cron-eksempel (ugentligt, søndag kl. 03:00):
 *
 *   0 3 * * 0 php /var/www/mitspor.dk/prune-tile-cache.php >> /var/log/mitspor-tile-prune.log 2>&1
 */

require_once __DIR__ . '/app/config.php';

// Forældreløse .lock/.tmp-filer bør aldrig leve længe under normal
// drift — 1 time er rigelig margin til en meget langsom/afbrudt
// upstream-hentning.
const ORPHAN_FILE_MAX_AGE_SECONDS = 3600;

if (!is_dir(TILE_CACHE_DIR)) {
    echo "Cache-mappen findes ikke (" . TILE_CACHE_DIR . ") — intet at rydde op i.\n";
    exit(0);
}

$now = time();
$deletedTiles = 0;
$deletedOrphans = 0;
$freedBytes = 0;
$removedDirs = 0;

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(TILE_CACHE_DIR, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);

foreach ($iterator as $item) {
    $path = (string) $item->getPathname();

    if ($item->isDir()) {
        // På dette tidspunkt (CHILD_FIRST) er alle filer i mappen
        // allerede behandlet — er den nu tom, kan den fjernes.
        $isEmpty = count(scandir($path) ?: ['.', '..']) <= 2;
        if ($isEmpty) {
            if (@rmdir($path)) {
                $removedDirs++;
            }
        }
        continue;
    }

    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $age = $now - (int) $item->getMTime();

    if ($extension === 'png') {
        if ($age > TILE_CACHE_PRUNE_AGE_SECONDS) {
            $size = (int) $item->getSize();
            if (@unlink($path)) {
                $deletedTiles++;
                $freedBytes += $size;
            }
        }
        continue;
    }

    if ($extension === 'lock' || $extension === 'tmp') {
        if ($age > ORPHAN_FILE_MAX_AGE_SECONDS) {
            if (@unlink($path)) {
                $deletedOrphans++;
            }
        }
        continue;
    }

    // Ukendte filtyper i cache-mappen rører vi ikke ved.
}

printf(
    "Færdig. %d tile(s) slettet (%.1f MB frigjort), %d forældreløs(e) lock/tmp-fil(er) slettet, %d tom(me) mappe(r) fjernet.\n",
    $deletedTiles,
    $freedBytes / 1024 / 1024,
    $deletedOrphans,
    $removedDirs
);
