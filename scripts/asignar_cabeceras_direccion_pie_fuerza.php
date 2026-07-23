<?php
declare(strict_types=1);

// Asigna ubicaciones evidentes del PIE DE FUERZA a direcciones cabecera vigentes.
// No crea, renombra, mueve ni modifica organizational_units.
// Las ubicaciones con detalle interno quedan como asignacion parcial a la direccion.
// Conserva cualquier revision ya aprobada, incluida U.C.M. y excepciones manuales.
//
// Uso:
// php scripts/asignar_cabeceras_direccion_pie_fuerza.php

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

function tableExists(PDO $pdo, string $table): bool
{
    $row = one(
        $pdo,
        'SELECT COUNT(*) total FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=:table',
        ['table'=>$table]
    );
    return (int)($row['total'] ?? 0) > 0;
}

if (
    !tableExists($pdo, 'moi_direcciones_cabecera_vigentes') ||
    !tableExists($pdo, 'workforce_sources') ||
    !tableExists($pdo, 'workforce_personnel_staging') ||
    !tableExists($pdo, 'workforce_unit_matches')
) {
    fwrite(STDERR, "Faltan tablas requeridas para asignar el PIE DE FUERZA.\n");
    exit(1);
}

$sourceKey = 'PIE_FUERZA_20260626';
$source = one($pdo, 'SELECT id FROM workforce_sources WHERE source_key=:source_key LIMIT 1', ['source_key'=>$sourceKey]);
if (!$source) {
    fwrite(STDERR, "No existe la fuente {$sourceKey}.\n");
    exit(1);
}

// Reglas institucionales confirmadas. La expresion se aplica sobre location_normalized.
$rules = [
    [
        'direction_number'=>1,
        'label'=>'Direccion General',
        'patterns'=>[
            '/^(DIRECCION GENERAL|DIR GENERAL|DIR GNRAL)(?:\s|$)/u',
        ],
    ],
    [
        'direction_number'=>2,
        'label'=>'Direccion Nacional de Inteligencia Policial',
        'patterns'=>[
            '/^(DIRECCION NACIONAL DE INTELIGENCIA POLICIAL|DIRECCION NACIONAL INTELIGENCIA POLICIAL|DIR NAL INTELIGENCIA POLICIAL|DIR NAC INTELIGENCIA POLICIAL|DNIP)(?:\s|$)/u',
        ],
    ],
    [
        'direction_number'=>6,
        'label'=>'Direccion Nacional Antidrogas',
        'patterns'=>[
            '/^(DIRECCION NACIONAL ANTIDROGAS|DIR NAL ANTIDROGAS|DIR NAC ANTIDROGAS)(?:\s|$)/u',
        ],
    ],
    [
        'direction_number'=>7,
        'label'=>'Direccion Nacional de Operaciones Policiales',
        'patterns'=>[
            '/^(DINOP|DIRECCION NACIONAL DE OPERACIONES POLICIALES|DIRECCION NACIONAL OPERACIONES POLICIALES|DIR NAL OPERACIONES POLICIALES|DIR NAC OPERACIONES POLICIALES)(?:\s|$)/u',
        ],
    ],
    [
        'direction_number'=>17,
        'label'=>'Direccion Nacional de Investigacion Judicial',
        'patterns'=>[
            '/^(DIRECCION NACIONAL DE INVESTIGACION JUDICIAL|DIRECCION NACIONAL INVESTIGACION JUDICIAL|DIR INVESTIGACION JUDICIAL|DIR NAL INVESTIGACION JUDICIAL|DIJ)(?:\s|$)/u',
        ],
    ],
    [
        'direction_number'=>18,
        'label'=>'Direccion Nacional de Operaciones de Transito',
        'patterns'=>[
            '/^(DIRECCION NACIONAL DE OPERACIONES DE TRANSITO|DIRECCION NACIONAL OPERACIONES DE TRANSITO|DIR NAC OPERAC DEL TRANSITO|DIR NAL OPERAC DEL TRANSITO|DIR NAC OPERACIONES TRANSITO)(?:\s|$)/u',
        ],
    ],
];

$directions = [];
foreach ($rules as $rule) {
    $direction = one(
        $pdo,
        "SELECT d.direction_number,d.direction_label,ou.id AS unit_id,ou.name AS unit_name,
                ou.status,ou.lifecycle_status,ou.legacy_table,ou.legacy_id
         FROM moi_direcciones_cabecera_vigentes d
         JOIN organizational_units ou
           ON BINARY ou.legacy_table=BINARY 'MOI_CABECERA_DIRECCION'
          AND CAST(ou.legacy_id AS UNSIGNED)=d.direction_number
         WHERE d.direction_number=:direction_number
           AND d.lifecycle_status='vigente'
           AND ou.status='active'
           AND ou.lifecycle_status='vigente'
           AND (ou.valid_from IS NULL OR ou.valid_from<=CURRENT_DATE)
           AND (ou.valid_to IS NULL OR ou.valid_to>=CURRENT_DATE)",
        ['direction_number'=>$rule['direction_number']]
    );
    if (!$direction) {
        fwrite(STDERR, "La direccion {$rule['direction_number']} - {$rule['label']} no tiene una cabecera vigente unica. No se aplicaron cambios.\n");
        exit(1);
    }
    $directions[(int)$rule['direction_number']] = $direction;
}

$people = rows(
    $pdo,
    "SELECT p.id,p.location_original,p.location_normalized,m.review_status,m.match_method
     FROM workforce_personnel_staging p
     LEFT JOIN workforce_unit_matches m ON m.personnel_staging_id=p.id
     WHERE p.source_id=:source_id
       AND p.import_status='importado'
       AND COALESCE(m.review_status,'pendiente')<>'aprobado'
     ORDER BY p.row_number",
    ['source_id'=>(int)$source['id']]
);

$upsert = $pdo->prepare(
    "INSERT INTO workforce_unit_matches
     (personnel_staging_id,matched_unit_id,matched_level,assignment_status,pending_level,
      match_method,confidence_level,candidate_count,candidate_data,review_status,
      review_notes,reviewed_by,reviewed_at)
     VALUES (:personnel_id,:unit_id,'direccion','asignado_parcial',:pending_level,
             'cabecera_direccion_confirmada','alto',1,NULL,'pendiente',
             :review_notes,NULL,NULL)
     ON DUPLICATE KEY UPDATE
        matched_unit_id=VALUES(matched_unit_id),
        matched_level='direccion',
        assignment_status='asignado_parcial',
        pending_level=VALUES(pending_level),
        match_method='cabecera_direccion_confirmada',
        confidence_level='alto',
        candidate_count=1,
        candidate_data=NULL,
        review_status='pendiente',
        review_notes=VALUES(review_notes),
        reviewed_by=NULL,
        reviewed_at=NULL,
        updated_at=NOW()"
);

$stats = [];
foreach ($rules as $rule) {
    $stats[(int)$rule['direction_number']] = 0;
}
$unmatched = 0;

$pdo->beginTransaction();
try {
    foreach ($people as $person) {
        $location = normalizeKey($person['location_normalized'] ?: $person['location_original']);
        if ($location === '') {
            continue;
        }

        $matchedRule = null;
        $matchedPattern = null;
        foreach ($rules as $rule) {
            foreach ($rule['patterns'] as $pattern) {
                if (preg_match($pattern, $location) === 1) {
                    $matchedRule = $rule;
                    $matchedPattern = $pattern;
                    break 2;
                }
            }
        }
        if ($matchedRule === null || $matchedPattern === null) {
            $unmatched++;
            continue;
        }

        $directionNumber = (int)$matchedRule['direction_number'];
        $direction = $directions[$directionNumber];
        $residual = trim((string)preg_replace($matchedPattern, '', $location, 1));
        $pendingLevel = $residual === '' ? 'departamento/dependencia' : 'unidad interna/dependencia';
        $notes = $residual === ''
            ? 'Direccion cabecera confirmada. El departamento o dependencia interna permanece pendiente.'
            : 'Direccion cabecera confirmada. Detalle interno pendiente de reubicacion: ' . $residual;

        $upsert->execute([
            'personnel_id'=>(int)$person['id'],
            'unit_id'=>(int)$direction['unit_id'],
            'pending_level'=>$pendingLevel,
            'review_notes'=>$notes,
        ]);
        $stats[$directionNumber]++;
    }

    $pdo->commit();
} catch (Throwable $exception) {
    $pdo->rollBack();
    fwrite(STDERR, 'Error: ' . $exception->getMessage() . "\n");
    exit(1);
}

$total = array_sum($stats);
echo "Fuente procesada: {$sourceKey}\n";
echo "Asignaciones parciales a direcciones cabecera: {$total}\n";
foreach ($rules as $rule) {
    $number = (int)$rule['direction_number'];
    echo str_pad((string)$number, 2, '0', STR_PAD_LEFT) . ' - ' . $rule['label'] . ': ' . $stats[$number] . "\n";
}
echo "Registros no alcanzados por estas reglas: {$unmatched}\n";
echo "Revisiones aprobadas y excepciones manuales fueron conservadas.\n";
echo "organizational_units no fue modificada.\n";
