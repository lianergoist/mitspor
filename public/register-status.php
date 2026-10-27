<?php
declare(strict_types=1);

/*
 * Spørges af siden "Tjek din indbakke" i appen (assets/afvent-bekraeftelse.js):
 * er den nye konto bekræftet, logges sessionen ind. Se app/afvent_bekraeftelse.php.
 */

session_start();

require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/afvent_bekraeftelse.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'ingen']);
    exit;
}

if (!csrf_validate($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['status' => 'ingen']);
    exit;
}

echo json_encode(['status' => afvent_bekraeftelse_tjek()]);
