<?php
declare(strict_types=1);

/*
 * Markørbiblioteket: de markørsæt, en rute kan vælge i stedet for
 * aktivitetens standardmarkør (routes.marker_icon, NULL = standard).
 *
 * En markør er enten to billeder i public/markers/, <navn>1_120x120.png
 * (vender mod øst) og <navn>2_120x120.png (vender mod vest), eller ét billede,
 * <navn>_120x120.png, der bruges i begge retninger (fx en nål). En ny markør
 * tilføjes ved at lægge filen/filerne i mappen og skrive navnet på listen her.
 */
const MARKER_SETS = [
    'hiker'    => 'Vandrer',
    'runner'   => 'Løber',
    'racebike' => 'Racercykel',
    'tourbike' => 'Turcykel',
    'kajak'    => 'Kajak',
    'kano'     => 'Kano',
    'sup'      => 'SUP',
    'ship'     => 'Skib',
    'neutral_blaa'   => 'Blå',
    'neutral_groen'  => 'Grøn',
    'neutral_roed'   => 'Rød',
    'neutral_turkis' => 'Turkis',
];

/**
 * Billederne til en markør (fra MARKER_SETS eller activity_types.icon_filename),
 * som stier relative til public/. Null, hvis der ikke findes billeder.
 *
 * @return array{east: string, west: string}|null
 */
function marker_image_urls(?string $marker): ?array
{
    if ($marker === null || !preg_match('/^[a-z0-9_]+$/i', $marker)) {
        return null;
    }
    $dir = __DIR__ . '/../public/markers/';
    if (is_file($dir . $marker . '1_120x120.png')) {
        return [
            'east' => 'markers/' . $marker . '1_120x120.png',
            'west' => 'markers/' . $marker . '2_120x120.png',
        ];
    }
    if (is_file($dir . $marker . '_120x120.png')) {
        $single = 'markers/' . $marker . '_120x120.png';
        return ['east' => $single, 'west' => $single];
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
