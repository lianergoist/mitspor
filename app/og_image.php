<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/tile_cache.php';
require_once __DIR__ . '/gpx.php';
require_once __DIR__ . '/overview_lines.php';
require_once __DIR__ . '/route_groups.php';

/**
 * Automatisk genererede delingsbilleder (Open Graph) for offentlige ruter.
 *
 * Billedet er 1200x630 px: øverst et kort (OSM-tiles fra egen tile-cache)
 * med ruten tegnet ovenpå, nederst en bjælke med titel, aktivitet,
 * distance og MitSpor-logo. Færdige billeder gemmes som JPEG i en mappe
 * UDEN FOR webroot og serveres af public/og-image.php.
 *
 * Kræver PHP-udvidelserne gd (med FreeType og JPEG) og xmlreader.
 */

const OG_WIDTH        = 1200;
const OG_HEIGHT       = 630;
const OG_BAR_HEIGHT   = 130;
const OG_MAP_HEIGHT   = OG_HEIGHT - OG_BAR_HEIGHT; // 500
const OG_MAP_PADDING  = 48;   // luft mellem rute og kortets kant (px)
const OG_MAX_ZOOM     = 16;   // højere zoom er spild for meget korte ruter
const OG_SUPERSAMPLE  = 2;    // ruten tegnes i 2x og skaleres ned (pæne kanter)
const OG_MAX_POINTS   = 3000; // ruten udtyndes til højst så mange punkter
const OG_JPEG_QUALITY = 90;
const OG_TIME_BUDGET  = 15.0; // sekunder til at hente tiles, før vi giver op

/** Ændres ved layout-/designændringer, så alle billeder genereres igen. */
const OG_RENDER_VERSION = '1';

function og_cache_dir(): string
{
    if (defined('OG_CACHE_DIR')) {
        return OG_CACHE_DIR;
    }
    // Standard: ved siden af tile-cachen (data/ ligger uden for webroot).
    return dirname(TILE_CACHE_DIR) . '/og-cache';
}

function og_asset(string $name): string
{
    return __DIR__ . '/og/' . $name;
}

/**
 * Kort "versions-streng" for en rute. Ændres når noget, der påvirker
 * billedet, ændres (rute-række, GPX-fil, designversion). Bruges både i
 * cache-filnavnet og som ?v= i og:image-URL'en, så Facebook m.fl. henter
 * det nye billede, når ruten er redigeret.
 */
function og_route_version(array $route): string
{
    $gpxMtime = 0;
    $gpxSize  = 0;
    if (!empty($route['gpx_file_path'])) {
        $full = gpx_full_path($route['gpx_file_path']);
        if (is_file($full)) {
            $gpxMtime = (int) filemtime($full);
            $gpxSize  = (int) filesize($full);
        }
    }

    return substr(sha1(implode('|', [
        OG_RENDER_VERSION,
        $route['updated_at'] ?? '',
        $route['title'] ?? '',
        $route['distance_meters'] ?? '',
        $route['elevation_gain_meters'] ?? '',
        $route['activity_icon'] ?? '',
        $gpxMtime,
        $gpxSize,
    ])), 0, 10);
}

// ---------------------------------------------------------------------
// GPX -> punktliste (streaming, lav hukommelse)
// ---------------------------------------------------------------------

/**
 * Læser lat/lon for spor- (trkpt) eller rutepunkter (rtept) fra en
 * GPX-fil og udtynder til højst OG_MAX_POINTS punkter. Læser kun
 * attributter — ingen højde, tid eller kommentarer.
 *
 * @return array<int, array{0: float, 1: float}> liste af [lat, lon]
 */
function og_read_points(string $gpxPath): array
{
    $reader = new XMLReader();
    // LIBXML_NONET: intet netværk. Ingen NOENT/DTDLOAD (XXE-hærdning).
    if (!@$reader->open($gpxPath, null, LIBXML_NONET)) {
        return [];
    }

    $trk = [];
    $rte = [];

    while (@$reader->read()) {
        if ($reader->nodeType !== XMLReader::ELEMENT) {
            continue;
        }
        $name = strtolower($reader->localName);
        if ($name !== 'trkpt' && $name !== 'rtept') {
            continue;
        }
        $lat = $reader->getAttribute('lat');
        $lon = $reader->getAttribute('lon');
        if ($lat === null || $lon === null || !is_numeric($lat) || !is_numeric($lon)) {
            continue;
        }
        $lat = (float) $lat;
        $lon = (float) $lon;
        if ($lat < -85 || $lat > 85 || $lon < -180 || $lon > 180) {
            continue;
        }
        if ($name === 'trkpt') {
            $trk[] = [$lat, $lon];
        } else {
            $rte[] = [$lat, $lon];
        }
    }
    $reader->close();

    // Samme regel som gpx_validate_and_parse(): foretræk spor.
    $points = count($trk) >= 2 ? $trk : $rte;

    return og_thin($points, OG_MAX_POINTS);
}

/** Udtynd ved at tage hvert n'te punkt — men behold altid det sidste. */
function og_thin(array $points, int $max): array
{
    $n = count($points);
    if ($n <= $max) {
        return $points;
    }
    $step = (int) ceil($n / $max);
    $out  = [];
    for ($i = 0; $i < $n; $i += $step) {
        $out[] = $points[$i];
    }
    $out[] = $points[$n - 1];

    return $out;
}

// ---------------------------------------------------------------------
// Web-Mercator (samme skema som OSM/Leaflet: 256 px tiles)
// ---------------------------------------------------------------------

function og_world_x(float $lon, int $zoom): float
{
    return ($lon + 180.0) / 360.0 * 256.0 * (2 ** $zoom);
}

function og_world_y(float $lat, int $zoom): float
{
    $lat = max(-85.05112878, min(85.05112878, $lat));
    $s   = sin(deg2rad($lat));

    return (0.5 - log((1 + $s) / (1 - $s)) / (4 * M_PI)) * 256.0 * (2 ** $zoom);
}

/** Højeste zoom, hvor hele ruten (plus luft) stadig er inden for kortet. */
function og_choose_zoom(float $minLat, float $maxLat, float $minLon, float $maxLon): int
{
    $availW = OG_WIDTH - 2 * OG_MAP_PADDING;
    $availH = OG_MAP_HEIGHT - 2 * OG_MAP_PADDING;
    $maxZ   = min(OG_MAX_ZOOM, TILE_MAX_ZOOM);

    for ($z = $maxZ; $z >= 2; $z--) {
        $w = og_world_x($maxLon, $z) - og_world_x($minLon, $z);
        $h = og_world_y($minLat, $z) - og_world_y($maxLat, $z);
        if ($w <= $availW && $h <= $availH) {
            return $z;
        }
    }

    return 2;
}

// ---------------------------------------------------------------------
// Tegning
// ---------------------------------------------------------------------

/**
 * Sæt kortet sammen af tiles. Returnerer null, hvis en tile ikke kan
 * hentes (så vi hellere falder tilbage til det statiske billede end
 * cacher et kort med huller).
 *
 * @param callable(int,int,int): ?array $fetch  som tile_fetch()
 * @return GdImage|null
 */
function og_build_map(int $zoom, float $centerX, float $centerY, callable $fetch, float $deadline)
{
    $map = imagecreatetruecolor(OG_WIDTH, OG_MAP_HEIGHT);
    if ($map === false) {
        return null;
    }
    imagefill($map, 0, 0, imagecolorallocate($map, 232, 228, 220));

    $originX = $centerX - OG_WIDTH / 2;
    $originY = $centerY - OG_MAP_HEIGHT / 2;

    $tx0 = (int) floor($originX / 256);
    $tx1 = (int) floor(($originX + OG_WIDTH - 1) / 256);
    $ty0 = (int) floor($originY / 256);
    $ty1 = (int) floor(($originY + OG_MAP_HEIGHT - 1) / 256);
    $tiles = 2 ** $zoom;

    for ($ty = $ty0; $ty <= $ty1; $ty++) {
        if ($ty < 0 || $ty >= $tiles) {
            continue; // uden for verdenskortet (nord/syd)
        }
        for ($tx = $tx0; $tx <= $tx1; $tx++) {
            if (microtime(true) > $deadline) {
                imagedestroy($map);
                return null;
            }
            $wrappedX = (($tx % $tiles) + $tiles) % $tiles;
            $res = $fetch($zoom, $wrappedX, $ty);
            if ($res === null) {
                imagedestroy($map);
                return null;
            }
            $tile = @imagecreatefromstring($res['data']);
            if ($tile === false) {
                imagedestroy($map);
                return null;
            }
            imagecopy(
                $map, $tile,
                (int) round($tx * 256 - $originX), (int) round($ty * 256 - $originY),
                0, 0, 256, 256
            );
            imagedestroy($tile);
        }
    }

    return $map;
}

/** Tegn en polylinje med hvid kant og farvet kerne på et (2x) lærred. */
function og_draw_route($layer, array $xy, int $core, int $casing, array $rgb = [229, 57, 53]): void
{
    $s = OG_SUPERSAMPLE;

    // Fjern punkter, der ligger tættere end ~1 px fra det forrige.
    $pts  = [];
    $last = null;
    foreach ($xy as $p) {
        if ($last === null || hypot($p[0] - $last[0], $p[1] - $last[1]) >= $s) {
            $pts[] = $p;
            $last  = $p;
        }
    }
    if (count($pts) < 2) {
        $pts[] = [$xy[0][0] + 0.5, $xy[0][1] + 0.5];
    }

    $white = imagecolorallocate($layer, 255, 255, 255);
    $red   = imagecolorallocate($layer, $rgb[0], $rgb[1], $rgb[2]);

    foreach ([[$white, $core + 2 * $casing], [$red, $core]] as [$color, $width]) {
        $half = $width / 2;
        $n = count($pts);
        for ($i = 0; $i < $n - 1; $i++) {
            [$x1, $y1] = $pts[$i];
            [$x2, $y2] = $pts[$i + 1];
            $dx  = $x2 - $x1;
            $dy  = $y2 - $y1;
            $len = hypot($dx, $dy);
            if ($len < 0.01) {
                continue;
            }
            $nx = -$dy / $len * $half;
            $ny =  $dx / $len * $half;
            imagefilledpolygon($layer, [
                (int) round($x1 + $nx), (int) round($y1 + $ny),
                (int) round($x2 + $nx), (int) round($y2 + $ny),
                (int) round($x2 - $nx), (int) round($y2 - $ny),
                (int) round($x1 - $nx), (int) round($y1 - $ny),
            ], $color);
        }
        // Runde samlinger og ender.
        foreach ($pts as [$x, $y]) {
            imagefilledellipse($layer, (int) round($x), (int) round($y), (int) $width, (int) $width, $color);
        }
    }
}

function og_draw_dot($layer, float $x, float $y, int $radius, array $rgb): void
{
    $white = imagecolorallocate($layer, 255, 255, 255);
    $fill  = imagecolorallocate($layer, $rgb[0], $rgb[1], $rgb[2]);
    imagefilledellipse($layer, (int) round($x), (int) round($y), $radius * 2 + 10, $radius * 2 + 10, $white);
    imagefilledellipse($layer, (int) round($x), (int) round($y), $radius * 2, $radius * 2, $fill);
}

/** Bredden af en tekst i pixels. */
function og_text_width(string $font, float $size, string $text): int
{
    $box = imagettfbbox($size, 0, $font, $text);

    return $box === false ? 0 : (int) ($box[2] - $box[0]);
}

/** Kort teksten med "…", indtil den passer i $maxWidth. */
function og_fit_text(string $font, float $size, string $text, int $maxWidth): string
{
    if (og_text_width($font, $size, $text) <= $maxWidth) {
        return $text;
    }
    while (mb_strlen($text) > 1) {
        $text = mb_substr($text, 0, -1);
        $candidate = rtrim($text) . '…';
        if (og_text_width($font, $size, $candidate) <= $maxWidth) {
            return $candidate;
        }
    }

    return $text;
}

function og_format_km(int $meters): string
{
    if ($meters < 1000) {
        return number_format($meters, 0, ',', '.') . ' m';
    }

    return number_format($meters / 1000, $meters < 100000 ? 1 : 0, ',', '.') . ' km';
}

/** Tegn nederste bjælke (titel, aktivitet · distance, logo). */
function og_draw_bar($img, array $route): void
{
    $navy  = imagecolorallocate($img, 14, 58, 91);
    $white = imagecolorallocate($img, 255, 255, 255);
    $pale  = imagecolorallocate($img, 211, 232, 246);
    $badge = imagecolorallocate($img, 238, 246, 252);

    $top = OG_MAP_HEIGHT;
    imagefilledrectangle($img, 0, $top, OG_WIDTH - 1, OG_HEIGHT - 1, $navy);
    // Tynd hvid kant mellem kort og bjælke, som i delingsbilledet.
    imagefilledrectangle($img, 0, $top, OG_WIDTH - 1, $top + 3, $white);

    $bold   = og_asset('Outfit-ExtraBold.ttf');
    $medium = og_asset('Outfit-Medium.ttf');
    $midY   = $top + 4 + (OG_BAR_HEIGHT - 4) / 2;

    // Aktivitets-badge til venstre.
    $badgeSize = 84;
    $bx = 44;
    $by = (int) round($midY - $badgeSize / 2);
    imagefilledellipse($img, $bx + $badgeSize / 2, $by + $badgeSize / 2, $badgeSize + 6, $badgeSize + 6, $white);
    imagefilledellipse($img, $bx + $badgeSize / 2, $by + $badgeSize / 2, $badgeSize, $badgeSize, $badge);

    if (isset($route['badge_number'])) {
        // Gruppe (etape-tur): blå cirkel med antal etaper, som på forsidens kort.
        imagefilledellipse($img, $bx + $badgeSize / 2, $by + $badgeSize / 2, $badgeSize, $badgeSize, imagecolorallocate($img, 37, 99, 235));
        $num = (string) (int) $route['badge_number'];
        $numSize = strlen($num) > 2 ? 26 : 38;
        $nw = og_text_width($bold, $numSize, $num);
        imagettftext($img, $numSize, 0, (int) round($bx + ($badgeSize - $nw) / 2), (int) round($by + $badgeSize / 2 + $numSize * 0.36), $white, $bold, $num);
        $icon = '';
    } else {
        $icon = (string) ($route['activity_icon'] ?? '');
    }
    if (preg_match('/^[a-z0-9_]+$/i', $icon)) {
        // De nye ikoner er 120x120 (skarpe, når de skaleres ned); de gamle er 50x50.
        $iconPath = __DIR__ . '/../public/markers/' . $icon . '1_120x120.png';
        if (!is_file($iconPath)) {
            $iconPath = __DIR__ . '/../public/markers/' . $icon . '1_50x50.png';
        }
        if (is_file($iconPath)) {
            $ic = @imagecreatefrompng($iconPath);
            if ($ic !== false) {
                $target = 62;
                imagecopyresampled(
                    $img, $ic,
                    (int) ($bx + ($badgeSize - $target) / 2), (int) ($by + ($badgeSize - $target) / 2),
                    0, 0, $target, $target, imagesx($ic), imagesy($ic)
                );
                imagedestroy($ic);
            }
        }
    }

    // Logo + navn til højre.
    $logoH = 60;
    $logo  = @imagecreatefrompng(og_asset('logo.png'));
    $wordW = og_text_width($bold, 30, 'MitSpor');
    $logoW = 58;
    $rightEdge = OG_WIDTH - 44;
    $wordX = $rightEdge - $wordW;
    $logoX = $wordX - 16 - $logoW;
    if ($logo !== false) {
        imagealphablending($img, true);
        imagecopyresampled($img, $logo, $logoX, (int) round($midY - $logoH / 2), 0, 0, $logoW, $logoH, imagesx($logo), imagesy($logo));
        imagedestroy($logo);
    }
    imagettftext($img, 30, 0, $wordX, (int) round($midY + 11), $white, $bold, 'MitSpor');

    // Titel + undertekst i midten.
    $textX = $bx + $badgeSize + 26;
    $maxW  = $logoX - 30 - $textX;
    $title = trim((string) ($route['title'] ?? ''));
    if ($title === '') {
        $title = isset($route['badge_number']) ? 'Etape-tur' : 'Rute';
    }

    $titleSize = 40;
    while ($titleSize > 32 && og_text_width($bold, $titleSize, $title) > $maxW) {
        $titleSize -= 2;
    }
    $title = og_fit_text($bold, $titleSize, $title, $maxW);
    imagettftext($img, $titleSize, 0, $textX, (int) round($midY - 4), $white, $bold, $title);

    $parts = [];
    if (!empty($route['subtitle'])) {
        $parts[] = (string) $route['subtitle'];
    }
    if (!empty($route['activity_name'])) {
        $parts[] = (string) $route['activity_name'];
    }
    if (!empty($route['distance_meters'])) {
        $parts[] = og_format_km((int) $route['distance_meters']);
    }
    if (!empty($route['elevation_gain_meters'])) {
        $parts[] = number_format((int) $route['elevation_gain_meters'], 0, ',', '.') . ' m stigning';
    }
    if ($parts) {
        $sub = og_fit_text($medium, 24, implode('  ·  ', $parts), $maxW);
        imagettftext($img, 24, 0, $textX, (int) round($midY + 36), $pale, $medium, $sub);
    }
}

/** OSM-krediteringen (krav i OSM's licens) nederst til højre på kortet. */
function og_draw_attribution($img): void
{
    $font = og_asset('Outfit-Medium.ttf');
    $text = '© OpenStreetMap-bidragydere';
    $size = 13;
    $w    = og_text_width($font, $size, $text);
    $x1   = OG_WIDTH - $w - 22;
    $y1   = OG_MAP_HEIGHT - 26;

    imagealphablending($img, true);
    $bg = imagecolorallocatealpha($img, 255, 255, 255, 30); // ~76 % dækkende
    imagefilledrectangle($img, $x1, $y1, OG_WIDTH - 1, OG_MAP_HEIGHT - 1, $bg);
    imagettftext($img, $size, 0, $x1 + 8, OG_MAP_HEIGHT - 8, imagecolorallocate($img, 60, 70, 80), $font, $text);
}

/**
 * Tegn hele billedet for ÉN rute og returnér JPEG-bytes (eller null ved fejl).
 *
 * @param array $route  felter: title, activity_name, activity_icon,
 *                      distance_meters, elevation_gain_meters
 * @param array $points [[lat, lon], ...]
 * @param callable(int,int,int): ?array|null $fetch  tile-hentning (standard: tile_fetch)
 */
function og_render(array $route, array $points, ?callable $fetch = null): ?string
{
    return og_render_lines($route, [['points' => $points]], $fetch, false);
}

/** Farver til etaperne i en gruppe — samme rækkefølge som på gruppesiden. */
const OG_STAGE_COLORS = [
    [230, 25, 75], [60, 180, 75], [67, 99, 216], [245, 130, 49], [145, 30, 180],
    [8, 145, 178], [217, 70, 239], [154, 99, 36], [15, 118, 110], [127, 29, 29],
];

/**
 * Tegn kort + linjer + bjælke og returnér JPEG-bytes (eller null ved fejl).
 *
 * @param array $meta   felter til bjælken (se og_draw_bar())
 * @param array<int, array{points: array, rgb?: array}> $lines  én eller flere linjer
 * @param bool  $stages true: tegn hver linje i sin farve med et nummer ved
 *                      starten (en gruppe); false: én rød rute med start/mål-prikker
 * @param callable(int,int,int): ?array|null $fetch  tile-hentning (standard: tile_fetch)
 */
function og_render_lines(array $meta, array $lines, ?callable $fetch = null, bool $stages = false): ?string
{
    if (!function_exists('imagecreatetruecolor') || !function_exists('imagettftext')) {
        return null;
    }
    $lines = array_values(array_filter($lines, static fn(array $l): bool => count($l['points']) >= 2));
    if (!$lines) {
        return null;
    }
    $fetch ??= 'tile_fetch';

    $minLat = 90.0; $maxLat = -90.0; $minLon = 180.0; $maxLon = -180.0;
    foreach ($lines as $line) {
        foreach ($line['points'] as [$lat, $lon]) {
            $minLat = min($minLat, $lat); $maxLat = max($maxLat, $lat);
            $minLon = min($minLon, $lon); $maxLon = max($maxLon, $lon);
        }
    }

    $zoom = og_choose_zoom($minLat, $maxLat, $minLon, $maxLon);

    // Midten af udstrækningen i verdens-pixels ved dette zoom.
    $cx = (og_world_x($minLon, $zoom) + og_world_x($maxLon, $zoom)) / 2;
    $cy = (og_world_y($minLat, $zoom) + og_world_y($maxLat, $zoom)) / 2;

    $map = og_build_map($zoom, $cx, $cy, $fetch, microtime(true) + OG_TIME_BUDGET);
    if ($map === null) {
        return null;
    }

    // Rute-lag i 2x med gennemsigtig baggrund.
    $s  = OG_SUPERSAMPLE;
    $lw = OG_WIDTH * $s;
    $lh = OG_MAP_HEIGHT * $s;
    $layer = imagecreatetruecolor($lw, $lh);
    imagealphablending($layer, false);
    imagesavealpha($layer, true);
    imagefill($layer, 0, 0, imagecolorallocatealpha($layer, 0, 0, 0, 127));
    imagealphablending($layer, true);

    $ox = $cx - OG_WIDTH / 2;
    $oy = $cy - OG_MAP_HEIGHT / 2;
    $projected = [];
    foreach ($lines as $line) {
        $xy = [];
        foreach ($line['points'] as [$lat, $lon]) {
            $xy[] = [(og_world_x($lon, $zoom) - $ox) * $s, (og_world_y($lat, $zoom) - $oy) * $s];
        }
        $projected[] = $xy;
    }

    if ($stages) {
        foreach ($projected as $i => $xy) {
            og_draw_route($layer, $xy, 6 * $s, (int) round(2.5 * $s), OG_STAGE_COLORS[$i % count(OG_STAGE_COLORS)]);
        }
        // Mål på sidste etape, så man kan se, hvor turen ender.
        $end = end($projected);
        $end = end($end);
        og_draw_dot($layer, $end[0], $end[1], 7 * $s, [14, 58, 91]);
        // Nummererede startpunkter (numrene skrives efter nedskaleringen, så de er skarpe).
        // Ved mange etaper er prikkerne mindre, så linjerne stadig kan ses imellem dem.
        $dotRadius = count($projected) > 8 ? 8 : 10;
        foreach ($projected as $i => $xy) {
            $rgb = OG_STAGE_COLORS[$i % count(OG_STAGE_COLORS)];
            og_draw_dot($layer, $xy[0][0], $xy[0][1], $dotRadius * $s, $rgb);
        }
    } else {
        $xy = $projected[0];
        og_draw_route($layer, $xy, 7 * $s, 3 * $s);
        $first = $xy[0];
        $last  = $xy[count($xy) - 1];
        og_draw_dot($layer, $last[0], $last[1], 8 * $s, [14, 58, 91]);   // mål: mørkeblå
        og_draw_dot($layer, $first[0], $first[1], 8 * $s, [46, 158, 79]); // start: grøn
    }

    imagealphablending($map, true);
    imagecopyresampled($map, $layer, 0, 0, 0, 0, OG_WIDTH, OG_MAP_HEIGHT, $lw, $lh);
    imagedestroy($layer);

    if ($stages) {
        $font = og_asset('Outfit-ExtraBold.ttf');
        $white = imagecolorallocate($map, 255, 255, 255);
        foreach ($projected as $i => $xy) {
            $num = (string) ($i + 1);
            $size = strlen($num) > 1 ? ($dotRadius < 10 ? 10 : 11) : ($dotRadius < 10 ? 12 : 13);
            $w = og_text_width($font, $size, $num);
            imagettftext($map, $size, 0, (int) round($xy[0][0] / $s - $w / 2), (int) round($xy[0][1] / $s + $size * 0.36), $white, $font, $num);
        }
    }

    // Endeligt lærred: kort øverst, bjælke nederst.
    $img = imagecreatetruecolor(OG_WIDTH, OG_HEIGHT);
    imagecopy($img, $map, 0, 0, 0, 0, OG_WIDTH, OG_MAP_HEIGHT);
    imagedestroy($map);

    og_draw_attribution($img);
    og_draw_bar($img, $meta);

    ob_start();
    imageinterlace($img, true);
    $ok = imagejpeg($img, null, OG_JPEG_QUALITY);
    $bytes = ob_get_clean();
    imagedestroy($img);

    return ($ok && is_string($bytes) && $bytes !== '') ? $bytes : null;
}

// ---------------------------------------------------------------------
// Cache (fil pr. rute + version) og generering med lås
// ---------------------------------------------------------------------

/** Sti til det cachede billede for denne rute-version (findes måske ikke). */
function og_cache_path(array $route): string
{
    return og_cache_dir() . '/' . $route['public_id'] . '-' . og_route_version($route) . '.jpg';
}

/**
 * Returnerer stien til et færdigt billede — fra cache hvis det findes,
 * ellers genereres det. Returnerer null, hvis det ikke kunne lade sig
 * gøre (så kalderen viser det statiske billede).
 *
 * Kun ÉN generering ad gangen (global fillås), så et pludseligt run af
 * nye delinger ikke overbelaster serveren eller hamrer på OSM.
 *
 * @param callable(int,int,int): ?array|null $fetch
 */
function og_get_image(array $route, ?callable $fetch = null, float $lockWaitSeconds = 8.0): ?string
{
    $path = og_cache_path($route);
    if (is_file($path)) {
        return $path;
    }

    $dir = og_cache_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return null;
    }

    $lock = @fopen($dir . '/.generate.lock', 'c');
    if ($lock === false) {
        return null;
    }

    $deadline = microtime(true) + $lockWaitSeconds;
    $locked = false;
    while (!($locked = flock($lock, LOCK_EX | LOCK_NB))) {
        if (microtime(true) >= $deadline) {
            break;
        }
        usleep(150000);
    }
    if (!$locked) {
        fclose($lock);
        return null;
    }

    try {
        // En anden proces kan have lavet den, mens vi ventede.
        if (is_file($path)) {
            return $path;
        }

        $gpx = gpx_full_path_safe($route['gpx_file_path'] ?? '');
        if ($gpx === null) {
            return null;
        }
        $points = og_read_points($gpx);
        $bytes  = og_render($route, $points, $fetch);
        if ($bytes === null) {
            return null;
        }

        $tmp = $path . '.' . getmypid() . '.tmp';
        if (file_put_contents($tmp, $bytes) === false) {
            return null;
        }
        rename($tmp, $path);

        // Ryd gamle versioner af samme rute.
        foreach (glob($dir . '/' . $route['public_id'] . '-*.jpg') ?: [] as $old) {
            if ($old !== $path) {
                @unlink($old);
            }
        }

        return $path;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** gpx_full_path() fra gpx.php, men null hvis filen ikke findes. */
function gpx_full_path_safe(string $relativePath): ?string
{
    if ($relativePath === '') {
        return null;
    }
    $full = gpx_full_path($relativePath);

    return is_file($full) ? $full : null;
}


// ---------------------------------------------------------------------
// Hooks: forhåndsgenerering, oprydning ved skjul/sletning
// ---------------------------------------------------------------------

/**
 * Henter de felter, der skal bruges til at tegne billedet, men KUN hvis
 * ruten er aktiv og offentlig. Bruges af både og-image.php og hooks her.
 */
function og_load_public_route(PDO $pdo, string $publicId): ?array
{
    if (!preg_match('/^[0-9]{8}$/', $publicId)) {
        return null;
    }
    $stmt = $pdo->prepare(
        "SELECT r.public_id, r.title, r.updated_at, r.gpx_file_path,
                r.distance_meters, r.elevation_gain_meters,
                a.name_da AS activity_name, a.icon_filename AS activity_icon
         FROM routes r
         JOIN activity_types a ON a.id = r.activity_type_id
         WHERE r.public_id = :public_id
           AND r.status = 'active'
           AND r.visibility = 'public'
         LIMIT 1"
    );
    $stmt->execute(['public_id' => $publicId]);
    $route = $stmt->fetch();

    return $route ?: null;
}

/** Slet alle cachede billeder og den forenklede oversigtslinje for én rute. */
function og_delete_images(string $publicId): void
{
    if (!preg_match('/^[0-9]{8}$/', $publicId)) {
        return;
    }
    foreach (glob(og_cache_dir() . '/' . $publicId . '-*.jpg') ?: [] as $file) {
        @unlink($file);
    }
    // Ruten er slettet eller ikke længere offentlig: den forenklede linje til
    // oversigtskortet skal heller ikke ligge tilbage (overview_lines.php).
    if (function_exists('line_delete')) {
        line_delete($publicId);
    }
}

/**
 * Kaldes efter en rute er oprettet eller ændret (også kun metadata).
 *
 * - Offentlig rute: billedet (gen)genereres EFTER at svaret er sendt til
 *   brugeren, så upload/gem ikke bliver langsommere. Det kræver PHP-FPM
 *   (fastcgi_finish_request). Uden FPM springes forhåndsgenerering over,
 *   og billedet laves i stedet første gang nogen beder om det.
 * - Ikke (længere) offentlig: eventuelle billeder slettes med det samme,
 *   så en rute, der gøres privat, ikke efterlader et kortbillede på disk.
 *
 * Fejl her må aldrig få selve gemningen til at fejle.
 */
function og_refresh(PDO $pdo, string $publicId): void
{
    try {
        if (!preg_match('/^[0-9]{8}$/', $publicId)) {
            return;
        }

        // Er ruten en etape i en gruppe, ændrer gruppens billede sig også.
        $stmt = $pdo->prepare(
            'SELECT g.public_id FROM routes r JOIN route_groups g ON g.id = r.group_id WHERE r.public_id = :pid'
        );
        $stmt->execute(['pid' => $publicId]);
        $groupPublicId = $stmt->fetchColumn();
        if ($groupPublicId !== false) {
            og_refresh_group($pdo, (string) $groupPublicId);
        }

        if (og_load_public_route($pdo, $publicId) === null) {
            og_delete_images($publicId);
            return;
        }

        if (!function_exists('fastcgi_finish_request')) {
            return;
        }

        register_shutdown_function(static function () use ($pdo, $publicId): void {
            try {
                // Slip session-låsen, så brugerens næste klik ikke venter.
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_write_close();
                }
                fastcgi_finish_request();
                @set_time_limit(60);

                $route = og_load_public_route($pdo, $publicId);
                if ($route !== null) {
                    og_get_image($route, null, 2.0);
                    // Den forenklede linje til oversigtskortet på forsiden.
                    line_get($route, true);
                }
            } catch (Throwable $e) {
                error_log('OG-forhåndsgenerering fejlede: ' . $e->getMessage());
            }
        });
    } catch (Throwable $e) {
        error_log('OG-oprydning fejlede: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------
// Delingsbilleder til grupper (etape-ture)
// ---------------------------------------------------------------------

/**
 * Gruppen og dens OFFENTLIGE etaper — eller null, hvis gruppen ikke findes
 * eller ingen af etaperne er offentlige (så må der ikke laves et billede).
 *
 * @return array{group: array, members: array}|null
 */
function og_load_public_group(PDO $pdo, string $groupPublicId): ?array
{
    $group = group_find($pdo, $groupPublicId);
    if ($group === null) {
        return null;
    }
    $members = group_members($pdo, (int) $group['id'], false);

    return $members ? ['group' => $group, 'members' => $members] : null;
}

/** Version af gruppens billede: ændres med titel, etaper og designversion. */
function og_group_version(array $group, array $members): string
{
    return substr(sha1(implode('|', [
        OG_RENDER_VERSION, 'group', $group['title'], group_geometry_version($members, false),
    ])), 0, 10);
}

function og_group_cache_path(array $group, array $members): string
{
    return og_cache_dir() . '/group-' . $group['public_id'] . '-' . og_group_version($group, $members) . '.jpg';
}

/** Undertekst: "5 etaper · 93,5 km · 708 m stigning". */
function og_group_subtitle(array $members): string
{
    $t = group_totals($members);
    $parts = [$t['stages'] . ($t['stages'] === 1 ? ' etape' : ' etaper')];
    if ($t['distance_meters'] > 0) {
        $parts[] = og_format_km($t['distance_meters']);
    }
    if ($t['elevation_gain_meters'] > 0) {
        $parts[] = number_format($t['elevation_gain_meters'], 0, ',', '.') . ' m stigning';
    }

    return implode('  ·  ', $parts);
}

/** Slet alle cachede billeder (alle versioner) for én gruppe. */
function og_delete_group_images(string $groupPublicId): void
{
    if (!group_valid_public_id($groupPublicId)) {
        return;
    }
    foreach (glob(og_cache_dir() . '/group-' . $groupPublicId . '-*.jpg') ?: [] as $file) {
        @unlink($file);
    }
}

/**
 * Sti til gruppens færdige billede (fra cache, ellers genereret), eller null.
 * Samme regler som og_get_image(): én generering ad gangen (global fillås).
 *
 * @param array $members  gruppens OFFENTLIGE etaper (group_members(..., false))
 * @param callable(int,int,int): ?array|null $fetch
 */
function og_get_group_image(array $group, array $members, ?callable $fetch = null, float $lockWaitSeconds = 8.0): ?string
{
    if (!$members) {
        return null;
    }
    $path = og_group_cache_path($group, $members);
    if (is_file($path)) {
        return $path;
    }

    $dir = og_cache_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return null;
    }
    $lock = @fopen($dir . '/.generate.lock', 'c');
    if ($lock === false) {
        return null;
    }
    $deadline = microtime(true) + $lockWaitSeconds;
    $locked = false;
    while (!($locked = flock($lock, LOCK_EX | LOCK_NB))) {
        if (microtime(true) >= $deadline) {
            break;
        }
        usleep(150000);
    }
    if (!$locked) {
        fclose($lock);
        return null;
    }

    try {
        if (is_file($path)) {
            return $path;
        }

        $stages = group_geometry($group, $members, false);
        $lines = array_map(static fn(array $st): array => ['points' => $st['points']], $stages);
        $meta = [
            'title'        => $group['title'],
            'badge_number' => count($members),
            'subtitle'     => og_group_subtitle($members),
        ];
        $bytes = og_render_lines($meta, $lines, $fetch, true);
        if ($bytes === null) {
            return null;
        }

        $tmp = $path . '.' . getmypid() . '.tmp';
        if (file_put_contents($tmp, $bytes) === false) {
            return null;
        }
        rename($tmp, $path);
        foreach (glob($dir . '/group-' . $group['public_id'] . '-*.jpg') ?: [] as $old) {
            if ($old !== $path) {
                @unlink($old);
            }
        }

        return $path;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/**
 * Kaldes, når en gruppe er ændret. Ingen offentlige etaper: slet billedet
 * med det samme. Ellers laves det igen EFTER svaret er sendt (kræver PHP-FPM,
 * som ved og_refresh()); uden FPM laves det, første gang nogen beder om det.
 */
function og_refresh_group(PDO $pdo, string $groupPublicId): void
{
    static $scheduled = [];

    try {
        if (!group_valid_public_id($groupPublicId)) {
            return;
        }
        if (og_load_public_group($pdo, $groupPublicId) === null) {
            og_delete_group_images($groupPublicId);
        }
        if (!function_exists('fastcgi_finish_request') || isset($scheduled[$groupPublicId])) {
            return;
        }
        $scheduled[$groupPublicId] = true;

        register_shutdown_function(static function () use ($pdo, $groupPublicId): void {
            try {
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_write_close();
                }
                fastcgi_finish_request();
                @set_time_limit(60);

                $data = og_load_public_group($pdo, $groupPublicId);
                if ($data === null) {
                    og_delete_group_images($groupPublicId); // gruppen er væk eller helt privat
                    return;
                }
                og_get_group_image($data['group'], $data['members'], null, 2.0);
            } catch (Throwable $e) {
                error_log('OG-gruppebillede fejlede: ' . $e->getMessage());
            }
        });
    } catch (Throwable $e) {
        error_log('OG-gruppeoprydning fejlede: ' . $e->getMessage());
    }
}
