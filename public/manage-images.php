<?php
declare(strict_types=1);

session_start();

// Siden viser brugerens egne, skiftende data. Ingen gemt kopi ved "tilbage".
header('Cache-Control: no-store');

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/image_deletion.php';

$userId = require_login();
$user = current_user($userId);

if ($user === null) {
    $_SESSION = [];
    session_destroy();
    header('Location: login.php');
    exit;
}

$pdo = get_db();

function img_h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function img_size_label(int $bytes): string
{
    if ($bytes >= 1024 * 1024) {
        return number_format($bytes / 1024 / 1024, 1, ',', '.') . ' MB';
    }
    return max(1, (int) round($bytes / 1024)) . ' kB';
}

function img_route_type_label(string $routeType): string
{
    return $routeType === 'spor' ? 'Spor' : 'Rute';
}

$errors = [];
$confirmImage = null; // billedet, der vises på bekræftelsessiden
$confirmUsage = [];

// --- Sletning (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $imageId = (int) ($_POST['image_id'] ?? 0);

    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Din session er udløbet. Genindlæs siden og prøv igen.';
    }

    $image = $imageId > 0 ? image_find_for_user($pdo, $userId, $imageId) : null;
    if (empty($errors) && $image === null) {
        $errors[] = 'Billedet blev ikke fundet, eller det tilhører dig ikke.';
    }

    if (empty($errors)) {
        // Brugen tjekkes igen her: billedet kan være sat ind i en rute,
        // efter bekræftelsessiden blev vist.
        $confirmUsage = image_find_usage($pdo, $userId, $image);
        if ($confirmUsage !== []) {
            $confirmImage = $image;
            $errors[] = 'Billedet bruges stadig og kan ikke slettes.';
        }
    }

    if (empty($errors)) {
        try {
            image_delete($pdo, $userId, $image);
            header('Location: /manage-images.php?slettet=1');
            exit;
        } catch (Throwable $e) {
            error_log('Billedsletning fejlede: ' . $e->getMessage());
            $errors[] = 'Der skete en fejl under sletning. Prøv igen.';
            $confirmImage = $image;
        }
    }
}

// --- Bekræftelse af sletning (GET ?slet=<id>) ---
if ($confirmImage === null && $_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['slet'])) {
    $confirmImage = image_find_for_user($pdo, $userId, (int) $_GET['slet']);
    if ($confirmImage === null) {
        $errors[] = 'Billedet blev ikke fundet, eller det tilhører dig ikke.';
    } else {
        $confirmUsage = image_find_usage($pdo, $userId, $confirmImage);
    }
}

// --- Oversigt ---
$images = [];
$usageMap = [];
if ($confirmImage === null) {
    $stmt = $pdo->prepare(
        'SELECT id, relative_path, thumbnail_relative_path, original_filename, size_bytes, created_at
         FROM user_images
         WHERE user_id = :user_id
         ORDER BY created_at DESC, id DESC'
    );
    $stmt->execute(['user_id' => $userId]);
    $images = $stmt->fetchAll();
    $usageMap = image_usage_map($pdo, $userId, $images);
}

$imageUrl = static fn (array $img, bool $thumb): string =>
    '/' . IMAGE_PUBLIC_URL_BASE . '/' . (($thumb && !empty($img['thumbnail_relative_path']))
        ? $img['thumbnail_relative_path']
        : $img['relative_path']);
?>
<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/svg+xml" href="/grafik/favicon.svg">
    <link rel="icon" type="image/png" sizes="32x32" href="/grafik/favicon-32.png">
    <meta name="robots" content="noindex, nofollow">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mine billeder</title>
    <link rel="stylesheet" href="/assets/style.css?v=22">
    <link rel="stylesheet" href="/assets/manage-images.css?v=1">
</head>
<body>

<div class="topbar">
    <a href="<?= $confirmImage !== null ? '/manage-images.php' : '/dashboard.php' ?>" class="brand" title="Tilbage" aria-label="Tilbage">
        <span class="back-arrow" aria-hidden="true">&larr;</span>
    </a>
</div>

<div class="container">
    <div class="card">
<?php if ($confirmImage !== null): ?>
        <h1>Slet billede</h1>

        <?php if (!empty($errors)): ?>
            <div class="modal-errors"><?php foreach ($errors as $error): ?><p><?= img_h($error) ?></p><?php endforeach; ?></div>
        <?php endif; ?>

        <figure class="image-confirm">
            <img src="<?= img_h($imageUrl($confirmImage, true)) ?>" alt="">
            <figcaption>
                <?= img_h($confirmImage['original_filename']) ?><br>
                <span class="image-meta"><?= img_h(img_size_label((int) $confirmImage['size_bytes'])) ?> &middot; uploadet <?= img_h(date('d.m.Y', strtotime((string) $confirmImage['created_at']))) ?></span>
            </figcaption>
        </figure>

        <?php if ($confirmUsage !== []): ?>
            <p>
                Billedet bruges i <?= count($confirmUsage) === 1 ? 'denne rute' : 'disse ' . count($confirmUsage) . ' ruter' ?>
                og kan ikke slettes. Fjern det fra waypointet i editoren først, og prøv så igen.
            </p>
            <ul class="image-usage-list">
                <?php foreach ($confirmUsage as $route): ?>
                    <li>
                        <span class="image-usage-title">
                            <?= img_h($route['title']) ?>
                            <span class="image-meta">(<?= img_h(img_route_type_label($route['route_type'])) ?>, <?= $route['visibility'] === 'public' ? 'offentlig' : 'privat' ?>)</span>
                        </span>
                        <span class="image-usage-links">
                            <a href="/route-view.php?id=<?= img_h($route['public_id']) ?>">Vis</a>
                            <a href="/editor/?route=<?= img_h($route['public_id']) ?>">Åbn i editoren</a>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p><a href="/manage-images.php" class="btn">Tilbage til mine billeder</a></p>
        <?php else: ?>
            <p>Billedet bruges ikke i nogen af dine ruter. Vil du slette det permanent? Det kan ikke fortrydes.</p>
            <form method="post" action="/manage-images.php" class="image-confirm-actions">
                <input type="hidden" name="csrf_token" value="<?= img_h(csrf_token()) ?>">
                <input type="hidden" name="image_id" value="<?= (int) $confirmImage['id'] ?>">
                <button type="submit" class="btn btn-danger">Slet billedet</button>
                <a href="/manage-images.php" class="btn">Annullér</a>
            </form>
        <?php endif; ?>

<?php else: ?>
        <h1>Mine billeder</h1>

        <?php if (isset($_GET['slettet'])): ?>
            <p class="image-notice">Billedet er slettet.</p>
        <?php endif; ?>
        <?php if (!empty($errors)): ?>
            <div class="modal-errors"><?php foreach ($errors as $error): ?><p><?= img_h($error) ?></p><?php endforeach; ?></div>
        <?php endif; ?>

        <?php if ($images === []): ?>
            <p class="empty-state">Du har ikke uploadet nogen billeder endnu. Billeder sættes ind på waypoints i editoren.</p>
        <?php else: ?>
            <p class="image-meta">
                <?= count($images) ?> billede<?= count($images) === 1 ? '' : 'r' ?>.
                Et billede, der bruges i en rute eller et spor, kan ikke slettes.
            </p>
            <ul class="image-grid">
                <?php foreach ($images as $img): ?>
                    <?php $used = $usageMap[(int) $img['id']] ?? []; ?>
                    <li class="image-grid-item">
                        <a href="<?= img_h($imageUrl($img, false)) ?>" class="image-grid-thumb" target="_blank" rel="noopener">
                            <img src="<?= img_h($imageUrl($img, true)) ?>" alt="" loading="lazy">
                        </a>
                        <div class="image-grid-info">
                            <span class="image-grid-name" title="<?= img_h($img['original_filename']) ?>"><?= img_h($img['original_filename']) ?></span>
                            <span class="image-meta"><?= img_h(img_size_label((int) $img['size_bytes'])) ?> &middot; <?= img_h(date('d.m.Y', strtotime((string) $img['created_at']))) ?></span>
                            <span class="image-meta <?= $used !== [] ? 'image-used' : '' ?>">
                                <?php if ($used === []): ?>
                                    Bruges ikke
                                <?php else: ?>
                                    Bruges i <?= count($used) ?> rute<?= count($used) === 1 ? '' : 'r' ?>
                                <?php endif; ?>
                            </span>
                        </div>
                        <a href="/manage-images.php?slet=<?= (int) $img['id'] ?>" class="btn image-grid-delete<?= $used === [] ? ' btn-danger' : '' ?>">
                            <?= $used === [] ? 'Slet' : 'Hvor bruges det?' ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
<?php endif; ?>
    </div>
</div>

</body>
</html>
