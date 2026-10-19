<?php
declare(strict_types=1);

require_once __DIR__ . '/live.php';
require_once __DIR__ . '/gpx.php';
require_once __DIR__ . '/og_image.php';

/**
 * Livesiden (public/live.php) og dens data (public/live-data.php).
 *
 * Forsinkelsen håndhæves her: besøgende får kun punkter, der er ældre end
 * livesendingens delay_minutes, og en etapes færdige spor vises først,
 * når etapen sluttede for mere end forsinkelsen siden. Ellers ville sporet
 * afsløre de seneste minutter. Ejeren ser alt uden forsinkelse.
 *
 * Siden henter først alt (version ændret) og derefter kun nye punkter
 * (since = sidste punkt-id). Versionen ændres, når en etape starter,
 * slutter eller får sit færdige spor, eller når livesendingen rettes.
 */

// En etape, der ikke har fået punkter i så lang tid, vises som pause.
const LIVE_AUTO_PAUSE_MINUTES = 120;

// Siden henter nye punkter med dette interval (sekunder).
const LIVE_POLL_SECONDS = 30;

/** Livesendingen med dette id, med ejerens visningsnavn, eller null. */
function live_event_find(PDO $pdo, string $publicId): ?array
{
    if (!live_valid_public_id($publicId)) {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT e.id, e.public_id, e.user_id, e.title, e.description, e.activity_type_id, e.status,
                e.visibility, e.share_token, e.delay_minutes, e.planned_start_at, e.group_id,
                e.updated_at, u.display_name AS owner_name
         FROM live_events e
         JOIN users u ON u.id = e.user_id
         WHERE e.public_id = :pid
         LIMIT 1'
    );
    $stmt->execute(['pid' => $publicId]);
    $row = $stmt->fetch();

    return $row ?: null;
}

/**
 * Må den besøgende se livesendingen? Ejeren altid; andre, hvis den er
 * offentlig, eller med den rigtige nøgle (k) fra delingslinket.
 */
function live_can_view(array $event, ?int $sessionUserId, string $key): bool
{
    if ($sessionUserId !== null && $sessionUserId === (int) $event['user_id']) {
        return true;
    }
    if ($event['visibility'] === 'public') {
        return true;
    }

    return $key !== '' && !empty($event['share_token']) && hash_equals((string) $event['share_token'], $key);
}

/**
 * Data til livesiden.
 *
 * @param string $clientVersion versionen, siden allerede har ('' første gang)
 * @param int    $sinceId       sidste punkt-id, siden har
 */
function live_view_payload(PDO $pdo, array $event, bool $isOwner, string $clientVersion, int $sinceId): array
{
    $eventId = (int) $event['id'];
    $delay = $isOwner ? 0 : (int) $event['delay_minutes'];

    $stmt = $pdo->prepare(
        'SELECT NOW() - INTERVAL :d MINUTE AS cutoff, UNIX_TIMESTAMP() AS now_epoch'
    );
    $stmt->execute(['d' => $delay]);
    $clock = $stmt->fetch();
    $cutoff = (string) $clock['cutoff'];
    $nowMs = (int) $clock['now_epoch'] * 1000;

    $stmt = $pdo->prepare(
        "SELECT s.id, s.position, s.title, s.status, s.client_upload_id, s.route_id,
                s.started_at <= :c1 AS started_visible,
                s.ended_at IS NOT NULL AND s.ended_at <= :c2 AS ended_visible,
                r.public_id AS route_public_id, r.title AS route_title, r.visibility AS route_visibility,
                r.gpx_file_path AS route_gpx, r.updated_at AS route_updated,
                pr.gpx_file_path AS planned_gpx, pr.updated_at AS planned_updated
         FROM live_stages s
         LEFT JOIN routes r ON r.id = s.route_id AND r.status = 'active'
         LEFT JOIN routes pr ON pr.id = s.planned_route_id AND pr.status = 'active'
         WHERE s.event_id = :eid
         ORDER BY s.position ASC"
    );
    $stmt->execute(['c1' => $cutoff, 'c2' => $cutoff, 'eid' => $eventId]);
    $rows = $stmt->fetchAll();

    // Er et spor uploadet efter "slut" i appen, er det endnu ikke koblet.
    foreach ($rows as $row) {
        if ($row['status'] === 'afsluttet' && $row['route_id'] === null && $row['client_upload_id'] !== null) {
            live_sync($pdo, $eventId);
            $stmt->execute(['c1' => $cutoff, 'c2' => $cutoff, 'eid' => $eventId]);
            $rows = $stmt->fetchAll();
            break;
        }
    }

    $stages = [];
    $liveStageIds = [];   // etaper, hvis rå punkter vises
    $versionParts = [$event['title'], $event['description'], $event['status'], $event['delay_minutes'], $isOwner ? 'o' : 'v'];
    foreach ($rows as $row) {
        $started = (bool) $row['started_visible'];
        $routeShown = $started && (bool) $row['ended_visible'] && $row['route_public_id'] !== null;
        if (!$started) {
            $state = 'planlagt';
        } elseif ($row['ended_visible']) {
            $state = 'afsluttet';
        } else {
            $state = 'live';
        }
        if ($started && !$routeShown) {
            $liveStageIds[(int) $row['id']] = (int) $row['position'];
        }

        $stages[] = ['row' => $row, 'state' => $state, 'route_shown' => $routeShown];
        $versionParts[] = implode(':', [
            $row['id'], $row['position'], $row['title'], $state,
            $routeShown ? $row['route_public_id'] . '@' . $row['route_updated'] : '',
            $row['planned_gpx'] !== null ? $row['planned_updated'] : '',
        ]);
    }
    $version = substr(sha1(implode('|', $versionParts)), 0, 12);
    $full = $version !== $clientVersion;

    $payload = [
        'success'  => true,
        'version'  => $version,
        'now_ms'   => $nowMs,
        'delay'    => $delay,
        'status'   => 'planlagt',
        'stages'   => null,
        'points'   => [],
        'last_id'  => $full ? 0 : $sinceId,
        'current'  => null,
    ];

    // Seneste synlige punkt (til markøren og "opdateret for … siden").
    $lastMs = null;
    if ($liveStageIds) {
        $in = implode(',', array_map('intval', array_keys($liveStageIds)));
        $stmt = $pdo->prepare(
            "SELECT stage_id, lat, lon, FLOOR(UNIX_TIMESTAMP(recorded_at) * 1000) AS t
             FROM live_points
             WHERE stage_id IN ($in) AND recorded_at <= :c
             ORDER BY recorded_at DESC
             LIMIT 1"
        );
        $stmt->execute(['c' => $cutoff]);
        $last = $stmt->fetch();
        if ($last) {
            $lastMs = (int) $last['t'];
            $payload['current'] = [
                'stage' => $liveStageIds[(int) $last['stage_id']],
                'lat'   => (float) $last['lat'],
                'lon'   => (float) $last['lon'],
                't'     => $lastMs,
            ];
        }
    }

    // Status, som besøgende ser den.
    $anyStarted = false;
    $anyLive = false;
    foreach ($stages as $st) {
        $anyStarted = $anyStarted || $st['state'] !== 'planlagt';
        $anyLive = $anyLive || $st['state'] === 'live';
    }
    if ($event['status'] === 'afsluttet' && !$anyLive) {
        $payload['status'] = 'afsluttet';
    } elseif ($anyLive && $lastMs !== null && $nowMs - $delay * 60000 - $lastMs < LIVE_AUTO_PAUSE_MINUTES * 60000) {
        $payload['status'] = 'live';
    } elseif ($anyLive && $lastMs === null) {
        $payload['status'] = 'live'; // startet, men ingen punkter endnu
    } elseif ($anyStarted) {
        $payload['status'] = 'pause';
    }

    if ($full) {
        $payload['stages'] = [];
        foreach ($stages as $st) {
            $row = $st['row'];
            $routeLink = $st['route_shown'] && ($isOwner || $row['route_visibility'] === 'public')
                ? '/route-view.php?id=' . $row['route_public_id']
                : null;
            $payload['stages'][] = [
                'position' => (int) $row['position'],
                'title'    => $row['title'] !== '' ? $row['title'] : ($st['route_shown'] ? (string) $row['route_title'] : ''),
                'state'    => $st['state'],
                'route'    => $st['route_shown'] ? live_gpx_points((string) $row['route_gpx']) : null,
                'link'     => $routeLink,
                'planned'  => $row['planned_gpx'] !== null ? live_gpx_points((string) $row['planned_gpx']) : null,
                'points'   => [],
            ];
        }
    }

    // Rå punkter: alle (forenklet) ved fuld hentning, ellers kun nye.
    if ($liveStageIds) {
        $in = implode(',', array_map('intval', array_keys($liveStageIds)));
        $stmt = $pdo->prepare(
            "SELECT id, stage_id, lat, lon
             FROM live_points
             WHERE stage_id IN ($in) AND recorded_at <= :c AND id > :since
             ORDER BY stage_id ASC, recorded_at ASC"
        );
        $stmt->execute(['c' => $cutoff, 'since' => $full ? 0 : $sinceId]);

        $byStage = [];
        $maxId = $payload['last_id'];
        foreach ($stmt as $p) {
            $byStage[(int) $p['stage_id']][] = [(float) $p['lat'], (float) $p['lon']];
            $maxId = max($maxId, (int) $p['id']);
        }
        $payload['last_id'] = $maxId;

        foreach ($byStage as $stageId => $pts) {
            $position = $liveStageIds[$stageId];
            if ($full) {
                foreach ($payload['stages'] as &$s) {
                    if ($s['position'] === $position) {
                        $s['points'] = group_simplify_for_map($pts);
                    }
                }
                unset($s);
            } else {
                $payload['points'][] = ['stage' => $position, 'points' => $pts];
            }
        }
    }

    return $payload;
}

/** Forenklede punkter fra en GPX-fil (som gruppesidens kort). */
function live_gpx_points(string $relativePath): array
{
    $full = gpx_full_path($relativePath);

    return is_file($full) ? group_simplify_for_map(og_read_points($full)) : [];
}
