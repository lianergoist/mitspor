<?php
declare(strict_types=1);

require_once __DIR__ . '/gpx.php';
require_once __DIR__ . '/image_links.php';
require_once __DIR__ . '/route_groups.php';
require_once __DIR__ . '/route_file.php';

/*
 * Download af en hel gruppe som én GPX-fil: én <trk> (eller <rte>) pr.
 * etape med etapens titel som navn, og waypoints fra alle etaper.
 *
 * Adgang: ejeren får alle etaper. Andre ser kun de offentlige etaper og får
 * kun dem, der må downloades (allow_download). De øvrige udelades, og siden
 * fortæller hvor mange. Private etaper tælles ikke med for andre end ejeren,
 * så antallet ikke afslører dem.
 *
 * Billedlinks følger samme regel som route_file_send(): ejeren beholder sine
 * egne, andre får ingen.
 */

const GPX_NS_11 = 'http://www.topografix.com/GPX/1/1';

/**
 * Hvilke af gruppens etaper må den besøgende downloade?
 *
 * @param list<array> $members de etaper, den besøgende kan se:
 *                             group_members($pdo, $groupId, $isOwner)
 * @return array{included: list<array>, skipped: int} included: dem, der
 *         kommer med i filen; skipped: synlige etaper, der ikke kommer med
 */
function group_download_plan(array $members, bool $isOwner): array
{
    // Etapenummeret er pladsen i gruppen (som på gruppesiden), også når
    // nogle etaper udelades.
    $included = [];
    foreach (array_values($members) as $i => $m) {
        if ($isOwner || (int) $m['allow_download'] === 1) {
            $included[] = $m + ['stage_number' => $i + 1];
        }
    }

    return [
        'included' => $included,
        'skipped'  => count($members) - count($included),
    ];
}

/**
 * Kopierer et element fra en etapes GPX-fil ind i den samlede fil. Elementer
 * i GPX-navnerummet (1.0 eller 1.1) flyttes til GPX 1.1, så hele filen er
 * GPX 1.1. Andre navnerum (fx Garmins <extensions>) bevares, som de er.
 */
function group_file_copy_node(DOMDocument $out, DOMNode $node): ?DOMNode
{
    if ($node instanceof DOMText || $node instanceof DOMCdataSection) {
        return $out->createTextNode($node->nodeValue ?? '');
    }
    if (!$node instanceof DOMElement) {
        return null; // kommentarer og processing instructions springes over
    }

    $ns = (string) $node->namespaceURI;
    if ($ns === '' || str_starts_with($ns, 'http://www.topografix.com/GPX/1/')) {
        $copy = $out->createElementNS(GPX_NS_11, $node->localName);
        foreach ($node->attributes as $attr) {
            if ($attr->namespaceURI === null || $attr->namespaceURI === '') {
                $copy->setAttribute($attr->name, $attr->value);
            }
        }
        foreach ($node->childNodes as $child) {
            $childCopy = group_file_copy_node($out, $child);
            if ($childCopy !== null) {
                $copy->appendChild($childCopy);
            }
        }
        return $copy;
    }

    return $out->importNode($node, true);
}

/**
 * Sætter <name> på en <trk>/<rte> (første barn, som GPX-skemaet kræver).
 */
function group_file_set_name(DOMDocument $out, DOMElement $el, string $name): void
{
    foreach ($el->childNodes as $child) {
        if ($child instanceof DOMElement && $child->localName === 'name') {
            $el->removeChild($child);
            break;
        }
    }
    $nameEl = $out->createElementNS(GPX_NS_11, 'name');
    $nameEl->appendChild($out->createTextNode($name));
    $el->insertBefore($nameEl, $el->firstChild);
}

/**
 * Bygger den samlede GPX-fil for de medtagne etaper.
 *
 * @return string|null GPX-indholdet, eller null hvis en etapes fil ikke kunne læses
 */
function group_file_build(array $group, array $included, bool $isOwner): ?string
{
    $out = new DOMDocument('1.0', 'UTF-8');
    $out->formatOutput = false;
    $gpx = $out->createElementNS(GPX_NS_11, 'gpx');
    $gpx->setAttribute('version', '1.1');
    $gpx->setAttribute('creator', 'MitSpor');
    $out->appendChild($gpx);

    $metadata = $out->createElementNS(GPX_NS_11, 'metadata');
    $metadata->appendChild($out->createElementNS(GPX_NS_11, 'name'))
        ->appendChild($out->createTextNode((string) $group['title']));
    if (trim((string) ($group['description'] ?? '')) !== '') {
        $metadata->appendChild($out->createElementNS(GPX_NS_11, 'desc'))
            ->appendChild($out->createTextNode((string) $group['description']));
    }
    $gpx->appendChild($metadata);

    // GPX-skemaets rækkefølge: alle <wpt>, så alle <rte>, så alle <trk>.
    $wpts = [];
    $rtes = [];
    $trks = [];
    $keepOwnerId = $isOwner ? (int) $group['user_id'] : null;

    foreach ($included as $member) {
        $raw = @file_get_contents(gpx_full_path((string) $member['gpx_file_path']));
        $clean = $raw === false ? null : gpx_remove_image_links($raw, $keepOwnerId);
        if ($clean === null) {
            error_log('Gruppe-download: kunne ikke læse etape ' . $member['public_id']);
            return null;
        }
        // Etaper markeret som rute downloades uden tidsstempler (som route_file_send()).
        if ($member['route_type'] === 'rute') {
            $clean = gpx_strip_all_times($clean);
        }

        $doc = new DOMDocument();
        if (!@$doc->loadXML($clean, LIBXML_NONET | LIBXML_PARSEHUGE)) {
            error_log('Gruppe-download: ugyldig GPX i etape ' . $member['public_id']);
            return null;
        }

        $stageName = $member['stage_number'] . '. ' . $member['title'];
        $lines = [];
        foreach ($doc->documentElement->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }
            if ($child->localName === 'wpt') {
                $wpts[] = group_file_copy_node($out, $child);
            } elseif ($child->localName === 'rte' || $child->localName === 'trk') {
                $lines[] = group_file_copy_node($out, $child);
            }
        }

        // Har en etape flere spor eller ruter, nummereres de inden for etapen.
        foreach ($lines as $n => $line) {
            group_file_set_name($out, $line, count($lines) > 1 ? $stageName . ' (del ' . ($n + 1) . ')' : $stageName);
            if ($line->localName === 'rte') {
                $rtes[] = $line;
            } else {
                $trks[] = $line;
            }
        }
    }

    foreach (array_merge($wpts, $rtes, $trks) as $node) {
        $gpx->appendChild($node);
    }

    $xml = $out->saveXML();

    return $xml === false ? null : $xml;
}

/**
 * Find gruppen og tjek adgangen. Bruges af group-file.php (sitet, med
 * session) og api/v1/group-file.php (appen, med token), så reglerne kun
 * står ét sted.
 *
 * @param int|null $viewerId den indloggede bruger (session eller token), ellers null
 * @return array{status: int, group: ?array, included: list<array>, is_owner: bool}
 *         status 200, ellers 404 (findes ikke / intet synligt) eller 403
 *         (ingen etaper må downloades)
 */
function group_file_find(PDO $pdo, string $publicId, ?int $viewerId): array
{
    $none = ['status' => 404, 'group' => null, 'included' => [], 'is_owner' => false];

    $group = group_find($pdo, $publicId);
    if ($group === null) {
        return $none;
    }
    $isOwner = $viewerId !== null && $viewerId === (int) $group['user_id'];

    // Samme regel som group-view.php: en gruppe uden synlige etaper findes
    // ikke for andre end ejeren.
    $members = group_members($pdo, (int) $group['id'], $isOwner);
    if ($members === []) {
        return $none;
    }

    $plan = group_download_plan($members, $isOwner);
    if ($plan['included'] === []) {
        return ['status' => 403, 'group' => $group, 'included' => [], 'is_owner' => $isOwner];
    }

    return ['status' => 200, 'group' => $group, 'included' => $plan['included'], 'is_owner' => $isOwner];
}

/**
 * Byg og send den samlede GPX-fil som download, og afslut.
 * Returnerer false (uden at sende noget), hvis filen ikke kunne bygges.
 */
function group_file_send(array $group, array $included, bool $isOwner): bool
{
    $content = group_file_build($group, $included, $isOwner);
    if ($content === null) {
        return false;
    }

    header('Content-Type: application/gpx+xml; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header(route_file_attachment_header(route_file_download_name($group)));
    echo $content;
    exit;
}
