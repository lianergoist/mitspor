<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/gpx.php';
require_once __DIR__ . '/image_storage.php';

/**
 * Henter ét af brugerens egne billeder. Returnerer null, hvis billedet
 * ikke findes eller tilhører en anden bruger.
 */
function image_find_for_user(PDO $pdo, int $userId, int $imageId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, user_id, relative_path, thumbnail_relative_path, original_filename, size_bytes, created_at
         FROM user_images
         WHERE id = :id AND user_id = :user_id
         LIMIT 1'
    );
    $stmt->execute(['id' => $imageId, 'user_id' => $userId]);
    $image = $stmt->fetch();

    return $image ?: null;
}

/**
 * Finder, hvilke af brugerens ruter og spor der bruger hvert billede.
 *
 * Databasen ved ikke, hvor et billede bruges: forbindelsen findes kun
 * som <link href="billeder/<bruger>/<fil>"> på et waypoint inde i
 * GPX-filen. Derfor gennemsøges brugerens egne GPX-filer (hver fil
 * læses én gang) efter billedernes filnavne. Et filnavn er 32
 * tilfældige hex-tegn, så et fund kan ikke være en tilfældighed.
 *
 * Kan en GPX-fil ikke læses, regnes ruten for at bruge ALLE billederne
 * (hellere nægte en sletning end slette et billede, der bruges).
 *
 * @param list<array> $images rækker fra user_images
 * @return array<int, list<array{public_id: string, title: string, route_type: string, visibility: string}>>
 *         billed-id => ruter (kun billeder i brug er med)
 */
function image_usage_map(PDO $pdo, int $userId, array $images): array
{
    if ($images === []) {
        return [];
    }

    $needlesById = [];
    foreach ($images as $image) {
        $needles = [basename((string) $image['relative_path'])];
        if (!empty($image['thumbnail_relative_path'])) {
            $needles[] = basename((string) $image['thumbnail_relative_path']);
        }
        $needlesById[(int) $image['id']] = $needles;
    }

    $stmt = $pdo->prepare(
        "SELECT public_id, title, route_type, visibility, gpx_file_path
         FROM routes
         WHERE user_id = :user_id AND status = 'active'
         ORDER BY title ASC"
    );
    $stmt->execute(['user_id' => $userId]);

    $usage = [];
    foreach ($stmt->fetchAll() as $route) {
        $fullPath = gpx_full_path((string) $route['gpx_file_path']);
        $content = is_file($fullPath) ? @file_get_contents($fullPath) : false;
        if ($content === false) {
            error_log('Billedsletning: kunne ikke læse GPX for rute ' . $route['public_id']);
        }

        $routeInfo = [
            'public_id'  => (string) $route['public_id'],
            'title'      => (string) $route['title'],
            'route_type' => (string) $route['route_type'],
            'visibility' => (string) $route['visibility'],
        ];

        foreach ($needlesById as $imageId => $needles) {
            $used = $content === false;
            foreach ($needles as $needle) {
                if ($used || str_contains($content, $needle)) {
                    $used = true;
                    break;
                }
            }
            if ($used) {
                $usage[$imageId][] = $routeInfo;
            }
        }
    }

    return $usage;
}

/**
 * Ruter og spor, der bruger ét bestemt billede (tom liste = ikke i brug).
 *
 * @return list<array{public_id: string, title: string, route_type: string, visibility: string}>
 */
function image_find_usage(PDO $pdo, int $userId, array $image): array
{
    return image_usage_map($pdo, $userId, [$image])[(int) $image['id']] ?? [];
}

/**
 * Sletter et billede, der IKKE er i brug: rækken, originalfilen og
 * miniaturen. Pladsen trækkes fra brugerens forbrug.
 * Kalderen skal selv have tjekket brugen med image_find_usage().
 */
function image_delete(PDO $pdo, int $userId, array $image): void
{
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('DELETE FROM user_images WHERE id = :id AND user_id = :user_id');
        $stmt->execute(['id' => $image['id'], 'user_id' => $userId]);

        if ($stmt->rowCount() > 0) {
            $stmt = $pdo->prepare(
                'UPDATE users SET storage_used_bytes = GREATEST(0, CAST(storage_used_bytes AS SIGNED) - :size) WHERE id = :id'
            );
            $stmt->execute(['size' => (int) $image['size_bytes'], 'id' => $userId]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    // Filerne slettes først, når rækken er væk. Går det galt her, er der
    // højst en forældreløs fil tilbage, ikke en række uden fil.
    @unlink(image_full_path((string) $image['relative_path']));
    if (!empty($image['thumbnail_relative_path'])) {
        @unlink(image_full_path((string) $image['thumbnail_relative_path']));
    }
}
