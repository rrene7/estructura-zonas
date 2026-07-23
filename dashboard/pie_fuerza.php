<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

if (!workforce_is_available($pdo)) {
    render_header('Personal', 'personal', 'Búsqueda y consulta del pie de fuerza.');
    render_empty_state(
        'El módulo de pie de fuerza no está disponible',
        'Ejecute database/pie_fuerza_20260626.sql para crear las tablas y vistas necesarias.'
    );
    render_footer();
    exit;
}

$sources = rows($pdo, 'SELECT * FROM workforce_sources ORDER BY document_date DESC, id DESC');
$source = current_workforce_source($pdo, (int)($_GET['source_id'] ?? 0));
$sourceId = (int)($source['id'] ?? 0);

$search = trim((string)($_GET['buscar'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));
$level = trim((string)($_GET['level'] ?? ''));
$unitId = (int)($_GET['unit_id'] ?? 0);
$zoneId = (int)($_GET['zone_id'] ?? 0);
$page = max(1, (int)($_GET['pagina'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

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
if ($unitId > 0) {
    $where[] = 'd.matched_unit_id = :unit_id';
    $params['unit_id'] = $unitId;
}
if ($zoneId > 0) {
    $where[] = 'd.territorial_zone_unit_id = :zone_id';
    $params['zone_id'] = $zoneId;
}
if ($search !== '') {
    $searchColumns = [
        'd.full_name',
        'd.position_number',
        'd.rank_text',
        'd.location_original',
        'd.matched_unit_name',
        'd.territorial_zone_name',
        'd.internal_detail',
    ];
    $searchParts = [];
    foreach ($searchColumns as $index => $column) {
        $key = 'search_' . $index;
        $searchParts[] = $column . ' LIKE :' . $key;
        $params[$key] = '%' . $search . '%';
    }
    $where[] = '(' . implode(' OR ', $searchParts) . ')';
}

$whereSql = implode(' AND ', $where);

$summary = $sourceId > 0
    ? one($pdo, 'SELECT * FROM vw_workforce_summary WHERE source_id = :source_id', ['source_id' => $sourceId])
    : [];

$units = $sourceId > 0
    ? rows(
        $pdo,
        "SELECT d.matched_unit_id AS id, d.matched_unit_name AS name, COUNT(*) AS total
         FROM vw_workforce_match_detail d
         WHERE d.source_id = :source_id
           AND d.matched_unit_id IS NOT NULL
         GROUP BY d.matched_unit_id, d.matched_unit_name
         ORDER BY d.matched_unit_name",
        ['source_id' => $sourceId]
    )
    : [];

$zones = $sourceId > 0
    ? rows(
        $pdo,
        "SELECT d.territorial_zone_unit_id AS id, d.territorial_zone_name AS name, COUNT(*) AS total
         FROM vw_workforce_match_detail d
         WHERE d.source_id = :source_id
           AND d.territorial_zone_unit_id IS NOT NULL
         GROUP BY d.territorial_zone_unit_id, d.territorial_zone_name
         ORDER BY d.territorial_zone_name",
        ['source_id' => $sourceId]
    )
    : [];

$totalFiltered = $sourceId > 0
    ? (int)(one($pdo, 'SELECT COUNT(*) AS total FROM vw_workforce_match_detail d WHERE ' . $whereSql, $params)['total'] ?? 0)
    : 0;
$totalPages = max(1, (int)ceil($totalFiltered / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

if (($_GET['descargar'] ?? '') === 'csv' && $sourceId > 0) {
    $exportRows = rows(
        $pdo,
        'SELECT d.* FROM vw_workforce_match_detail d WHERE ' . $whereSql . ' ORDER BY d.full_name, d.position_number',
        $params
    );

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=pie_fuerza_' . $sourceId . '_' . date('Ymd_His') . '.csv');
    $output = fopen('php://output', 'wb');
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, [
        'Rango',
        'Posición',
        'Nombre completo',
        'Ubicación registrada',
        'Unidad funcional',
        'Zona donde presta servicio',
        'Dependencia o sección',
        'Nivel organizacional',
        'Estado de ubicación',
        'Validación',
    ]);
    foreach ($exportRows as $row) {
        fputcsv($output, [
            $row['rank_text'],
            $row['position_number'],
            $row['full_name'],
            $row['location_original'],
            $row['matched_unit_name'],
            $row['territorial_zone_name'],
            $row['internal_detail'],
            level_label($row['matched_level']),
            assignment_label($row['assignment_status']),
            review_label($row['review_status']),
        ]);
    }
    fclose($output);
    exit;
}

$people = $sourceId > 0
    ? rows(
        $pdo,
        'SELECT d.* FROM vw_workforce_match_detail d
         WHERE ' . $whereSql . '
         ORDER BY d.full_name, d.position_number
         LIMIT ' . $perPage . ' OFFSET ' . $offset,
        $params
    )
    : [];

$activeFilters = array_filter([
    'source_id' => $sourceId,
    'buscar' => $search,
    'status' => $status,
    'level' => $level,
    'unit_id' => $unitId,
    'zone_id' => $zoneId,
], static fn (mixed $value): bool => $value !== '' && $value !== 0 && $value !== '0');

$firstShown = $totalFiltered > 0 ? $offset + 1 : 0;
$lastShown = min($offset + $perPage, $totalFiltered);

render_header(
    'Personal',
    'personal',
    'Busque por nombre, posición, rango, dirección, zona o dependencia.'
);
render_breadcrumbs([
    ['label' => 'Inicio', 'href' => 'index.php'],
    ['label' => 'Personal'],
]);
?>

<?php if (!$source): ?>
    <?php render_empty_state('No hay una fuente cargada', 'Importe un listado de pie de fuerza para iniciar la consulta.'); ?>
<?php else: ?>
    <div class="page-intro">
        <div>
            <h2>Listado de funcionarios</h2>
            <p>La unidad funcional indica a qué dependencia pertenece. La zona territorial indica dónde presta servicio cuando aplica.</p>
        </div>
        <div class="button-row">
            <a class="button soft" href="reportes.php?source_id=<?= h($sourceId) ?>">Reportes</a>
            <a class="button primary" href="<?= h(query_url('pie_fuerza.php', array_merge($activeFilters, ['descargar' => 'csv']))) ?>">Descargar CSV</a>
        </div>
    </div>

    <div class="kpi-grid">
        <article class="kpi-card card">
            <span class="kpi-label">Total de la fuente</span>
            <strong class="kpi-value"><?= h(format_number($summary['total_personas'] ?? 0)) ?></strong>
            <span class="kpi-note">Todos los funcionarios registrados.</span>
        </article>
        <article class="kpi-card card success">
            <span class="kpi-label">Ubicación completa</span>
            <strong class="kpi-value"><?= h(format_number($summary['asignados_completos'] ?? 0)) ?></strong>
            <span class="kpi-note">Unidad y nivel identificados.</span>
        </article>
        <article class="kpi-card card info">
            <span class="kpi-label">Unidad confirmada</span>
            <strong class="kpi-value"><?= h(format_number($summary['asignados_parciales'] ?? 0)) ?></strong>
            <span class="kpi-note">Con detalle interno o territorial.</span>
        </article>
        <article class="kpi-card card">
            <span class="kpi-label">Resultados del filtro</span>
            <strong class="kpi-value"><?= h(format_number($totalFiltered)) ?></strong>
            <span class="kpi-note">Coincidencias de la búsqueda actual.</span>
        </article>
    </div>

    <section class="panel filters-panel">
        <div class="panel-header">
            <div>
                <h2>Buscar y filtrar</h2>
                <p>Puede usar solo el buscador o combinarlo con los filtros.</p>
            </div>
        </div>
        <form method="get">
            <div class="filter-grid">
                <div class="field">
                    <label for="buscar">Nombre, posición, rango o dependencia</label>
                    <input id="buscar" name="buscar" type="search" value="<?= h($search) ?>" placeholder="Escriba lo que desea encontrar">
                </div>
                <div class="field">
                    <label for="source_id">Fuente</label>
                    <select id="source_id" name="source_id">
                        <?php foreach ($sources as $item): ?>
                            <option value="<?= h($item['id']) ?>" <?= (int)$item['id'] === $sourceId ? 'selected' : '' ?>>
                                <?= h($item['document_date'] ?: $item['source_key']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="status">Estado de ubicación</label>
                    <select id="status" name="status">
                        <option value="">Todos</option>
                        <?php foreach (['asignado_completo', 'asignado_parcial', 'pendiente_revision', 'sin_coincidencia'] as $option): ?>
                            <option value="<?= h($option) ?>" <?= $status === $option ? 'selected' : '' ?>><?= h(assignment_label($option)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="unit_id">Unidad funcional</label>
                    <select id="unit_id" name="unit_id">
                        <option value="0">Todas las unidades</option>
                        <?php foreach ($units as $item): ?>
                            <option value="<?= h($item['id']) ?>" <?= (int)$item['id'] === $unitId ? 'selected' : '' ?>>
                                <?= h($item['name']) ?> (<?= h(format_number($item['total'])) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="zone_id">Zona donde presta servicio</label>
                    <select id="zone_id" name="zone_id">
                        <option value="0">Todas las zonas</option>
                        <?php foreach ($zones as $item): ?>
                            <option value="<?= h($item['id']) ?>" <?= (int)$item['id'] === $zoneId ? 'selected' : '' ?>>
                                <?= h($item['name']) ?> (<?= h(format_number($item['total'])) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="filter-actions">
                <button class="button primary" type="submit">Aplicar filtros</button>
                <a class="button" href="pie_fuerza.php?source_id=<?= h($sourceId) ?>">Limpiar</a>
                <span class="result-summary">Mostrando <?= h(format_number($firstShown)) ?>–<?= h(format_number($lastShown)) ?> de <?= h(format_number($totalFiltered)) ?>.</span>
            </div>
        </form>
    </section>

    <section class="panel">
        <div class="panel-header">
            <div>
                <h2>Resultados</h2>
                <p>Abra “Ver ficha” para consultar cada dato con una explicación sencilla.</p>
            </div>
        </div>

        <?php if (!$people): ?>
            <?php render_empty_state('No se encontraron resultados', 'Cambie el texto de búsqueda o limpie uno de los filtros.'); ?>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Funcionario</th>
                        <th>Ubicación registrada</th>
                        <th>Unidad funcional</th>
                        <th>Zona territorial</th>
                        <th>Dependencia o sección</th>
                        <th>Estado</th>
                        <th>Acción</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($people as $person): ?>
                        <tr>
                            <td>
                                <span class="person-name"><?= h($person['full_name']) ?></span>
                                <span class="subtext"><?= h($person['rank_text'] ?: 'Sin rango') ?> · Posición <?= h($person['position_number'] ?: 'sin número') ?></span>
                            </td>
                            <td><?= h($person['location_original'] ?: 'No indicada') ?></td>
                            <td>
                                <?= h($person['matched_unit_name'] ?: 'Sin unidad confirmada') ?>
                                <span class="subtext"><?= h(level_label($person['matched_level'])) ?></span>
                            </td>
                            <td class="<?= empty($person['territorial_zone_name']) ? 'empty-cell' : '' ?>">
                                <?= h($person['territorial_zone_name'] ?: 'No aplica o no indicada') ?>
                            </td>
                            <td class="<?= empty($person['internal_detail']) ? 'empty-cell' : '' ?>">
                                <?= h($person['internal_detail'] ?: 'Sin detalle adicional') ?>
                            </td>
                            <td>
                                <span class="badge <?= h(assignment_class($person['assignment_status'])) ?>">
                                    <?= h(assignment_label($person['assignment_status'])) ?>
                                </span>
                                <span class="subtext"><?= h(review_label($person['review_status'])) ?></span>
                            </td>
                            <td>
                                <a class="button soft" href="persona_detalle.php?id=<?= h($person['personnel_staging_id']) ?>">Ver ficha</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="pagination">
                <span class="result-summary">Página <?= h($page) ?> de <?= h($totalPages) ?></span>
                <div class="pagination-links">
                    <?php if ($page > 1): ?>
                        <a class="button" href="<?= h(query_url('pie_fuerza.php', array_merge($activeFilters, ['pagina' => $page - 1]))) ?>">← Anterior</a>
                    <?php endif; ?>
                    <?php if ($page < $totalPages): ?>
                        <a class="button primary" href="<?= h(query_url('pie_fuerza.php', array_merge($activeFilters, ['pagina' => $page + 1]))) ?>">Siguiente →</a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </section>

    <section class="notice info">
        <strong>Cómo interpretar “Unidad confirmada”:</strong>
        no significa que el registro esté incorrecto. Indica que la dirección o unidad principal está validada y que la sección, departamento, sede o comisión se conserva como detalle adicional.
    </section>
<?php endif; ?>

<?php render_footer(); ?>
