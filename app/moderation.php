<?php
declare(strict_types=1);

require_once __DIR__ . '/route_deletion.php';

/**
 * Opretter en rapport om enten en rute eller en kommentar (præcis ét
 * af $routeId/$commentId skal være sat). Undgår dubletter: hvis samme
 * bruger allerede har en åben (ikke-færdigbehandlet) rapport om samme
 * indhold, oprettes der ikke en ny.
 *
 * @return array{success: bool, errors?: string[]}
 */
function submit_report(PDO $pdo, int $reporterId, ?int $routeId, ?int $commentId, string $reason): array
{
    $reason = trim($reason);

    if (($routeId === null) === ($commentId === null)) {
        // Programmeringsfejl hos kalderen, ikke en brugerfejl — men vi
        // fejler pænt i stedet for at ramme databasens CHECK-constraint.
        return ['success' => false, 'errors' => ['Ugyldig rapport.']];
    }

    if (mb_strlen($reason) < 5 || mb_strlen($reason) > 2000) {
        return ['success' => false, 'errors' => ['Begrundelsen skal være mellem 5 og 2000 tegn.']];
    }

    $stmt = $pdo->prepare(
        "SELECT id FROM content_reports
         WHERE reported_by_user_id = :reporter
           AND status = 'open'
           AND route_id <=> :route_id
           AND comment_id <=> :comment_id
         LIMIT 1"
    );
    $stmt->execute(['reporter' => $reporterId, 'route_id' => $routeId, 'comment_id' => $commentId]);
    if ($stmt->fetch()) {
        return ['success' => false, 'errors' => ['Du har allerede rapporteret dette — det afventer gennemgang.']];
    }

    $stmt = $pdo->prepare(
        'INSERT INTO content_reports (route_id, comment_id, reported_by_user_id, reason)
         VALUES (:route_id, :comment_id, :reporter, :reason)'
    );
    $stmt->execute([
        'route_id'   => $routeId,
        'comment_id' => $commentId,
        'reporter'   => $reporterId,
        'reason'     => $reason,
    ]);

    return ['success' => true];
}

/**
 * Afviser en rapport uden at foretage nogen handling på indholdet.
 */
function admin_dismiss_report(PDO $pdo, int $reportId, int $adminId): void
{
    $stmt = $pdo->prepare(
        "UPDATE content_reports
         SET status = 'dismissed', reviewed_at = NOW(), reviewed_by_user_id = :admin_id
         WHERE id = :id"
    );
    $stmt->execute(['admin_id' => $adminId, 'id' => $reportId]);
}

/**
 * Markerer en rapport som gennemgået, uden at indholdet fjernes
 * (bruges når rapporten er set, men ikke vurderes at kræve handling).
 */
function admin_mark_reviewed(PDO $pdo, int $reportId, int $adminId): void
{
    $stmt = $pdo->prepare(
        "UPDATE content_reports
         SET status = 'reviewed', reviewed_at = NOW(), reviewed_by_user_id = :admin_id
         WHERE id = :id"
    );
    $stmt->execute(['admin_id' => $adminId, 'id' => $reportId]);
}

/**
 * Fjerner den rapporterede rute (samme tombesten-mekanisme som ved
 * selvbetjent sletning) og markerer rapporten — samt evt. andre åbne
 * rapporter om samme rute — som "actioned".
 */
function admin_remove_reported_route(PDO $pdo, int $reportId, int $adminId): array
{
    $stmt = $pdo->prepare('SELECT route_id FROM content_reports WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $reportId]);
    $report = $stmt->fetch();

    if (!$report || $report['route_id'] === null) {
        return ['success' => false, 'errors' => ['Rapporten peger ikke på en rute.']];
    }

    $stmt = $pdo->prepare("SELECT * FROM routes WHERE id = :id AND status = 'active' LIMIT 1");
    $stmt->execute(['id' => $report['route_id']]);
    $route = $stmt->fetch();

    if ($route) {
        delete_route($pdo, $route, $adminId);
    }
    // Findes ruten ikke længere (fx allerede slettet af ejeren selv),
    // markerer vi blot rapporten som færdigbehandlet nedenfor.

    $stmt = $pdo->prepare(
        "UPDATE content_reports
         SET status = 'actioned', reviewed_at = NOW(), reviewed_by_user_id = :admin_id
         WHERE route_id = :route_id AND status = 'open'"
    );
    $stmt->execute(['admin_id' => $adminId, 'route_id' => $report['route_id']]);

    return ['success' => true];
}
