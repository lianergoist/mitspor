<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/account_deletion.php';

/**
 * Sletning af inaktive konti (køres hver nat af prune-inactive-accounts.php).
 *
 * En konto er inaktiv, når brugeren ikke har været logget ind på sitet
 * eller brugt appen i INACTIVE_DELETE_DAYS (users.last_active_at, som
 * current_user() opdaterer). Før sletning sendes to advarsler, og der går
 * altid mindst INACTIVE_FIRST_WARNING_DAYS fra første advarsel og
 * INACTIVE_SECOND_WARNING_DAYS fra anden, også hvis jobbet har været
 * nede. Aktivitet nulstiller advarslerne. Kan en mail ikke sendes,
 * prøves der igen næste nat, så ingen slettes uden begge advarsler.
 *
 * Ubekræftede konti slettes uden advarsel efter UNVERIFIED_DELETE_DAYS:
 * adressen er aldrig bekræftet, så der er ingen sikker adresse at advare.
 * Administratorer slettes aldrig. Reglerne står i privatlivspolitikken.
 */
const INACTIVE_DELETE_DAYS = 365;
const INACTIVE_FIRST_WARNING_DAYS = 30;
const INACTIVE_SECOND_WARNING_DAYS = 7;
const UNVERIFIED_DELETE_DAYS = 30;

/**
 * @return array{unverified_deleted: int, warned_first: int, warned_second: int, deleted: int, mail_failed: int}
 */
function inactive_accounts_run(PDO $pdo, bool $dryRun, callable $log): array
{
    $stats = ['unverified_deleted' => 0, 'warned_first' => 0, 'warned_second' => 0, 'deleted' => 0, 'mail_failed' => 0];

    // --- Ubekræftede konti ---
    $stmt = $pdo->prepare(
        "SELECT id FROM users
         WHERE status = 'active' AND is_admin = 0 AND email_verified_at IS NULL
           AND created_at < NOW() - INTERVAL :days DAY"
    );
    $stmt->execute(['days' => UNVERIFIED_DELETE_DAYS]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $log('Ubekræftet konto ' . $id . ' slettes');
        if (!$dryRun) {
            inactive_accounts_delete($pdo, (int) $id);
        }
        $stats['unverified_deleted']++;
    }

    // --- Sletning efter anden advarsel ---
    $stmt = $pdo->prepare(
        "SELECT id FROM users
         WHERE status = 'active' AND is_admin = 0 AND inactivity_warning_level = 2
           AND inactivity_warned_at <= NOW() - INTERVAL :second DAY
           AND last_active_at < NOW() - INTERVAL :days DAY"
    );
    $stmt->execute(['second' => INACTIVE_SECOND_WARNING_DAYS, 'days' => INACTIVE_DELETE_DAYS]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $log('Inaktiv konto ' . $id . ' slettes');
        if (!$dryRun) {
            inactive_accounts_delete($pdo, (int) $id);
        }
        $stats['deleted']++;
    }

    // --- Anden advarsel (7 dage før) ---
    inactive_accounts_warn(
        $pdo, $dryRun, $log, $stats, 1, 2,
        INACTIVE_FIRST_WARNING_DAYS - INACTIVE_SECOND_WARNING_DAYS,
        INACTIVE_DELETE_DAYS - INACTIVE_SECOND_WARNING_DAYS,
        INACTIVE_SECOND_WARNING_DAYS
    );

    // --- Første advarsel (30 dage før) ---
    inactive_accounts_warn(
        $pdo, $dryRun, $log, $stats, 0, 1,
        0,
        INACTIVE_DELETE_DAYS - INACTIVE_FIRST_WARNING_DAYS,
        INACTIVE_FIRST_WARNING_DAYS
    );

    return $stats;
}

/**
 * Send advarsler til konti på trin $fromLevel, der har været inaktive i
 * mindst $inactiveDays, og hvis forrige advarsel er mindst $sinceDays
 * gammel. Sletningsdatoen i mailen er mindst $daysLeft dage frem.
 */
function inactive_accounts_warn(
    PDO $pdo,
    bool $dryRun,
    callable $log,
    array &$stats,
    int $fromLevel,
    int $toLevel,
    int $sinceDays,
    int $inactiveDays,
    int $daysLeft
): void {
    $stmt = $pdo->prepare(
        "SELECT u.id, u.email, u.display_name, u.storage_used_bytes, u.last_active_at,
                (SELECT COUNT(*) FROM routes r WHERE r.user_id = u.id AND r.status = 'active') AS route_count,
                (SELECT COUNT(*) FROM user_images i WHERE i.user_id = u.id) AS image_count
         FROM users u
         WHERE u.status = 'active' AND u.is_admin = 0 AND u.email_verified_at IS NOT NULL
           AND u.inactivity_warning_level = :level
           AND (u.inactivity_warned_at IS NULL OR u.inactivity_warned_at <= NOW() - INTERVAL :since DAY)
           AND u.last_active_at < NOW() - INTERVAL :inactive DAY"
    );
    $stmt->execute(['level' => $fromLevel, 'since' => $sinceDays, 'inactive' => $inactiveDays]);

    foreach ($stmt->fetchAll() as $account) {
        // Sletningen sker tidligst, når kontoen har været inaktiv i et år,
        // og tidligst $daysLeft dage efter denne advarsel.
        $deleteAt = max(
            strtotime($account['last_active_at'] . ' +' . INACTIVE_DELETE_DAYS . ' days'),
            strtotime('+' . $daysLeft . ' days')
        );
        $deleteDate = date('j.n.Y', $deleteAt);
        $daysToDelete = (int) ceil(($deleteAt - time()) / 86400);

        $log('Konto ' . $account['id'] . ': advarsel ' . $toLevel . ' (slettes ' . $deleteDate . ')');
        if ($dryRun) {
            $stats[$toLevel === 1 ? 'warned_first' : 'warned_second']++;
            continue;
        }

        $sent = send_inactivity_warning($account['email'], [
            'display_name'       => (string) $account['display_name'],
            'route_count'        => (int) $account['route_count'],
            'image_count'        => (int) $account['image_count'],
            'storage_used_bytes' => (int) $account['storage_used_bytes'],
        ], $daysToDelete, $deleteDate);

        if (!$sent) {
            $log('Konto ' . $account['id'] . ': mailen kunne ikke sendes, prøver igen næste nat');
            $stats['mail_failed']++;
            continue;
        }

        $pdo->prepare(
            'UPDATE users SET inactivity_warning_level = :level, inactivity_warned_at = NOW()
             WHERE id = :id AND inactivity_warning_level = :from'
        )->execute(['level' => $toLevel, 'id' => $account['id'], 'from' => $fromLevel]);
        $stats[$toLevel === 1 ? 'warned_first' : 'warned_second']++;
    }
}

function inactive_accounts_delete(PDO $pdo, int $userId): void
{
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id AND status = 'active'");
    $stmt->execute(['id' => $userId]);
    $user = $stmt->fetch();
    if ($user) {
        // deletion_requests registrerer brugeren selv som anmoder
        // (kolonnen er NOT NULL); sletningen er sket efter reglerne ovenfor.
        delete_account($pdo, $user);
    }
}
