<?php
declare(strict_types=1);

// Separa pertenencia funcional, detalle interno y ubicacion territorial en el PIE DE FUERZA.
// No crea ni modifica organizational_units.
//
// Uso:
// php scripts/clasificar_contexto_territorial_pie_fuerza.php \
//   --matched-unit-id=123 \
//   --location-prefix="PREFIJO NORMALIZADO" \
//   --aliases="ALIAS=NUMERO_ZONA;OTRO ALIAS=NUMERO_ZONA"

$configPath = __DIR__ . '/../dashboard/config.php';
if (!is_file($configPath)) {
    fwrite(STDERR, "Falta dashboard/config.php\n");
    exit(1);
}

$options = getopt('', [
    'matched-unit-id:',
    'location-prefix:',
    'aliases::',
    'source-key::',
]);

$matchedUnitId = (int)($options['matched-unit-id'] ?? 0);
$locationPrefix = trim((string)($options['location-prefix'] ?? ''));
$aliasesText = trim((string)($options['aliases'] ?? ''));
$sourceKey = trim((string)($options['source-key'] ?? 'PIE_FUERZA_20260626'));

if ($matchedUnitId <= 0 || $locationPrefix === '') {
    fwrite(STDERR, "Faltan --matched-unit-id o --location-prefix.\n");
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

function columnExists(PDO $pdo, string $table, string $column): bool
{
    $row = one(
        $pdo,
        'SELECT COUNT(*) AS total FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=:table AND column_name=:column',
        ['table'=>$table, 'column'=>$column]
    );
    return (int)($row['total'] ?? 0) > 0;
}

function parseAliases(string $aliasesText): array
{
    $result = [];
    if ($aliasesText === '') {
        return $result;
    }
    foreach (explode(';', $aliasesText) as $pair) {
        $pair = trim($pair);
        if ($pair === '' || !str_contains($pair, '=')) {
            continue;
        }
        [$alias, $zoneNumber] = array_map('trim', explode('=', $pair, 2));
        $alias = normalizeKey($alias);
        $zoneNumber = (int)$zoneNumber;
        if ($alias !== '' && $zoneNumber > 0) {
            $result[$alias] = $zoneNumber;
        }
    }
    return $result;
}

function autoAliasesForZone(array $zone): array
{
    $aliases = [];
    $texts = [
        $zone['zone_label'] ?? '',
        $zone['zone_name'] ?? '',
        $zone['normalized_name'] ?? '',
        $zone['unit_name'] ?? '',
    ];

    foreach ($texts as $text) {
        $normalized = normalizeKey($text);
        if ($normalized === '') {
            continue;
        }
        $normalized = preg_replace('/^\d{1,2}\s+ZONA\s+POLICIAL\s*/u', '', $normalized);
        $normalized = preg_replace('/\b\d+(?:RA|DA|TA|MA|NA|VA|A)?\s+REGION\s+DE\s+POLICIA\b/u', '', (string)$normalized);
        $normalized = preg_replace('/\bZONA\s+POLICIAL\b/u', '', (string)$normalized);
        $normalized = trim((string)preg_replace('/\s+/', ' ', (string)$normalized));
        if ($normalized === '') {
            continue;
        }

        $aliases[$normalized] = true;
        $tokens = preg_split('/\s+/', $normalized) ?: [];
        foreach ($tokens as $token) {
            if (strlen($token) < 5 || in_array($token, ['PANAMA','OESTE','NORTE','REGION','POLICIA'], true)) {
                continue;
            }
            $aliases[$token] = true;
        }

        if (preg_match('/\bSAN\s+([A-Z0-9]+)\b/u', $normalized, $matches) === 1) {
            $aliases['SAN ' . $matches[1]] = true;
        }
        if (preg_match('/\bDON\s+([A-Z0-9]+)\b/u', $normalized, $matches) === 1) {
            $aliases['DON ' . $matches[1]] = true;
        }
    }

    return array_keys($aliases);
}

function detectExplicitZoneNumber(string $residual): ?int
{
    $patterns = [
        '/(?:^|\s)(\d{1,2})(?:RA|DA|TA|MA|NA|VA|A)?\s*ZP(?:\s|$)/u',
        '/(?:^|\s)ZP\s*(\d{1,2})(?:\s|$)/u',
        '/(?:^|\s)(\d{1,2})\s+ZONA\s+POLICIAL(?:\s|$)/u',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $residual, $matches) === 1) {
            return (int)$matches[1];
        }
    }
    return null;
}

function stripTerritorialReference(string $residual, ?string $matchedAlias, ?int $explicitZoneNumber): string
{
    $internal = $residual;
    if ($explicitZoneNumber !== null) {
        $patterns = [
            '/(?:^|\s)' . $explicitZoneNumber . '(?:RA|DA|TA|MA|NA|VA|A)?\s*ZP(?:\s|$)/u',
            '/(?:^|\s)ZP\s*' . $explicitZoneNumber . '(?:\s|$)/u',
            '/(?:^|\s)' . $explicitZoneNumber . '\s+ZONA\s+POLICIAL(?:\s|$)/u',
        ];
        foreach ($patterns as $pattern) {
            $internal = (string)preg_replace($pattern, ' ', $internal, 1);
        }
    }
    if ($matchedAlias !== null && $matchedAlias !== '') {
        $internal = (string)preg_replace('/(?:^|\s)' . preg_quote($matchedAlias, '/') . '(?:\s|$)/u', ' ', $internal, 1);
    }
    return trim((string)preg_replace('/\s+/', ' ', $internal));
}

foreach (['organizational_units','moi_zonas_cabecera_vigentes','workforce_sources','workforce_personnel_staging','workforce_unit_matches'] as $table) {
    if (!tableExists($pdo, $table)) {
        fwrite(STDERR, "Falta la tabla requerida {$table}.\n");
        exit(1);
    }
}

if (!columnExists($pdo, 'workforce_unit_matches', 'territorial_zone_unit_id')) {
    $pdo->exec('ALTER TABLE workforce_unit_matches ADD COLUMN territorial_zone_unit_id BIGINT UNSIGNED NULL AFTER matched_unit_id');
    $pdo->exec('ALTER TABLE workforce_unit_matches ADD INDEX idx_workforce_territorial_zone (territorial_zone_unit_id)');
}
if (!columnExists($pdo, 'workforce_unit_matches', 'internal_detail')) {
    $pdo->exec('ALTER TABLE workforce_unit_matches ADD COLUMN internal_detail VARCHAR(180) NULL AFTER pending_level');
}

$service = one(
    $pdo,
    "SELECT id,name FROM organizational_units
     WHERE id=:id AND status='active' AND lifecycle_status='vigente'",
    ['id'=>$matchedUnitId]
);
if (!$service) {
    fwrite(STDERR, "La unidad funcional indicada no existe o no esta vigente.\n");
    exit(1);
}

$source = one($pdo, 'SELECT id FROM workforce_sources WHERE source_key=:source_key LIMIT 1', ['source_key'=>$sourceKey]);
if (!$source) {
    fwrite(STDERR, "No existe la fuente {$sourceKey}.\n");
    exit(1);
}

$zoneRows = rows(
    $pdo,
    "SELECT z.zone_number,z.zone_label,z.zone_name,z.normalized_name,
            ou.id AS unit_id,ou.name AS unit_name
     FROM moi_zonas_cabecera_vigentes z
     JOIN organizational_units ou
       ON BINARY ou.legacy_table=BINARY 'MOI_CABECERA_ZONA'
      AND CAST(ou.legacy_id AS UNSIGNED)=z.zone_number
      AND ou.status='active'
      AND ou.lifecycle_status='vigente'
     WHERE z.lifecycle_status='vigente'
       AND (z.valid_from IS NULL OR z.valid_from<=CURRENT_DATE)
       AND (z.valid_to IS NULL OR z.valid_to>=CURRENT_DATE)
     ORDER BY z.zone_number"
);

$zonesByNumber = [];
$aliasToZoneNumber = [];
foreach ($zoneRows as $zone) {
    $zoneNumber = (int)$zone['zone_number'];
    $zonesByNumber[$zoneNumber] = $zone;
    foreach (autoAliasesForZone($zone) as $alias) {
        if (!isset($aliasToZoneNumber[$alias])) {
            $aliasToZoneNumber[$alias] = $zoneNumber;
        }
    }
}
foreach (parseAliases($aliasesText) as $alias => $zoneNumber) {
    if (isset($zonesByNumber[$zoneNumber])) {
        $aliasToZoneNumber[$alias] = $zoneNumber;
    }
}
uksort($aliasToZoneNumber, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));

$normalizedPrefix = normalizeKey($locationPrefix);
$people = rows(
    $pdo,
    "SELECT p.id,p.location_original,p.location_normalized,m.assignment_status,m.review_status,m.match_method
     FROM workforce_personnel_staging p
     JOIN workforce_unit_matches m ON m.personnel_staging_id=p.id
     WHERE p.source_id=:source_id
       AND p.import_status='importado'
       AND m.matched_unit_id=:matched_unit_id
     ORDER BY p.row_number",
    ['source_id'=>(int)$source['id'], 'matched_unit_id'=>$matchedUnitId]
);

$update = $pdo->prepare(
    "UPDATE workforce_unit_matches
     SET territorial_zone_unit_id=:territorial_zone_unit_id,
         internal_detail=:internal_detail,
         assignment_status=:assignment_status,
         pending_level=:pending_level,
         review_notes=:review_notes,
         updated_at=NOW()
     WHERE personnel_staging_id=:personnel_id
       AND matched_unit_id=:matched_unit_id"
);

$mapped = 0;
$unresolved = 0;
$complete = 0;
$partial = 0;
$unresolvedGroups = [];

$pdo->beginTransaction();
try {
    foreach ($people as $person) {
        $location = normalizeKey($person['location_normalized'] ?: $person['location_original']);
        $residual = $location;
        if ($normalizedPrefix !== '' && str_starts_with($location, $normalizedPrefix)) {
            $residual = trim(substr($location, strlen($normalizedPrefix)));
        }

        $zoneNumber = detectExplicitZoneNumber($residual);
        $matchedAlias = null;
        if ($zoneNumber === null) {
            foreach ($aliasToZoneNumber as $alias => $candidateZoneNumber) {
                if (preg_match('/(?:^|\s)' . preg_quote($alias, '/') . '(?:\s|$)/u', $residual) === 1) {
                    $zoneNumber = $candidateZoneNumber;
                    $matchedAlias = $alias;
                    break;
                }
            }
        }

        $zone = $zoneNumber !== null ? ($zonesByNumber[$zoneNumber] ?? null) : null;
        $internalDetail = stripTerritorialReference($residual, $matchedAlias, $zoneNumber);
        $assignmentStatus = $internalDetail === '' ? 'asignado_completo' : 'asignado_parcial';
        $pendingLevel = $internalDetail === '' ? null : 'seccion/unidad';

        if ($zone !== null) {
            $mapped++;
            $notes = 'Pertenencia funcional: ' . $service['name'] .
                '. Ubicacion territorial: ' . $zone['zone_label'] . '.';
        } else {
            $unresolved++;
            $notes = 'Pertenencia funcional: ' . $service['name'] .
                '. Ubicacion territorial pendiente de identificar.';
            $unresolvedGroups[$residual] = ($unresolvedGroups[$residual] ?? 0) + 1;
        }
        if ($internalDetail !== '') {
            $notes .= ' Detalle interno: ' . $internalDetail . '.';
            $partial++;
        } else {
            $complete++;
        }

        $update->execute([
            'territorial_zone_unit_id'=>$zone['unit_id'] ?? null,
            'internal_detail'=>$internalDetail !== '' ? $internalDetail : null,
            'assignment_status'=>$assignmentStatus,
            'pending_level'=>$pendingLevel,
            'review_notes'=>$notes,
            'personnel_id'=>(int)$person['id'],
            'matched_unit_id'=>$matchedUnitId,
        ]);
    }
    $pdo->commit();
} catch (Throwable $exception) {
    $pdo->rollBack();
    fwrite(STDERR, 'Error: ' . $exception->getMessage() . "\n");
    exit(1);
}

arsort($unresolvedGroups);

echo 'Unidad funcional: ' . $service['name'] . ' [' . $matchedUnitId . "]\n";
echo 'Registros procesados: ' . count($people) . "\n";
echo 'Con zona territorial identificada: ' . $mapped . "\n";
echo 'Sin zona territorial identificada: ' . $unresolved . "\n";
echo 'Asignaciones funcionalmente completas: ' . $complete . "\n";
echo 'Con detalle interno pendiente: ' . $partial . "\n";
echo "La zona territorial no altera el conteo funcional de la unidad.\n";

if ($unresolvedGroups !== []) {
    echo "\nPrincipales referencias territoriales pendientes:\n";
    $shown = 0;
    foreach ($unresolvedGroups as $group => $count) {
        echo '- ' . ($group !== '' ? $group : '[VACIO]') . ': ' . $count . "\n";
        $shown++;
        if ($shown >= 25) {
            break;
        }
    }
}
