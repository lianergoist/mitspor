<?php
declare(strict_types=1);

session_start();

header('Content-Type: application/json; charset=UTF-8');

// Kræver login, så dette ikke bliver et åbent, gratis relæ for hvem som
// helst til at misbruge Open Topo Datas gratis kvote.
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Du skal være logget ind.']);
    exit;
}

$locations = (string) ($_GET['locations'] ?? '');

if ($locations === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Manglende locations-parameter.']);
    exit;
}

// Simpel formvalidering: kun tal, komma, punktum, pipe og minus tilladt
// (samme format som "lat,lon|lat,lon|..."), så vi ikke videresender
// vilkårlig brugerinput til en ekstern URL.
if (!preg_match('/^-?[0-9.,|-]+$/', $locations)) {
    http_response_code(400);
    echo json_encode(['error' => 'Ugyldigt format på locations.']);
    exit;
}

$url = 'https://api.opentopodata.org/v1/srtm30m?locations=' . urlencode($locations);

$context = stream_context_create([
    'http' => [
        'method'  => 'GET',
        'timeout' => 15,
        'header'  => "User-Agent: MitSpor/1.0\r\n",
        'ignore_errors' => true, // så vi selv kan læse fejl-svar (4xx/5xx) i stedet for en PHP-advarsel
    ],
]);

$response = @file_get_contents($url, false, $context);

if ($response === false) {
    http_response_code(502);
    echo json_encode(['error' => "Kunne ikke kontakte højdedata-API'et."]);
    exit;
}

// Videresend Open Topo Datas egen statuskode, hvis vi kan aflæse den.
$httpCode = 200;
if (isset($http_response_header) && is_array($http_response_header)) {
    foreach ($http_response_header as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $header, $m)) {
            $httpCode = (int) $m[1];
        }
    }
}
http_response_code($httpCode);

echo $response;
