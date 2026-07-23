<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$unitId = max(0, (int) ($_GET['id'] ?? 0));
if ($unitId <= 0) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'message' => 'Identificador de unidad inválido.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$unit = one(
    $pdo,
    "SELECT
        id,
        name,
        system_code,
        code AS institutional_code,
        moi_code
     FROM organizational_units
     WHERE id = :unit_id
     LIMIT 1",
    ['unit_id' => $unitId]
);

if (!$unit) {
    http_response_code(404);
    echo json_encode([
        'ok' => false,
        'message' => 'La unidad no existe.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

echo json_encode([
    'ok' => true,
    'unit' => [
        'id' => (int) $unit['id'],
        'name' => (string) $unit['name'],
        'system_code' => (string) ($unit['system_code'] ?? ''),
        'institutional_code' => (string) ($unit['institutional_code'] ?? ''),
        'moi_code' => (string) ($unit['moi_code'] ?? ''),
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
