<?php
declare(strict_types=1);

/**
 * Sletter sikkerhedsnoter ældre end SECURITY_LOG_RETENTION_DAYS, som
 * privatlivspolitikken lover (afsnit 6):
 * - login_attempts og rate_limit_log (IP-adresser og indtastede e-mails)
 * - udløbne eller brugte engangslinks (bekræftelse af e-mail, nulstilling
 *   af adgangskode og skift af e-mail)
 *
 * Engangskoder til fælles login ryddes allerede i app/app_login.php.
 *
 * Beregnet til at køre via cron, IKKE som et web-endpoint:
 *
 *   php prune-security-logs.php            # udfør
 *   php prune-security-logs.php --dry-run  # vis kun antal
 *
 * Cron-eksempel (hver nat kl. 03:30):
 *
 *   30 3 * * * php /var/www/mitspor.dk/prune-security-logs.php >> /var/log/mitspor-security-prune.log 2>&1
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/app/db.php';

$dryRun = in_array('--dry-run', $argv, true);
$pdo = get_db();
$days = SECURITY_LOG_RETENTION_DAYS;

// Tabel => betingelse for, at en række er for gammel.
$targets = [
    'login_attempts'            => 'attempted_at < NOW() - INTERVAL :days DAY',
    'rate_limit_log'            => 'created_at < NOW() - INTERVAL :days DAY',
    'email_verification_tokens' => 'GREATEST(expires_at, COALESCE(used_at, expires_at)) < NOW() - INTERVAL :days DAY',
    'password_reset_tokens'     => 'GREATEST(expires_at, COALESCE(used_at, expires_at)) < NOW() - INTERVAL :days DAY',
    'email_change_tokens'       => 'GREATEST(expires_at, COALESCE(used_at, expires_at)) < NOW() - INTERVAL :days DAY',
];

$parts = [];
foreach ($targets as $table => $condition) {
    $sql = $dryRun
        ? "SELECT COUNT(*) FROM $table WHERE $condition"
        : "DELETE FROM $table WHERE $condition";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['days' => $days]);
    $parts[] = $table . ': ' . ($dryRun ? (int) $stmt->fetchColumn() : $stmt->rowCount());
}

echo date('Y-m-d H:i:s') . ' ' . ($dryRun ? '[prøvekørsel] ' : '')
    . 'Slettet ældre end ' . $days . ' dage — ' . implode(', ', $parts) . "\n";
