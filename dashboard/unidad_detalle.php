<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$unitId = (int)($_GET['id'] ?? 0);
$source = current_workforce_source($pdo, (int)($_GET['source_id'] ?? 0));
$sourceId = (int)($source['id'] ?? 0);

$unit = $unitId > 0
    ? one(
        $pdo,
        "SELECT
            u.*,
            parent.name AS parent_name,
            parent.id AS parent_unit_id
         FROM organizational_units u
         LEFT JOIN organizational_units parent ON parent.id = u.parent_id
         WHERE u.id = :id
         LIMIT 1",
        ['id' => $unitId]
    )
    : [];

if (!$unit) {
    http_response_code(404);
    render_header('Detalle de unidad', 'estructura', 'Consulta de la estructura institucional.');
    render_empty_state(
        'No se encontró la unidad',
        'Regrese al listado de estructura y seleccione una unidad vigente.',
        'unidades.php?grupo=todas',
        'Volver a la estructura'
    );
    render_footer();
    exit;
}

$legacyTable = strtoupper(trim((string)($unit['legacy_table'] ?? '')));
if ($legacyTable === 'TABCUAR') {
    $canonicalUnit = one(
        $pdo,
        "SELECT canonical.id
         FROM organizational_units canonical
         WHERE canonical.id <> :current_id
           AND canonical.status = 'active'
           AND canonical.lifecycle_status = 'vigente'
           AND canonical.legacy_table IN ('MOI_CABECERA_DIRECCION', 'MOI_CABECERA_ZONA')
           AND UPPER(TRIM(canonical.name)) = UPPER(TRIM(:unit_name))
         ORDER BY
           CASE
             WHEN canonical.legacy_table = 'MOI_CABECERA_DIRECCION' THEN 1
             WHEN canonical.legacy_table = 'MOI_CABECERA_ZONA' THEN 2
             ELSE 9
           END,
           canonical.id
         LIMIT 1",
        [
            'current_id' => $unitId,
            'unit_name' => (string)$unit['name'],
        ]
    );

    if (!empty($canonicalUnit['id'])) {
        header(
            'Location: unidad_detalle.php?id=' . (int)$canonicalUnit['id']
            . '&source_id=' . $sourceId
            . '&redirigido=1'
        );
        exit;
    }
}

$code = (string)($unit['code'] ?? '');
$isDirectionGeneral = $code === 'DN-01';

if (str_starts_with($code, 'DN-') || $code === 'SG-1') {
    $group = 'direcciones';
    $active = 'direcciones';
    $groupLabel = 'Direcciones nacionales';
} elseif (str_starts_with($code, 'ZP-')) {
    $group = 'zonas';
    $active = 'zonas';
    $groupLabel = 'Zonas policiales';
} elseif (str_starts_with($code, 'SP-')) {
    $group = 'servicios';
    $active = 'servicios';
    $groupLabel = 'Servicios policiales';
} else {
    $group = 'todas';
    $active = 'estructura';
    $groupLabel = 'Estructura institucional';
}

$summary = $sourceId > 0
    ? one(
        $pdo,
        "SELECT
            COUNT(*) AS total,
            SUM(m.assignment_status = 'asignado_completo') AS completos,
            SUM(m.assignment_status = 'asignado_parcial') AS parciales,
            SUM(m.review_status = 'aprobado') AS validados,
            SUM(m.territorial_zone_unit_id IS NOT NULL) AS con_zona,
            SUM(NULLIF(TRIM(COALESCE(m.internal_detail, '')), '') IS NOT NULL) AS con_detalle
         FROM workforce_unit_matches m
         JOIN workforce_personnel_staging p ON p.id = m.personnel_staging_id
         WHERE m.matched_unit_id = :unit_id
           AND p.source_id = :source_id",
        ['unit_id' => $unitId, 'source_id' => $sourceId]
    )
    : [];

$territorialSummary = $sourceId > 0
    ? one(
        $pdo,
        "SELECT COUNT(*) AS total
         FROM workforce_unit_matches m
         JOIN workforce_personnel_staging p ON p.id = m.personnel_staging_id
         WHERE m.territorial_zone_unit_id = :unit_id
           AND p.source_id = :source_id",
        ['unit_id' => $unitId, 'source_id' => $sourceId]
    )
    : [];

$totalDirect = (int)($summary['total'] ?? 0);
$withDetail = (int)($summary['con_detalle'] ?? 0);
$withoutDetail = max(0, $totalDirect - $withDetail);

$leader = [];
if ($sourceId > 0 && $isDirectionGeneral) {
    $leader = one(
        $pdo,
        "SELECT d.*
         FROM vw_workforce_match_detail d
         WHERE d.source_id = :source_id
           AND d.matched_unit_id = :unit_id
           AND UPPER(TRIM(COALESCE(d.rank_text, ''))) IN ('DIRECT', 'DIRECTOR', 'DIRECTOR GENERAL')
         ORDER BY CAST(d.position_number AS UNSIGNED), d.row_number
         LIMIT 1",
        ['source_id' => $sourceId, 'unit_id' => $unitId]
    );
}

$children = rows(
    $pdo,
    "SELECT child_summary.*
     FROM (
        SELECT
            child.id,
            child.code,
            child.name,
            child.short_name,
            child.level,
            child.moi_level,
            child.territorial_scope,
            child.legacy_table,
            (SELECT COUNT(*)
             FROM workforce_unit_matches m
             JOIN workforce_personnel_staging p ON p.id = m.personnel_staging_id
             WHERE m.matched_unit_id = child.id
               AND p.source_id = :source_direct) AS personal_directo,
            (SELECT COUNT(*)
             FROM workforce_unit_matches m
             JOIN workforce_personnel_staging p ON p.id = m.personnel_staging_id
             WHERE m.territorial_zone_unit_id = child.id
               AND p.source_id = :source_territorial) AS referencia_territorial,
            (SELECT COUNT(*)
             FROM organizational_units grandchild
             WHERE grandchild.parent_id = child.id
               AND grandchild.status = 'active'
               AND grandchild.lifecycle_status = 'vigente') AS unidades_hijas
        FROM organizational_units child
        WHERE child.parent_id = :parent_id
          AND child.status = 'active'
          AND child.lifecycle_status = 'vigente'
          AND NOT (
              UPPER(TRIM(COALESCE(child.legacy_table, ''))) = 'TABCUAR'
              AND EXISTS (
                  SELECT 1
                  FROM organizational_units canonical
                  WHERE canonical.id <> child.id
                    AND canonical.status = 'active'
                    AND canonical.lifecycle_status = 'vigente'
                    AND canonical.legacy_table IN ('MOI_CABECERA_DIRECCION', 'MOI_CABECERA_ZONA')
                    AND UPPER(TRIM(canonical.name)) = UPPER(TRIM(child.name))
              )
          )
     ) child_summary
     WHERE child_summary.personal_directo > 0
        OR child_summary.referencia_territorial > 0
        OR child_summary.unidades_hijas > 0
     ORDER BY
        child_summary.personal_directo DESC,
        child_summary.unidades_hijas DESC,
        COALESCE(child_summary.moi_level, child_summary.level, 99),
        child_summary.name",
    [
        'source_direct' => $sourceId,
        'source_territorial' => $sourceId,
        'parent_id' => $unitId,
    ]
);

$officeText = "UPPER(CONCAT_WS(' ', COALESCE(d.internal_detail, ''), COALESCE(d.location_original, '')))";
$officeExpression = "
CASE
    WHEN {$officeText} REGEXP 'AUDITORIA' THEN 'Auditoría Interna'
    WHEN {$officeText} REGEXP 'SEGURIDAD[[:space:]]+DE[[:space:]]+INSTAL|SEG[.]?[[:space:]]*INSTAL' THEN 'Seguridad de Instalaciones'
    WHEN {$officeText} REGEXP 'PROTECCION.*SEGUR|PROT[.]?[[:space:]]*SEGUR|DPTO[.]?[[:space:]]*PROT' THEN 'Departamento de Protección y Seguridad'
    WHEN {$officeText} REGEXP 'JUNTA.*DISCIPLINARIA.*SUPERIOR|(^|[[:space:]])JDS([[:space:]]|$)' THEN 'Junta Disciplinaria Superior'
    WHEN {$officeText} REGEXP 'CONSEJO.*SEGURIDAD' THEN 'Consejo de Seguridad'
    WHEN {$officeText} REGEXP 'CAPELL' THEN 'Capellanía'
    WHEN {$officeText} REGEXP 'ANALISIS.*ESTRATEG' THEN 'Centro de Análisis Estratégico'
    WHEN {$officeText} REGEXP 'TRAMITE.*TRANSFER' THEN 'Trámite de Transferencia'
    WHEN {$officeText} REGEXP 'CENTRO DE OPERACIONES REGIONAL|C[ .]*O[ .]*R' THEN CONCAT(
        'COR - ',
        COALESCE(
            NULLIF(TRIM(SUBSTRING_INDEX(NULLIF(d.internal_detail, ''), ' / ', -1)), ''),
            NULLIF(TRIM(d.territorial_zone_name), ''),
            NULLIF(TRIM(d.location_original), ''),
            'Sin ubicación específica'
        )
    )
    WHEN {$officeText} REGEXP 'COMISION INTERINSTITUCIONAL|MINSEG|MIGRACION|SENAFRONT|AERONAVAL|SERVICIO DE PROTECCION INSTITUCIONAL' THEN 'Comisiones interinstitucionales'
    WHEN {$officeText} REGEXP 'SECRETARIA GENERAL|SEC[.]?[[:space:]]*GENERAL' THEN 'Secretaría General'
    WHEN {$officeText} REGEXP 'OFICINA.*PROTOCOLO|DIR[.]?[[:space:]]*GRAL.*PROTOCOLO' THEN 'Oficina de Protocolo'
    WHEN {$officeText} REGEXP 'EDECAN' THEN 'Edecanes'
    WHEN {$officeText} REGEXP 'INSPECTORIA GENERAL' THEN 'Inspectoría General'
    WHEN {$officeText} REGEXP 'ASESORIA LEGAL' THEN 'Asesoría Legal'
    WHEN NULLIF(TRIM(COALESCE(d.internal_detail, '')), '') IS NULL THEN 'Dirección General (oficina principal)'
    ELSE 'Otras oficinas y dependencias'
END";

$officeGroups = [];
$officeFilter = trim((string)($_GET['oficina'] ?? ''));

if ($isDirectionGeneral && $sourceId > 0) {
    $officeGroups = rows(
        $pdo,
        "SELECT office_group, COUNT(*) AS total
         FROM (
             SELECT {$officeExpression} AS office_group
             FROM vw_workforce_match_detail d
             WHERE d.source_id = :source_offices
               AND d.matched_unit_id = :unit_offices
         ) office_scope
         GROUP BY office_group
         ORDER BY
             CASE
                 WHEN office_group = 'Dirección General (oficina principal)' THEN 1
                 WHEN office_group = 'Departamento de Protección y Seguridad' THEN 2
                 WHEN office_group = 'Auditoría Interna' THEN 3
                 WHEN office_group = 'Seguridad de Instalaciones' THEN 4
                 WHEN office_group = 'Secretaría General' THEN 5
                 WHEN office_group = 'Junta Disciplinaria Superior' THEN 6
                 WHEN office_group = 'Consejo de Seguridad' THEN 7
                 WHEN office_group = 'Capellanía' THEN 8
                 WHEN office_group = 'Centro de Análisis Estratégico' THEN 9
                 WHEN office_group = 'Trámite de Transferencia' THEN 10
                 WHEN office_group LIKE 'COR - %' THEN 11
                 WHEN office_group = 'Comisiones interinstitucionales' THEN 12
                 ELSE 99
             END,
             total DESC,
             office_group",
        [
            'source_offices' => $sourceId,
            'unit_offices' => $unitId,
        ]
    );

    $allowedOfficeGroups = array_column($officeGroups, 'office_group');
    if ($officeFilter !== '' && !in_array($officeFilter, $allowedOfficeGroups, true)) {
        $officeFilter = '';
    }
} else {
    $officeFilter = '';
}

$page = max(1, (int)($_GET['pagina_personal'] ?? 1));
$perPage = 50;

if ($isDirectionGeneral) {
    $peopleBaseSql = "SELECT d.*, {$officeExpression} AS office_group
                      FROM vw_workforce_match_detail d
                      WHERE d.source_id = :source_people
                        AND d.matched_unit_id = :unit_people";
} else {
    $peopleBaseSql = "SELECT d.*, NULL AS office_group
                      FROM vw_workforce_match_detail d
                      WHERE d.source_id = :source_people
                        AND d.matched_unit_id = :unit_people";
}

$countParams = [
    'source_people' => $sourceId,
    'unit_people' => $unitId,
];
$countWhere = '';
if ($officeFilter !== '') {
    $countWhere = ' WHERE people_scope.office_group = :office_count';
    $countParams['office_count'] = $officeFilter;
}

$totalPeopleFiltered = $sourceId > 0
    ? (int)(one(
        $pdo,
        "SELECT COUNT(*) AS total FROM ({$peopleBaseSql}) people_scope{$countWhere}",
        $countParams
    )['total'] ?? 0)
    : 0;

$totalPages = max(1, (int)ceil($totalPeopleFiltered / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$listParams = [
    'source_people' => $sourceId,
    'unit_people' => $unitId,
];
$listWhere = '';
if ($officeFilter !== '') {
    $listWhere = ' WHERE people_scope.office_group = :office_list';
    $listParams['office_list'] = $officeFilter;
}

$people = $sourceId > 0
    ? rows(
        $pdo,
        "SELECT people_scope.*
         FROM ({$peopleBaseSql}) people_scope
         {$listWhere}
         ORDER BY
           CASE
             WHEN UPPER(TRIM(COALESCE(people_scope.rank_text, ''))) IN ('DIRECT', 'DIRECTOR', 'DIRECTOR GENERAL') THEN 0
             ELSE 1
           END,
           people_scope.full_name,
           people_scope.position_number
         LIMIT {$perPage} OFFSET {$offset}",
        $listParams
    )
    : [];

$firstShown = $totalPeopleFiltered > 0 ? $offset + 1 : 0;
$lastShown = min($offset + count($people), $totalPeopleFiltered);

$territorialPeople = $sourceId > 0 && (int)($territorialSummary['total'] ?? 0) > 0
    ? rows(
        $pdo,
        "SELECT d.*
         FROM vw_workforce_match_detail d
         WHERE d.source_id = :source_id
           AND d.territorial_zone_unit_id = :unit_id
         ORDER BY d.matched_unit_name, d.full_name
         LIMIT 40",
        ['source_id' => $sourceId, 'unit_id' => $unitId]
    )
    : [];

render_header($unit['name'], $active, 'Detalle de personal, dependencias y referencias territoriales.');
render_breadcrumbs([
    ['label' => 'Inicio', 'href' => 'index.php'],
    ['label' => $groupLabel, 'href' => 'unidades.php?grupo=' . $group . '&source_id=' . $sourceId],
    ['label' => $unit['name']],
]);
?>

<?php if (($_GET['redirigido'] ?? '') === '1'): ?>
    <div class="notice info">
        Se abrió automáticamente la unidad institucional vigente para evitar mostrar una referencia histórica duplicada.
    </div>
<?php endif; ?>

<div class="page-intro">
    <div>
        <h2><?= h($unit['name']) ?></h2>
        <p>
            <?= h($unit['parent_name'] ?: 'Unidad de nivel superior') ?>
            <?php if (!empty($unit['code'])): ?> · Código <?= h($unit['code']) ?><?php endif; ?>
        </p>
    </div>
    <div class="button-row">
        <a class="button" href="unidades.php?grupo=<?= h($group) ?>&source_id=<?= h($sourceId) ?>">← Volver</a>
        <a class="button primary" href="pie_fuerza.php?source_id=<?= h($sourceId) ?>&unit_id=<?= h($unitId) ?>">Buscar en todo el personal</a>
    </div>
</div>

<?php if ($leader): ?>
    <section class="panel">
        <div class="panel-header">
            <div>
                <span class="kpi-label">Director General</span>
                <h2><?= h($leader['full_name']) ?></h2>
                <p><?= h($leader['rank_text']) ?> · Posición <?= h($leader['position_number']) ?></p>
            </div>
            <a class="button primary" href="persona_detalle.php?id=<?= h($leader['personnel_staging_id']) ?>">Ver ficha del director</a>
        </div>
    </section>
<?php endif; ?>

<div class="kpi-grid">
    <article class="kpi-card card">
        <span class="kpi-label">Personal de la unidad</span>
        <strong class="kpi-value"><?= h(format_number($totalDirect)) ?></strong>
        <span class="kpi-note">Todos los funcionarios vinculados funcionalmente a esta unidad.</span>
    </article>
    <article class="kpi-card card info">
        <span class="kpi-label">Con oficina o sección</span>
        <strong class="kpi-value"><?= h(format_number($withDetail)) ?></strong>
        <span class="kpi-note">Registros que especifican departamento, oficina, sección, sede o comisión.</span>
    </article>
    <article class="kpi-card card warning">
        <span class="kpi-label">Oficina principal o sin detalle</span>
        <strong class="kpi-value"><?= h(format_number($withoutDetail)) ?></strong>
        <span class="kpi-note">Personal de la unidad que no tiene una oficina interna separada en el listado original.</span>
    </article>
    <article class="kpi-card card success">
        <span class="kpi-label">Registros validados</span>
        <strong class="kpi-value"><?= h(format_number($summary['validados'] ?? 0)) ?></strong>
        <span class="kpi-note">Asignaciones revisadas y aprobadas dentro de la fuente seleccionada.</span>
    </article>
</div>

<?php if ($isDirectionGeneral && $officeGroups): ?>
    <section class="panel">
        <div class="panel-header">
            <div>
                <h2>Oficinas, departamentos y grupos de trabajo</h2>
                <p>La Dirección General se presenta por áreas de trabajo. Seleccione una tarjeta para ver solamente su personal.</p>
            </div>
            <?php if ($officeFilter !== ''): ?>
                <a class="button" href="<?= h(query_url('unidad_detalle.php', [
                    'id' => $unitId,
                    'source_id' => $sourceId,
                ])) ?>#personal">Mostrar todas</a>
            <?php endif; ?>
        </div>
        <div class="action-grid">
            <?php foreach ($officeGroups as $office): ?>
                <?php
                $officeName = (string)$office['office_group'];
                $officeUrl = query_url('unidad_detalle.php', [
                    'id' => $unitId,
                    'source_id' => $sourceId,
                    'oficina' => $officeName,
                ]) . '#personal';
                ?>
                <a class="action-card card" href="<?= h($officeUrl) ?>">
                    <span class="action-icon"><?= h(format_number($office['total'])) ?></span>
                    <h3><?= h($officeName) ?></h3>
                    <p><?= h(format_number($office['total'])) ?> funcionarios registrados en este grupo.</p>
                    <span class="action-link"><?= $officeFilter === $officeName ? 'Mostrando ahora' : 'Ver personal →' ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<?php if ($children): ?>
    <?php if ($isDirectionGeneral): ?>
        <section class="panel">
            <details class="advanced">
                <summary>Ver unidades institucionales vinculadas según la estructura</summary>
                <p class="subtext">Aquí se muestran unidades formalmente relacionadas, separadas de las oficinas internas obtenidas del listado de personal.</p>
                <div class="unit-list">
                    <?php foreach ($children as $child): ?>
                        <article class="unit-card card">
                            <div>
                                <h3><a href="unidad_detalle.php?id=<?= h($child['id']) ?>&source_id=<?= h($sourceId) ?>"><?= h($child['name']) ?></a></h3>
                                <p><?= h($child['code'] ?: 'Sin código visible') ?></p>
                                <div class="unit-meta">
                                    <?php if ((int)$child['unidades_hijas'] > 0): ?>
                                        <span class="badge info"><?= h(format_number($child['unidades_hijas'])) ?> dependencias</span>
                                    <?php endif; ?>
                                    <?php if ((int)$child['referencia_territorial'] > 0): ?>
                                        <span class="badge success"><?= h(format_number($child['referencia_territorial'])) ?> referencias territoriales</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="unit-count">
                                <strong><?= h(format_number($child['personal_directo'])) ?></strong>
                                <span>personal directo</span>
                                <a class="button soft" href="unidad_detalle.php?id=<?= h($child['id']) ?>&source_id=<?= h($sourceId) ?>">Abrir</a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </details>
        </section>
    <?php else: ?>
        <section class="panel">
            <div class="panel-header">
                <div>
                    <h2>Dependencias y unidades subordinadas</h2>
                    <p>Solo se muestran unidades con personal, referencias territoriales o dependencias vigentes para continuar navegando.</p>
                </div>
            </div>
            <div class="unit-list">
                <?php foreach ($children as $child): ?>
                    <article class="unit-card card">
                        <div>
                            <h3><a href="unidad_detalle.php?id=<?= h($child['id']) ?>&source_id=<?= h($sourceId) ?>"><?= h($child['name']) ?></a></h3>
                            <p><?= h($child['code'] ?: 'Sin código visible') ?></p>
                        </div>
                        <div class="unit-count">
                            <strong><?= h(format_number($child['personal_directo'])) ?></strong>
                            <span>personal directo</span>
                            <a class="button soft" href="unidad_detalle.php?id=<?= h($child['id']) ?>&source_id=<?= h($sourceId) ?>">Abrir</a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
<?php endif; ?>

<section class="panel" id="personal">
    <div class="panel-header">
        <div>
            <h2><?= $officeFilter !== '' ? h($officeFilter) : 'Personal de la unidad' ?></h2>
            <p>
                Mostrando <?= h(format_number($firstShown)) ?>–<?= h(format_number($lastShown)) ?>
                de <?= h(format_number($totalPeopleFiltered)) ?> funcionarios.
                <?= $officeFilter !== '' ? 'El listado está filtrado por la oficina seleccionada.' : 'Use las tarjetas superiores para consultar un grupo específico.' ?>
            </p>
        </div>
        <?php if ($officeFilter !== ''): ?>
            <a class="button" href="<?= h(query_url('unidad_detalle.php', [
                'id' => $unitId,
                'source_id' => $sourceId,
            ])) ?>#personal">Quitar filtro</a>
        <?php endif; ?>
    </div>

    <?php if (!$people): ?>
        <div class="notice info">No se encontraron funcionarios para el criterio seleccionado.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Funcionario</th>
                    <th>Ubicación registrada</th>
                    <?php if ($isDirectionGeneral): ?><th>Grupo u oficina</th><?php endif; ?>
                    <th>Zona territorial</th>
                    <th>Dependencia o sección</th>
                    <th>Estado</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($people as $person): ?>
                    <tr>
                        <td>
                            <span class="person-name"><?= h($person['full_name']) ?></span>
                            <span class="subtext"><?= h($person['rank_text']) ?> · Posición <?= h($person['position_number']) ?></span>
                        </td>
                        <td><?= h($person['location_original'] ?: 'No indicada') ?></td>
                        <?php if ($isDirectionGeneral): ?><td><?= h($person['office_group'] ?: 'Dirección General') ?></td><?php endif; ?>
                        <td class="<?= empty($person['territorial_zone_name']) ? 'empty-cell' : '' ?>"><?= h($person['territorial_zone_name'] ?: 'No aplica') ?></td>
                        <td class="<?= empty($person['internal_detail']) ? 'empty-cell' : '' ?>"><?= h($person['internal_detail'] ?: 'Sin detalle interno') ?></td>
                        <td><span class="badge <?= h(assignment_class($person['assignment_status'])) ?>"><?= h(assignment_label($person['assignment_status'])) ?></span></td>
                        <td><a class="button soft" href="persona_detalle.php?id=<?= h($person['personnel_staging_id']) ?>">Ver ficha</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <span class="result-summary">Página <?= h(format_number($page)) ?> de <?= h(format_number($totalPages)) ?></span>
                <div class="pagination-links">
                    <?php if ($page > 1): ?>
                        <a class="button" href="<?= h(query_url('unidad_detalle.php', [
                            'id' => $unitId,
                            'source_id' => $sourceId,
                            'oficina' => $officeFilter,
                            'pagina_personal' => $page - 1,
                        ])) ?>#personal">← Anterior</a>
                    <?php endif; ?>
                    <?php if ($page < $totalPages): ?>
                        <a class="button primary" href="<?= h(query_url('unidad_detalle.php', [
                            'id' => $unitId,
                            'source_id' => $sourceId,
                            'oficina' => $officeFilter,
                            'pagina_personal' => $page + 1,
                        ])) ?>#personal">Siguiente →</a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>

<?php if ($territorialPeople): ?>
    <section class="panel">
        <div class="panel-header">
            <div>
                <h2>Personal de otras unidades que presta servicio aquí</h2>
                <p>La unidad funcional se mantiene, pero esta unidad aparece como referencia territorial.</p>
            </div>
            <a class="button soft" href="pie_fuerza.php?source_id=<?= h($sourceId) ?>&zone_id=<?= h($unitId) ?>">Ver todos</a>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Funcionario</th><th>Unidad funcional</th><th>Dependencia o sección</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($territorialPeople as $person): ?>
                    <tr>
                        <td><span class="person-name"><?= h($person['full_name']) ?></span><span class="subtext"><?= h($person['rank_text']) ?> · <?= h($person['position_number']) ?></span></td>
                        <td><?= h($person['matched_unit_name'] ?: 'Sin unidad') ?></td>
                        <td><?= h($person['internal_detail'] ?: 'Sin detalle') ?></td>
                        <td><a class="button soft" href="persona_detalle.php?id=<?= h($person['personnel_staging_id']) ?>">Ver ficha</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<section class="panel">
    <details class="advanced">
        <summary>Ver información técnica de la unidad</summary>
        <dl class="technical-grid">
            <dt>ID</dt><dd><?= h($unit['id']) ?></dd>
            <dt>Código</dt><dd><?= h($unit['code']) ?></dd>
            <dt>Código MOI</dt><dd><?= h($unit['moi_code']) ?></dd>
            <dt>Nivel</dt><dd><?= h($unit['level']) ?></dd>
            <dt>Nivel MOI</dt><dd><?= h($unit['moi_level']) ?></dd>
            <dt>Alcance territorial</dt><dd><?= h($unit['territorial_scope']) ?></dd>
            <dt>Estado</dt><dd><?= h($unit['status']) ?> / <?= h($unit['lifecycle_status']) ?></dd>
            <dt>Origen estructural</dt><dd><?= h($unit['structure_source']) ?></dd>
        </dl>
    </details>
</section>

<?php render_footer(); ?>