<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/db.php';

header('Content-Type: application/xml; charset=UTF-8');

$pdo = get_db();
$stmt = $pdo->query(
    "SELECT public_id, updated_at
     FROM routes
     WHERE status = 'active' AND visibility = 'public'
     ORDER BY updated_at DESC"
);
$routes = $stmt->fetchAll();

// Grupper (etape-ture) med mindst én offentlig etape.
$stmt = $pdo->query(
    "SELECT g.public_id, MAX(r.updated_at) AS updated_at
     FROM route_groups g
     JOIN routes r ON r.group_id = g.id AND r.status = 'active' AND r.visibility = 'public'
     GROUP BY g.id
     ORDER BY updated_at DESC"
);
$groups = $stmt->fetchAll();

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    <url>
        <loc><?= htmlspecialchars(APP_BASE_URL, ENT_QUOTES, 'UTF-8') ?>/</loc>
        <changefreq>daily</changefreq>
        <priority>1.0</priority>
    </url>
<?php foreach ($routes as $route): ?>
    <url>
        <loc><?= htmlspecialchars(APP_BASE_URL . '/route-view.php?id=' . urlencode($route['public_id']), ENT_QUOTES, 'UTF-8') ?></loc>
        <lastmod><?= date('Y-m-d', strtotime($route['updated_at'])) ?></lastmod>
        <changefreq>monthly</changefreq>
        <priority>0.7</priority>
    </url>
<?php endforeach; ?>
<?php foreach ($groups as $group): ?>
    <url>
        <loc><?= htmlspecialchars(APP_BASE_URL . '/group-view.php?id=' . urlencode($group['public_id']), ENT_QUOTES, 'UTF-8') ?></loc>
        <lastmod><?= date('Y-m-d', strtotime($group['updated_at'])) ?></lastmod>
        <changefreq>monthly</changefreq>
        <priority>0.7</priority>
    </url>
<?php endforeach; ?>
</urlset>
