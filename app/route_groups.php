<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/gpx.php';
require_once __DIR__ . '/og_image.php';

/**
 * Grupper af ruter — fx en 14-dages tur delt i 14 etaper.
 *
 * Model:
 *  - route_groups: titel + beskrivelse, ejet af én bruger.
 *  - routes.group_id / routes.group_position: hvilken gruppe en rute
 *    (etape) hører til, og dens rækkefølge (1, 2, 3 ...).
 *  - SYNLIGHED er stadig pr. rute. En gruppe har ingen egen synlighed:
 *    besøgende ser kun de etaper, der er offentlige. Har en gruppe
 *    ingen offentlige etaper, kan kun ejeren se den.
 *  - Hver etape tæller som én rute i route_quota (uændret).
 *
 * Alle funktioner, der ændrer noget, tager et bruger-id og tjekker
 * ejerskab af både gruppe og ruter — kalderne behøver ikke gøre det.
 */

const GROUP_TITLE_MIN = 2;
const GROUP_TITLE_MAX = 200;

// ---------------------------------------------------------------------
// Opslag
// ---------------------------------------------------------------------

function group_generate_public_id(PDO $pdo): string
{
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $candidate = str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
        $stmt = $pdo->prepare('SELECT 1 FROM route_groups WHERE public_id = :id LIMIT 1');
        $stmt->execute(['id' => $candidate]);
        if (!$stmt->fetch()) {
            return $candidate;
        }
    }
    throw new RuntimeException('Kunne ikke generere et unikt gruppe-id.');
}

function group_valid_public_id(string $publicId): bool
{
    return (bool) preg_match('/^[0-9]{8}$/', $publicId);
}

/** Gruppen med dette offentlige id (uanset ejer), eller null. */
function group_find(PDO $pdo, string $publicId): ?array
{
    if (!group_valid_public_id($publicId)) {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT g.id, g.public_id, g.user_id, g.title, g.description, g.created_at, u.display_name AS uploader_name
         FROM route_groups g
         JOIN users u ON u.id = g.user_id
         WHERE g.public_id = :public_id
         LIMIT 1'
    );
    $stmt->execute(['public_id' => $publicId]);
    $row = $stmt->fetch();

    return $row ?: null;
}

/** Gruppen, hvis den ejes af $userId — ellers null. */
function group_find_owned(PDO $pdo, int $userId, string $publicId): ?array
{
    $group = group_find($pdo, $publicId);

    return ($group !== null && (int) $group['user_id'] === $userId) ? $group : null;
}

/**
 * Brugerens grupper til kontrolpanelet, med antal etaper, samlet distance
 * og hvor mange af etaperne der er offentlige.
 */
function group_list_for_user(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare(
        "SELECT g.id, g.public_id, g.title, g.description,
                COUNT(r.id) AS stage_count,
                COALESCE(SUM(r.distance_meters), 0) AS distance_meters,
                COALESCE(SUM(r.visibility = 'public'), 0) AS public_count
         FROM route_groups g
         LEFT JOIN routes r ON r.group_id = g.id AND r.status = 'active'
         WHERE g.user_id = :uid
         GROUP BY g.id
         ORDER BY g.title ASC"
    );
    $stmt->execute(['uid' => $userId]);

    return $stmt->fetchAll();
}

/**
 * Gruppens aktive etaper i rækkefølge.
 *
 * @param bool $includePrivate true for ejeren; ellers kun offentlige etaper
 */
function group_members(PDO $pdo, int $groupId, bool $includePrivate): array
{
    $sql = "SELECT r.id, r.public_id, r.title, r.visibility, r.group_position,
                   r.distance_meters, r.elevation_gain_meters, r.has_time_data,
                   r.hide_uploader_name, r.allow_download, r.updated_at, r.gpx_file_path,
                   r.start_lat, r.start_lon,
                   a.name_da AS activity_name,
                   COALESCE(r.marker_icon, a.icon_filename) AS activity_icon, a.category
            FROM routes r
            JOIN activity_types a ON a.id = r.activity_type_id
            WHERE r.group_id = :gid AND r.status = 'active'"
        . ($includePrivate ? '' : " AND r.visibility = 'public'")
        . ' ORDER BY r.group_position ASC, r.id ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['gid' => $groupId]);

    return $stmt->fetchAll();
}

/** Samlede tal for en liste af etaper (fra group_members()). */
function group_totals(array $members): array
{
    $distance = 0;
    $gain = 0;
    foreach ($members as $m) {
        $distance += (int) $m['distance_meters'];
        $gain += (int) $m['elevation_gain_meters'];
    }

    return ['stages' => count($members), 'distance_meters' => $distance, 'elevation_gain_meters' => $gain];
}

/**
 * Kontekst til route-view.php: hvilken gruppe ruten hører til, hvilket
 * nummer den har blandt de etaper, den besøgende MÅ se, og forrige/næste.
 * Etaper, den besøgende ikke må se (private), springes over. En rute,
 * der åbnes via et delelink, indgår selv i listen, også selvom den er privat.
 *
 * @return array{group: array, index: int, total: int, prev: ?array, next: ?array}|null
 */
function group_context_for_route(PDO $pdo, int $groupId, int $routeId, bool $isOwner): ?array
{
    $stmt = $pdo->prepare('SELECT id, public_id, title FROM route_groups WHERE id = :id');
    $stmt->execute(['id' => $groupId]);
    $group = $stmt->fetch();
    if (!$group) {
        return null;
    }

    $stmt = $pdo->prepare(
        "SELECT id, public_id, title
         FROM routes
         WHERE group_id = :gid AND status = 'active'
           AND (visibility = 'public' OR id = :rid OR :owner = 1)
         ORDER BY group_position ASC, id ASC"
    );
    $stmt->execute(['gid' => $groupId, 'rid' => $routeId, 'owner' => $isOwner ? 1 : 0]);
    $stages = $stmt->fetchAll();

    $pos = null;
    foreach ($stages as $i => $s) {
        if ((int) $s['id'] === $routeId) {
            $pos = $i;
            break;
        }
    }
    if ($pos === null) {
        return null;
    }

    return [
        'group' => $group,
        'index' => $pos + 1,
        'total' => count($stages),
        'prev'  => $pos > 0 ? $stages[$pos - 1] : null,
        'next'  => $pos < count($stages) - 1 ? $stages[$pos + 1] : null,
    ];
}

// ---------------------------------------------------------------------
// Oprettelse og redigering
// ---------------------------------------------------------------------

function group_validate_text(string $title, string $description): array
{
    $errors = [];
    $len = mb_strlen(trim($title));
    if ($len < GROUP_TITLE_MIN || $len > GROUP_TITLE_MAX) {
        $errors[] = 'Gruppens titel skal være mellem ' . GROUP_TITLE_MIN . ' og ' . GROUP_TITLE_MAX . ' tegn.';
    }
    if (mb_strlen($description) > 5000) {
        $errors[] = 'Beskrivelsen er for lang (højst 5000 tegn).';
    }

    return $errors;
}

/** @return array{success: bool, errors?: string[], group_id?: int, public_id?: string} */
function group_create(PDO $pdo, int $userId, string $title, string $description = ''): array
{
    $title = trim($title);
    $description = trim($description);
    $errors = group_validate_text($title, $description);
    if ($errors) {
        return ['success' => false, 'errors' => $errors];
    }

    $publicId = group_generate_public_id($pdo);
    $stmt = $pdo->prepare(
        'INSERT INTO route_groups (public_id, user_id, title, description) VALUES (:pid, :uid, :title, :descr)'
    );
    $stmt->execute([
        'pid'   => $publicId,
        'uid'   => $userId,
        'title' => $title,
        'descr' => $description !== '' ? $description : null,
    ]);

    return ['success' => true, 'group_id' => (int) $pdo->lastInsertId(), 'public_id' => $publicId];
}

/** @return array{success: bool, errors?: string[]} */
function group_update_text(PDO $pdo, int $userId, string $groupPublicId, string $title, string $description): array
{
    $group = group_find_owned($pdo, $userId, $groupPublicId);
    if ($group === null) {
        return ['success' => false, 'errors' => ['Gruppen blev ikke fundet, eller tilhører dig ikke.']];
    }
    $title = trim($title);
    $description = trim($description);
    $errors = group_validate_text($title, $description);
    if ($errors) {
        return ['success' => false, 'errors' => $errors];
    }
    $stmt = $pdo->prepare('UPDATE route_groups SET title = :title, description = :descr WHERE id = :id');
    $stmt->execute(['title' => $title, 'descr' => $description !== '' ? $description : null, 'id' => $group['id']]);
    og_refresh_group($pdo, (string) $group['public_id']);

    return ['success' => true];
}

/**
 * Næste ledige placering (sidst i gruppen).
 */
function group_next_position(PDO $pdo, int $groupId): int
{
    $stmt = $pdo->prepare('SELECT COALESCE(MAX(group_position), 0) + 1 FROM routes WHERE group_id = :gid');
    $stmt->execute(['gid' => $groupId]);

    return (int) $stmt->fetchColumn();
}

/** Nummerér gruppens aktive etaper 1..n i den nuværende rækkefølge. */
function group_renumber(PDO $pdo, int $groupId): void
{
    $stmt = $pdo->prepare(
        "SELECT id FROM routes WHERE group_id = :gid AND status = 'active' ORDER BY group_position ASC, id ASC"
    );
    $stmt->execute(['gid' => $groupId]);
    $upd = $pdo->prepare('UPDATE routes SET group_position = :pos, updated_at = updated_at WHERE id = :id');
    $pos = 1;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $routeId) {
        $upd->execute(['pos' => $pos++, 'id' => $routeId]);
    }
}

/**
 * Sæt (eller fjern) en rutes gruppe. Ruten lægges sidst i den nye gruppe.
 * Er den gamle gruppe herefter tom, slettes den. $groupId = null fjerner
 * ruten fra sin gruppe.
 *
 * updated_at røres bevidst ikke: at flytte en rute mellem grupper ændrer
 * hverken selve ruten eller dens delingsbillede.
 *
 * @return array{success: bool, errors?: string[]}
 */
function group_assign_route(PDO $pdo, int $userId, int $routeId, ?int $groupId): array
{
    $stmt = $pdo->prepare(
        "SELECT id, group_id FROM routes WHERE id = :id AND user_id = :uid AND status = 'active' LIMIT 1"
    );
    $stmt->execute(['id' => $routeId, 'uid' => $userId]);
    $route = $stmt->fetch();
    if (!$route) {
        return ['success' => false, 'errors' => ['Ruten blev ikke fundet, eller tilhører dig ikke.']];
    }

    $oldGroupId = $route['group_id'] !== null ? (int) $route['group_id'] : null;
    if ($oldGroupId === $groupId) {
        return ['success' => true];
    }

    if ($groupId !== null) {
        $stmt = $pdo->prepare('SELECT id FROM route_groups WHERE id = :id AND user_id = :uid');
        $stmt->execute(['id' => $groupId, 'uid' => $userId]);
        if (!$stmt->fetch()) {
            return ['success' => false, 'errors' => ['Gruppen blev ikke fundet, eller tilhører dig ikke.']];
        }
    }

    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    try {
        $position = $groupId !== null ? group_next_position($pdo, $groupId) : null;
        $stmt = $pdo->prepare(
            'UPDATE routes SET group_id = :gid, group_position = :pos, updated_at = updated_at WHERE id = :id'
        );
        $stmt->execute(['gid' => $groupId, 'pos' => $position, 'id' => $routeId]);

        if ($oldGroupId !== null) {
            group_renumber($pdo, $oldGroupId);
            group_delete_if_empty($pdo, $oldGroupId);
        }
        if ($own) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Gruppering fejlede: ' . $e->getMessage());

        return ['success' => false, 'errors' => ['Der skete en fejl. Prøv igen.']];
    }

    if ($oldGroupId !== null) {
        group_invalidate_cache_by_id($pdo, $oldGroupId);
    }
    if ($groupId !== null) {
        group_invalidate_cache_by_id($pdo, $groupId);
    }

    return ['success' => true];
}

/**
 * Som group_assign_route(), men med de OFFENTLIGE id'er brugerfladen kender.
 * $groupPublicId = '' fjerner ruten fra dens gruppe.
 *
 * @return array{success: bool, errors?: string[]}
 */
function group_assign_route_by_public_ids(PDO $pdo, int $userId, string $routePublicId, string $groupPublicId): array
{
    $stmt = $pdo->prepare("SELECT id FROM routes WHERE public_id = :pid AND user_id = :uid AND status = 'active' LIMIT 1");
    $stmt->execute(['pid' => $routePublicId, 'uid' => $userId]);
    $routeId = $stmt->fetchColumn();
    if ($routeId === false) {
        return ['success' => false, 'errors' => ['Ruten blev ikke fundet, eller tilhører dig ikke.']];
    }

    $groupId = null;
    if ($groupPublicId !== '') {
        $group = group_find_owned($pdo, $userId, $groupPublicId);
        if ($group === null) {
            return ['success' => false, 'errors' => ['Gruppen blev ikke fundet, eller tilhører dig ikke.']];
        }
        $groupId = (int) $group['id'];
    }

    return group_assign_route($pdo, $userId, (int) $routeId, $groupId);
}

/**
 * Tilføj flere ruter til en gruppe i den givne rækkefølge (offentlige
 * rute-id'er), sidst i gruppen. Ruter, der allerede er i gruppen, bliver
 * stående, hvor de er — brug group_reorder() til at omordne.
 *
 * @param string[] $routePublicIds
 * @return array{success: bool, errors?: string[]}
 */
function group_add_routes(PDO $pdo, int $userId, string $groupPublicId, array $routePublicIds): array
{
    $group = group_find_owned($pdo, $userId, $groupPublicId);
    if ($group === null) {
        return ['success' => false, 'errors' => ['Gruppen blev ikke fundet, eller tilhører dig ikke.']];
    }

    $ids = [];
    foreach ($routePublicIds as $pid) {
        $stmt = $pdo->prepare(
            "SELECT id FROM routes WHERE public_id = :pid AND user_id = :uid AND status = 'active' LIMIT 1"
        );
        $stmt->execute(['pid' => (string) $pid, 'uid' => $userId]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            return ['success' => false, 'errors' => ['En af ruterne blev ikke fundet, eller tilhører dig ikke.']];
        }
        $ids[] = (int) $id;
    }
    if (!$ids) {
        return ['success' => false, 'errors' => ['Vælg mindst én rute.']];
    }

    $pdo->beginTransaction();
    try {
        foreach ($ids as $routeId) {
            $result = group_assign_route($pdo, $userId, $routeId, (int) $group['id']);
            if (!$result['success']) {
                throw new RuntimeException(implode(' ', $result['errors'] ?? ['Fejl']));
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Gruppering fejlede: ' . $e->getMessage());

        return ['success' => false, 'errors' => ['Der skete en fejl. Prøv igen.']];
    }
    group_invalidate_cache_by_id($pdo, (int) $group['id']);

    return ['success' => true];
}

/**
 * Sæt etapernes rækkefølge. $orderedRoutePublicIds skal indeholde
 * PRÆCIS gruppens aktive etaper (ingen mangler, ingen fremmede).
 *
 * @param string[] $orderedRoutePublicIds
 * @return array{success: bool, errors?: string[]}
 */
function group_reorder(PDO $pdo, int $userId, string $groupPublicId, array $orderedRoutePublicIds): array
{
    $group = group_find_owned($pdo, $userId, $groupPublicId);
    if ($group === null) {
        return ['success' => false, 'errors' => ['Gruppen blev ikke fundet, eller tilhører dig ikke.']];
    }

    $stmt = $pdo->prepare("SELECT public_id, id FROM routes WHERE group_id = :gid AND status = 'active'");
    $stmt->execute(['gid' => $group['id']]);
    $current = $stmt->fetchAll(PDO::FETCH_KEY_PAIR); // public_id => id

    $given = array_values(array_map('strval', $orderedRoutePublicIds));
    $expected = array_map('strval', array_keys($current));
    sort($expected);
    $check = $given;
    sort($check);
    if ($check !== $expected) {
        return ['success' => false, 'errors' => ['Rækkefølgen passer ikke til gruppens etaper. Genindlæs siden.']];
    }

    $pdo->beginTransaction();
    try {
        $upd = $pdo->prepare('UPDATE routes SET group_position = :pos, updated_at = updated_at WHERE id = :id');
        foreach ($given as $i => $pid) {
            $upd->execute(['pos' => $i + 1, 'id' => $current[$pid]]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('Omordning fejlede: ' . $e->getMessage());

        return ['success' => false, 'errors' => ['Der skete en fejl. Prøv igen.']];
    }
    group_invalidate_cache_by_id($pdo, (int) $group['id']);

    return ['success' => true];
}

/**
 * Tidspunkt for rutens første punkt med tid (unix-sekunder), eller null.
 * Læser kun starten af filen.
 */
function gpx_first_time_epoch(string $path): ?int
{
    $reader = new XMLReader();
    if (!@$reader->open($path, null, LIBXML_NONET)) {
        return null;
    }
    $found = null;
    $points = 0;
    while (@$reader->read()) {
        if ($reader->nodeType !== XMLReader::ELEMENT) {
            continue;
        }
        $name = strtolower($reader->localName);
        if ($name !== 'trkpt' && $name !== 'rtept') {
            continue;
        }
        $xml = $reader->readOuterXml();
        if (preg_match('~<(?:\w+:)?time>\s*([^<\s][^<]*?)\s*</(?:\w+:)?time>~', $xml, $m)) {
            $t = strtotime($m[1]);
            if ($t !== false) {
                $found = $t;
                break;
            }
        }
        if (++$points >= 50) {
            break; // ingen tid i de første punkter — giv op
        }
    }
    $reader->close();

    return $found;
}

/**
 * Sortér gruppens etaper efter tidsstempel på deres første punkt.
 * Kræver, at ALLE etaper har tid; ellers ændres intet (og der returneres en
 * forklaring), så en delvist tidsstemplet gruppe ikke blandes tilfældigt.
 *
 * @return array{success: bool, errors?: string[]}
 */
function group_sort_by_time(PDO $pdo, int $userId, string $groupPublicId): array
{
    $group = group_find_owned($pdo, $userId, $groupPublicId);
    if ($group === null) {
        return ['success' => false, 'errors' => ['Gruppen blev ikke fundet, eller tilhører dig ikke.']];
    }

    $times = [];
    foreach (group_members($pdo, (int) $group['id'], true) as $m) {
        $full = gpx_full_path((string) $m['gpx_file_path']);
        $t = is_file($full) ? gpx_first_time_epoch($full) : null;
        if ($t === null) {
            return ['success' => false, 'errors' => ['Ikke alle etaper har tidsstempler, så de kan ikke sorteres automatisk. Træk dem på plads i stedet.']];
        }
        $times[$m['public_id']] = $t;
    }
    asort($times, SORT_NUMERIC);

    return group_reorder($pdo, $userId, $groupPublicId, array_map('strval', array_keys($times)));
}

/** Slet gruppen, hvis den ikke har flere aktive etaper. */
function group_delete_if_empty(PDO $pdo, int $groupId): void
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM routes WHERE group_id = :gid AND status = 'active'");
    $stmt->execute(['gid' => $groupId]);
    if ((int) $stmt->fetchColumn() === 0) {
        group_invalidate_cache_by_id($pdo, $groupId);
        // Tombesten-rækker (slettede ruter) skal ikke holde en tom gruppe i live.
        $pdo->prepare('UPDATE routes SET group_id = NULL, group_position = NULL WHERE group_id = :gid')
            ->execute(['gid' => $groupId]);
        $pdo->prepare('DELETE FROM route_groups WHERE id = :gid')->execute(['gid' => $groupId]);
    }
}

/**
 * Opløs gruppen: etaperne bliver stående som almindelige ruter, og selve
 * gruppen slettes. Ingen ruter slettes.
 *
 * @return array{success: bool, errors?: string[]}
 */
function group_dissolve(PDO $pdo, int $userId, string $groupPublicId): array
{
    $group = group_find_owned($pdo, $userId, $groupPublicId);
    if ($group === null) {
        return ['success' => false, 'errors' => ['Gruppen blev ikke fundet, eller tilhører dig ikke.']];
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE routes SET group_id = NULL, group_position = NULL, updated_at = updated_at WHERE group_id = :gid')
            ->execute(['gid' => $group['id']]);
        $pdo->prepare('DELETE FROM route_groups WHERE id = :gid')->execute(['gid' => $group['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('Opløsning af gruppe fejlede: ' . $e->getMessage());

        return ['success' => false, 'errors' => ['Der skete en fejl. Prøv igen.']];
    }

    // Efter commit: gruppen findes ikke mere, så cache og delingsbillede slettes med det samme.
    group_invalidate_cache((string) $group['public_id']);

    return ['success' => true];
}

/**
 * "Gør hele gruppen offentlig/privat": sætter synligheden på ALLE gruppens
 * aktive etaper. Offentlig kræver, at brugeren bekræfter den ene advarsel
 * om offentlige ruter ($ack).
 *
 * @param array $user  brugerrækken (fra current_user())
 * @return array{success: bool, errors?: string[], changed?: int}
 */
function group_set_visibility(PDO $pdo, array $user, string $groupPublicId, string $visibility, bool $ack): array
{
    if (!in_array($visibility, ['private', 'public'], true)) {
        return ['success' => false, 'errors' => ['Ugyldig synlighed valgt.']];
    }
    $userId = (int) $user['id'];
    $group = group_find_owned($pdo, $userId, $groupPublicId);
    if ($group === null) {
        return ['success' => false, 'errors' => ['Gruppen blev ikke fundet, eller tilhører dig ikke.']];
    }
    // Samme regel som ved en enkelt rute (update_route()): offentlig kræver
    // den ene afkrydsning om, at start-/slutpunkter kan afsløre bopælen.
    if ($visibility === 'public' && !$ack) {
        return ['success' => false, 'errors' => ['Du skal bekræfte advarslen om offentlige ruter for at gøre gruppen offentlig.']];
    }

    $stmt = $pdo->prepare(
        "SELECT id, public_id FROM routes
         WHERE group_id = :gid AND status = 'active' AND visibility <> :vis"
    );
    $stmt->execute(['gid' => $group['id'], 'vis' => $visibility]);
    $toChange = $stmt->fetchAll();

    $pdo->beginTransaction();
    try {
        // Præcis som update_route(): visibility_changed_at sættes, og
        // updated_at får lov at opdatere (delingsbilledet skal laves igen).
        $upd = $pdo->prepare('UPDATE routes SET visibility = :vis, visibility_changed_at = NOW() WHERE id = :id');
        foreach ($toChange as $r) {
            $upd->execute(['vis' => $visibility, 'id' => $r['id']]);
        }
        if ($visibility === 'public' && $user['public_route_notice_ack_at'] === null) {
            $pdo->prepare('UPDATE users SET public_route_notice_ack_at = NOW() WHERE id = :id')
                ->execute(['id' => $userId]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('Gruppesynlighed fejlede: ' . $e->getMessage());

        return ['success' => false, 'errors' => ['Der skete en fejl. Prøv igen.']];
    }

    group_invalidate_cache((string) $group['public_id']);
    foreach ($toChange as $r) {
        og_refresh($pdo, (string) $r['public_id']); // nyt billede, eller slet det ved "privat"
    }

    return ['success' => true, 'changed' => count($toChange)];
}

// ---------------------------------------------------------------------
// Oversigt (forside/søgning)
// ---------------------------------------------------------------------

/**
 * Slå ruter, der ligger i en gruppe, sammen til ÉN post pr. gruppe.
 * Bruges af forsiden: kortet viser gruppen som ét punkt (startpunktet på
 * den første offentlige etape) i stedet for én markør pr. etape.
 *
 * Input: rækker med public_id, title, activity_type_id, category, route_type, lat, lon,
 * min_lat/min_lon/max_lat/max_lon, distance_meters, group_id, group_position,
 * group_public_id, group_title.
 *
 * @return array<int, array> poster med type 'route' eller 'group'
 */
function group_collapse_overview(array $rows): array
{
    $entries = [];
    $groups = [];

    // Udstrækning [syd, vest, nord, øst] — bruges af forsiden til at afgøre,
    // hvilke ruter der (også) går gennem det viste kortudsnit.
    $bboxOf = static fn(array $r): array => [
        (float) ($r['min_lat'] ?? $r['lat']), (float) ($r['min_lon'] ?? $r['lon']),
        (float) ($r['max_lat'] ?? $r['lat']), (float) ($r['max_lon'] ?? $r['lon']),
    ];

    // Type + underlag ("spor:water", "rute:land" ...) — styrer farven på forsiden.
    $kindOf = static fn(array $r): string => ((string) ($r['route_type'] ?? 'rute')) . ':' . $r['category'];

    foreach ($rows as $r) {
        if ($r['group_id'] === null) {
            $entries[] = [
                'type'           => 'route',
                'id'             => $r['public_id'],
                'title'          => $r['title'],
                'categories'     => [$r['category']],
                'activityTypeId' => (int) $r['activity_type_id'],
                'lat'            => (float) $r['lat'],
                'lon'            => (float) $r['lon'],
                'km'             => round(((int) ($r['distance_meters'] ?? 0)) / 1000, 1),
                'bbox'           => $bboxOf($r),
                'kind'           => $kindOf($r),
            ];
            continue;
        }

        $gid = (int) $r['group_id'];
        $bb = $bboxOf($r);
        if (!isset($groups[$gid])) {
            $groups[$gid] = [
                'type'           => 'group',
                'id'             => $r['group_public_id'],
                'title'          => $r['group_title'],
                'categories'     => [],
                'activityTypeId' => (int) $r['activity_type_id'],
                'lat'            => (float) $r['lat'],
                'lon'            => (float) $r['lon'],
                'stages'         => 0,
                'meters'         => 0,
                'bbox'           => $bb,
                'sids'           => [],
                'kinds'          => [],
                '_firstPos'      => (int) $r['group_position'],
            ];
        }
        $g = &$groups[$gid];
        $g['stages']++;
        $g['meters'] += (int) $r['distance_meters'];
        $g['sids'][] = $r['public_id'];
        $g['kinds'][$r['public_id']] = $kindOf($r); // hver etape har sin egen farve
        $g['bbox'] = [
            min($g['bbox'][0], $bb[0]), min($g['bbox'][1], $bb[1]),
            max($g['bbox'][2], $bb[2]), max($g['bbox'][3], $bb[3]),
        ];
        if (!in_array($r['category'], $g['categories'], true)) {
            $g['categories'][] = $r['category'];
        }
        // Gruppens punkt = startpunktet på den første (laveste) offentlige etape.
        if ((int) $r['group_position'] < $g['_firstPos']) {
            $g['_firstPos'] = (int) $r['group_position'];
            $g['lat'] = (float) $r['lat'];
            $g['lon'] = (float) $r['lon'];
            $g['activityTypeId'] = (int) $r['activity_type_id'];
        }
        unset($g);
    }

    foreach ($groups as $g) {
        unset($g['_firstPos']);
        $g['km'] = round($g['meters'] / 1000, 1);
        $entries[] = $g;
    }

    return $entries;
}

// ---------------------------------------------------------------------
// Geometri til gruppesiden (forenklede spor, cachet på disk)
// ---------------------------------------------------------------------

function group_cache_dir(): string
{
    if (defined('GROUP_CACHE_DIR')) {
        return GROUP_CACHE_DIR;
    }

    return dirname(TILE_CACHE_DIR) . '/group-cache';
}

/** Slet alle cachede geometrier for gruppen (alle versioner). */
function group_invalidate_cache(string $groupPublicId): void
{
    if (!group_valid_public_id($groupPublicId)) {
        return;
    }
    foreach (glob(group_cache_dir() . '/' . $groupPublicId . '-*.json') ?: [] as $file) {
        @unlink($file);
    }
    // Gruppen er ændret: delingsbilledet (og:image) skal laves igen, eller
    // slettes, hvis gruppen ikke længere har offentlige etaper.
    og_refresh_group(get_db(), $groupPublicId);
}

function group_invalidate_cache_by_id(PDO $pdo, int $groupId): void
{
    $stmt = $pdo->prepare('SELECT public_id FROM route_groups WHERE id = :id');
    $stmt->execute(['id' => $groupId]);
    $pid = $stmt->fetchColumn();
    if ($pid !== false) {
        group_invalidate_cache((string) $pid);
    }
}

/**
 * Douglas-Peucker på [lat, lon]-punkter. Afstande måles i en simpel
 * ækvirektangulær projektion (lon skaleres med cos(bredde)) — rigeligt
 * præcist til at tegne en rute på et kort.
 *
 * @param array<int, array{0: float, 1: float}> $pts
 * @return array<int, array{0: float, 1: float}>
 */
function group_simplify(array $pts, float $toleranceDeg): array
{
    $n = count($pts);
    if ($n <= 2) {
        return $pts;
    }
    $k = cos(deg2rad($pts[intdiv($n, 2)][0]));
    $xs = [];
    $ys = [];
    foreach ($pts as $i => $p) {
        $xs[$i] = $p[1] * $k;
        $ys[$i] = $p[0];
    }

    $keep = array_fill(0, $n, false);
    $keep[0] = $keep[$n - 1] = true;
    $stack = [[0, $n - 1]];
    $tolSq = $toleranceDeg * $toleranceDeg;

    while ($stack) {
        [$a, $b] = array_pop($stack);
        $dx = $xs[$b] - $xs[$a];
        $dy = $ys[$b] - $ys[$a];
        $lenSq = $dx * $dx + $dy * $dy;
        $maxSq = -1.0;
        $idx = -1;
        for ($i = $a + 1; $i < $b; $i++) {
            if ($lenSq == 0.0) {
                $ex = $xs[$i] - $xs[$a];
                $ey = $ys[$i] - $ys[$a];
                $dSq = $ex * $ex + $ey * $ey;
            } else {
                $t = (($xs[$i] - $xs[$a]) * $dx + ($ys[$i] - $ys[$a]) * $dy) / $lenSq;
                $t = max(0.0, min(1.0, $t));
                $px = $xs[$a] + $t * $dx;
                $py = $ys[$a] + $t * $dy;
                $ex = $xs[$i] - $px;
                $ey = $ys[$i] - $py;
                $dSq = $ex * $ex + $ey * $ey;
            }
            if ($dSq > $maxSq) {
                $maxSq = $dSq;
                $idx = $i;
            }
        }
        if ($maxSq > $tolSq && $idx !== -1) {
            $keep[$idx] = true;
            $stack[] = [$a, $idx];
            $stack[] = [$idx, $b];
        }
    }

    $out = [];
    foreach ($pts as $i => $p) {
        if ($keep[$i]) {
            $out[] = $p;
        }
    }

    return $out;
}

/** Udtynd, indtil højst $maxPoints er tilbage, og afrund til ca. 1 m. */
function group_simplify_for_map(array $pts, int $maxPoints = 2000): array
{
    $tol = 0.00005; // ca. 5 m
    $out = group_simplify($pts, $tol);
    while (count($out) > $maxPoints && $tol < 0.05) {
        $tol *= 1.6;
        $out = group_simplify($pts, $tol);
    }

    return array_map(static fn(array $p): array => [round($p[0], 5), round($p[1], 5)], $out);
}

/**
 * Kort "versions-streng" for gruppens etaper: ændres, når en etape tilføjes,
 * fjernes, flyttes, redigeres eller får ny GPX-fil, eller når synlighedsomfanget
 * ($ownerScope) skifter. Bruges i cache-filnavne (geometri og delingsbillede).
 *
 * @param array $members  fra group_members()
 */
function group_geometry_version(array $members, bool $ownerScope): string
{
    $parts = [$ownerScope ? 'all' : 'pub'];
    foreach ($members as $m) {
        $full = gpx_full_path((string) $m['gpx_file_path']);
        $parts[] = implode(':', [
            $m['public_id'], $m['group_position'], $m['updated_at'],
            is_file($full) ? filemtime($full) : 0, is_file($full) ? filesize($full) : 0,
        ]);
    }

    return substr(sha1(implode('|', $parts)), 0, 12);
}

/**
 * Forenklede spor for gruppens etaper (til kortet på gruppesiden).
 * Cachet som JSON-fil (uden for webroot); filnavnet indeholder en version,
 * der ændres, når en etape ændres, og filerne slettes ved sletning/omordning.
 *
 * @param array $members  fra group_members() — kun dem, der må ses
 * @return array<int, array{id: string, title: string, points: array}>
 */
function group_geometry(array $group, array $members, bool $ownerScope): array
{
    $version = group_geometry_version($members, $ownerScope);
    $dir = group_cache_dir();
    $cacheFile = $dir . '/' . $group['public_id'] . '-' . $version . '.json';

    if (is_file($cacheFile)) {
        $cached = json_decode((string) file_get_contents($cacheFile), true);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $stages = [];
    foreach ($members as $m) {
        $full = gpx_full_path((string) $m['gpx_file_path']);
        $points = is_file($full) ? og_read_points($full) : [];
        $stages[] = [
            'id'     => $m['public_id'],
            'title'  => $m['title'],
            'points' => group_simplify_for_map($points),
        ];
    }

    if (is_dir($dir) || @mkdir($dir, 0755, true) || is_dir($dir)) {
        foreach (glob($dir . '/' . $group['public_id'] . '-*.json') ?: [] as $old) {
            @unlink($old);
        }
        $tmp = $cacheFile . '.' . getmypid() . '.tmp';
        if (file_put_contents($tmp, json_encode($stages)) !== false) {
            rename($tmp, $cacheFile);
        }
    }

    return $stages;
}
