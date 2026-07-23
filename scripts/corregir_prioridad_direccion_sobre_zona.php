<?php
declare(strict_types=1);

// Corrige el PIE DE FUERZA cuando una ubicacion menciona una direccion y una zona.
// Regla institucional: la direccion define la pertenencia funcional y el conteo.
// La zona mencionada solo representa ubicacion territorial y no cambia el total de la direccion.
// El nombre territorial siempre se toma de moi_zonas_cabecera_vigentes; nunca se inventa.
//
// Ejemplo:
// DNOT / 11a ZP -> se contabiliza en Direccion Nacional de Operaciones de Transito.
// Ubicacion territorial -> 11 Zona Policial - San Miguelito.
//
// No crea, renombra, mueve ni modifica organizational_units.
// Conserva todas las revisiones ya aprobadas.
//
// Uso:
// php scripts/corregir_prioridad_direccion_sobre_zona.php

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

/** @return array{zone_number:int,zone:?array}|null */
function physicalZoneReference(string $location, array $zonesByNumber): ?array
{
    $patterns = [
        '/(?:^|\s)(\d{1,2})(?:RA|DA|TA|MA|NA|VA|A)?\s*ZP(?:\s|$)/u',
        '/(?:^|\s)(\d{1,2})\s+ZONA\s+POLICIAL(?:\s|$)/u',
        '/(?:^|\s)ZP\s*(\d{1,2})(?:\s|$)/u',
        '/(?:^|\s)ZONA(?:\s+POLICIAL)?\s*(\d{1,2})(?:\s|$)/u',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $location, $matches) === 1) {
            $number = (int)$matches[1];
            return [
                'zone_number'=>$number,
                'zone'=>$zonesByNumber[$number] ?? null,
            ];
        }
    }
    return null;
}

if (
    !tableExists($pdo, 'moi_direcciones_cabecera_vigentes') ||
    !tableExists($pdo, 'moi_zonas_cabecera_vigentes') ||
    !tableExists($pdo, 'workforce_sources') ||
    !tableExists($pdo, 'workforce_personnel_staging') ||
    !tableExists($pdo, 'workforce_unit_matches')
) {
    fwrite(STDERR, "Faltan tablas requeridas para procesar el PIE DE FUERZA.\n");
    exit(1);
}

$sourceKey = 'PIE_FUERZA_20260626';
$source = one($pdo, 'SELECT id FROM workforce_sources WHERE source_key=:source_key LIMIT 1', ['source_key'=>$sourceKey]);
if (!$source) {
    fwrite(STDERR, "No existe la fuente {$sourceKey}.\n");
    exit(1);
}

$zonesByNumber = [];
$zoneRows = rows(
    $pdo,
    "SELECT z.zone_number,z.zone_label,z.zone_name,z.normalized_name,
            ou.id AS unit_id,ou.name AS unit_name,ou.code AS unit_code,ou.moi_code
     FROM moi_zonas_cabecera_vigentes z
     LEFT JOIN organizational_units ou
       ON BINARY ou.legacy_table=BINARY 'MOI_CABECERA_ZONA'
      AND CAST(ou.legacy_id AS UNSIGNED)=z.zone_number
      AND ou.status='active'
      AND ou.lifecycle_status='vigente'
     WHERE z.lifecycle_status='vigente'
       AND (z.valid_from IS NULL OR z.valid_from<=CURRENT_DATE)
       AND (z.valid_to IS NULL OR z.valid_to>=CURRENT_DATE)
     ORDER BY z.zone_number"
);
foreach ($zoneRows as $zone) {
    $zonesByNumber[(int)$zone['zone_number']] = $zone;
}

// Las expresiones se aplican sobre location_normalized.
// Se incluyen nombres oficiales y abreviaturas confirmadas en el archivo fuente.
$rules = [
    1 => [
        'label'=>'Direccion General',
        'patterns'=>['/^(DIRECCION GENERAL|DIR GENERAL|DIR GNRAL)(?:\s|$)/u'],
    ],
    2 => [
        'label'=>'Direccion Nacional de Inteligencia Policial',
        'patterns'=>['/^(DNIP|DIRECCION NACIONAL DE INTELIGENCIA POLICIAL|DIRECCION NACIONAL INTELIGENCIA POLICIAL|DIR NAL INTELIGENCIA POLICIAL|DIR NAC INTELIGENCIA POLICIAL)(?:\s|$)/u'],
    ],
    6 => [
        'label'=>'Direccion Nacional Antidrogas',
        'patterns'=>['/^(DIRECCION NACIONAL ANTIDROGAS|DIR NAL ANTIDROGAS|DIR NAC ANTIDROGAS)(?:\s|$)/u'],
    ],
    7 => [
        'label'=>'Direccion Nacional de Operaciones Policiales',
        'patterns'=>['/^(DINOP|DIRECCION NACIONAL DE OPERACIONES POLICIALES|DIRECCION NACIONAL OPERACIONES POLICIALES|DIR NAL OPERACIONES POLICIALES|DIR NAC OPERACIONES POLICIALES)(?:\s|$)/u'],
    ],
    17 => [
        'label'=>'Direccion Nacional de Investigacion Judicial',
        'patterns'=>['/^(DIJ|DIRECCION NACIONAL DE INVESTIGACION JUDICIAL|DIRECCION NACIONAL INVESTIGACION JUDICIAL|DIR INVESTIGACION JUDICIAL|DIR NAL INVESTIGACION JUDICIAL)(?:\s|$)/u'],
    ],
    18 => [
        'label'=>'Direccion Nacional de Operaciones de Transito',
        'patterns'=>['/^(DNOT|DNOPT|DIRECCION NACIONAL DE OPERACIONES DE TRANSITO|DIRECCION NACIONAL OPERACIONES DE TRANSITO|DIR NAC OPERAC DEL TRANSITO|DIR NAL OPERAC DEL TRANSITO|DIR NAC OPERACIONES TRANSITO)(?:\s|$)/u'],
    ],
];

$directions = [];
foreach ($rules as $number => $rule) {
    $direction = one(
        $pdo,
        "SELECT d.direction_number,d.direction_label,ou.id AS unit_id,ou.name AS unit_name
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
        ['direction_number'=>$number]
    );
    if (!$direction) {
        fwrite(STDERR, "La direccion {$number} - {$rule['label']} no tiene una cabecera vigente unica. No se aplicaron cambios.\n");
        exit(1);
    }
    $directions[$number] = $direction;
}

$people = rows(
    $pdo,
    "SELECT p.id,p.location_original,p.location_normalized,
            m.matched_level,m.assignment_status,m.review_status,m.match_method
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
     VALUES (:personnel_id,:unit_id,'direccion','asignado_parcial','unidad interna/dependencia',
             'prioridad_funcional_direccion','alto',1,NULL,'pendiente',
             :review_notes,NULL,NULL)
     ON DUPLICATE KEY UPDATE
        matched_unit_id=VALUES(matched_unit_id),
        matched_level='direccion',
        assignment_status='asignado_parcial',
        pending_level='unidad interna/dependencia',
        match_method='prioridad_funcional_direccion',
        confidence_level='alto',
        candidate_count=1,
        candidate_data=NULL,
        review_status='pendiente',
        review_notes=VALUES(review_notes),
        reviewed_by=NULL,
        reviewed_at=NULL,
        updated_at=NOW()"
);

$stats = array_fill_keys(array_keys($rules), 0);
$correctedFromZone = 0;
$withCatalogZone = 0;
$unknownZoneReference = 0;

$pdo->beginTransaction();
try {
    foreach ($people as $person) {
        $location = normalizeKey($person['location_normalized'] ?: $person['location_original']);
        if ($location === '') {
            continue;
        }

        $matchedNumber = null;
        $matchedPattern = null;
        foreach ($rules as $number => $rule) {
            foreach ($rule['patterns'] as $pattern) {
                if (preg_match($pattern, $location) === 1) {
                    $matchedNumber = (int)$number;
                    $matchedPattern = $pattern;
                    break 2;
                }
            }
        }
        if ($matchedNumber === null || $matchedPattern === null) {
            continue;
        }

        $direction = $directions[$matchedNumber];
        $residual = trim((string)preg_replace($matchedPattern, '', $location, 1));
        $zoneReference = physicalZoneReference($residual, $zonesByNumber);

        $notes = 'Pertenencia funcional confirmada: ' . $direction['direction_label'] .
            '. Este personal se contabiliza en la direccion, no en la zona territorial.';
        if ($zoneReference !== null && $zoneReference['zone'] !== null) {
            $zone = $zoneReference['zone'];
            $notes .= ' Ubicacion territorial detectada: ' . $zone['zone_label'] .
                ' (nomenclatura vigente del catalogo de zonas).';
            $withCatalogZone++;
        } elseif ($zoneReference !== null) {
            $notes .= ' Se detecto referencia territorial al numero de zona ' .
                $zoneReference['zone_number'] .
                ', pero no existe una cabecera vigente con ese numero; queda pendiente de revision.';
            $unknownZoneReference++;
        }
        if ($residual !== '') {
            $notes .= ' Detalle interno pendiente de reubicacion: ' . $residual . '.';
        }

        if (($person['matched_level'] ?? '') === 'zona') {
            $correctedFromZone++;
        }

        $upsert->execute([
            'personnel_id'=>(int)$person['id'],
            'unit_id'=>(int)$direction['unit_id'],
            'review_notes'=>$notes,
        ]);
        $stats[$matchedNumber]++;
    }

    $pdo->commit();
} catch (Throwable $exception) {
    $pdo->rollBack();
    fwrite(STDERR, 'Error: ' . $exception->getMessage() . "\n");
    exit(1);
}

$total = array_sum($stats);
echo "Fuente procesada: {$sourceKey}\n";
echo "Personas asignadas por pertenencia funcional a direccion: {$total}\n";
echo "Registros corregidos que antes estaban a nivel de zona: {$correctedFromZone}\n";
echo "Registros con zona territorial identificada por nomenclatura vigente: {$withCatalogZone}\n";
echo "Referencias a numeros de zona no presentes en el catalogo vigente: {$unknownZoneReference}\n";
foreach ($rules as $number => $rule) {
    echo str_pad((string)$number, 2, '0', STR_PAD_LEFT) . ' - ' . $rule['label'] . ': ' . $stats[$number] . "\n";
}
echo "Las revisiones aprobadas fueron conservadas.\n";
echo "El conteo principal queda en la direccion; la zona usa exclusivamente su nombre y nomenclatura vigente como referencia territorial.\n";
echo "organizational_units no fue modificada.\n";
