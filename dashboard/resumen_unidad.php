<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$unitId = (int)($_GET['unit_id'] ?? 0);
$source = current_workforce_source($pdo, (int)($_GET['source_id'] ?? 0));
$sourceId = (int)($source['id'] ?? 0);

if ($unitId <= 0 || $sourceId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Parámetros incompletos.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$unit = one(
    $pdo,
    "SELECT id, name, code
     FROM organizational_units
     WHERE id = :id
       AND status = 'active'
       AND lifecycle_status = 'vigente'
     LIMIT 1",
    ['id' => $unitId]
);

if (!$unit) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'message' => 'Unidad no encontrada.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ((string)($unit['code'] ?? '') === 'DN-01') {
    echo json_encode([
        'ok' => true,
        'aggregate' => false,
        'reason' => 'direccion_general_usa_clasificacion_especial',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$units = rows(
    $pdo,
    "SELECT id, parent_id
     FROM organizational_units
     WHERE status = 'active'
       AND lifecycle_status = 'vigente'"
);

$childrenByParent = [];
foreach ($units as $candidate) {
    $candidateId = (int)$candidate['id'];
    $parentId = (int)($candidate['parent_id'] ?? 0);
    if ($candidateId <= 0 || $parentId <= 0) {
        continue;
    }
    $childrenByParent[$parentId][] = $candidateId;
}

$hierarchyIds = [];
$visited = [];
$pending = [$unitId];

while ($pending) {
    $currentId = (int)array_shift($pending);
    if ($currentId <= 0 || isset($visited[$currentId])) {
        continue;
    }

    $visited[$currentId] = true;
    $hierarchyIds[] = $currentId;

    foreach ($childrenByParent[$currentId] ?? [] as $childId) {
        if (!isset($visited[$childId])) {
            $pending[] = $childId;
        }
    }
}

$placeholders = [];
$params = [
    'source_id' => $sourceId,
    'root_id' => $unitId,
];

foreach ($hierarchyIds as $index => $hierarchyId) {
    $key = 'unit_' . $index;
    $placeholders[] = ':' . $key;
    $params[$key] = $hierarchyId;
}

$summary = one(
    $pdo,
    "SELECT
        COUNT(*) AS total,
        SUM(m.matched_unit_id = :root_id) AS direct_total,
        SUM(m.review_status = 'aprobado') AS validated_total
     FROM workforce_unit_matches m
     JOIN workforce_personnel_staging p
       ON p.id = m.personnel_staging_id
     WHERE p.source_id = :source_id
       AND m.matched_unit_id IN (" . implode(', ', $placeholders) . ")",
    $params
);

$total = (int)($summary['total'] ?? 0);
$directTotal = (int)($summary['direct_total'] ?? 0);
$subordinateTotal = max(0, $total - $directTotal);

$response = [
    'ok' => true,
    'aggregate' => count($hierarchyIds) > 1,
    'unit_id' => $unitId,
    'unit_name' => (string)$unit['name'],
    'direct_total' => $directTotal,
    'subordinate_total' => $subordinateTotal,
    'total' => $total,
    'validated_total' => (int)($summary['validated_total'] ?? 0),
    'subordinate_units' => max(0, count($hierarchyIds) - 1),
];

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
