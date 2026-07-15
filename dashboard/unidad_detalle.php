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
    render_empty_state('No se encontró la unidad', 'Regrese al listado de estructura y seleccione una unidad vigente.', 'unidades.php?grupo=todas', 'Volver a la estructura');
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

$leader = [];
if ($sourceId > 0 && $code === 'DN-01') {
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
    "SELECT
        child.id,
        child.code,
        child.name,
        child.short_name,
        child.level,
        child.moi_level,
        child.territorial_scope,
        (SELECT COUNT(*)
         FROM workforce_unit_matches m
         JOIN workforce_personnel_staging p ON p.id = m.personnel_staging_id
         WHERE m.matched_unit_id = child.id
           AND p.source_id = :source_id) AS personal_directo
     FROM organizational_units child
     WHERE child.parent_id = :parent_id
       AND child.status = 'active'
       AND child.lifecycle_status = 'vigente'
     ORDER BY COALESCE(child.moi_level, child.level, 99), child.name",
    ['source_id' => $sourceId, 'parent_id' => $unitId]
);

$people = $sourceId > 0
    ? rows(
        $pdo,
        "SELECT d.*
         FROM vw_workforce_match_detail d
         WHERE d.source_id = :source_id
           AND d.matched_unit_id = :unit_id
         ORDER BY
           CASE
             WHEN UPPER(TRIM(COALESCE(d.rank_text, ''))) IN ('DIRECT', 'DIRECTOR', 'DIRECTOR GENERAL') THEN 0
             ELSE 1
           END,
           d.full_name,
           d.position_number
         LIMIT 60",
        ['source_id' => $sourceId, 'unit_id' => $unitId]
    )
    : [];

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
        <a class="button primary" href="pie_fuerza.php?source_id=<?= h($sourceId) ?>&unit_id=<?= h($unitId) ?>">Ver todo el personal</a>
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
        <span class="kpi-label">Personal directo</span>
        <strong class="kpi-value"><?= h(format_number($summary['total'] ?? 0)) ?></strong>
        <span class="kpi-note">Funcionarios cuya unidad funcional principal es esta.</span>
    </article>
    <article class="kpi-card card success">
        <span class="kpi-label">Ubicación completa</span>
        <strong class="kpi-value"><?= h(format_number($summary['completos'] ?? 0)) ?></strong>
        <span class="kpi-note">Unidad y nivel organizacional identificados.</span>
    </article>
    <article class="kpi-card card info">
        <span class="kpi-label">Con detalle interno</span>
        <strong class="kpi-value"><?= h(format_number($summary['con_detalle'] ?? 0)) ?></strong>
        <span class="kpi-note">Incluye departamento, sección, sede o comisión.</span>
    </article>
    <article class="kpi-card card">
        <span class="kpi-label">Referencia territorial</span>
        <strong class="kpi-value"><?= h(format_number($territorialSummary['total'] ?? 0)) ?></strong>
        <span class="kpi-note">Personal que presta servicio aquí aunque pertenezca a otra unidad.</span>
    </article>
</div>

<?php if ($children): ?>
    <section class="panel">
        <div class="panel-header">
            <div>
                <h2>Dependencias y unidades subordinadas</h2>
                <p>Seleccione una para continuar navegando dentro de la estructura.</p>
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

<section class="panel">
    <div class="panel-header">
        <div>
            <h2>Personal de la unidad</h2>
            <p>Se muestran hasta 60 funcionarios. Use “Ver todo el personal” para abrir el listado completo con filtros.</p>
        </div>
    </div>

    <?php if (!$people): ?>
        <div class="notice info">Esta unidad no tiene personal asignado directamente en la fuente seleccionada.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Funcionario</th>
                    <th>Ubicación registrada</th>
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
                        <td class="<?= empty($person['territorial_zone_name']) ? 'empty-cell' : '' ?>"><?= h($person['territorial_zone_name'] ?: 'No aplica') ?></td>
                        <td class="<?= empty($person['internal_detail']) ? 'empty-cell' : '' ?>"><?= h($person['internal_detail'] ?: 'Sin detalle') ?></td>
                        <td><span class="badge <?= h(assignment_class($person['assignment_status'])) ?>"><?= h(assignment_label($person['assignment_status'])) ?></span></td>
                        <td><a class="button soft" href="persona_detalle.php?id=<?= h($person['personnel_staging_id']) ?>">Ver ficha</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
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
