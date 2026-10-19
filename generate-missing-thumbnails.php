<?php
declare(strict_types=1);

/**
 * Laver miniaturer til billeder, der mangler en (user_images.
 * thumbnail_relative_path IS NULL). Ældre billeder blev uploadet, før
 * der blev lavet miniaturer, og editoren viser ellers originalen, som
 * fylder meget i hukommelsen på telefoner.
 *
 * Køres én gang fra kommandolinjen (ingen hukommelsesgrænse som i
 * PHP-FPM), med samme databaseadgang som cron-jobbene:
 *
 *   php generate-missing-thumbnails.php --dry-run  # vis kun antal
 *   php generate-missing-thumbnails.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/app/db.php';
require_once __DIR__ . '/app/image_storage.php';

$dryRun = in_array('--dry-run', $argv, true);
$pdo = get_db();

$rows = $pdo->query(
    'SELECT id, user_id, relative_path FROM user_images WHERE thumbnail_relative_path IS NULL ORDER BY id'
)->fetchAll();

$made = 0;
$failed = 0;
$update = $pdo->prepare('UPDATE user_images SET thumbnail_relative_path = :thumb WHERE id = :id');

foreach ($rows as $row) {
    $filename = basename((string) $row['relative_path']);
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    if ($dryRun) {
        continue;
    }

    $thumbnail = image_generate_thumbnail((int) $row['user_id'], $filename, $extension);
    if ($thumbnail === null) {
        echo 'Kunne ikke lave miniature til billede ' . $row['id'] . "\n";
        $failed++;
        continue;
    }
    $update->execute(['thumb' => $thumbnail['relative_path'], 'id' => $row['id']]);
    $made++;
}

echo date('Y-m-d H:i:s') . ' ' . ($dryRun
    ? '[prøvekørsel] ' . count($rows) . ' billeder mangler en miniature'
    : 'Miniaturer lavet: ' . $made . ', fejlede: ' . $failed) . "\n";
