<?php
declare(strict_types=1);

// API: liste over aktive aktivitetstyper (til valg ved upload).
// Svar: { success, activity_types: [{ id, name_da }] }

require_once __DIR__ . '/../../../app/api_auth.php';

api_bootstrap(['GET']);

api_require_token();

// Samme sortering som upload-formularen på sitet.
$rows = get_db()->query(
    'SELECT id, name_da FROM activity_types WHERE is_active = 1 ORDER BY (slug IN (\'water_other\',\'land_other\')) DESC, name_da ASC'
)->fetchAll();

$types = array_map(
    static fn (array $row): array => ['id' => (int) $row['id'], 'name_da' => $row['name_da']],
    $rows
);

api_json(['success' => true, 'activity_types' => $types]);
