<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * Validerer en GPX-streng og udtrækker stats (distance, højdemeter,
 * bounding box, centroid). Hærdet mod XXE: ingen DTD-load, intet
 * netværk (LIBXML_NONET), ingen entity-substitution.
 *
 * @return array{valid: bool, errors: string[], stats?: array}
 */
function gpx_validate_and_parse(string $xmlContent): array
{
    if (trim($xmlContent) === '') {
        return ['valid' => false, 'errors' => ['Filen er tom.']];
    }

    libxml_use_internal_errors(true);

    $dom = new DOMDocument();
    // Bevidst INGEN LIBXML_NOENT og INGEN LIBXML_DTDLOAD — det er dem,
    // der ville åbne for XXE. LIBXML_NONET forhindrer desuden ethvert
    // forsøg på at hente eksterne ressourcer over netværket.
    $loaded = $dom->loadXML($xmlContent, LIBXML_NONET);
    libxml_clear_errors();

    if (!$loaded) {
        return ['valid' => false, 'errors' => ['Filen er ikke gyldig XML/GPX.']];
    }

    $root = $dom->documentElement;
    if ($root === null || strtolower($root->localName) !== 'gpx') {
        return ['valid' => false, 'errors' => ['Filen mangler et gyldigt <gpx>-rodelement.']];
    }

    // Foretræk trkpt (spor), fald tilbage til rtept (rute), så begge
    // typer GPX-filer kan bruges.
    $points = gpx_extract_points($dom, 'trkpt');
    if (count($points) < 2) {
        $points = gpx_extract_points($dom, 'rtept');
    }

    if (count($points) < 2) {
        return ['valid' => false, 'errors' => ['Filen indeholder for få koordinater (mindst 2 punkter kræves).']];
    }

    $stats = gpx_compute_stats($points);

    return ['valid' => true, 'errors' => [], 'stats' => $stats, 'points' => $points];
}

/**
 * De indbyggede visnings-funktioner og de datafelter, hver af dem kræver.
 * SKAL matche AVAILABLE_FUNCTIONS i editor/app.js (og GRAPH_METRICS i
 * route-view.php).
 */
const GPX_FUNCTION_REQUIRED_FIELDS = [
    'elevation_profile' => ['ele'],
    'time_total'        => ['time'],
    'speed_current'     => ['time'],
    'speed_avg'         => ['time'],
    'time_moving'       => ['time'],
    'speed_avg_moving'  => ['time'],
    'speed_min'         => ['time'],
    'speed_max'         => ['time'],
    'hr_graph'          => ['hr'],
    'cadence_graph'     => ['cad'],
    'power_graph'       => ['power'],
];

/**
 * Finder de datafelter, en GPX-fil rent faktisk indeholder på sine punkter —
 * både faste elementer (ele/time) og alt i <extensions> (fx hr, cad, power),
 * uanset navnerum-prefix. Samme princip som detectGpxDataFields() i editoren.
 *
 * @return string[] feltnavne (kun dem, der er relevante for funktionerne)
 */
function gpx_detect_function_fields(DOMDocument $dom): array
{
    $wanted = [];
    foreach (GPX_FUNCTION_REQUIRED_FIELDS as $fields) {
        foreach ($fields as $f) {
            $wanted[$f] = true;
        }
    }

    $found = [];
    // Samme valg som gpx_validate_and_parse(): trkpt, ellers rtept.
    $pointEls = $dom->getElementsByTagName('trkpt');
    if ($pointEls->length < 2) {
        $pointEls = $dom->getElementsByTagName('rtept');
    }

    $walkExtensions = null;
    $walkExtensions = static function (DOMElement $node) use (&$walkExtensions, &$found, $wanted): void {
        foreach ($node->childNodes as $child) {
            if (!($child instanceof DOMElement)) {
                continue;
            }
            $hasElementChildren = false;
            foreach ($child->childNodes as $grandChild) {
                if ($grandChild instanceof DOMElement) {
                    $hasElementChildren = true;
                    break;
                }
            }
            if ($hasElementChildren) {
                $walkExtensions($child); // fx en TrackPointExtension-container
            } elseif (isset($wanted[$child->localName])) {
                $found[$child->localName] = true;
            }
        }
    };

    foreach ($pointEls as $point) {
        foreach ($point->childNodes as $child) {
            if (!($child instanceof DOMElement)) {
                continue;
            }
            if ($child->localName === 'extensions') {
                $walkExtensions($child);
            } elseif (isset($wanted[$child->localName])) {
                $found[$child->localName] = true;
            }
        }
        if (count($found) === count($wanted)) {
            break; // alt relevant er fundet — ingen grund til at læse resten
        }
    }

    return array_keys($found);
}

/**
 * ALLE visnings-funktioner, som en GPX-fil har data til — bruges som
 * udgangspunkt for en NY rute, hvor ejeren endnu ikke har valgt noget:
 * er der tidsdata, vises alle tids-baserede funktioner, osv.
 *
 * @return string[] funktionsnøgler
 */
function gpx_default_enabled_functions(string $xmlContent): array
{
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $loaded = $dom->loadXML($xmlContent, LIBXML_NONET); // samme XXE-hærdning som ovenfor
    libxml_clear_errors();
    if (!$loaded) {
        return [];
    }

    $fields = array_flip(gpx_detect_function_fields($dom));
    $keys = [];
    foreach (GPX_FUNCTION_REQUIRED_FIELDS as $key => $required) {
        $ok = true;
        foreach ($required as $f) {
            if (!isset($fields[$f])) {
                $ok = false;
                break;
            }
        }
        if ($ok) {
            $keys[] = $key;
        }
    }
    return $keys;
}

/**
 * Udtræk lat/lon/ele/time fra alle elementer af et givent tagnavn (uden
 * hensyn til namespace-prefix, da GPX-filer varierer).
 *
 * seg_start er true for det første punkt i hvert <trkseg> eller <rte>.
 * Afstand, højde og hastighed regnes ikke hen over et skift, så springet
 * mellem to spor eller segmenter ikke tæller med.
 *
 * @return array<int, array{lat: float, lon: float, ele: ?float, time_epoch: ?float, seg_start: bool}>
 */
function gpx_extract_points(DOMDocument $dom, string $tagName): array
{
    $points = [];
    $prevParent = null;

    foreach ($dom->getElementsByTagName($tagName) as $el) {
        $latAttr = $el->getAttribute('lat');
        $lonAttr = $el->getAttribute('lon');

        if ($latAttr === '' || $lonAttr === '') {
            continue;
        }

        $lat = (float) $latAttr;
        $lon = (float) $lonAttr;

        // Simpel sanity-check af koordinater
        if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
            continue;
        }

        $ele = null;
        foreach ($el->getElementsByTagName('ele') as $eleEl) {
            $ele = (float) $eleEl->nodeValue;
            break;
        }

        $timeEpoch = null;
        foreach ($el->getElementsByTagName('time') as $timeEl) {
            $parsed = strtotime((string) $timeEl->nodeValue);
            if ($parsed !== false) {
                $timeEpoch = (float) $parsed;
            }
            break;
        }

        $segStart = $el->parentNode !== $prevParent;
        $prevParent = $el->parentNode;

        $points[] = ['lat' => $lat, 'lon' => $lon, 'ele' => $ele, 'time_epoch' => $timeEpoch, 'seg_start' => $segStart];
    }

    return $points;
}

/**
 * Beregn distance (haversine), højdemeter op/ned, bounding box og
 * centroid ud fra en liste af punkter.
 */
function gpx_compute_stats(array $points): array
{
    $distanceMeters = 0.0;
    $elevationGain = 0.0;
    $elevationLoss = 0.0;

    $minLat = $maxLat = $points[0]['lat'];
    $minLon = $maxLon = $points[0]['lon'];
    $latSum = 0.0;
    $lonSum = 0.0;

    $prev = null;

    foreach ($points as $point) {
        $minLat = min($minLat, $point['lat']);
        $maxLat = max($maxLat, $point['lat']);
        $minLon = min($minLon, $point['lon']);
        $maxLon = max($maxLon, $point['lon']);
        $latSum += $point['lat'];
        $lonSum += $point['lon'];

        // Intet spring mellem to spor/segmenter (se gpx_extract_points()).
        if ($prev !== null && empty($point['seg_start'])) {
            $distanceMeters += gpx_haversine_meters($prev['lat'], $prev['lon'], $point['lat'], $point['lon']);

            if ($prev['ele'] !== null && $point['ele'] !== null) {
                $diff = $point['ele'] - $prev['ele'];
                if ($diff > 0) {
                    $elevationGain += $diff;
                } else {
                    $elevationLoss += abs($diff);
                }
            }
        }

        $prev = $point;
    }

    $count = count($points);

    $hasTimeData = false;
    foreach ($points as $point) {
        if ($point['time_epoch'] !== null) {
            $hasTimeData = true;
            break;
        }
    }

    return [
        'distance_meters'        => (int) round($distanceMeters),
        'elevation_gain_meters'  => (int) round($elevationGain),
        'elevation_loss_meters'  => (int) round($elevationLoss),
        'min_lat'                => $minLat,
        'max_lat'                => $maxLat,
        'min_lon'                => $minLon,
        'max_lon'                => $maxLon,
        'centroid_lat'           => $latSum / $count,
        'centroid_lon'           => $lonSum / $count,
        'start_lat'              => $points[0]['lat'],
        'start_lon'              => $points[0]['lon'],
        'has_time_data'          => $hasTimeData,
    ];
}

/**
 * Afstand mellem to koordinater i meter (haversine-formel).
 */
function gpx_haversine_meters(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $earthRadius = 6371000.0; // meter

    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);

    $a = sin($dLat / 2) ** 2
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    return $earthRadius * $c;
}

/**
 * Tjek om nogen af GPX-filens tidsstemplede punkter antyder en
 * hastighed, der overstiger $maxSpeedKmh mellem to nabopunkter — eller
 * om tidsstemplerne ikke er stigende (hvilket i sig selv er umuligt).
 * En kæde "nulstilles" ved et punkt uden tid, så vi kun sammenligner
 * nabopunkter, der begge faktisk har et tidsstempel.
 *
 * @param array<int, array{lat: float, lon: float, time_epoch: ?float}> $points
 * @return array{violation: bool, max_speed_kmh: float}
 */
function gpx_check_speed_plausibility(array $points, float $maxSpeedKmh): array
{
    $maxFoundKmh = 0.0;
    $violation = false;
    $prev = null;

    foreach ($points as $point) {
        // Et nyt spor/segment sammenlignes ikke med det forrige: de kan være
        // optaget på forskellige dage eller stå i en anden rækkefølge.
        if ($point['time_epoch'] === null || !empty($point['seg_start'])) {
            $prev = $point['time_epoch'] === null ? null : $point;
            continue;
        }

        if ($prev !== null) {
            $dt = $point['time_epoch'] - $prev['time_epoch'];

            if ($dt <= 0) {
                // Tid går ikke fremad mellem to på hinanden følgende
                // punkter — umuligt, og dermed en plausibilitetsfejl i
                // sig selv, uanset afstand.
                $violation = true;
            } else {
                $distanceMeters = gpx_haversine_meters($prev['lat'], $prev['lon'], $point['lat'], $point['lon']);
                $speedKmh = ($distanceMeters / $dt) * 3.6;

                if ($speedKmh > $maxFoundKmh) {
                    $maxFoundKmh = $speedKmh;
                }
                if ($speedKmh > $maxSpeedKmh) {
                    $violation = true;
                }
            }
        }

        $prev = $point;
    }

    return ['violation' => $violation, 'max_speed_kmh' => $maxFoundKmh];
}

/**
 * Fjern samtlige <time>-elementer fra en GPX-streng (bruges når
 * brugeren vælger at fjerne tidsstempler efter en plausibilitets-
 * advarsel). Alt andet i filen bevares uændret.
 */
function gpx_strip_all_times(string $xmlContent): string
{
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadXML($xmlContent, LIBXML_NONET);
    libxml_clear_errors();

    // Kopiér listen først — getElementsByTagName() er "live" og opdateres,
    // hvis vi fjerner noder, mens vi itererer direkte over den.
    $timeNodes = iterator_to_array($dom->getElementsByTagName('time'));

    foreach ($timeNodes as $timeNode) {
        $timeNode->parentNode->removeChild($timeNode);
    }

    return $dom->saveXML();
}

/**
 * Gem GPX-indhold som fil under brugerens undermappe.
 * Filnavnet er tilfældigt genereret — ALDRIG afledt af brugerinput —
 * for at undgå path traversal og navnekollisioner.
 *
 * @return array{relative_path: string, full_path: string, size_bytes: int}
 */
function gpx_store_file(int $userId, string $xmlContent): array
{
    $userDir = DATA_BASE_PATH . '/' . $userId;

    if (!is_dir($userDir)) {
        if (!mkdir($userDir, 0750, true) && !is_dir($userDir)) {
            throw new RuntimeException('Kunne ikke oprette brugerens datamappe.');
        }
    }

    $filename = bin2hex(random_bytes(16)) . '.gpx';
    $fullPath = $userDir . '/' . $filename;

    $bytesWritten = file_put_contents($fullPath, $xmlContent, LOCK_EX);
    if ($bytesWritten === false) {
        throw new RuntimeException('Kunne ikke skrive GPX-filen til disk.');
    }
    chmod($fullPath, 0640);

    return [
        'relative_path' => $userId . '/' . $filename,
        'full_path'     => $fullPath,
        'size_bytes'    => $bytesWritten,
    ];
}

/**
 * Byg den fulde sti til en gemt GPX-fil ud fra dens relative sti
 * (som gemt i routes.gpx_file_path).
 */
function gpx_full_path(string $relativePath): string
{
    return DATA_BASE_PATH . '/' . $relativePath;
}

/**
 * Genererer et tilfældigt, ikke-fortløbende 8-cifret public_id til en
 * ny rute, og sikrer at det ikke allerede er i brug. Bruges i URL'er
 * i stedet for rutens interne id, så besøgende ikke kan gætte/tælle
 * sig frem til andre ruters ID'er.
 */
function gpx_generate_unique_public_id(PDO $pdo): string
{
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $candidate = str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);

        $stmt = $pdo->prepare('SELECT 1 FROM routes WHERE public_id = :id LIMIT 1');
        $stmt->execute(['id' => $candidate]);
        if (!$stmt->fetch()) {
            return $candidate;
        }
    }

    throw new RuntimeException('Kunne ikke generere et unikt public_id.');
}
