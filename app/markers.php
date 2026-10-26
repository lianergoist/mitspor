<?php
declare(strict_types=1);

/*
 * Markørbiblioteket: de markører, en rute kan vælge i stedet for
 * aktivitetens standardmarkør (routes.marker_icon, NULL = standard).
 *
 * En markør er enten to billeder i public/markers/, <navn>1_120x120.png
 * (vender mod øst) og <navn>2_120x120.png (vender mod vest), eller ét billede,
 * <navn>_120x120.png. Et enkelt billede til en aktivitet skal vende mod øst;
 * mod vest vises det spejlvendt. Et enkelt neutralt billede (fx en nål)
 * bruges uændret i begge retninger.
 *
 * Hver markør hører til en aktivitet (activity_types.icon_filename) eller er
 * neutral (null). I markørvalget vises kun den valgte aktivitets markører og
 * de neutrale. En ny variant tilføjes ved at lægge filen i mappen og skrive
 * den på listen her.
 */
const MARKER_SETS = [
    // nøgle (filnavn uden _120x120.png) => [navn, aktivitet eller null]
    'hiker'            => ['Vandrer', 'hiker'],
    'hiker1_v2'        => ['Vandrer v2', 'hiker'],
    'hiker_retro'      => ['Vandrer retro', 'hiker'],
    'runner'           => ['Løber', 'runner'],
    'runner1_v2'       => ['Løber v2', 'runner'],
    'runner_retro'     => ['Løber retro', 'runner'],
    'racebike'         => ['Racercykel', 'racebike'],
    'racebike1_v2'     => ['Racercykel v2', 'racebike'],
    'racebike_retro'   => ['Racercykel retro', 'racebike'],
    'tourbike'         => ['Turcykel', 'tourbike'],
    'tourbike1_v2'     => ['Turcykel v2', 'tourbike'],
    'tourbike_retro'   => ['Turcykel retro', 'tourbike'],
    'kajak'            => ['Kajak', 'kajak'],
    'kajak1_v2'        => ['Kajak v2', 'kajak'],
    'kajak_retro'      => ['Kajak retro', 'kajak'],
    'kano'             => ['Kano', 'kano'],
    'kano1_v2'         => ['Kano v2', 'kano'],
    'kano_retro'       => ['Kano retro', 'kano'],
    'sup'              => ['SUP', 'sup'],
    'sup1_v2'          => ['SUP v2', 'sup'],
    'sup_retro'        => ['SUP retro', 'sup'],
    'ship'             => ['Skib', 'ship'],
    'ship1_v2'         => ['Skib v2', 'ship'],
    'ship_retro'       => ['Skib retro', 'ship'],
    'neutral_blaa'     => ['Blå', null],
    'neutral_groen'    => ['Grøn', null],
    'neutral_roed'     => ['Rød', null],
    'neutral_turkis'   => ['Turkis', null],
];

/**
 * Billederne til en markør (fra MARKER_SETS eller activity_types.icon_filename),
 * som stier relative til public/. Null, hvis der ikke findes billeder.
 * mirror = true: west er samme billede som east og skal vises spejlvendt.
 *
 * @return array{east: string, west: string, mirror: bool}|null
 */
function marker_image_urls(?string $marker): ?array
{
    if ($marker === null || !preg_match('/^[a-z0-9_]+$/i', $marker)) {
        return null;
    }
    $dir = __DIR__ . '/../public/markers/';
    if (is_file($dir . $marker . '1_120x120.png')) {
        return [
            'east'   => 'markers/' . $marker . '1_120x120.png',
            'west'   => 'markers/' . $marker . '2_120x120.png',
            'mirror' => false,
        ];
    }
    if (is_file($dir . $marker . '_120x120.png')) {
        $single = 'markers/' . $marker . '_120x120.png';
        $neutral = isset(MARKER_SETS[$marker]) && MARKER_SETS[$marker][1] === null;
        return ['east' => $single, 'west' => $single, 'mirror' => !$neutral];
    }
    return null;
}

/**
 * Er værdien et gyldigt valg? '' og null betyder "aktivitetens standard".
 */
function marker_is_valid(?string $marker): bool
{
    return $marker === null || $marker === '' || isset(MARKER_SETS[$marker]);
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
