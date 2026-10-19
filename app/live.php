<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/route_groups.php';

/**
 * Live-tracking: en livesending (live_events) med en fast URL, oprettet
 * på forhånd, og etaper (live_stages), som appen sender rå punkter til
 * (live_points), mens de er i gang.
 *
 * Model:
 *  - Hver optagelse i appen er én etape og har optagelsens
 *    client_upload_id (UUID). Den færdige, udglattede GPX uploades med
 *    det samme id gennem den almindelige upload (api/v1/upload-route.php)
 *    og bliver dermed et almindeligt spor. live_sync() kobler sporet til
 *    etapen (live_stages.route_id).
 *  - Med én etape forbliver sporet et almindeligt spor. Når en anden etape
 *    er startet, oprettes en gruppe (live_events.group_id), og etapernes
 *    spor lægges i den i etapernes rækkefølge.
 *  - Planlagte etaper (status 'planlagt') tages i brug i rækkefølge, når
 *    appen starter en ny etape. Ellers oprettes en ny etape sidst.
 *
 * Alle funktioner, der ændrer noget, får en livesending, som kalderen har
 * slået op med live_event_find_owned(), så ejerskab er tjekket.
 */

// Standardværdier, hvis config.php endnu ikke har konstanterne
// (se app/config.example.php).
if (!defined('API_LIVE_RATE_LIMIT_MAX_ATTEMPTS')) {
    define('API_LIVE_RATE_LIMIT_MAX_ATTEMPTS', 600);
}
if (!defined('API_LIVE_RATE_LIMIT_WINDOW_SECONDS')) {
    define('API_LIVE_RATE_LIMIT_WINDOW_SECONDS', 3600);
}

// Største antal punkter i én forespørgsel fra appen.
const LIVE_POINTS_MAX_PER_REQUEST = 2000;

// Punkter, der er ældre end etapens start minus dette, afvises
// (fx et forkert ur på telefonen).
const LIVE_POINTS_MAX_AGE_HOURS = 48;

// Punkter med tid længere ude i fremtiden end dette afvises.
const LIVE_POINTS_MAX_FUTURE_SECONDS = 300;

// ---------------------------------------------------------------------
// Opslag
// ---------------------------------------------------------------------

function live_valid_public_id(string $publicId): bool
{
    return (bool) preg_match('/^[0-9]{8}$/', $publicId);
}

function live_valid_client_upload_id(string $clientUploadId): bool
{
    return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $clientUploadId);
}

/** Livesendingen, hvis den ejes af $userId — ellers null. */
function live_event_find_owned(PDO $pdo, int $userId, string $publicId): ?array
{
    if (!live_valid_public_id($publicId)) {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT id, public_id, user_id, title, description, activity_type_id, status,
                visibility, delay_minutes, group_id
         FROM live_events
         WHERE public_id = :pid AND user_id = :uid
         LIMIT 1'
    );
    $stmt->execute(['pid' => $publicId, 'uid' => $userId]);
    $row = $stmt->fetch();

    return $row ?: null;
}

/** Livesendingens etaper i rækkefølge. */
function live_stages(PDO $pdo, int $eventId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, position, title, status, planned_route_id, client_upload_id, route_id,
                started_at, ended_at, last_point_at, point_count
         FROM live_stages
         WHERE event_id = :eid
         ORDER BY position ASC'
    );
    $stmt->execute(['eid' => $eventId]);

    return $stmt->fetchAll();
}

/** Etapen med dette client_upload_id i livesendingen, eller null. */
function live_stage_find(PDO $pdo, int $eventId, string $clientUploadId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, event_id, position, title, status, planned_route_id, client_upload_id, route_id,
                started_at, ended_at, last_point_at, point_count,
                UNIX_TIMESTAMP(started_at) AS started_epoch
         FROM live_stages
         WHERE event_id = :eid AND client_upload_id = :cuid
         LIMIT 1'
    );
    $stmt->execute(['eid' => $eventId, 'cuid' => $clientUploadId]);
    $row = $stmt->fetch();

    return $row ?: null;
}

/**
 * Brugerens livesendinger, der ikke er afsluttet (til valget i appen),
 * med deres etaper.
 */
function live_events_open_for_user(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare(
        "SELECT id, public_id, title, activity_type_id, status, visibility, delay_minutes, planned_start_at
         FROM live_events
         WHERE user_id = :uid AND status <> 'afsluttet'
         ORDER BY COALESCE(planned_start_at, created_at) ASC, id ASC"
    );
    $stmt->execute(['uid' => $userId]);
    $events = $stmt->fetchAll();

    foreach ($events as &$event) {
        $event['stages'] = live_stages($pdo, (int) $event['id']);
    }
    unset($event);

    return $events;
}

/** Etapen som JSON til appen. */
function live_stage_json(array $stage): array
{
    return [
        'id'       => (int) $stage['id'],
        'position' => (int) $stage['position'],
        'title'    => (string) $stage['title'],
        'status'   => $stage['status'],
        'planned'  => !empty($stage['planned_route_id']),
    ];
}

// ---------------------------------------------------------------------
// Etaper
// ---------------------------------------------------------------------

/**
 * Start en etape for optagelsen $clientUploadId. Gentages kaldet (fx efter
 * netværksfejl), returneres den samme etape. Ellers tages den første
 * planlagte etape i brug, eller der oprettes en ny sidst. En anden etape,
 * der stadig står som i gang, afsluttes.
 *
 * @return array{success: bool, errors?: string[], stage?: array}
 */
function live_stage_start(PDO $pdo, array $event, string $clientUploadId): array
{
    $eventId = (int) $event['id'];

    $existing = live_stage_find($pdo, $eventId, $clientUploadId);
    if ($existing !== null) {
        return ['success' => true, 'stage' => $existing];
    }

    if ($event['status'] === 'afsluttet') {
        return ['success' => false, 'errors' => ['Livesendingen er afsluttet.']];
    }

    $pdo->beginTransaction();
    try {
        // Lås livesendingen, så to samtidige starter ikke får samme plads.
        $stmt = $pdo->prepare('SELECT id FROM live_events WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $eventId]);

        $pdo->prepare(
            "UPDATE live_stages
             SET status = 'afsluttet', ended_at = COALESCE(last_point_at, NOW())
             WHERE event_id = :eid AND status = 'live'"
        )->execute(['eid' => $eventId]);

        $stmt = $pdo->prepare(
            "SELECT id FROM live_stages
             WHERE event_id = :eid AND status = 'planlagt'
             ORDER BY position ASC
             LIMIT 1"
        );
        $stmt->execute(['eid' => $eventId]);
        $plannedId = $stmt->fetchColumn();

        if ($plannedId !== false) {
            $pdo->prepare(
                "UPDATE live_stages
                 SET status = 'live', client_upload_id = :cuid, started_at = NOW()
                 WHERE id = :id"
            )->execute(['cuid' => $clientUploadId, 'id' => $plannedId]);
        } else {
            $stmt = $pdo->prepare('SELECT COALESCE(MAX(position), 0) + 1 FROM live_stages WHERE event_id = :eid');
            $stmt->execute(['eid' => $eventId]);
            $position = (int) $stmt->fetchColumn();

            $pdo->prepare(
                "INSERT INTO live_stages (event_id, position, status, client_upload_id, started_at)
                 VALUES (:eid, :pos, 'live', :cuid, NOW())"
            )->execute(['eid' => $eventId, 'pos' => $position, 'cuid' => $clientUploadId]);
        }

        $pdo->prepare("UPDATE live_events SET status = 'live' WHERE id = :id")->execute(['id' => $eventId]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        // Samme optagelse startet af en samtidig forespørgsel.
        $existing = live_stage_find($pdo, $eventId, $clientUploadId);
        if ($existing !== null) {
            return ['success' => true, 'stage' => $existing];
        }
        error_log('Live: etape kunne ikke startes: ' . $e->getMessage());

        return ['success' => false, 'errors' => ['Der skete en fejl. Prøv igen.']];
    }

    live_sync($pdo, $eventId);

    return ['success' => true, 'stage' => live_stage_find($pdo, $eventId, $clientUploadId)];
}

/**
 * Afslut etapen. $finishEvent afslutter også hele livesendingen; ellers
 * står den på pause, til næste etape startes.
 *
 * @return array{success: bool, errors?: string[], stage?: array}
 */
function live_stage_end(PDO $pdo, array $event, string $clientUploadId, bool $finishEvent): array
{
    $eventId = (int) $event['id'];

    $stage = live_stage_find($pdo, $eventId, $clientUploadId);
    if ($stage === null) {
        return ['success' => false, 'errors' => ['Etapen blev ikke fundet.']];
    }

    if ($stage['status'] === 'live') {
        $pdo->prepare(
            "UPDATE live_stages
             SET status = 'afsluttet', ended_at = GREATEST(COALESCE(last_point_at, NOW()), started_at)
             WHERE id = :id"
        )->execute(['id' => $stage['id']]);
    }

    if ($finishEvent) {
        $pdo->prepare(
            "UPDATE live_events SET status = 'afsluttet', ended_at = COALESCE(ended_at, NOW()) WHERE id = :id"
        )->execute(['id' => $eventId]);
    } else {
        // Kun hvis ingen anden etape er i gang (en senere start har allerede
        // afsluttet denne og sat livesendingen til 'live').
        $pdo->prepare(
            "UPDATE live_events SET status = 'pause'
             WHERE id = :id AND status = 'live'
               AND NOT EXISTS (SELECT 1 FROM live_stages WHERE event_id = :eid AND status = 'live')"
        )->execute(['id' => $eventId, 'eid' => $eventId]);
    }

    live_sync($pdo, $eventId);

    return ['success' => true, 'stage' => live_stage_find($pdo, $eventId, $clientUploadId)];
}

// ---------------------------------------------------------------------
// Punkter
// ---------------------------------------------------------------------

/**
 * Gem punkter fra appen. Hvert punkt er [lat, lon, højde|null, tid i ms
 * (unix), nøjagtighed i m|null] — samme rækkefølge som optagerens egne
 * punkter. Punkter, der allerede er gemt (samme tid), springes over, så
 * appen trygt kan sende igen efter en netværksfejl.
 *
 * @return array{success: bool, errors?: string[], received?: int, last_ms?: int|null, point_count?: int}
 */
function live_points_add(PDO $pdo, array $stage, array $points): array
{
    if (count($points) > LIVE_POINTS_MAX_PER_REQUEST) {
        return ['success' => false, 'errors' => ['For mange punkter i én forespørgsel (højst ' . LIVE_POINTS_MAX_PER_REQUEST . ').']];
    }

    // Tiderne regnes i databasen, som ikke nødvendigvis bruger PHP's tidszone.
    $startMs = (int) $stage['started_epoch'] * 1000;
    $minMs = $startMs - LIVE_POINTS_MAX_AGE_HOURS * 3600 * 1000;
    $maxMs = (time() + LIVE_POINTS_MAX_FUTURE_SECONDS) * 1000;

    $rows = [];
    foreach ($points as $p) {
        if (!is_array($p) || !array_is_list($p) || count($p) < 4) {
            return ['success' => false, 'errors' => ['Ugyldigt punkt.']];
        }
        [$lat, $lon, $ele, $t] = $p;
        $acc = $p[4] ?? null;

        if (!live_is_number($lat) || !live_is_number($lon) || !live_is_number($t)
            || ($ele !== null && !live_is_number($ele))
            || ($acc !== null && !live_is_number($acc))) {
            return ['success' => false, 'errors' => ['Ugyldigt punkt.']];
        }
        if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
            return ['success' => false, 'errors' => ['Ugyldigt punkt.']];
        }
        $t = (int) round($t);
        if ($t < $minMs || $t > $maxMs) {
            return ['success' => false, 'errors' => ['Punktets tid passer ikke til etapen. Tjek telefonens ur.']];
        }

        $rows[$t] = [
            sprintf('%.6f', $lat),
            sprintf('%.6f', $lon),
            $ele !== null && abs($ele) < 100000 ? sprintf('%.1f', $ele) : null,
            sprintf('%d.%03d', intdiv($t, 1000), $t % 1000),
            $acc !== null ? min(65535, max(0, (int) round($acc))) : null,
        ];
    }

    $received = 0;
    if ($rows) {
        $placeholders = [];
        $params = [];
        foreach ($rows as [$lat, $lon, $ele, $sec, $acc]) {
            $placeholders[] = '(?, FROM_UNIXTIME(?), ?, ?, ?, ?)';
            array_push($params, $stage['id'], $sec, $lat, $lon, $ele, $acc);
        }
        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO live_points (stage_id, recorded_at, lat, lon, ele, accuracy_m) VALUES '
            . implode(', ', $placeholders)
        );
        $stmt->execute($params);
        $received = $stmt->rowCount();
    }

    if ($received > 0) {
        $pdo->prepare(
            'UPDATE live_stages s
             JOIN (SELECT COUNT(*) AS n, MAX(recorded_at) AS last_at FROM live_points WHERE stage_id = :sid1) p
             SET s.point_count = p.n, s.last_point_at = p.last_at
             WHERE s.id = :sid2'
        )->execute(['sid1' => $stage['id'], 'sid2' => $stage['id']]);
    }

    $stmt = $pdo->prepare(
        'SELECT point_count, FLOOR(UNIX_TIMESTAMP(last_point_at) * 1000) AS last_ms FROM live_stages WHERE id = :id'
    );
    $stmt->execute(['id' => $stage['id']]);
    $row = $stmt->fetch();

    return [
        'success'     => true,
        'received'    => $received,
        'last_ms'     => $row['last_ms'] !== null ? (int) $row['last_ms'] : null,
        'point_count' => (int) $row['point_count'],
    ];
}

function live_is_number(mixed $value): bool
{
    return (is_int($value) || is_float($value)) && is_finite((float) $value);
}

// ---------------------------------------------------------------------
// Spor og gruppe
// ---------------------------------------------------------------------

/**
 * Kobl etapernes færdige spor til etaperne (via client_upload_id), og
 * opret gruppen, når der er startet mindst to etaper. Et spor lægges kun
 * i gruppen i det øjeblik, det kobles, eller når gruppen oprettes; har
 * brugeren senere selv flyttet det ud, røres det ikke igen.
 *
 * Kaldes efter start og slut af en etape og af livesiden, så rækkefølgen
 * mellem upload og "slut" i appen er ligegyldig.
 */
function live_sync(PDO $pdo, int $eventId): void
{
    // Navngiven lås i stedet for en transaktion: gruppefunktionerne
    // starter selv transaktioner.
    $lock = 'mitspor_live_' . $eventId;
    $stmt = $pdo->prepare('SELECT GET_LOCK(:name, 5)');
    $stmt->execute(['name' => $lock]);
    if ((int) $stmt->fetchColumn() !== 1) {
        return;
    }

    try {
        live_sync_locked($pdo, $eventId);
    } catch (Throwable $e) {
        error_log('Live: synkronisering fejlede: ' . $e->getMessage());
    } finally {
        $pdo->prepare('SELECT RELEASE_LOCK(:name)')->execute(['name' => $lock]);
    }
}

function live_sync_locked(PDO $pdo, int $eventId): void
{
    $stmt = $pdo->prepare('SELECT id, user_id, title, description, group_id FROM live_events WHERE id = :id');
    $stmt->execute(['id' => $eventId]);
    $event = $stmt->fetch();
    if (!$event) {
        return;
    }
    $userId = (int) $event['user_id'];

    // 1. Kobl nye spor til deres etaper.
    $stmt = $pdo->prepare(
        "SELECT s.id AS stage_id, r.id AS route_id
         FROM live_stages s
         JOIN routes r ON r.user_id = :uid AND r.client_upload_id = s.client_upload_id AND r.status = 'active'
         WHERE s.event_id = :eid AND s.route_id IS NULL"
    );
    $stmt->execute(['uid' => $userId, 'eid' => $eventId]);
    $newlyLinked = [];
    $upd = $pdo->prepare('UPDATE live_stages SET route_id = :rid WHERE id = :sid');
    foreach ($stmt->fetchAll() as $row) {
        $upd->execute(['rid' => $row['route_id'], 'sid' => $row['stage_id']]);
        $newlyLinked[(int) $row['route_id']] = true;
    }

    // 2. Gruppe først, når der er startet mindst to etaper.
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM live_stages WHERE event_id = :eid AND started_at IS NOT NULL');
    $stmt->execute(['eid' => $eventId]);
    if ((int) $stmt->fetchColumn() < 2) {
        return;
    }

    // Etapernes aktive spor i etapernes rækkefølge.
    $stmt = $pdo->prepare(
        "SELECT r.id, r.public_id, r.group_id
         FROM live_stages s
         JOIN routes r ON r.id = s.route_id AND r.status = 'active'
         WHERE s.event_id = :eid
         ORDER BY s.position ASC"
    );
    $stmt->execute(['eid' => $eventId]);
    $routes = $stmt->fetchAll();
    if (!$routes) {
        // En tom gruppe ville blive slettet igen; vent på det første spor.
        return;
    }

    $group = null;
    if ($event['group_id'] !== null) {
        $stmt = $pdo->prepare('SELECT id, public_id FROM route_groups WHERE id = :id AND user_id = :uid');
        $stmt->execute(['id' => $event['group_id'], 'uid' => $userId]);
        $group = $stmt->fetch() ?: null;
    }

    $created = false;
    if ($group === null) {
        if ($event['group_id'] !== null) {
            // Brugeren har selv opløst eller slettet gruppen.
            return;
        }
        $result = group_create($pdo, $userId, (string) $event['title'], (string) ($event['description'] ?? ''));
        if (!$result['success']) {
            error_log('Live: gruppe kunne ikke oprettes: ' . implode(' ', $result['errors'] ?? []));
            return;
        }
        $group = ['id' => $result['group_id'], 'public_id' => $result['public_id']];
        $pdo->prepare('UPDATE live_events SET group_id = :gid WHERE id = :id')
            ->execute(['gid' => $group['id'], 'id' => $eventId]);
        $created = true;
    }
    $groupId = (int) $group['id'];

    // 3. Læg spor i gruppen: alle ved oprettelsen, ellers kun nye.
    $assigned = false;
    foreach ($routes as $route) {
        if ($route['group_id'] !== null) {
            continue;
        }
        if (!$created && !isset($newlyLinked[(int) $route['id']])) {
            continue;
        }
        $result = group_assign_route($pdo, $userId, (int) $route['id'], $groupId);
        $assigned = $assigned || $result['success'];
    }
    if (!$assigned) {
        return;
    }

    // 4. Etapernes spor i etapernes rækkefølge, øvrige ruter i gruppen bagefter.
    $liveOrder = [];
    foreach ($routes as $i => $route) {
        $liveOrder[(string) $route['public_id']] = $i;
    }
    $members = group_members($pdo, $groupId, true);
    usort($members, static function (array $a, array $b) use ($liveOrder): int {
        $ka = $liveOrder[(string) $a['public_id']] ?? PHP_INT_MAX;
        $kb = $liveOrder[(string) $b['public_id']] ?? PHP_INT_MAX;

        return $ka <=> $kb ?: (int) $a['group_position'] <=> (int) $b['group_position'];
    });
    group_reorder($pdo, $userId, (string) $group['public_id'], array_column($members, 'public_id'));
}

// ---------------------------------------------------------------------
// Sletning
// ---------------------------------------------------------------------

/**
 * Slet alle brugerens livesendinger med etaper og punkter (kontosletning).
 * Sporene og gruppen er almindelige ruter og slettes for sig.
 */
function live_delete_for_user(PDO $pdo, int $userId): void
{
    $pdo->prepare(
        'DELETE p FROM live_points p
         JOIN live_stages s ON s.id = p.stage_id
         JOIN live_events e ON e.id = s.event_id
         WHERE e.user_id = :uid'
    )->execute(['uid' => $userId]);
    $pdo->prepare(
        'DELETE s FROM live_stages s
         JOIN live_events e ON e.id = s.event_id
         WHERE e.user_id = :uid'
    )->execute(['uid' => $userId]);
    $pdo->prepare('DELETE FROM live_events WHERE user_id = :uid')->execute(['uid' => $userId]);
}
