<?php
declare(strict_types=1);
session_start();

$configPath = __DIR__ . '/config.php';
if (!is_file($configPath)) {
    http_response_code(500);
    die('Falta dashboard/config.php');
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

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function rows(PDO $pdo, string $sql, array $params = []): array
{
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return $statement->fetchAll();
}

function one(PDO $pdo, string $sql, array $params = []): array
{
    $result = rows($pdo, $sql, $params);
    return $result[0] ?? [];
}

function normalizeKey(mixed $value): string
{
    $text = trim((string)$value);
    $text = function_exists('mb_strtoupper') ? mb_strtoupper($text, 'UTF-8') : strtoupper($text);
    $text = strtr($text, ['Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N']);
    $text = preg_replace('/[^A-Z0-9]+/u', ' ', $text);
    return trim((string)preg_replace('/\s+/', ' ', (string)$text));
}

function matchedLevel(array $unit): string
{
    $type = normalizeKey($unit['unit_type'] ?? '');
    $legacy = normalizeKey($unit['legacy_table'] ?? '');

    if ($type === 'ZONA POLICIAL' || $legacy === 'MOI CABECERA ZONA') {
        return 'zona';
    }
    if (in_array($type, ['DIRECCION NACIONAL', 'SUBDIRECCION NACIONAL'], true) || $legacy === 'MOI CABECERA DIRECCION') {
        return 'direccion';
    }
    if (in_array($type, ['AREA', 'AREA POLICIAL'], true) || $legacy === 'MOI CABECERA AREA') {
        return 'area';
    }
    if (str_contains($type, 'SERVICIO')) {
        return 'servicio';
    }
    if (in_array($type, [
        'DEPARTAMENTO', 'DIVISION', 'SECCION', 'OFICINA', 'DEPENDENCIA',
        'ESTACION', 'ESTACION POLICIAL', 'SUBESTACION', 'SUBESTACION POLICIAL',
        'PUESTO', 'PUESTO POLICIAL', 'DESTACAMENTO',
    ], true)) {
        return 'dependencia';
    }
    return 'unidad';
}

function defaultPendingLevel(string $matchedLevel): string
{
    return match ($matchedLevel) {
        'zona' => 'area/dependencia',
        'direccion' => 'departamento/dependencia',
        'area' => 'dependencia/servicio',
        default => 'nivel inferior',
    };
}

if (empty($_SESSION['pie_fuerza_bulk_csrf'])) {
    $_SESSION['pie_fuerza_bulk_csrf'] = bin2hex(random_bytes(24));
}
$csrf = (string)$_SESSION['pie_fuerza_bulk_csrf'];

$sources = rows($pdo, 'SELECT * FROM workforce_sources ORDER BY document_date DESC, id DESC');
$sourceId = (int)($_GET['source_id'] ?? $_POST['source_id'] ?? ($sources[0]['id'] ?? 0));
$source = $sourceId > 0 ? one($pdo, 'SELECT * FROM workforce_sources WHERE id=:id', ['id'=>$sourceId]) : [];
$locationKey = trim((string)($_GET['location'] ?? $_POST['location'] ?? ''));
$search = trim((string)($_GET['buscar'] ?? ''));
$groupStatus = trim((string)($_GET['estado_grupo'] ?? 'por_revisar'));
$unitSearch = trim((string)($_GET['unidad_buscar'] ?? ''));
$message = '';
$error = '';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
            throw new RuntimeException('Token de seguridad inválido. Recargue la página.');
        }
        if (!$source || $locationKey === '') {
            throw new RuntimeException('Debe seleccionar una fuente y una ubicación original.');
        }

        $action = (string)($_POST['action'] ?? '');
        $reviewedBy = trim((string)($_POST['reviewed_by'] ?? 'usuario_local')) ?: 'usuario_local';
        $baseParams = ['source_id'=>$sourceId, 'location'=>$locationKey];
        $counts = one(
            $pdo,
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN COALESCE(m.match_method,'')='revision_manual' THEN 1 ELSE 0 END) AS manuales
             FROM workforce_personnel_staging p
             LEFT JOIN workforce_unit_matches m ON m.personnel_staging_id=p.id
             WHERE p.source_id=:source_id
               AND p.import_status='importado'
               AND p.location_normalized=:location",
            $baseParams
        );
        $eligible = max(0, (int)($counts['total'] ?? 0) - (int)($counts['manuales'] ?? 0));
        if ($eligible === 0) {
            throw new RuntimeException('Este grupo no tiene registros elegibles. Las excepciones manuales se conservan.');
        }

        $pdo->beginTransaction();
        if ($action === 'assign') {
            $unitId = (int)($_POST['unit_id'] ?? 0);
            $assignmentStatus = (string)($_POST['assignment_status'] ?? 'asignado_parcial');
            if (!in_array($assignmentStatus, ['asignado_completo', 'asignado_parcial'], true)) {
                throw new RuntimeException('Estado de asignación no permitido.');
            }
            $unit = one(
                $pdo,
                "SELECT ou.id,ou.name,ou.legacy_table,ou.legacy_id,ut.name AS unit_type
                 FROM organizational_units ou
                 LEFT JOIN unit_types ut ON ut.id=ou.unit_type_id
                 WHERE ou.id=:id
                   AND ou.status='active'
                   AND ou.lifecycle_status='vigente'
                   AND (ou.valid_from IS NULL OR ou.valid_from<=CURRENT_DATE)
                   AND (ou.valid_to IS NULL OR ou.valid_to>=CURRENT_DATE)
                 LIMIT 1",
                ['id'=>$unitId]
            );
            if (!$unit) {
                throw new RuntimeException('La unidad seleccionada no existe o no está vigente.');
            }

            $level = matchedLevel($unit);
            $pendingLevel = null;
            if ($assignmentStatus === 'asignado_parcial') {
                $pendingLevel = trim((string)($_POST['pending_level'] ?? '')) ?: defaultPendingLevel($level);
            }
            $notes = trim((string)($_POST['notes'] ?? ''));
            if ($notes === '') {
                $notes = 'Asignación masiva por ubicación original: ' . $locationKey;
            }

            $statement = $pdo->prepare(
                "INSERT INTO workforce_unit_matches
                 (personnel_staging_id,matched_unit_id,matched_level,assignment_status,pending_level,
                  match_method,confidence_level,candidate_count,candidate_data,review_status,
                  review_notes,reviewed_by,reviewed_at)
                 SELECT p.id,:unit_id,:level,:assignment_status,:pending_level,
                        'revision_masiva','alto',1,NULL,'aprobado',:notes,:reviewed_by,NOW()
                 FROM workforce_personnel_staging p
                 LEFT JOIN workforce_unit_matches current_match ON current_match.personnel_staging_id=p.id
                 WHERE p.source_id=:source_id
                   AND p.import_status='importado'
                   AND p.location_normalized=:location
                   AND COALESCE(current_match.match_method,'')<>'revision_manual'
                 ON DUPLICATE KEY UPDATE
                    matched_unit_id=VALUES(matched_unit_id),
                    matched_level=VALUES(matched_level),
                    assignment_status=VALUES(assignment_status),
                    pending_level=VALUES(pending_level),
                    match_method='revision_masiva',
                    confidence_level='alto',
                    candidate_count=1,
                    candidate_data=NULL,
                    review_status='aprobado',
                    review_notes=VALUES(review_notes),
                    reviewed_by=VALUES(reviewed_by),
                    reviewed_at=NOW(),
                    updated_at=NOW()"
            );
            $statement->execute([
                'unit_id'=>$unitId,
                'level'=>$level,
                'assignment_status'=>$assignmentStatus,
                'pending_level'=>$pendingLevel,
                'notes'=>$notes,
                'reviewed_by'=>$reviewedBy,
                'source_id'=>$sourceId,
                'location'=>$locationKey,
            ]);
            $pdo->commit();
            $message = "Se aplicó la asignación a {$eligible} personas. Las excepciones revisadas manualmente no fueron modificadas.";
        } elseif ($action === 'no_match') {
            $notes = trim((string)($_POST['notes'] ?? '')) ?: 'Ubicación no encontrada en la estructura vigente: ' . $locationKey;
            $statement = $pdo->prepare(
                "INSERT INTO workforce_unit_matches
                 (personnel_staging_id,matched_unit_id,matched_level,assignment_status,pending_level,
                  match_method,confidence_level,candidate_count,candidate_data,review_status,
                  review_notes,reviewed_by,reviewed_at)
                 SELECT p.id,NULL,'ninguno','sin_coincidencia',NULL,
                        'revision_masiva','alto',0,NULL,'aprobado',:notes,:reviewed_by,NOW()
                 FROM workforce_personnel_staging p
                 LEFT JOIN workforce_unit_matches current_match ON current_match.personnel_staging_id=p.id
                 WHERE p.source_id=:source_id
                   AND p.import_status='importado'
                   AND p.location_normalized=:location
                   AND COALESCE(current_match.match_method,'')<>'revision_manual'
                 ON DUPLICATE KEY UPDATE
                    matched_unit_id=NULL,
                    matched_level='ninguno',
                    assignment_status='sin_coincidencia',
                    pending_level=NULL,
                    match_method='revision_masiva',
                    confidence_level='alto',
                    candidate_count=0,
                    candidate_data=NULL,
                    review_status='aprobado',
                    review_notes=VALUES(review_notes),
                    reviewed_by=VALUES(reviewed_by),
                    reviewed_at=NOW(),
                    updated_at=NOW()"
            );
            $statement->execute([
                'notes'=>$notes,
                'reviewed_by'=>$reviewedBy,
                'source_id'=>$sourceId,
                'location'=>$locationKey,
            ]);
            $pdo->commit();
            $message = "Se marcaron {$eligible} personas sin coincidencia. Las excepciones manuales fueron conservadas.";
        } else {
            throw new RuntimeException('Acción masiva no reconocida.');
        }
    }
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $error = $exception->getMessage();
}

$groupWhere = [
    'p.source_id=:source_id',
    "p.import_status='importado'",
    "p.location_normalized IS NOT NULL",
    "p.location_normalized<>''",
];
$groupParams = ['source_id'=>$sourceId];
if ($search !== '') {
    $groupWhere[] = '(p.location_original LIKE :search OR p.location_normalized LIKE :search)';
    $groupParams['search'] = '%' . $search . '%';
}

$having = '';
switch ($groupStatus) {
    case 'pendiente_revision':
        $having = 'HAVING pendientes_revision > 0';
        break;
    case 'asignado_parcial':
        $having = 'HAVING asignados_parciales > 0';
        break;
    case 'asignado_completo':
        $having = 'HAVING asignados_completos > 0';
        break;
    case 'sin_coincidencia':
        $having = 'HAVING sin_coincidencia > 0';
        break;
    case 'aprobado':
        $having = 'HAVING aprobados > 0';
        break;
    case 'todos':
        $having = '';
        break;
    default:
        $groupStatus = 'por_revisar';
        $having = 'HAVING por_revisar > 0';
        break;
}

$groups = [];
if ($sourceId > 0) {
    $groups = rows(
        $pdo,
        "SELECT p.location_normalized,
                MIN(NULLIF(p.location_original,'')) AS location_original,
                COUNT(*) AS total_personas,
                SUM(CASE WHEN COALESCE(m.assignment_status,'pendiente_revision')='asignado_completo' THEN 1 ELSE 0 END) AS asignados_completos,
                SUM(CASE WHEN COALESCE(m.assignment_status,'pendiente_revision')='asignado_parcial' THEN 1 ELSE 0 END) AS asignados_parciales,
                SUM(CASE WHEN COALESCE(m.assignment_status,'pendiente_revision')='pendiente_revision' THEN 1 ELSE 0 END) AS pendientes_revision,
                SUM(CASE WHEN COALESCE(m.assignment_status,'pendiente_revision')='sin_coincidencia' THEN 1 ELSE 0 END) AS sin_coincidencia,
                SUM(CASE WHEN COALESCE(m.review_status,'pendiente')='aprobado' THEN 1 ELSE 0 END) AS aprobados,
                SUM(CASE WHEN COALESCE(m.match_method,'')='revision_manual' THEN 1 ELSE 0 END) AS excepciones_manuales,
                SUM(CASE WHEN COALESCE(m.assignment_status,'pendiente_revision') IN ('pendiente_revision','asignado_parcial')
                          AND COALESCE(m.review_status,'pendiente')<>'aprobado' THEN 1 ELSE 0 END) AS por_revisar,
                COUNT(DISTINCT m.matched_unit_id) AS unidades_distintas,
                GROUP_CONCAT(DISTINCT ou.name ORDER BY ou.name SEPARATOR ' | ') AS unidades_confirmadas
         FROM workforce_personnel_staging p
         LEFT JOIN workforce_unit_matches m ON m.personnel_staging_id=p.id
         LEFT JOIN organizational_units ou ON ou.id=m.matched_unit_id
         WHERE " . implode(' AND ', $groupWhere) . "
         GROUP BY p.location_normalized
         {$having}
         ORDER BY por_revisar DESC, pendientes_revision DESC, asignados_parciales DESC, total_personas DESC, location_original
         LIMIT 300",
        $groupParams
    );
}

$selectedGroup = [];
$people = [];
$currentUnits = [];
if ($sourceId > 0 && $locationKey !== '') {
    $selectedGroup = one(
        $pdo,
        "SELECT p.location_normalized,
                MIN(NULLIF(p.location_original,'')) AS location_original,
                COUNT(*) AS total_personas,
                SUM(CASE WHEN COALESCE(m.assignment_status,'pendiente_revision')='asignado_completo' THEN 1 ELSE 0 END) AS asignados_completos,
                SUM(CASE WHEN COALESCE(m.assignment_status,'pendiente_revision')='asignado_parcial' THEN 1 ELSE 0 END) AS asignados_parciales,
                SUM(CASE WHEN COALESCE(m.assignment_status,'pendiente_revision')='pendiente_revision' THEN 1 ELSE 0 END) AS pendientes_revision,
                SUM(CASE WHEN COALESCE(m.assignment_status,'pendiente_revision')='sin_coincidencia' THEN 1 ELSE 0 END) AS sin_coincidencia,
                SUM(CASE WHEN COALESCE(m.match_method,'')='revision_manual' THEN 1 ELSE 0 END) AS excepciones_manuales
         FROM workforce_personnel_staging p
         LEFT JOIN workforce_unit_matches m ON m.personnel_staging_id=p.id
         WHERE p.source_id=:source_id
           AND p.import_status='importado'
           AND p.location_normalized=:location
         GROUP BY p.location_normalized",
        ['source_id'=>$sourceId, 'location'=>$locationKey]
    );
    $people = rows(
        $pdo,
        "SELECT d.*
         FROM vw_workforce_match_detail d
         WHERE d.source_id=:source_id AND d.location_normalized=:location
         ORDER BY d.row_number
         LIMIT 200",
        ['source_id'=>$sourceId, 'location'=>$locationKey]
    );
    $currentUnits = rows(
        $pdo,
        "SELECT DISTINCT ou.id,ou.name,ou.parent_id,ou.legacy_table,ou.legacy_id,ut.name AS unit_type,parent.name AS parent_name
         FROM workforce_personnel_staging p
         JOIN workforce_unit_matches m ON m.personnel_staging_id=p.id
         JOIN organizational_units ou ON ou.id=m.matched_unit_id
         LEFT JOIN unit_types ut ON ut.id=ou.unit_type_id
         LEFT JOIN organizational_units parent ON parent.id=ou.parent_id
         WHERE p.source_id=:source_id AND p.location_normalized=:location
         ORDER BY ou.name",
        ['source_id'=>$sourceId, 'location'=>$locationKey]
    );
}

$unitWhere = [
    "ou.status='active'",
    "ou.lifecycle_status='vigente'",
    '(ou.valid_from IS NULL OR ou.valid_from<=CURRENT_DATE)',
    '(ou.valid_to IS NULL OR ou.valid_to>=CURRENT_DATE)',
];
$unitParams = [];
if ($unitSearch !== '') {
    $unitWhere[] = '(ou.name LIKE :unit_search OR ou.short_name LIKE :unit_search OR ou.code LIKE :unit_search OR ou.moi_code LIKE :unit_search)';
    $unitParams['unit_search'] = '%' . $unitSearch . '%';
} elseif ($currentUnits) {
    $ids = array_values(array_unique(array_map(static fn(array $unit): int => (int)$unit['id'], $currentUnits)));
    $placeholders = [];
    $parentPlaceholders = [];
    foreach ($ids as $index => $id) {
        $key = 'current_' . $index;
        $parentKey = 'parent_' . $index;
        $placeholders[] = ':' . $key;
        $parentPlaceholders[] = ':' . $parentKey;
        $unitParams[$key] = $id;
        $unitParams[$parentKey] = $id;
    }
    $in = implode(',', $placeholders);
    $parentIn = implode(',', $parentPlaceholders);
    $unitWhere[] = "(ou.id IN ({$in}) OR ou.parent_id IN ({$parentIn}))";
} else {
    $unitWhere[] = "(ou.legacy_table IN ('MOI_CABECERA_ZONA','MOI_CABECERA_DIRECCION') OR ut.name IN ('zona_policial','direccion_nacional'))";
}

$candidates = $locationKey !== '' ? rows(
    $pdo,
    "SELECT ou.id,ou.name,ou.short_name,ou.code,ou.moi_code,ou.legacy_table,ou.legacy_id,
            ut.name AS unit_type,parent.name AS parent_name
     FROM organizational_units ou
     LEFT JOIN unit_types ut ON ut.id=ou.unit_type_id
     LEFT JOIN organizational_units parent ON parent.id=ou.parent_id
     WHERE " . implode(' AND ', $unitWhere) . "
     ORDER BY COALESCE(ou.moi_level,ou.level,99),ut.name,ou.name
     LIMIT 200",
    $unitParams
) : [];
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Revisión masiva PIE DE FUERZA</title>
<style>
body{font-family:Arial,sans-serif;margin:0;background:#f4f6f8;color:#1f2937}header{background:#111827;color:#fff;padding:18px 28px}header h1{margin:0;font-size:22px}.top{margin:7px 0 0}.top a{color:#d1d5db;margin-right:14px;font-weight:bold}main{padding:22px}.card,section{background:#fff;border-radius:10px;padding:15px;box-shadow:0 1px 4px #0002;margin-bottom:16px}.layout{display:grid;grid-template-columns:minmax(380px,44%) 1fr;gap:16px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:10px}.kpi{border:1px solid #e5e7eb;border-radius:8px;padding:10px;background:#f9fafb}.label{font-size:11px;color:#6b7280;text-transform:uppercase}.value{font-size:22px;font-weight:bold;margin-top:4px}.filters,.inline{display:flex;gap:8px;flex-wrap:wrap;align-items:center}input,select,button{padding:8px;border:1px solid #d1d5db;border-radius:6px}button,.btn{background:#047857;color:#fff;border:0;text-decoration:none;padding:9px 11px;border-radius:7px;cursor:pointer}.btn.blue{background:#1d4ed8}.btn.gray{background:#4b5563}.danger{background:#b91c1c}.msg{background:#ecfdf5;border:1px solid #10b981}.err{background:#fef2f2;border:1px solid #ef4444}.warn{background:#fffbeb;border:1px solid #f59e0b}.muted{font-size:12px;color:#6b7280}.ok{color:#047857;font-weight:bold}.partial{color:#a16207;font-weight:bold}.bad{color:#b91c1c;font-weight:bold}.scroll{overflow:auto;max-height:670px}table{width:100%;border-collapse:collapse;font-size:12.5px}th,td{padding:7px;border-bottom:1px solid #e5e7eb;text-align:left;vertical-align:top}th{background:#f9fafb;position:sticky;top:0}.group-link{font-weight:bold}.pill{display:inline-block;padding:3px 7px;border-radius:999px;background:#eef2ff;font-size:11px}.candidate-form{display:grid;gap:6px}.source-select a{display:inline-block;margin:3px;padding:7px 9px;border-radius:7px;background:#e5e7eb;color:#111827;text-decoration:none}.source-select a.active{background:#1d4ed8;color:#fff}@media(max-width:1050px){.layout{grid-template-columns:1fr}}
</style>
</head>
<body>
<header>
<h1>PIE DE FUERZA — revisión masiva por ubicación</h1>
<p class="top"><a href="pie_fuerza.php?source_id=<?=h($sourceId)?>">Listado de personas</a><a href="index.php">Dashboard</a><a href="trabajo_zonas.php">Trabajo por zona</a></p>
</header>
<main>
<?php if ($message): ?><section class="msg"><?=h($message)?></section><?php endif; ?>
<?php if ($error): ?><section class="err"><?=h($error)?></section><?php endif; ?>
<section class="source-select"><h2>Fuente</h2><?php foreach ($sources as $item): ?><a class="<?=((int)$item['id']===$sourceId)?'active':''?>" href="?source_id=<?=h($item['id'])?>"><?=h($item['source_key'])?> — <?=h($item['document_date'])?></a><?php endforeach; ?></section>
<section class="warn"><strong>Regla protegida:</strong> esta pantalla solo asigna personas a unidades vigentes que ya existen. No crea, renombra, mueve ni modifica <code>organizational_units</code>. Las revisiones manuales individuales se conservan como excepciones.</section>
<section><h2>Buscar grupos de ubicación</h2><form class="filters" method="get"><input type="hidden" name="source_id" value="<?=h($sourceId)?>"><input name="buscar" value="<?=h($search)?>" placeholder="Buscar ubicación original" size="34"><select name="estado_grupo"><?php foreach(['por_revisar'=>'Por revisar','pendiente_revision'=>'Con pendientes','asignado_parcial'=>'Con parciales','asignado_completo'=>'Con completos','sin_coincidencia'=>'Sin coincidencia','aprobado'=>'Con aprobados','todos'=>'Todos'] as $key=>$label): ?><option value="<?=h($key)?>" <?=$groupStatus===$key?'selected':''?>><?=h($label)?></option><?php endforeach; ?></select><button>Filtrar</button></form></section>
<div class="layout">
<div>
<section><h2>Ubicaciones agrupadas</h2><p class="muted">Se muestran hasta 300 ubicaciones. Ordenadas por mayor cantidad pendiente.</p><div class="scroll"><table><thead><tr><th>Ubicación original</th><th>Personas</th><th>Estado</th><th>Abrir</th></tr></thead><tbody><?php foreach ($groups as $group): ?><tr><td><strong><?=h($group['location_original'] ?: $group['location_normalized'])?></strong><br><span class="muted"><?=h($group['unidades_confirmadas'] ?: 'Sin unidad confirmada')?></span></td><td><?=h($group['total_personas'])?><br><span class="muted">Excepciones: <?=h($group['excepciones_manuales'])?></span></td><td><span class="bad">Pend.: <?=h($group['pendientes_revision'])?></span><br><span class="partial">Parc.: <?=h($group['asignados_parciales'])?></span><br><span class="ok">Comp.: <?=h($group['asignados_completos'])?></span></td><td><a class="group-link" href="?<?=h(http_build_query(['source_id'=>$sourceId,'estado_grupo'=>$groupStatus,'buscar'=>$search,'location'=>$group['location_normalized']]))?>">Revisar grupo</a></td></tr><?php endforeach; ?></tbody></table></div></section>
</div>
<div>
<?php if (!$selectedGroup): ?>
<section><h2>Seleccione una ubicación</h2><p>Abra un grupo de la lista para revisar sus personas y asignarlo masivamente.</p></section>
<?php else: ?>
<section><h2><?=h($selectedGroup['location_original'] ?: $selectedGroup['location_normalized'])?></h2><div class="grid"><div class="kpi"><div class="label">Total</div><div class="value"><?=h($selectedGroup['total_personas'])?></div></div><div class="kpi"><div class="label">Pendientes</div><div class="value bad"><?=h($selectedGroup['pendientes_revision'])?></div></div><div class="kpi"><div class="label">Parciales</div><div class="value partial"><?=h($selectedGroup['asignados_parciales'])?></div></div><div class="kpi"><div class="label">Completos</div><div class="value ok"><?=h($selectedGroup['asignados_completos'])?></div></div><div class="kpi"><div class="label">Excepciones manuales</div><div class="value"><?=h($selectedGroup['excepciones_manuales'])?></div></div></div></section>
<?php if ($currentUnits): ?><section><h2>Unidad actualmente sugerida</h2><?php foreach ($currentUnits as $unit): ?><p><strong><?=h($unit['name'])?></strong> <span class="pill"><?=h($unit['unit_type'])?></span><br><span class="muted">Superior: <?=h($unit['parent_name'] ?: 'Sin superior')?> | <?=h($unit['legacy_table'])?>: <?=h($unit['legacy_id'])?></span></p><?php endforeach; ?></section><?php endif; ?>
<section><h2>Buscar unidad existente</h2><form method="get" class="inline"><input type="hidden" name="source_id" value="<?=h($sourceId)?>"><input type="hidden" name="location" value="<?=h($locationKey)?>"><input type="hidden" name="estado_grupo" value="<?=h($groupStatus)?>"><input name="unidad_buscar" value="<?=h($unitSearch)?>" placeholder="Zona, dirección, área o dependencia" size="42"><button>Buscar</button></form><p class="muted">Sin búsqueda se muestran la unidad sugerida y sus hijos directos. Escriba una palabra para buscar en toda la estructura vigente.</p></section>
<section><h2>Asignar todo el grupo</h2><div class="scroll"><table><thead><tr><th>Unidad vigente</th><th>Superior</th><th>Tipo / código</th><th>Aplicar</th></tr></thead><tbody><?php foreach ($candidates as $candidate): ?><tr><td><strong><?=h($candidate['name'])?></strong><br><span class="muted"><?=h($candidate['legacy_table'])?>: <?=h($candidate['legacy_id'])?></span></td><td><?=h($candidate['parent_name'] ?: 'Sin superior')?></td><td><?=h($candidate['unit_type'])?><br><?=h($candidate['moi_code'] ?: $candidate['code'])?></td><td><form method="post" class="candidate-form" onsubmit="return confirm('¿Aplicar esta asignación a todo el grupo, conservando las excepciones manuales?')"><input type="hidden" name="csrf" value="<?=h($csrf)?>"><input type="hidden" name="source_id" value="<?=h($sourceId)?>"><input type="hidden" name="location" value="<?=h($locationKey)?>"><input type="hidden" name="action" value="assign"><input type="hidden" name="unit_id" value="<?=h($candidate['id'])?>"><div class="inline"><select name="assignment_status"><option value="asignado_parcial">Asignación parcial</option><option value="asignado_completo">Asignación completa</option></select><input name="pending_level" placeholder="Nivel pendiente"></div><input name="reviewed_by" value="usuario_local" placeholder="Revisado por"><input name="notes" value="Asignación masiva por ubicación original" size="34"><button>Aplicar al grupo</button></form></td></tr><?php endforeach; ?></tbody></table></div></section>
<section><h2>Marcar el grupo sin coincidencia</h2><form method="post" class="inline" onsubmit="return confirm('¿Confirmar que esta ubicación no existe en la estructura vigente?')"><input type="hidden" name="csrf" value="<?=h($csrf)?>"><input type="hidden" name="source_id" value="<?=h($sourceId)?>"><input type="hidden" name="location" value="<?=h($locationKey)?>"><input type="hidden" name="action" value="no_match"><input name="reviewed_by" value="usuario_local"><input name="notes" value="Ubicación no encontrada en la estructura vigente" size="48"><button class="danger">Marcar sin coincidencia</button></form></section>
<section><h2>Personas del grupo</h2><p class="muted">Se muestran hasta 200. Cada persona puede mantenerse como excepción mediante la revisión individual.</p><div class="scroll"><table><thead><tr><th>Fila</th><th>Funcionario</th><th>Rango / Posición</th><th>Resultado</th><th>Revisión individual</th></tr></thead><tbody><?php foreach ($people as $person): ?><tr><td><?=h($person['row_number'])?></td><td><strong><?=h($person['full_name'])?></strong></td><td><?=h($person['rank_text'])?><br><span class="muted"><?=h($person['position_number'])?></span></td><td><?=h($person['matched_unit_name'] ?: 'Sin unidad')?><br><span class="pill"><?=h($person['assignment_status'] ?: 'pendiente_revision')?></span></td><td><a href="pie_fuerza_revision.php?id=<?=h($person['personnel_staging_id'])?>">Abrir excepción</a></td></tr><?php endforeach; ?></tbody></table></div></section>
<?php endif; ?>
</div>
</div>
</main>
</body>
</html>
