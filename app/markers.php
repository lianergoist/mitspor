<?php
declare(strict_types=1);

/*
 * Markørbiblioteket: de markører, en rute kan vælge i stedet for
 * aktivitetens standardmarkør (routes.marker_icon, NULL = standard).
 *
 * Markørerne læses fra mapperne i public/markers/, så en ny markør tilføjes
 * blot ved at lægge billedet i den rette mappe. Koden skal ikke ændres.
 *
 *   public/markers/<aktivitet>/   en mappe pr. aktivitet (activity_types.icon_filename)
 *   public/markers/neutral/       markører, der vises for alle aktiviteter
 *
 * Billederne er PNG med gennemsigtig baggrund, ca. 120×120 px (de vises i
 * halv størrelse). Filnavne må kun indeholde a-z, 0-9, _ og -. En markør er:
 *   - ét billede, <navn>.png, der vender mod øst. Mod vest vises det
 *     spejlvendt, undtagen i neutral/, hvor det bruges uændret (fx en nål).
 *   - eller et par, <navn>_east.png og <navn>_west.png.
 * Markøren <aktivitet>/standard er aktivitetens standardmarkør. Den vises
 * som "Standard" i markørvalget.
 *
 * Navnet i markørvalget laves ud fra filnavnet (retro -> Retro). Andre navne,
 * fx med æ, ø og å, kan skrives i en valgfri navne.json i mappen:
 * {"groen": "Grøn"}.
 *
 * I databasen gemmes markøren som <mappe>/<navn>, fx sup/retro. Fjernes et
 * billede, vises aktivitetens standardmarkør i stedet.
 */
const MARKER_DIR = __DIR__ . '/../public/markers/';
const MARKER_NEUTRAL = 'neutral';
const MARKER_STANDARD = 'standard';
const MARKER_NAME_PATTERN = '[a-z0-9_-]+';

/**
 * Alle markører, man kan vælge, undtagen standardmarkørerne.
 * Aktivitetens markører først (sorteret efter navn), de neutrale til sidst.
 *
 * @return array<string, array{name: string, activity: ?string}>  nøgle = <mappe>/<navn>
 */
function marker_library(): array
{
    static $library = null;
    if ($library !== null) {
        return $library;
    }

    $activities = [];
    $neutral = [];
    foreach (glob(MARKER_DIR . '*', GLOB_ONLYDIR) ?: [] as $dirPath) {
        $folder = basename($dirPath);
        if (!preg_match('/^' . MARKER_NAME_PATTERN . '$/', $folder)) {
            continue;
        }
        $names = marker_folder_names($dirPath);
        $markers = [];
        foreach (glob($dirPath . '/*.png') ?: [] as $file) {
            $base = basename($file, '.png');
            if (str_ends_with($base, '_west')) {
                continue; // hører til et _east-billede
            }
            if (str_ends_with($base, '_east')) {
                $base = substr($base, 0, -5);
                if (!is_file($dirPath . '/' . $base . '_west.png')) {
                    continue; // et par mangler sin vestvendte halvdel
                }
            }
            if ($base === MARKER_STANDARD || !preg_match('/^' . MARKER_NAME_PATTERN . '$/', $base)) {
                continue;
            }
            $markers[$folder . '/' . $base] = [
                'name'     => $names[$base] ?? mb_convert_case(str_replace(['_', '-'], ' ', $base), MB_CASE_TITLE, 'UTF-8'),
                'activity' => $folder === MARKER_NEUTRAL ? null : $folder,
            ];
        }
        uasort($markers, fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
        if ($folder === MARKER_NEUTRAL) {
            $neutral = $markers;
        } else {
            $activities += $markers;
        }
    }

    return $library = $activities + $neutral;
}

/**
 * Visningsnavne fra mappens valgfri navne.json ({"filnavn": "Navn"}).
 *
 * @return array<string, string>
 */
function marker_folder_names(string $dirPath): array
{
    $file = $dirPath . '/navne.json';
    if (!is_file($file)) {
        return [];
    }
    $data = json_decode((string) file_get_contents($file), true);
    if (!is_array($data)) {
        return [];
    }
    return array_filter($data, fn($name, $key): bool => is_string($key) && is_string($name), ARRAY_FILTER_USE_BOTH);
}

/**
 * Billederne til en markør som stier relative til public/. Værdien er enten
 * en markør (<mappe>/<navn>, fra routes.marker_icon) eller en aktivitet
 * (activity_types.icon_filename), som giver <aktivitet>/standard.
 * Null, hvis der ikke findes billeder.
 * mirror = true: west er samme billede som east og skal vises spejlvendt.
 *
 * @return array{east: string, west: string, mirror: bool}|null
 */
function marker_image_urls(?string $marker): ?array
{
    if ($marker === null) {
        return null;
    }
    if (preg_match('/^' . MARKER_NAME_PATTERN . '$/', $marker)) {
        $marker .= '/' . MARKER_STANDARD;
    }
    if (!preg_match('#^(' . MARKER_NAME_PATTERN . ')/(' . MARKER_NAME_PATTERN . ')$#', $marker, $m)) {
        return null;
    }
    [, $folder, $name] = $m;
    $path = $folder . '/' . $name;
    if (is_file(MARKER_DIR . $path . '_east.png') && is_file(MARKER_DIR . $path . '_west.png')) {
        return [
            'east'   => 'markers/' . $path . '_east.png',
            'west'   => 'markers/' . $path . '_west.png',
            'mirror' => false,
        ];
    }
    if (is_file(MARKER_DIR . $path . '.png')) {
        $single = 'markers/' . $path . '.png';
        return ['east' => $single, 'west' => $single, 'mirror' => $folder !== MARKER_NEUTRAL];
    }
    return null;
}

/**
 * Er værdien et gyldigt valg? '' og null betyder "aktivitetens standard".
 * Ellers skal det være en markør (<mappe>/<navn>), der findes. En
 * aktivitets standardmarkør (fx kajak/standard) kan også vælges, så en
 * rute kan beholde en anden aktivitets markør.
 */
function marker_is_valid(?string $marker): bool
{
    if ($marker === null || $marker === '') {
        return true;
    }
    return str_contains($marker, '/') && strlen($marker) <= 50 && marker_image_urls($marker) !== null;
}

/**
 * Gemmer rutens markør. '' eller null = aktivitetens standard (NULL).
 * Kalderen skal have tjekket værdien med marker_is_valid() og ejerskabet.
 */
function marker_save(PDO $pdo, int $routeId, int $userId, ?string $marker): void
{
    $stmt = $pdo->prepare('UPDATE routes SET marker_icon = :marker WHERE id = :id AND user_id = :user_id');
    $stmt->execute([
        'marker'  => ($marker === null || $marker === '') ? null : $marker,
        'id'      => $routeId,
        'user_id' => $userId,
    ]);
}
