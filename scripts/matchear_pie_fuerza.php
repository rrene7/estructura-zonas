<?php
declare(strict_types=1);

// Vincula el PIE DE FUERZA con la estructura vigente existente.
// Nunca inserta, renombra, mueve ni modifica organizational_units.
//
// Uso:
// php scripts/matchear_pie_fuerza.php --source-key=PIE_FUERZA_20260626
// php scripts/matchear_pie_fuerza.php --source-key=PIE_FUERZA_20260626 --forzar=1

$options = getopt('', ['source-key::', 'forzar::']);
$sourceKey = (string)($options['source-key'] ?? 'PIE_FUERZA_20260626');
$forzar = isset($options['forzar']) && (string)$options['forzar'] === '1';

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

function query_all(PDO $pdo, string $sql, array $params = []): array
{
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return $statement->fetchAll();
}

function query_one(PDO $pdo, string $sql, array $params = []): ?array
{
    $rows = query_all($pdo, $sql, $params);
    return $rows[0] ?? null;
}

function table_exists(PDO $pdo, string $table): bool
{
    $row = query_one(
        $pdo,
        'SELECT COUNT(*) total FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=:table',
        ['table' => $table]
    );
    return (int)($row['total'] ?? 0) > 0;
}

function clean_text(mixed $value): string
{
    $text = trim((string)$value);
    return preg_replace('/\s+/u', ' ', $text) ?? '';
}

function upper_text(string $value): string
{
    return function_exists('mb_strtoupper') ? mb_strtoupper($value, 'UTF-8') : strtoupper($value);
}

function normalize_key(mixed $value): string
{
    $text = upper_text(clean_text($value));
    $text = strtr($text, [
        'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U',
        'Ü' => 'U', 'Ñ' => 'N', 'ª' => 'A', 'º' => 'O',
    ]);
    $text = preg_replace('/[^A-Z0-9]+/u', ' ', $text);
    return trim((string)preg_replace('/\s+/', ' ', (string)$text));
}

function normalized_length(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function contains_phrase(string $haystack, string $needle): bool
{
    if ($needle === '') {
        return false;
    }
    return preg_match('/(?:^|\s)' . preg_quote($needle, '/') . '(?:\s|$)/u', $haystack) === 1;
}

function unit_kind(array $unit): string
{
    $type = normalize_key($unit['unit_type'] ?? '');
    $legacy = normalize_key($unit['legacy_table'] ?? '');
    if ($type === 'ZONA POLICIAL' || $legacy === 'MOI CABECERA ZONA') {
        return 'zona';
    }
    if ($type === 'DIRECCION NACIONAL' || $type === 'SUBDIRECCION NACIONAL' || $legacy === 'MOI CABECERA DIRECCION') {
        return 'direccion';
    }
    if ($type === 'AREA' || $type === 'AREA POLICIAL' || $legacy === 'MOI CABECERA AREA') {
        return 'area';
    }
    if (str_contains($type, 'SERVICIO')) {
        return 'servicio';
    }
    if (in_array($type, ['DEPARTAMENTO', 'DIVISION', 'SECCION', 'OFICINA', 'DEPENDENCIA', 'ESTACION', 'ESTACION POLICIAL', 'SUBESTACION', 'SUBESTACION POLICIAL', 'PUESTO', 'PUESTO POLICIAL', 'DESTACAMENTO'], true)) {
        return 'dependencia';
    }
    return 'unidad';
}

/** @return array<int,array{key:string,source:string}> */
function unit_aliases(array $unit): array
{
    $values = [
        'name' => $unit['name'] ?? '',
        'short_name' => $unit['short_name'] ?? '',
        'code' => $unit['code'] ?? '',
        'moi_code' => $unit['moi_code'] ?? '',
    ];
    $aliases = [];
    $seen = [];
    foreach ($values as $source => $value) {
        $key = normalize_key($value);
        if ($key === '' || isset($seen[$key])) {
            continue;
        }
        if (preg_match('/^\d+$/', $key) === 1 && strlen($key) < 4) {
            continue;
        }
        $seen[$key] = true;
        $aliases[] = ['key' => $key, 'source' => $source];
    }
    return $aliases;
}

/** @param array<int,array<string,mixed>> $unitsById */
function depth_of(array $unit, array $unitsById): int
{
    $declared = max((int)($unit['moi_level'] ?? 0), (int)($unit['level'] ?? 0));
    if ($declared > 0) {
        return $declared;
    }
    $depth = 0;
    $current = $unit;
    $visited = [];
    while (!empty($current['parent_id'])) {
        $parentId = (int)$current['parent_id'];
        if (isset($visited[$parentId]) || !isset($unitsById[$parentId])) {
            break;
        }
        $visited[$parentId] = true;
        $depth++;
        $current = $unitsById[$parentId];
    }
    return $depth;
}

/** @param array<int,array<string,mixed>> $unitsById */
function is_descendant_or_self(int $unitId, int $rootId, array $unitsById): bool
{
    $currentId = $unitId;
    $visited = [];
    while ($currentId > 0 && isset($unitsById[$currentId]) && !isset($visited[$currentId])) {
        if ($currentId === $rootId) {
            return true;
        }
        $visited[$currentId] = true;
        $currentId = (int)($unitsById[$currentId]['parent_id'] ?? 0);
    }
    return false;
}

/** @param array<int,array<string,mixed>> $unitsById @return array<int,array<string,mixed>> */
function ancestor_units(array $unit, array $unitsById): array
{
    $ancestors = [];
    $current = $unit;
    $visited = [];
    while (!empty($current['parent_id'])) {
        $parentId = (int)$current['parent_id'];
        if (isset($visited[$parentId]) || !isset($unitsById[$parentId])) {
            break;
        }
        $visited[$parentId] = true;
        $current = $unitsById[$parentId];
        $ancestors[] = $current;
    }
    return $ancestors;
}

/** @param array<int,array<string,mixed>> $roots */
function detect_root(string $location, array $roots): ?array
{
    $best = null;
    foreach ($roots as $root) {
        $score = 0;
        $method = '';
        $kind = unit_kind($root);

        if ($kind === 'zona' && preg_match('/(?:^|\s)(\d{1,2})(?:RA|DA|TA|MA|NA)?\s+ZONA(?:\s|$)/u', $location, $matches) === 1) {
            $legacyId = (int)($root['legacy_id'] ?? 0);
            $zoneNumber = (int)$matches[1];
            if ($legacyId === $zoneNumber) {
                $score = 3000;
                $method = 'numero_zona';
            }
        }

        foreach (unit_aliases($root) as $alias) {
            $key = $alias['key'];
            $length = normalized_length($key);
            if ($location === $key) {
                $candidateScore = 2600 + $length;
                if ($candidateScore > $score) {
                    $score = $candidateScore;
                    $method = 'raiz_exacta_' . $alias['source'];
                }
            } elseif ($length >= 4 && contains_phrase($location, $key)) {
                $candidateScore = 1800 + $length;
                if ($candidateScore > $score) {
                    $score = $candidateScore;
                    $method = 'raiz_contenida_' . $alias['source'];
                }
            }
        }

        if ($score <= 0) {
            continue;
        }
        $candidate = ['unit' => $root, 'score' => $score, 'method' => $method];
        if ($best === null || $candidate['score'] > $best['score']) {
            $best = $candidate;
        } elseif ($candidate['score'] === $best['score'] && (int)$candidate['unit']['id'] !== (int)$best['unit']['id']) {
            $best['ambiguous'] = true;
        }
    }
    return $best;
}

/** @param array<string,mixed> $unit @param array<int,array<string,mixed>> $unitsById */
function residual_location(string $location, array $unit, array $unitsById): string
{
    $remove = [];
    foreach (array_merge([$unit], ancestor_units($unit, $unitsById)) as $related) {
        foreach (unit_aliases($related) as $alias) {
            if (normalized_length($alias['key']) >= 3) {
                $remove[] = $alias['key'];
            }
        }
    }
    usort($remove, static fn(string $a, string $b): int => normalized_length($b) <=> normalized_length($a));
    $residual = ' ' . $location . ' ';
    foreach (array_unique($remove) as $phrase) {
        $residual = preg_replace('/(?:^|\s)' . preg_quote($phrase, '/') . '(?:\s|$)/u', ' ', $residual) ?? $residual;
    }
    $generic = [
        'POLICIA', 'POLICIAL', 'NACIONAL', 'ZONA', 'DIRECCION', 'AREA', 'SERVICIO',
        'UNIDAD', 'DEPENDENCIA', 'DEPARTAMENTO', 'DIVISION', 'SECCION', 'OFICINA',
        'ESTACION', 'SUBESTACION', 'PUESTO', 'SEDE', 'DE', 'DEL', 'LA', 'EL', 'LOS', 'LAS',
    ];
    $tokens = preg_split('/\s+/', trim($residual)) ?: [];
    $tokens = array_values(array_filter($tokens, static function (string $token) use ($generic): bool {
        if ($token === '' || in_array($token, $generic, true)) {
            return false;
        }
        if (preg_match('/^\d+$/', $token) === 1) {
            return false;
        }
        return strlen($token) > 1;
    }));
    return implode(' ', $tokens);
}

if (!table_exists($pdo, 'workforce_personnel_staging') || !table_exists($pdo, 'workforce_unit_matches')) {
    fwrite(STDERR, "Faltan las tablas del modulo. Ejecute database/pie_fuerza_20260626.sql\n");
    exit(1);
}

$source = query_one($pdo, 'SELECT * FROM workforce_sources WHERE source_key=:source_key LIMIT 1', ['source_key' => $sourceKey]);
if (!$source) {
    fwrite(STDERR, "No existe la fuente {$sourceKey}. Importe primero el archivo.\n");
    exit(1);
}

$units = query_all(
    $pdo,
    "SELECT ou.id,ou.parent_id,ou.unit_type_id,ou.code,ou.moi_code,ou.name,ou.short_name,ou.level,ou.moi_level,
            ou.legacy_table,ou.legacy_id,ou.lifecycle_status,ou.valid_from,ou.valid_to,ut.name AS unit_type
     FROM organizational_units ou
     LEFT JOIN unit_types ut ON ut.id=ou.unit_type_id
     WHERE ou.status='active'
       AND ou.lifecycle_status='vigente'
       AND (ou.valid_from IS NULL OR ou.valid_from<=CURRENT_DATE)
       AND (ou.valid_to IS NULL OR ou.valid_to>=CURRENT_DATE)"
);
if ($units === []) {
    fwrite(STDERR, "No hay unidades vigentes para realizar el match.\n");
    exit(1);
}

$unitsById = [];
$roots = [];
foreach ($units as &$unit) {
    $unit['id'] = (int)$unit['id'];
    $unit['parent_id'] = $unit['parent_id'] !== null ? (int)$unit['parent_id'] : null;
    $unit['aliases'] = unit_aliases($unit);
    $unitsById[$unit['id']] = $unit;
    if (in_array(unit_kind($unit), ['zona', 'direccion'], true)) {
        $roots[] = $unit;
    }
}
unset($unit);

$people = query_all(
    $pdo,
    "SELECT p.*,m.review_status AS existing_review_status
     FROM workforce_personnel_staging p
     LEFT JOIN workforce_unit_matches m ON m.personnel_staging_id=p.id
     WHERE p.source_id=:source_id AND p.import_status='importado'
     ORDER BY p.row_number",
    ['source_id' => $source['id']]
);

$upsert = $pdo->prepare(
    "INSERT INTO workforce_unit_matches
    (personnel_staging_id,matched_unit_id,matched_level,assignment_status,pending_level,match_method,confidence_level,candidate_count,candidate_data,review_status,review_notes,reviewed_by,reviewed_at)
    VALUES (:personnel_id,:unit_id,:matched_level,:assignment_status,:pending_level,:match_method,:confidence,:candidate_count,:candidate_data,:review_status,:review_notes,NULL,NULL)
    ON DUPLICATE KEY UPDATE
        matched_unit_id=VALUES(matched_unit_id),matched_level=VALUES(matched_level),assignment_status=VALUES(assignment_status),
        pending_level=VALUES(pending_level),match_method=VALUES(match_method),confidence_level=VALUES(confidence_level),
        candidate_count=VALUES(candidate_count),candidate_data=VALUES(candidate_data),review_status=VALUES(review_status),
        review_notes=VALUES(review_notes),reviewed_by=NULL,reviewed_at=NULL,updated_at=NOW()"
);

$stats = [
    'asignado_completo' => 0,
    'asignado_parcial' => 0,
    'pendiente_revision' => 0,
    'sin_coincidencia' => 0,
    'manual_omitido' => 0,
];

$pdo->beginTransaction();
try {
    foreach ($people as $person) {
        if (!$forzar && ($person['existing_review_status'] ?? '') === 'aprobado') {
            $stats['manual_omitido']++;
            continue;
        }

        $location = normalize_key($person['location_normalized'] ?: $person['location_original']);
        $rootMatch = detect_root($location, $roots);
        $rootUnit = $rootMatch['unit'] ?? null;
        $rootId = $rootUnit ? (int)$rootUnit['id'] : 0;

        $candidates = [];
        foreach ($units as $unit) {
            $unitId = (int)$unit['id'];
            if ($rootId > 0 && !is_descendant_or_self($unitId, $rootId, $unitsById)) {
                continue;
            }

            $bestUnitScore = 0;
            $bestAlias = '';
            $bestMethod = '';
            foreach ($unit['aliases'] as $alias) {
                $key = $alias['key'];
                $length = normalized_length($key);
                if ($key === '' || ($length < 4 && $alias['source'] !== 'code' && $alias['source'] !== 'moi_code')) {
                    continue;
                }

                $score = 0;
                $method = '';
                if ($location !== '' && $location === $key) {
                    $score = 4000 + ($length * 3);
                    $method = 'exacto_' . $alias['source'];
                } elseif ($location !== '' && contains_phrase($location, $key)) {
                    if ($rootId === 0 && $length < 8 && !in_array($alias['source'], ['code', 'moi_code'], true)) {
                        continue;
                    }
                    $score = 2300 + ($length * 3);
                    $method = 'contenido_' . $alias['source'];
                } elseif ($rootId === 0 && $location !== '' && $length >= 10 && contains_phrase($key, $location)) {
                    $score = 1200 + $length;
                    $method = 'ubicacion_contenida_en_unidad';
                }

                if ($score > $bestUnitScore) {
                    $bestUnitScore = $score;
                    $bestAlias = $key;
                    $bestMethod = $method;
                }
            }

            if ($bestUnitScore <= 0) {
                continue;
            }
            $depth = depth_of($unit, $unitsById);
            $bestUnitScore += $depth * 20;
            if ($rootId > 0 && $unitId === $rootId) {
                $bestUnitScore -= 100;
            }
            $candidates[] = [
                'unit' => $unit,
                'score' => $bestUnitScore,
                'alias' => $bestAlias,
                'method' => $bestMethod,
                'depth' => $depth,
            ];
        }

        usort($candidates, static function (array $a, array $b): int {
            return [$b['score'], $b['depth'], normalized_length($b['alias'])]
                <=> [$a['score'], $a['depth'], normalized_length($a['alias'])];
        });

        $top = $candidates[0] ?? null;
        $second = $candidates[1] ?? null;
        $ambiguous = $top !== null && $second !== null
            && (int)$top['unit']['id'] !== (int)$second['unit']['id']
            && ((int)$top['score'] - (int)$second['score']) < 15;

        $matchedUnit = null;
        $assignmentStatus = 'pendiente_revision';
        $matchedLevel = 'ninguno';
        $pendingLevel = null;
        $matchMethod = 'sin_match';
        $confidence = 'bajo';
        $reviewStatus = 'pendiente';
        $reviewNotes = 'No se encontro una coincidencia segura en la estructura vigente.';

        if ($top !== null && !$ambiguous) {
            $matchedUnit = $top['unit'];
            $matchedLevel = unit_kind($matchedUnit);
            $matchMethod = $top['method'];
            $residual = residual_location($location, $matchedUnit, $unitsById);
            $isRoot = in_array($matchedLevel, ['zona', 'direccion'], true);
            $isExact = str_starts_with($top['method'], 'exacto_');

            if ($isRoot) {
                $assignmentStatus = 'asignado_parcial';
                $pendingLevel = $matchedLevel === 'zona' ? 'area/dependencia' : 'departamento/dependencia';
                $reviewStatus = 'pendiente';
                $reviewNotes = 'Cabecera confirmada; falta revisar el nivel inferior indicado en la ubicacion original.';
            } elseif ($matchedLevel === 'area' && !$isExact && $residual !== '') {
                $assignmentStatus = 'asignado_parcial';
                $pendingLevel = 'dependencia/servicio';
                $reviewStatus = 'pendiente';
                $reviewNotes = 'Area confirmada; queda pendiente identificar una unidad inferior.';
            } elseif (!$isExact && $residual !== '') {
                $assignmentStatus = 'asignado_parcial';
                $pendingLevel = 'nivel inferior';
                $reviewStatus = 'pendiente';
                $reviewNotes = 'Se asigno la unidad mas especifica confirmada; existe texto residual para revision: ' . $residual;
            } else {
                $assignmentStatus = 'asignado_completo';
                $pendingLevel = null;
                $reviewStatus = 'automatico';
                $reviewNotes = 'Coincidencia unica contra una unidad vigente existente.';
            }
            $confidence = $isExact ? 'alto' : 'medio';
        } elseif ($rootUnit !== null && empty($rootMatch['ambiguous'])) {
            $matchedUnit = $rootUnit;
            $matchedLevel = unit_kind($rootUnit);
            $assignmentStatus = 'asignado_parcial';
            $pendingLevel = $matchedLevel === 'zona' ? 'area/dependencia' : 'departamento/dependencia';
            $matchMethod = (string)($rootMatch['method'] ?? 'raiz_detectada');
            $confidence = str_contains($matchMethod, 'numero_zona') || str_contains($matchMethod, 'exacta') ? 'alto' : 'medio';
            $reviewStatus = 'pendiente';
            $reviewNotes = 'Se confirmo la zona o direccion; el area o dependencia queda pendiente de revision.';
        } elseif ($ambiguous) {
            $reviewNotes = 'Se encontraron varias unidades con puntuacion similar. No se asigno automaticamente.';
        }

        $candidatePayload = [];
        foreach (array_slice($candidates, 0, 8) as $candidate) {
            $candidatePayload[] = [
                'unit_id' => (int)$candidate['unit']['id'],
                'name' => $candidate['unit']['name'],
                'type' => $candidate['unit']['unit_type'],
                'score' => (int)$candidate['score'],
                'method' => $candidate['method'],
            ];
        }

        $upsert->execute([
            'personnel_id' => $person['id'],
            'unit_id' => $matchedUnit['id'] ?? null,
            'matched_level' => $matchedLevel,
            'assignment_status' => $assignmentStatus,
            'pending_level' => $pendingLevel,
            'match_method' => $matchMethod,
            'confidence' => $confidence,
            'candidate_count' => count($candidates),
            'candidate_data' => json_encode($candidatePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'review_status' => $reviewStatus,
            'review_notes' => $reviewNotes,
        ]);
        $stats[$assignmentStatus]++;
    }

    $updateSource = $pdo->prepare("UPDATE workforce_sources SET source_status='procesado',updated_at=NOW() WHERE id=:id");
    $updateSource->execute(['id' => $source['id']]);
    $pdo->commit();
} catch (Throwable $exception) {
    $pdo->rollBack();
    fwrite(STDERR, 'Error: ' . $exception->getMessage() . "\n");
    exit(1);
}

echo "Fuente procesada: {$sourceKey}\n";
echo "Asignados completos: {$stats['asignado_completo']}\n";
echo "Asignados parciales: {$stats['asignado_parcial']}\n";
echo "Pendientes de revision: {$stats['pendiente_revision']}\n";
echo "Sin coincidencia: {$stats['sin_coincidencia']}\n";
echo "Asignaciones manuales conservadas: {$stats['manual_omitido']}\n";
echo "organizational_units no fue modificada.\n";
