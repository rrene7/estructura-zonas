<?php
declare(strict_types=1);

// Diagnostico de solo lectura para comparar ubicaciones internas del PIE DE FUERZA
// contra unidades vigentes ya existentes debajo de DINOP.
//
// No inserta, actualiza ni elimina datos.
// Uso:
// php scripts/diagnosticar_unidades_internas_dinop.php

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
        'Á'=>'A', 'É'=>'E', 'Í'=>'I', 'Ó'=>'O', 'Ú'=>'U', 'Ü'=>'U', 'Ñ'=>'N',
        'ª'=>'A', 'º'=>'O',
    ]);
    $text = preg_replace('/[^A-Z0-9]+/u', ' ', $text);
    return trim((string)preg_replace('/\s+/', ' ', (string)$text));
}

function aliasesForUnit(array $unit): array
{
    $aliases = [];
    foreach (['name', 'short_name', 'code', 'moi_code'] as $field) {
        $alias = normalizeKey($unit[$field] ?? '');
        if ($alias === '' || preg_match('/^\d+$/', $alias) === 1) {
            continue;
        }
        $aliases[$alias] = $field;
    }
    return $aliases;
}

function containsPhrase(string $haystack, string $needle): bool
{
    if ($needle === '') {
        return false;
    }
    return preg_match('/(?:^|\s)' . preg_quote($needle, '/') . '(?:\s|$)/u', $haystack) === 1;
}

function extractInternalText(string $location): string
{
    $text = normalizeKey($location);
    $text = preg_replace('/^(DINOP|DIRECCION NACIONAL DE OPERACIONES POLICIALES|DIRECCION NACIONAL OPERACIONES POLICIALES)(?:\s|$)/u', ' ', $text) ?? $text;

    // Se retiran referencias territoriales, pero no se inventa ninguna unidad interna.
    $text = preg_replace('/(?:^|\s)\d{1,2}(?:RA|DA|TA|MA|NA|VA|A)?\s*ZP(?:\s|$)/u', ' ', $text) ?? $text;
    $text = preg_replace('/(?:^|\s)\d{1,2}\s+ZONA\s+POLICIAL(?:\s|$)/u', ' ', $text) ?? $text;
    $text = preg_replace('/(?:^|\s)ZP\s*\d{1,2}(?:\s|$)/u', ' ', $text) ?? $text;
    $text = preg_replace('/(?:^|\s)ZONA(?:\s+POLICIAL)?\s*\d{1,2}(?:\s|$)/u', ' ', $text) ?? $text;

    return trim((string)preg_replace('/\s+/', ' ', $text));
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

$units = rows(
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

$descendants = array_values(array_filter($units, static fn(array $unit): bool => (int)$unit['depth'] > 0));

$groups = rows(
    $pdo,
    "SELECT p.location_original,COUNT(*) AS people
     FROM workforce_personnel_staging p
     JOIN workforce_unit_matches m ON m.personnel_staging_id=p.id
     WHERE m.matched_unit_id=:dinop_id
       AND p.import_status='importado'
     GROUP BY p.location_original
     ORDER BY people DESC,p.location_original
     LIMIT 80",
    ['dinop_id'=>(int)$dinop['id']]
);

echo "CABECERA DINOP\n";
echo "ID: {$dinop['id']}\n";
echo "Nombre: {$dinop['name']}\n";
echo "Unidades vigentes subordinadas encontradas: " . count($descendants) . "\n\n";

echo "ARBOL VIGENTE DINOP\n";
if ($descendants === []) {
    echo "- No hay unidades subordinadas vigentes registradas debajo de DINOP.\n";
} else {
    foreach ($descendants as $unit) {
        $indent = str_repeat('  ', max(0, (int)$unit['depth'] - 1));
        echo $indent . '- [' . $unit['id'] . '] ' . $unit['name'];
        echo ' | tipo=' . ($unit['unit_type'] ?: 'sin_tipo');
        if (!empty($unit['short_name'])) {
            echo ' | corto=' . $unit['short_name'];
        }
        if (!empty($unit['moi_code']) || !empty($unit['code'])) {
            echo ' | codigo=' . ($unit['moi_code'] ?: $unit['code']);
        }
        echo "\n";
    }
}

echo "\nUBICACIONES DEL PIE DE FUERZA Y CANDIDATOS EXISTENTES\n";
echo "Se muestran hasta 80 grupos. Solo se informa coincidencia cuando un nombre, nombre corto o codigo existente aparece en la ubicacion.\n\n";

foreach ($groups as $group) {
    $locationOriginal = (string)$group['location_original'];
    $locationNormalized = normalizeKey($locationOriginal);
    $internalText = extractInternalText($locationOriginal);
    $matches = [];

    foreach ($descendants as $unit) {
        foreach (aliasesForUnit($unit) as $alias => $source) {
            if (strlen($alias) < 3) {
                continue;
            }
            if (containsPhrase($locationNormalized, $alias) || containsPhrase($internalText, $alias)) {
                $matches[(int)$unit['id']] = [
                    'id'=>(int)$unit['id'],
                    'name'=>$unit['name'],
                    'type'=>$unit['unit_type'],
                    'alias'=>$alias,
                    'source'=>$source,
                    'length'=>strlen($alias),
                ];
            }
        }
    }

    usort($matches, static fn(array $a, array $b): int => $b['length'] <=> $a['length']);
    $top = $matches[0] ?? null;

    echo str_repeat('-', 78) . "\n";
    echo "Personas: {$group['people']}\n";
    echo "Ubicacion original: {$locationOriginal}\n";
    echo "Detalle interno normalizado: " . ($internalText !== '' ? $internalText : 'SIN DETALLE') . "\n";
    if ($top) {
        echo "Candidato existente: [{$top['id']}] {$top['name']} | tipo={$top['type']} | alias={$top['alias']}\n";
        echo "Coincidencias existentes encontradas: " . count($matches) . "\n";
    } else {
        echo "Candidato existente: SIN COINCIDENCIA DIRECTA\n";
    }
}

echo "\nDiagnostico finalizado. No se modifico ninguna tabla.\n";
