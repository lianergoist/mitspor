<?php
declare(strict_types=1);

/**
 * Advarer om og sletter inaktive og ubekræftede konti — se reglerne i
 * app/inactive_accounts.php og afsnit 6 i privatlivspolitikken.
 *
 * Beregnet til at køre via cron, IKKE som et web-endpoint:
 *
 *   php prune-inactive-accounts.php            # udfør
 *   php prune-inactive-accounts.php --dry-run  # vis kun, hvad der ville ske
 *
 * Cron-eksempel (hver nat kl. 03:15):
 *
 *   15 3 * * * php /var/www/mitspor.dk/prune-inactive-accounts.php >> /var/log/mitspor-inactive.log 2>&1
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/app/inactive_accounts.php';

$dryRun = in_array('--dry-run', $argv, true);
$log = static function (string $line): void {
    echo date('Y-m-d H:i:s') . ' ' . $line . "\n";
};

$stats = inactive_accounts_run(get_db(), $dryRun, $log);

$log(($dryRun ? '[prøvekørsel] ' : '') . sprintf(
    'Ubekræftede slettet: %d, første advarsel: %d, anden advarsel: %d, slettet: %d, mail fejlede: %d',
    $stats['unverified_deleted'],
    $stats['warned_first'],
    $stats['warned_second'],
    $stats['deleted'],
    $stats['mail_failed']
));
