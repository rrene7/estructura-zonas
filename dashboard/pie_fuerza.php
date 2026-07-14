<?php
declare(strict_types=1);

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

function table_exists(PDO $pdo, string $table): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) total
         FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name = :table'
    );
    $statement->execute(['table' => $table]);
    return (int)($statement->fetch()['total'] ?? 0) > 0;
}

function column_exists(PDO $pdo, string $table, string $column): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) total
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = :table
           AND column_name = :column'
    );
    $statement->execute(['table' => $table, 'column' => $column]);
    return (int)($statement->fetch()['total'] ?? 0) > 0;
}

$hasModule = table_exists($pdo, 'workforce_sources')
    && table_exists($pdo, 'workforce_personnel_staging')
    && table_exists($pdo, 'workforce_unit_matches')
    && table_exists($pdo, 'vw_workforce_match_detail')
    && table_exists($pdo, 'vw_workforce_summary');

$hasTerritorialContext = $hasModule
    && column_exists($pdo, 'workforce_unit_matches', 'territorial_zone_unit_id')
    && column_exists($pdo, 'workforce_unit_matches', 'internal_detail');

$sources = $hasModule
    ? rows($pdo, 'SELECT * FROM workforce_sources ORDER BY document_date DESC, id DESC')
    : [];
$sourceId = (int)($_GET['source_id'] ?? ($sources[0]['id'] ?? 0));
$source = $sourceId > 0
    ? one($pdo, 'SELECT * FROM workforce_sources WHERE id = :id', ['id' => $sourceId])
    : [];

$status = trim((string)($_GET['status'] ?? ''));
$level = trim((string)($_GET['level'] ?? ''));
$review = trim((string)($_GET['review'] ?? ''));
$zoneId = (int)($_GET['zone_id'] ?? 0);
$search = trim((string)($_GET['buscar'] ?? ''));

$summary = $sourceId > 0 && $hasModule
    ? one($pdo, 'SELECT * FROM vw_workforce_summary WHERE source_id = :source_id', ['source_id' => $sourceId])
    : [];

$contextSummary = [];
$zoneOptions = [];
if ($sourceId > 0 && $hasTerritorialContext) {
    $contextSummary = one(
        $pdo,
        "SELECT
            SUM(m.territorial_zone_unit_id IS NOT NULL) AS con_zona_territorial,
            SUM(NULLIF(TRIM(COALESCE(m.internal_detail, '')), '') IS NOT NULL) AS con_detalle_interno
         FROM workforce_personnel_staging p
         LEFT JOIN workforce_unit_matches m ON m.personnel_staging_id = p.id
         WHERE p.source_id = :source_id
           AND p.import_status = 'importado'",
        ['source_id' => $sourceId]
    );

    $zoneOptions = rows(
        $pdo,
        "SELECT DISTINCT z.id, z.name
         FROM workforce_personnel_staging p
         JOIN workforce_unit_matches m ON m.personnel_staging_id = p.id
         JOIN organizational_units z ON z.id = m.territorial_zone_unit_id
         WHERE p.source_id = :source_id
         ORDER BY CAST(z.legacy_id AS UNSIGNED), z.name",
        ['source_id' => $sourceId]
    );
}

$where = ['d.source_id = :source_id'];
$params = ['source_id' => $sourceId];

if ($status !== '') {
    $where[] = 'd.assignment_status = :status';
    $params['status'] = $status;
}
if ($level !== '') {
    $where[] = 'd.matched_level = :level';
    $params['level'] = $level;
}
if ($review !== '') {
    $where[] = 'd.review_status = :review';
    $params['review'] = $review;
}
if ($zoneId > 0 && $hasTerritorialContext) {
    $where[] = 'm.territorial_zone_unit_id = :zone_id';
    $params['zone_id'] = $zoneId;
}
if ($search !== '') {
    $searchFields = [
        'd.full_name LIKE :buscar',
        'd.position_number LIKE :buscar',
        'd.location_original LIKE :buscar',
        'd.matched_unit_name LIKE :buscar',
        'd.rank_text LIKE :buscar',
    ];
    if ($hasTerritorialContext) {
        $searchFields[] = 'territorial.name LIKE :buscar';
        $searchFields[] = 'm.internal_detail LIKE :buscar';
    }
    $where[] = '(' . implode(' OR ', $searchFields) . ')';
    $params['buscar'] = '%' . $search . '%';
}

$detail = [];
$byUnit = [];
if ($sourceId > 0 && $hasModule) {
    if ($hasTerritorialContext) {
        $detailSql =
            "SELECT
                d.*,
                m.territorial_zone_unit_id,
                territorial.name AS territorial_zone_name,
                territorial.code AS territorial_zone_code,
                m.internal_detail
             FROM vw_workforce_match_detail d
             LEFT JOIN workforce_unit_matches m ON m.id = d.match_id
             LEFT JOIN organizational_units territorial ON territorial.id = m.territorial_zone_unit_id
             WHERE " . implode(' AND ', $where) .
            ' ORDER BY d.row_number LIMIT 1000';

        $byUnit = rows(
            $pdo,
            "SELECT
                COALESCE(d.matched_unit_name, 'SIN UNIDAD') AS unidad_funcional,
                COALESCE(territorial.name, 'SIN ZONA TERRITORIAL') AS zona_territorial,
                d.matched_level,
                d.assignment_status,
                COUNT(*) AS total
             FROM vw_workforce_match_detail d
             LEFT JOIN workforce_unit_matches m ON m.id = d.match_id
             LEFT JOIN organizational_units territorial ON territorial.id = m.territorial_zone_unit_id
             WHERE d.source_id = :source_id
             GROUP BY
                unidad_funcional,
                zona_territorial,
                d.matched_level,
                d.assignment_status
             ORDER BY total DESC, unidad_funcional, zona_territorial
             LIMIT 200",
            ['source_id' => $sourceId]
        );
    } else {
        $detailSql =
            "SELECT
                d.*,
                NULL AS territorial_zone_unit_id,
                NULL AS territorial_zone_name,
                NULL AS territorial_zone_code,
                NULL AS internal_detail
             FROM vw_workforce_match_detail d
             WHERE " . implode(' AND ', $where) .
            ' ORDER BY d.row_number LIMIT 1000';

        $byUnit = rows(
            $pdo,
            "SELECT
                COALESCE(d.matched_unit_name, 'SIN UNIDAD') AS unidad_funcional,
                'SIN ZONA TERRITORIAL' AS zona_territorial,
                d.matched_level,
                d.assignment_status,
                COUNT(*) AS total
             FROM vw_workforce_match_detail d
             WHERE d.source_id = :source_id
             GROUP BY unidad_funcional, d.matched_level, d.assignment_status
             ORDER BY total DESC, unidad_funcional
             LIMIT 200",
            ['source_id' => $sourceId]
        );
    }

    $detail = rows($pdo, $detailSql, $params);
}

if (($_GET['descargar'] ?? '') === 'csv' && $hasModule && $sourceId > 0) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=pie_fuerza_match_' . $sourceId . '.csv');
    $out = fopen('php://output', 'wb');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [
        'Fila',
        'Rango',
        'Posicion',
        'Nombre',
        'Apellido',
        'Ubicacion original',
        'Tipo Policia',
        'Unidad funcional',
        'Zona territorial',
        'Detalle interno',
        'Nivel confirmado',
        'Estado',
        'Nivel pendiente',
        'Metodo',
        'Confianza',
        'Revision',
    ]);
    foreach ($detail as $row) {
        fputcsv($out, [
            $row['row_number'],
            $row['rank_text'],
            $row['position_number'],
            $row['first_name'],
            $row['last_name'],
            $row['location_original'],
            $row['police_type_original'],
            $row['matched_unit_name'],
            $row['territorial_zone_name'],
            $row['internal_detail'],
            $row['matched_level'],
            $row['assignment_status'] ?: 'pendiente_revision',
            $row['pending_level'],
            $row['match_method'],
            $row['confidence_level'],
            $row['review_status'],
        ]);
    }
    fclose($out);
    exit;
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PIE DE FUERZA</title>
    <style>
        body{font-family:Arial,sans-serif;margin:0;background:#f4f6f8;color:#1f2937}
        header{background:#111827;color:#fff;padding:18px 28px}
        header h1{margin:0;font-size:22px}
        .top{margin:7px 0 0}.top a{color:#d1d5db;margin-right:14px;font-weight:bold}
        main{padding:22px}.card,section{background:#fff;border-radius:10px;padding:15px;box-shadow:0 1px 4px #0002;margin-bottom:16px}
        .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(155px,1fr));gap:10px}
        .kpi{border:1px solid #e5e7eb;border-radius:8px;padding:12px;background:#f9fafb}
        .kpi .label{font-size:12px;color:#6b7280;text-transform:uppercase}.kpi .value{font-size:25px;font-weight:bold;margin-top:5px}
        .filters{display:flex;gap:8px;flex-wrap:wrap}.filters input,.filters select,.filters button{padding:8px;border:1px solid #d1d5db;border-radius:6px}
        .filters button,.btn{background:#047857;color:#fff;text-decoration:none;border:0;padding:9px 11px;border-radius:7px;display:inline-block}
        .btn.blue{background:#1d4ed8}.btn.orange{background:#b45309}.warn{background:#fffbeb;border:1px solid #f59e0b}
        .ok{color:#047857;font-weight:bold}.bad{color:#b91c1c;font-weight:bold}.partial{color:#a16207;font-weight:bold}
        .muted{color:#6b7280;font-size:12px}.context{display:block;margin-top:4px;padding:5px 7px;border-radius:6px;background:#f3f4f6}
        table{width:100%;border-collapse:collapse;font-size:12.5px}th,td{border-bottom:1px solid #e5e7eb;padding:7px;text-align:left;vertical-align:top}
        th{background:#f9fafb;position:sticky;top:0}.scroll{overflow:auto;max-height:650px}.pill{display:inline-block;padding:3px 7px;border-radius:999px;background:#eef2ff;font-size:11px}
        .source-select a{display:inline-block;margin:3px;padding:7px 9px;border-radius:7px;background:#e5e7eb;color:#111827;text-decoration:none}.source-select a.active{background:#1d4ed8;color:#fff}
    </style>
</head>
<body>
<header>
    <h1>PIE DE FUERZA — asignación contra estructura vigente</h1>
    <p class="top">
        <a href="index.php">Dashboard</a>
        <a href="trabajo_zonas.php">Trabajo por zona</a>
        <a href="asignar_unidades_direccion.php">Direcciones</a>
        <a href="pie_fuerza_masiva.php?source_id=<?=h($sourceId)?>">Revisión masiva</a>
    </p>
</header>
<main>
<?php if (!$hasModule): ?>
    <section class="warn">
        <h2>Módulo no instalado</h2>
        <p>Ejecute <code>database/pie_fuerza_20260626.sql</code>.</p>
    </section>
<?php else: ?>
    <section class="source-select">
        <h2>Fuente</h2>
        <?php foreach ($sources as $item): ?>
            <a class="<?=((int)$item['id'] === $sourceId) ? 'active' : ''?>" href="?source_id=<?=h($item['id'])?>">
                <?=h($item['source_key'])?> — <?=h($item['document_date'])?>
            </a>
        <?php endforeach; ?>
        <?php if (!$sources): ?><p>No hay fuentes importadas.</p><?php endif; ?>
    </section>

    <?php if ($source): ?>
        <section>
            <h2><?=h($source['document_name'])?> — <?=h($source['sheet_name'])?></h2>
            <p class="muted">Archivo privado: <?=h($source['uploaded_file_name'])?> | Estado: <?=h($source['source_status'])?>.</p>
            <div class="grid">
                <div class="kpi"><div class="label">Total personas</div><div class="value"><?=h($summary['total_personas'] ?? 0)?></div></div>
                <div class="kpi"><div class="label">Asignación completa</div><div class="value ok"><?=h($summary['asignados_completos'] ?? 0)?></div></div>
                <div class="kpi"><div class="label">Asignación parcial</div><div class="value partial"><?=h($summary['asignados_parciales'] ?? 0)?></div></div>
                <div class="kpi"><div class="label">Con zona territorial</div><div class="value"><?=h($contextSummary['con_zona_territorial'] ?? 0)?></div></div>
                <div class="kpi"><div class="label">Con detalle interno</div><div class="value"><?=h($contextSummary['con_detalle_interno'] ?? 0)?></div></div>
                <div class="kpi"><div class="label">Pendientes</div><div class="value bad"><?=h($summary['pendientes_revision'] ?? 0)?></div></div>
                <div class="kpi"><div class="label">Sin coincidencia</div><div class="value bad"><?=h($summary['sin_coincidencia'] ?? 0)?></div></div>
            </div>
            <?php if (!$hasTerritorialContext): ?>
                <p class="warn">La base todavía no tiene las columnas de contexto territorial. Ejecute el actualizador del módulo.</p>
            <?php endif; ?>
        </section>

        <section>
            <h2>Filtros</h2>
            <form class="filters" method="get">
                <input type="hidden" name="source_id" value="<?=h($sourceId)?>">
                <input name="buscar" value="<?=h($search)?>" placeholder="Nombre, posición, unidad, zona o detalle">
                <select name="status">
                    <option value="">Todos los estados</option>
                    <?php foreach (['asignado_completo','asignado_parcial','pendiente_revision','sin_coincidencia'] as $option): ?>
                        <option value="<?=h($option)?>" <?=$status === $option ? 'selected' : ''?>><?=h($option)?></option>
                    <?php endforeach; ?>
                </select>
                <select name="level">
                    <option value="">Todos los niveles</option>
                    <?php foreach (['zona','direccion','area','dependencia','servicio','unidad','ninguno'] as $option): ?>
                        <option value="<?=h($option)?>" <?=$level === $option ? 'selected' : ''?>><?=h($option)?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($hasTerritorialContext): ?>
                    <select name="zone_id">
                        <option value="0">Todas las zonas territoriales</option>
                        <?php foreach ($zoneOptions as $zone): ?>
                            <option value="<?=h($zone['id'])?>" <?=$zoneId === (int)$zone['id'] ? 'selected' : ''?>><?=h($zone['name'])?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
                <select name="review">
                    <option value="">Toda revisión</option>
                    <?php foreach (['automatico','pendiente','aprobado','rechazado'] as $option): ?>
                        <option value="<?=h($option)?>" <?=$review === $option ? 'selected' : ''?>><?=h($option)?></option>
                    <?php endforeach; ?>
                </select>
                <button>Filtrar</button>
                <a class="btn blue" href="?<?=h(http_build_query([
                    'source_id' => $sourceId,
                    'status' => $status,
                    'level' => $level,
                    'zone_id' => $zoneId,
                    'review' => $review,
                    'buscar' => $search,
                    'descargar' => 'csv',
                ]))?>">Descargar CSV</a>
                <a class="btn orange" href="pie_fuerza_masiva.php?source_id=<?=h($sourceId)?>">Revisión masiva por ubicación</a>
            </form>
        </section>

        <section>
            <h2>Personas y asignación</h2>
            <div class="scroll">
                <table>
                    <thead>
                    <tr>
                        <th>Fila</th>
                        <th>Funcionario</th>
                        <th>Rango / Posición</th>
                        <th>Ubicación original</th>
                        <th>Unidad funcional</th>
                        <th>Zona territorial</th>
                        <th>Detalle interno</th>
                        <th>Resultado</th>
                        <th>Revisión</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($detail as $row):
                        $assignmentStatus = $row['assignment_status'] ?: 'pendiente_revision';
                        $statusClass = $assignmentStatus === 'asignado_completo'
                            ? 'ok'
                            : ($assignmentStatus === 'asignado_parcial' ? 'partial' : 'bad');
                    ?>
                        <tr>
                            <td><?=h($row['row_number'])?></td>
                            <td><strong><?=h($row['full_name'])?></strong><br><span class="muted"><?=h($row['police_type_original'])?></span></td>
                            <td><?=h($row['rank_text'])?><br><span class="muted"><?=h($row['position_number'])?></span></td>
                            <td><?=h($row['location_original'])?></td>
                            <td>
                                <strong><?=h($row['matched_unit_name'] ?: 'Sin unidad')?></strong>
                                <span class="context muted"><?=h($row['matched_unit_type'])?> / <?=h($row['matched_level'] ?: 'ninguno')?></span>
                            </td>
                            <td>
                                <strong><?=h($row['territorial_zone_name'] ?: 'Sin zona territorial')?></strong>
                                <?php if (!empty($row['territorial_zone_code'])): ?><span class="context muted"><?=h($row['territorial_zone_code'])?></span><?php endif; ?>
                            </td>
                            <td><?=h($row['internal_detail'] ?: 'Sin detalle interno')?></td>
                            <td>
                                <span class="<?=$statusClass?>"><?=h($assignmentStatus)?></span>
                                <span class="context muted">Pendiente: <?=h($row['pending_level'] ?: 'ninguno')?><br><?=h($row['match_method'])?> / <?=h($row['confidence_level'])?></span>
                            </td>
                            <td>
                                <span class="pill"><?=h($row['review_status'] ?: 'pendiente')?></span><br>
                                <a href="pie_fuerza_revision.php?id=<?=h($row['personnel_staging_id'])?>">Revisar / asignar</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section>
            <h2>Conteo por unidad funcional y zona territorial</h2>
            <table>
                <thead><tr><th>Unidad funcional</th><th>Zona territorial</th><th>Nivel</th><th>Estado</th><th>Total</th></tr></thead>
                <tbody>
                <?php foreach ($byUnit as $item): ?>
                    <tr>
                        <td><?=h($item['unidad_funcional'])?></td>
                        <td><?=h($item['zona_territorial'])?></td>
                        <td><?=h($item['matched_level'])?></td>
                        <td><?=h($item['assignment_status'])?></td>
                        <td><?=h($item['total'])?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    <?php endif; ?>
<?php endif; ?>
</main>
</body>
</html>
