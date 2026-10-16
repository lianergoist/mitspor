<?php
declare(strict_types=1);

/*
 * Markørbiblioteket: de markørsæt, en rute kan vælge i stedet for
 * aktivitetens standardmarkør (routes.marker_icon, NULL = standard).
 *
 * Et sæt er to billeder i public/markers/: <navn>1_120x120.png (vender mod
 * øst) og <navn>2_120x120.png (vender mod vest). Et nyt sæt tilføjes ved at
 * lægge de to filer i mappen og skrive navnet på listen her.
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
];

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
