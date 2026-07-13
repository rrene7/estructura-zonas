<?php
declare(strict_types=1);

// Diagnostico de solo lectura para validar SPM dentro de DINOP.
// No inserta, actualiza ni elimina datos.
// Uso: php scripts/diagnosticar_spm_dinop.php

$configPath = __DIR__ . '/../dashboard/config.php';
if (!is_file($configPath)) {
    fwrite(STDERR, "Falta dashboard/config.php\n");
    exit(1);
}

$config = require $configPath;
$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=%s',
    $config['db_host'],
    $config['db_port'],
    $config['db_name'],
    $config['charset']
);
$pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');

function rows(PDO $pdo, string $sql, array $params = []): array
{
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return $statement->fetchAll();
}

function one(PDO $pdo, string $sql, array $params = []): ?array
{
    $result = rows($pdo, $sql, $params);
    return $result[0] ?? null;
}

function normalizeKey(mixed $value): string
{
    $text = trim((string)$value);
    $text = function_exists('mb_strtoupper') ? mb_strtoupper($text, 'UTF-8') : strtoupper($text);
    $text = strtr($text, [
        'Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N','ª'=>'A','º'=>'O',
    ]);
    $text = preg_replace('/[^A-Z0-9]+/u', ' ', $text);
    return trim((string)preg_replace('/\s+/', ' ', (string)$text));
}

$dinop = one(
    $pdo,
    "SELECT ou.id,ou.name,ou.short_name,ou.code,ou.moi_code,ut.name AS unit_type
     FROM organizational_units ou
     LEFT JOIN unit_types ut ON ut.id=ou.unit_type_id
     WHERE BINARY ou.legacy_table=BINARY 'MOI_CABECERA_DIRECCION'
       AND CAST(ou.legacy_id AS UNSIGNED)=7
       AND ou.status='active'
       AND ou.lifecycle_status='vigente'
       AND (ou.valid_from IS NULL OR ou.valid_from<=CURRENT_DATE)
       AND (ou.valid_to IS NULL OR ou.valid_to>=CURRENT_DATE)
     LIMIT 1"
);
if (!$dinop) {
    fwrite(STDERR, "No se encontro la cabecera vigente de DINOP.\n");
    exit(1);
}

$tree = rows(
    $pdo,
    "WITH RECURSIVE dinop_tree AS (
        SELECT ou.id,ou.parent_id,ou.name,ou.short_name,ou.code,ou.moi_code,
               ou.legacy_table,ou.legacy_id,ut.name AS unit_type,0 AS depth
        FROM organizational_units ou
        LEFT JOIN unit_types ut ON ut.id=ou.unit_type_id
        WHERE ou.id=:root_id

        UNION ALL

        SELECT child.id,child.parent_id,child.name,child.short_name,child.code,child.moi_code,
               child.legacy_table,child.legacy_id,ut.name AS unit_type,parent.depth+1
        FROM organizational_units child
        JOIN dinop_tree parent ON child.parent_id=parent.id
        LEFT JOIN unit_types ut ON ut.id=child.unit_type_id
        WHERE child.status='active'
          AND child.lifecycle_status='vigente'
          AND (child.valid_from IS NULL OR child.valid_from<=CURRENT_DATE)
          AND (child.valid_to IS NULL OR child.valid_to>=CURRENT_DATE)
    )
    SELECT * FROM dinop_tree ORDER BY depth,name",
    ['root_id'=>(int)$dinop['id']]
);

$spmCandidates = [];
foreach ($tree as $unit) {
    if ((int)$unit['depth'] === 0) {
        continue;
    }
    $aliases = [
        normalizeKey($unit['name'] ?? ''),
        normalizeKey($unit['short_name'] ?? ''),
        normalizeKey($unit['code'] ?? ''),
        normalizeKey($unit['moi_code'] ?? ''),
    ];
    $aliases = array_values(array_unique(array_filter($aliases)));
    foreach ($aliases as $alias) {
        if (
            $alias === 'SPM' ||
            $alias === 'SERVICIO POLICIAL MOTORIZADO' ||
            str_contains($alias, 'SERVICIO POLICIAL MOTORIZADO')
        ) {
            $spmCandidates[(int)$unit['id']] = $unit;
            break;
        }
    }
}

$source = one(
    $pdo,
    "SELECT id FROM workforce_sources WHERE source_key='PIE_FUERZA_20260626' LIMIT 1"
);
if (!$source) {
    fwrite(STDERR, "No existe la fuente PIE_FUERZA_20260626.\n");
    exit(1);
}

$groups = rows(
    $pdo,
    "SELECT p.location_original,COUNT(*) AS people,
            MIN(m.assignment_status) AS assignment_status,
            GROUP_CONCAT(DISTINCT ou.name ORDER BY ou.name SEPARATOR ' | ') AS current_units
     FROM workforce_personnel_staging p
     LEFT JOIN workforce_unit_matches m ON m.personnel_staging_id=p.id
     LEFT JOIN organizational_units ou ON ou.id=m.matched_unit_id
     WHERE p.source_id=:source_id
       AND p.import_status='importado'
       AND (
            p.location_normalized LIKE 'DINOP SPM %'
            OR p.location_normalized='DINOP SPM'
            OR p.location_normalized LIKE '% SERVICIO POLICIAL MOTORIZADO %'
            OR p.location_normalized LIKE 'SERVICIO POLICIAL MOTORIZADO %'
       )
     GROUP BY p.location_original
     ORDER BY people DESC,p.location_original",
    ['source_id'=>(int)$source['id']]
);

$totalPeople = array_sum(array_map(static fn(array $row): int => (int)$row['people'], $groups));

echo "DIAGNOSTICO SPM / DINOP\n";
echo "Cabecera funcional: {$dinop['name']}\n";
echo "Candidatos SPM vigentes dentro de DINOP: " . count($spmCandidates) . "\n\n";

if ($spmCandidates === []) {
    echo "No existe una coincidencia vigente unica para SPM debajo de DINOP.\n";
    echo "No debe hacerse una asignacion completa ni crearse una unidad desde el Excel.\n\n";
} else {
    foreach ($spmCandidates as $unit) {
        echo '- [' . $unit['id'] . '] ' . $unit['name'];
        echo ' | tipo=' . ($unit['unit_type'] ?: 'sin_tipo');
        echo ' | corto=' . ($unit['short_name'] ?: 'sin_nombre_corto');
        echo ' | codigo=' . ($unit['moi_code'] ?: $unit['code'] ?: 'sin_codigo');
        echo ' | profundidad=' . $unit['depth'];
        echo "\n";
    }
    echo "\n";
}

echo "Grupos del PIE DE FUERZA que mencionan SPM: " . count($groups) . "\n";
echo "Personas totales que mencionan SPM: {$totalPeople}\n\n";

foreach ($groups as $group) {
    echo str_repeat('-', 78) . "\n";
    echo "Personas: {$group['people']}\n";
    echo "Ubicacion original: {$group['location_original']}\n";
    echo "Unidad actual: " . ($group['current_units'] ?: 'SIN UNIDAD') . "\n";
    echo "Estado actual: " . ($group['assignment_status'] ?: 'pendiente_revision') . "\n";
}

echo "\nRESULTADO\n";
if (count($spmCandidates) === 1) {
    $candidate = array_values($spmCandidates)[0];
    echo "Existe una unica unidad SPM vigente debajo de DINOP: [{$candidate['id']}] {$candidate['name']}.\n";
    echo "Puede prepararse la asignacion del grupo a esa unidad, conservando DINOP como direccion de conteo.\n";
} elseif (count($spmCandidates) > 1) {
    echo "Hay varias unidades candidatas. Se requiere revision manual antes de asignar.\n";
} else {
    echo "No existe una unidad SPM vigente debajo de DINOP. El grupo debe permanecer parcial en DINOP.\n";
}

echo "Diagnostico finalizado. No se modifico ninguna tabla.\n";
