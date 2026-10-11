<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/csrf.php';
require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/app_tilbage.php';

$userId = require_login();
$user = current_user($userId);

if ($user === null) {
    $_SESSION = [];
    session_destroy();
    header('Location: ../login.php');
    exit;
}

if (!$user['is_admin']) {
    http_response_code(403);
    echo 'Ingen adgang.';
    exit;
}

$pdo = get_db();

$stmt = $pdo->query(
    "SELECT cr.id, cr.route_id, cr.comment_id, cr.reason, cr.created_at,
            reporter.display_name AS reporter_name, reporter.email AS reporter_email,
            r.title AS route_title, r.status AS route_status, r.public_id AS route_public_id,
            ro.display_name AS route_owner_name,
            rc.body AS comment_body,
            co.display_name AS comment_owner_name
     FROM content_reports cr
     JOIN users reporter ON reporter.id = cr.reported_by_user_id
     LEFT JOIN routes r ON r.id = cr.route_id
     LEFT JOIN users ro ON ro.id = r.user_id
     LEFT JOIN route_comments rc ON rc.id = cr.comment_id
     LEFT JOIN users co ON co.id = rc.user_id
     WHERE cr.status = 'open'
     ORDER BY cr.created_at ASC"
);
$reports = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="UTF-8">
    <title>Admin — åbne rapporter</title>
</head>
<body>
<?php render_app_back_button(); ?>

<h1>Åbne rapporter (<?= count($reports) ?>)</h1>

<?php if (empty($reports)): ?>
    <p>Ingen åbne rapporter lige nu.</p>
<?php endif; ?>

<?php foreach ($reports as $report): ?>
    <hr>
    <p>
        Rapporteret af <?= htmlspecialchars($report['reporter_name'], ENT_QUOTES, 'UTF-8') ?>
        (<?= htmlspecialchars($report['reporter_email'], ENT_QUOTES, 'UTF-8') ?>)
        den <?= htmlspecialchars($report['created_at'], ENT_QUOTES, 'UTF-8') ?>
    </p>

    <?php if ($report['route_id']): ?>
        <p>
            <strong>Rute:</strong>
            <?php if ($report['route_status'] === 'active'): ?>
                <a href="../route-view.php?id=<?= htmlspecialchars($report['route_public_id'], ENT_QUOTES, 'UTF-8') ?>" target="_blank">
                    <?= htmlspecialchars($report['route_title'], ENT_QUOTES, 'UTF-8') ?>
                </a>
            <?php elseif ($report['route_title'] !== null): ?>
                <?= $report['route_title'] !== '' ? htmlspecialchars($report['route_title'], ENT_QUOTES, 'UTF-8') . ' ' : '' ?>(allerede slettet — indholdet er ryddet)
            <?php else: ?>
                (ruten findes ikke længere)
            <?php endif; ?>
            — ejer: <?= htmlspecialchars($report['route_owner_name'] ?? 'ukendt', ENT_QUOTES, 'UTF-8') ?>
        </p>
    <?php elseif ($report['comment_id']): ?>
        <p>
            <strong>Kommentar</strong> af <?= htmlspecialchars($report['comment_owner_name'] ?? 'ukendt', ENT_QUOTES, 'UTF-8') ?>:<br>
            <?= nl2br(htmlspecialchars($report['comment_body'] ?? '(fjernet)', ENT_QUOTES, 'UTF-8')) ?>
        </p>
    <?php endif; ?>

    <p><strong>Begrundelse:</strong> <?= nl2br(htmlspecialchars($report['reason'], ENT_QUOTES, 'UTF-8')) ?></p>

    <form method="post" action="action.php" style="display:inline">
        <input type="hidden" name="report_id" value="<?= (int) $report['id'] ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <button type="submit" name="action" value="dismiss">Afvis rapport</button>
        <button type="submit" name="action" value="reviewed">Marker som gennemgået</button>
        <?php if ($report['route_id'] && $report['route_status'] === 'active'): ?>
            <button type="submit" name="action" value="remove_route">Fjern ruten</button>
        <?php endif; ?>
    </form>
<?php endforeach; ?>

</body>
</html>
