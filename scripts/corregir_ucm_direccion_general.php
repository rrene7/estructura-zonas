<?php
declare(strict_types=1);

// Corrige la clasificacion de la unidad U.C.M. ya existente y completa
// la asignacion del grupo DIRECCION GENERAL.U.C.M. del PIE DE FUERZA.
//
// Reglas de seguridad:
// - No crea una unidad organizacional nueva.
// - No cambia el parent_id existente.
// - Exige que la unidad TABCUAR 100250000 ya dependa de Direccion General.
// - Conserva las excepciones individuales realizadas con revision_manual.
//
// Uso:
// php scripts/corregir_ucm_direccion_general.php

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

function executeSql(PDO $pdo, string $sql, array $params = []): int
{
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return $statement->rowCount();
}

function tableExists(PDO $pdo, string $table): bool
{
    $row = one(
        $pdo,
        'SELECT COUNT(*) total FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=:table',
        ['table' => $table]
    );
    return (int)($row['total'] ?? 0) > 0;
}

function normalizeKey(mixed $value): string
{
    $text = trim((string)$value);
    $text = function_exists('mb_strtoupper') ? mb_strtoupper($text, 'UTF-8') : strtoupper($text);
    $text = strtr($text, [
        'Á'=>'A', 'É'=>'E', 'Í'=>'I', 'Ó'=>'O', 'Ú'=>'U', 'Ü'=>'U', 'Ñ'=>'N',
    ]);
    $text = preg_replace('/[^A-Z0-9]+/u', ' ', $text);
    return trim((string)preg_replace('/\s+/', ' ', (string)$text));
}

$legacyTable = 'TABCUAR';
$legacyId = '100250000';
$sourceKey = 'PIE_FUERZA_20260626';
$locationNormalized = 'DIRECCION GENERAL U C M';

try {
    $pdo->beginTransaction();

    $units = rows(
        $pdo,
        "SELECT ou.id,ou.parent_id,ou.name,ou.short_name,ou.unit_type_id,ou.legacy_table,ou.legacy_id,
                ou.lifecycle_status,ou.status,ut.name AS unit_type,parent.name AS parent_name
         FROM organizational_units ou
         LEFT JOIN unit_types ut ON ut.id=ou.unit_type_id
         LEFT JOIN organizational_units parent ON parent.id=ou.parent_id
         WHERE BINARY ou.legacy_table=BINARY :legacy_table
           AND BINARY CAST(ou.legacy_id AS CHAR)=BINARY :legacy_id
         FOR UPDATE",
        ['legacy_table'=>$legacyTable, 'legacy_id'=>$legacyId]
    );

    if (count($units) !== 1) {
        throw new RuntimeException(
            'Se esperaba exactamente una unidad TABCUAR 100250000 y se encontraron ' . count($units) . '. No se aplicaron cambios.'
        );
    }

    $ucm = $units[0];
    if (empty($ucm['parent_id']) || normalizeKey($ucm['parent_name'] ?? '') !== 'DIRECCION GENERAL') {
        throw new RuntimeException(
            'La unidad U.C.M. no tiene como superior actual a Direccion General. Superior detectado: ' .
            (($ucm['parent_name'] ?? '') !== '' ? $ucm['parent_name'] : 'Sin superior') . '. No se aplicaron cambios.'
        );
    }
    if (($ucm['status'] ?? '') !== 'active' || ($ucm['lifecycle_status'] ?? '') !== 'vigente') {
        throw new RuntimeException('La unidad U.C.M. no esta activa y vigente. No se aplicaron cambios.');
    }

    executeSql(
        $pdo,
        "INSERT IGNORE INTO unit_types (name,description,created_at,updated_at)
         VALUES ('servicio_policial','Servicio policial subordinado a una cabecera institucional',NOW(),NOW())"
    );
    $serviceType = one($pdo, "SELECT id FROM unit_types WHERE name='servicio_policial' LIMIT 1");
    if (!$serviceType) {
        throw new RuntimeException('No se pudo obtener el tipo servicio_policial.');
    }

    executeSql(
        $pdo,
        "UPDATE organizational_units
         SET unit_type_id=:unit_type_id,
             name='Unidad de Control de Multitud (U.C.M.)',
             short_name='U.C.M.',
             territorial_scope='especializado',
             command_structure='mando_directo',
             command_relationship='operacional',
             is_operational=1,
             is_administrative=0,
             is_decision_center=0,
             is_operational_executor=1,
             lifecycle_notes='Unidad de Control de Multitud subordinada directamente a Direccion General. Clasificacion institucional validada.',
             updated_at=NOW()
         WHERE id=:id",
        ['unit_type_id'=>(int)$serviceType['id'], 'id'=>(int)$ucm['id']]
    );

    if (tableExists($pdo, 'organizational_unit_relationships')) {
        executeSql(
            $pdo,
            "INSERT INTO organizational_unit_relationships
             (source_unit_id,target_unit_id,relationship_type,valid_from,valid_to,status,notes,created_at,updated_at)
             SELECT :source_id,:target_id,'jerarquica',CURRENT_DATE,NULL,'active',
                    'U.C.M. depende directamente de Direccion General',NOW(),NOW()
             WHERE NOT EXISTS (
                 SELECT 1 FROM organizational_unit_relationships
                 WHERE source_unit_id=:source_id_check
                   AND target_unit_id=:target_id_check
                   AND relationship_type='jerarquica'
                   AND status='active'
             )",
            [
                'source_id'=>(int)$ucm['id'],
                'target_id'=>(int)$ucm['parent_id'],
                'source_id_check'=>(int)$ucm['id'],
                'target_id_check'=>(int)$ucm['parent_id'],
            ]
        );
    }

    $source = one($pdo, 'SELECT id FROM workforce_sources WHERE source_key=:source_key LIMIT 1', ['source_key'=>$sourceKey]);
    $groupTotal = 0;
    $manualExceptions = 0;
    $eligible = 0;

    if ($source && tableExists($pdo, 'workforce_personnel_staging') && tableExists($pdo, 'workforce_unit_matches')) {
        $counts = one(
            $pdo,
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN COALESCE(m.match_method,'')='revision_manual' THEN 1 ELSE 0 END) AS manuales
             FROM workforce_personnel_staging p
             LEFT JOIN workforce_unit_matches m ON m.personnel_staging_id=p.id
             WHERE p.source_id=:source_id
               AND p.import_status='importado'
               AND p.location_normalized=:location",
            ['source_id'=>(int)$source['id'], 'location'=>$locationNormalized]
        );
        $groupTotal = (int)($counts['total'] ?? 0);
        $manualExceptions = (int)($counts['manuales'] ?? 0);
        $eligible = max(0, $groupTotal - $manualExceptions);

        if ($eligible > 0) {
            executeSql(
                $pdo,
                "INSERT INTO workforce_unit_matches
                 (personnel_staging_id,matched_unit_id,matched_level,assignment_status,pending_level,
                  match_method,confidence_level,candidate_count,candidate_data,review_status,
                  review_notes,reviewed_by,reviewed_at)
                 SELECT p.id,:unit_id,'servicio','asignado_completo',NULL,
                        'validacion_estructura_ucm','alto',1,NULL,'aprobado',
                        'U.C.M. confirmada como Unidad de Control de Multitud subordinada a Direccion General.',
                        'validacion_institucional',NOW()
                 FROM workforce_personnel_staging p
                 LEFT JOIN workforce_unit_matches current_match ON current_match.personnel_staging_id=p.id
                 WHERE p.source_id=:source_id
                   AND p.import_status='importado'
                   AND p.location_normalized=:location
                   AND COALESCE(current_match.match_method,'')<>'revision_manual'
                 ON DUPLICATE KEY UPDATE
                    matched_unit_id=VALUES(matched_unit_id),
                    matched_level='servicio',
                    assignment_status='asignado_completo',
                    pending_level=NULL,
                    match_method='validacion_estructura_ucm',
                    confidence_level='alto',
                    candidate_count=1,
                    candidate_data=NULL,
                    review_status='aprobado',
                    review_notes=VALUES(review_notes),
                    reviewed_by='validacion_institucional',
                    reviewed_at=NOW(),
                    updated_at=NOW()",
                [
                    'unit_id'=>(int)$ucm['id'],
                    'source_id'=>(int)$source['id'],
                    'location'=>$locationNormalized,
                ]
            );
        }
    }

    $pdo->commit();

    echo "Unidad corregida: Unidad de Control de Multitud (U.C.M.)\n";
    echo "Legacy conservado: {$legacyTable}: {$legacyId}\n";
    echo "Superior conservado: {$ucm['parent_name']}\n";
    echo "Tipo anterior: " . ($ucm['unit_type'] ?: 'sin tipo') . "\n";
    echo "Tipo nuevo: servicio_policial\n";
    echo "Personas encontradas en DIRECCION GENERAL.U.C.M.: {$groupTotal}\n";
    echo "Asignadas completamente a U.C.M.: {$eligible}\n";
    echo "Excepciones manuales conservadas: {$manualExceptions}\n";
    echo "No se creo ninguna unidad y no se cambio el parent_id.\n";
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Error: ' . $exception->getMessage() . "\n");
    exit(1);
}
