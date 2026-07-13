<?php
declare(strict_types=1);

// Registra o reutiliza una cabecera vigente de servicio policial bajo la cabecera
// canonica de Direccion General y asigna registros del PIE DE FUERZA mediante un
// token de ubicacion confirmado externamente.
//
// Este script es generico: los datos institucionales se suministran por CLI y no
// quedan codificados en el repositorio.
//
// Uso:
// php scripts/registrar_servicio_policial_pie_fuerza.php \
//   --name="Nombre oficial" \
//   --short="SIGLA" \
//   --moi-code="CODIGO" \
//   --location-token="TOKEN NORMALIZADO" \
//   --valid-from="YYYY-MM-DD"

$configPath = __DIR__ . '/../dashboard/config.php';
if (!is_file($configPath)) {
    fwrite(STDERR, "Falta dashboard/config.php\n");
    exit(1);
}

$options = getopt('', [
    'name:',
    'short:',
    'moi-code:',
    'location-token:',
    'valid-from::',
    'source-key::',
]);

$name = trim((string)($options['name'] ?? ''));
$short = trim((string)($options['short'] ?? ''));
$moiCode = trim((string)($options['moi-code'] ?? ''));
$locationToken = trim((string)($options['location-token'] ?? ''));
$validFrom = trim((string)($options['valid-from'] ?? date('Y-m-d')));
$sourceKey = trim((string)($options['source-key'] ?? 'PIE_FUERZA_20260626'));

if ($name === '' || $short === '' || $moiCode === '' || $locationToken === '') {
    fwrite(STDERR, "Faltan parametros obligatorios: --name, --short, --moi-code y --location-token.\n");
    exit(1);
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $validFrom) !== 1) {
    fwrite(STDERR, "--valid-from debe tener formato YYYY-MM-DD.\n");
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

function tableExists(PDO $pdo, string $table): bool
{
    $row = one(
        $pdo,
        'SELECT COUNT(*) AS total FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=:table',
        ['table'=>$table]
    );
    return (int)($row['total'] ?? 0) > 0;
}

$normalizedToken = normalizeKey($locationToken);
$normalizedShort = normalizeKey($short);
$normalizedMoiCode = normalizeKey($moiCode);
if ($normalizedToken === '' || $normalizedShort === '' || $normalizedMoiCode === '') {
    fwrite(STDERR, "Los parametros normalizados no pueden quedar vacios.\n");
    exit(1);
}

foreach (['organizational_units','unit_types','workforce_sources','workforce_personnel_staging','workforce_unit_matches'] as $requiredTable) {
    if (!tableExists($pdo, $requiredTable)) {
        fwrite(STDERR, "Falta la tabla requerida {$requiredTable}.\n");
        exit(1);
    }
}

try {
    $pdo->beginTransaction();

    $roots = rows(
        $pdo,
        "SELECT ou.id,ou.name,ou.level,ou.moi_level
         FROM organizational_units ou
         LEFT JOIN unit_types ut ON ut.id=ou.unit_type_id
         WHERE BINARY ou.legacy_table=BINARY 'MOI_CABECERA_DIRECCION'
           AND CAST(ou.legacy_id AS UNSIGNED)=1
           AND ut.name='directorio_general'
           AND ou.status='active'
           AND ou.lifecycle_status='vigente'
           AND (ou.valid_from IS NULL OR ou.valid_from<=CURRENT_DATE)
           AND (ou.valid_to IS NULL OR ou.valid_to>=CURRENT_DATE)
         FOR UPDATE"
    );
    if (count($roots) !== 1) {
        throw new RuntimeException('Se esperaba una sola cabecera canonica vigente de Direccion General y se encontraron ' . count($roots) . '.');
    }
    $root = $roots[0];

    $pdo->exec(
        "INSERT IGNORE INTO unit_types (name,description,created_at,updated_at)
         VALUES ('servicio_policial','Servicio policial',NOW(),NOW())"
    );
    $serviceType = one($pdo, "SELECT id FROM unit_types WHERE name='servicio_policial' LIMIT 1");
    if (!$serviceType) {
        throw new RuntimeException('No se pudo obtener el tipo servicio_policial.');
    }

    $serviceCandidates = rows(
        $pdo,
        "SELECT ou.id,ou.parent_id,ou.name,ou.short_name,ou.code,ou.moi_code,ou.status,ou.lifecycle_status
         FROM organizational_units ou
         WHERE (
              UPPER(COALESCE(ou.short_name,''))=UPPER(:short_name)
              OR UPPER(COALESCE(ou.code,''))=UPPER(:code)
              OR UPPER(COALESCE(ou.moi_code,''))=UPPER(:moi_code)
         )
         FOR UPDATE",
        ['short_name'=>$short,'code'=>$moiCode,'moi_code'=>$moiCode]
    );

    if (count($serviceCandidates) > 1) {
        throw new RuntimeException('Hay varias unidades candidatas con la misma sigla o codigo. No se aplicaron cambios.');
    }

    $created = false;
    if ($serviceCandidates === []) {
        $insertService = $pdo->prepare(
            "INSERT INTO organizational_units
             (parent_id,unit_type_id,code,moi_code,name,short_name,level,moi_level,
              is_operational,is_administrative,command_structure,command_relationship,
              territorial_scope,functional_axis,is_decision_center,is_operational_executor,
              status,lifecycle_status,valid_from,valid_to,lifecycle_notes,
              legacy_table,legacy_id,created_at,updated_at)
             VALUES
             (:parent_id,:unit_type_id,:code,:moi_code,:name,:short_name,:level,:moi_level,
              1,0,'mando_directo','operacional','nacional','servicio_policial',0,1,
              'active','vigente',:valid_from,NULL,
              'Cabecera de servicio policial validada institucionalmente.',
              'MOI_CABECERA_SERVICIO',:legacy_id,NOW(),NOW())"
        );
        $insertService->execute([
            'parent_id'=>(int)$root['id'],
            'unit_type_id'=>(int)$serviceType['id'],
            'code'=>$moiCode,
            'moi_code'=>$moiCode,
            'name'=>$name,
            'short_name'=>$short,
            'level'=>((int)($root['level'] ?? 0)) + 1,
            'moi_level'=>((int)($root['moi_level'] ?? 0)) + 1,
            'valid_from'=>$validFrom,
            'legacy_id'=>$moiCode,
        ]);
        $serviceId = (int)$pdo->lastInsertId();
        $created = true;
    } else {
        $service = $serviceCandidates[0];
        if ((int)$service['parent_id'] !== (int)$root['id']) {
            throw new RuntimeException('La unidad candidata existente no depende de la cabecera canonica de Direccion General. No se aplicaron cambios.');
        }
        if (($service['status'] ?? '') !== 'active' || ($service['lifecycle_status'] ?? '') !== 'vigente') {
            throw new RuntimeException('La unidad candidata existente no esta activa y vigente. No se aplicaron cambios.');
        }
        $serviceId = (int)$service['id'];
    }

    if (tableExists($pdo, 'organizational_unit_relationships')) {
        $relationship = $pdo->prepare(
            "INSERT INTO organizational_unit_relationships
             (source_unit_id,target_unit_id,relationship_type,valid_from,valid_to,status,notes,created_at,updated_at)
             SELECT :source_id,:target_id,'jerarquica',:valid_from,NULL,'active',
                    'Relacion jerarquica de servicio policial validada institucionalmente.',NOW(),NOW()
             WHERE NOT EXISTS (
                 SELECT 1 FROM organizational_unit_relationships
                 WHERE source_unit_id=:source_id_check
                   AND target_unit_id=:target_id_check
                   AND relationship_type='jerarquica'
                   AND status='active'
             )"
        );
        $relationship->execute([
            'source_id'=>$serviceId,
            'target_id'=>(int)$root['id'],
            'valid_from'=>$validFrom,
            'source_id_check'=>$serviceId,
            'target_id_check'=>(int)$root['id'],
        ]);
    }

    $source = one(
        $pdo,
        'SELECT id FROM workforce_sources WHERE source_key=:source_key LIMIT 1',
        ['source_key'=>$sourceKey]
    );
    if (!$source) {
        throw new RuntimeException("No existe la fuente {$sourceKey}.");
    }

    $people = rows(
        $pdo,
        "SELECT p.id,p.location_original,p.location_normalized,
                m.matched_unit_id,m.match_method,m.review_status
         FROM workforce_personnel_staging p
         LEFT JOIN workforce_unit_matches m ON m.personnel_staging_id=p.id
         WHERE p.source_id=:source_id
           AND p.import_status='importado'
           AND (p.location_normalized=:token OR p.location_normalized LIKE CONCAT(:token_prefix,'%'))
         ORDER BY p.row_number",
        [
            'source_id'=>(int)$source['id'],
            'token'=>$normalizedToken,
            'token_prefix'=>$normalizedToken . ' ',
        ]
    );

    $upsert = $pdo->prepare(
        "INSERT INTO workforce_unit_matches
         (personnel_staging_id,matched_unit_id,matched_level,assignment_status,pending_level,
          match_method,confidence_level,candidate_count,candidate_data,review_status,
          review_notes,reviewed_by,reviewed_at)
         VALUES
         (:personnel_id,:unit_id,'servicio',:assignment_status,:pending_level,
          'prioridad_servicio_policial','alto',1,NULL,'aprobado',
          :review_notes,'validacion_institucional',NOW())
         ON DUPLICATE KEY UPDATE
            matched_unit_id=VALUES(matched_unit_id),
            matched_level='servicio',
            assignment_status=VALUES(assignment_status),
            pending_level=VALUES(pending_level),
            match_method='prioridad_servicio_policial',
            confidence_level='alto',
            candidate_count=1,
            candidate_data=NULL,
            review_status='aprobado',
            review_notes=VALUES(review_notes),
            reviewed_by='validacion_institucional',
            reviewed_at=NOW(),
            updated_at=NOW()"
    );

    $complete = 0;
    $partial = 0;
    $manualPreserved = 0;
    $approvedPreserved = 0;

    foreach ($people as $person) {
        if (($person['match_method'] ?? '') === 'revision_manual') {
            $manualPreserved++;
            continue;
        }
        if (
            ($person['review_status'] ?? '') === 'aprobado' &&
            (int)($person['matched_unit_id'] ?? 0) !== $serviceId
        ) {
            $approvedPreserved++;
            continue;
        }

        $locationNormalized = normalizeKey($person['location_normalized'] ?: $person['location_original']);
        $residual = trim(substr($locationNormalized, strlen($normalizedToken)));
        $isComplete = $residual === '';
        $assignmentStatus = $isComplete ? 'asignado_completo' : 'asignado_parcial';
        $pendingLevel = $isComplete ? null : 'seccion/unidad';
        $notes = $isComplete
            ? 'Pertenencia al servicio policial confirmada institucionalmente.'
            : 'Pertenencia al servicio policial confirmada institucionalmente. Detalle interno pendiente: ' . $residual;

        $upsert->execute([
            'personnel_id'=>(int)$person['id'],
            'unit_id'=>$serviceId,
            'assignment_status'=>$assignmentStatus,
            'pending_level'=>$pendingLevel,
            'review_notes'=>$notes,
        ]);

        if ($isComplete) {
            $complete++;
        } else {
            $partial++;
        }
    }

    $pdo->commit();

    echo 'Servicio: ' . $name . "\n";
    echo 'ID: ' . $serviceId . "\n";
    echo 'Cabecera superior: ' . $root['name'] . ' [' . $root['id'] . "]\n";
    echo 'Unidad creada: ' . ($created ? 'SI' : 'NO, se reutilizo la existente') . "\n";
    echo 'Registros encontrados por token: ' . count($people) . "\n";
    echo 'Asignaciones completas: ' . $complete . "\n";
    echo 'Asignaciones parciales: ' . $partial . "\n";
    echo 'Revisiones manuales conservadas: ' . $manualPreserved . "\n";
    echo 'Otras revisiones aprobadas conservadas: ' . $approvedPreserved . "\n";
    echo "El conteo principal queda en el servicio policial.\n";
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Error: ' . $exception->getMessage() . "\n");
    exit(1);
}
