<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$unitId = (int)($_GET['unit_id'] ?? 0);
$source = current_workforce_source($pdo, (int)($_GET['source_id'] ?? 0));
$sourceId = (int)($source['id'] ?? 0);
$dependencyName = trim((string)($_GET['dependencia'] ?? ''));

$unit = $unitId > 0
    ? one(
        $pdo,
        "SELECT
            u.id,
            u.name,
            u.code,
            u.parent_id,
            parent.name AS parent_name
         FROM organizational_units u
         LEFT JOIN organizational_units parent ON parent.id = u.parent_id
         WHERE u.id = :id
           AND u.status = 'active'
           AND u.lifecycle_status = 'vigente'
         LIMIT 1",
        ['id' => $unitId]
    )
    : [];

if (!$unit || $sourceId <= 0 || $dependencyName === '') {
    http_response_code(404);
    render_header('Dependencia o sección', 'estructura', 'Consulta de personal por dependencia interna.');
    render_empty_state(
        'No se encontró la dependencia',
        'Regrese al detalle de la unidad y seleccione una dependencia o sección registrada.',
        'unidades.php?grupo=todas',
        'Volver a la estructura'
    );
    render_footer();
    exit;
}

$dependencyText = "UPPER(TRIM(COALESCE(d.internal_detail, '')))";
$dependencyExpression = "
CASE
    WHEN {$dependencyText} IN ('CICLISTA', 'GRU CICLISTA', 'GRUPO CICLISTA')
        THEN 'CICLISTA'
    WHEN {$dependencyText} REGEXP '^GUARNIC|^GUARNICION$'
        THEN 'Guarnición'
    WHEN {$dependencyText} REGEXP '^P[ .-]*[0-9]+$'
        THEN CONCAT(
            'P-',
            REPLACE(REPLACE(REPLACE(REPLACE({$dependencyText}, 'P', ''), ' ', ''), '.', ''), '-', '')
        )
    WHEN {$dependencyText} REGEXP '^(G[ .]*POL[ .]*[A-Z]|GRUPO[[:space:]]+POLICIAL[[:space:]]+[A-Z]|GRUPO[[:space:]]+[A-Z])$'
        THEN CONCAT(
            'GRUPO POLICIAL ',
            RIGHT(REPLACE(REPLACE({$dependencyText}, ' ', ''), '.', ''), 1)
        )
    WHEN {$dependencyText} REGEXP '^(S[ .]*ESPEC|SERVICIO[[:space:]]+ESPECIAL)$'
        THEN 'SERVICIO ESPECIAL'
    WHEN {$dependencyText} REGEXP '^(B[ .]*DE[ .]*MUSIC|BANDA[[:space:]]+DE[[:space:]]+MUSICA)$'
        THEN 'BANDA DE MÚSICA'
    WHEN {$dependencyText} REGEXP '^(S[ .]*CARLO|SAN[[:space:]]+CARLOS)$'
        THEN 'SAN CARLOS'
    WHEN NULLIF(TRIM(COALESCE(d.internal_detail, '')), '') IS NULL
        THEN 'Sin detalle interno'
    ELSE TRIM(d.internal_detail)
END";

$dependencyExists = (int)(one(
    $pdo,
    "SELECT COUNT(*) AS total
     FROM vw_workforce_match_detail d
     WHERE d.source_id = :source_id
       AND d.matched_unit_id = :unit_id
       AND {$dependencyExpression} = :dependency_name",
    [
        'source_id' => $sourceId,
        'unit_id' => $unitId,
        'dependency_name' => $dependencyName,
    ]
)['total'] ?? 0);

if ($dependencyExists <= 0) {
    http_response_code(404);
    render_header('Dependencia o sección', 'estructura', 'Consulta de personal por dependencia interna.');
    render_empty_state(
        'La dependencia ya no está disponible',
        'Puede haber cambiado la fuente seleccionada. Regrese a la unidad para consultar las dependencias vigentes.',
        'unidad_detalle.php?id=' . $unitId . '&source_id=' . $sourceId,
        'Volver a la unidad'
    );
    render_footer();
    exit;
}

$code = (string)($unit['code'] ?? '');
if (str_starts_with($code, 'DN-') || $code === 'SG-1') {
    $active = 'direcciones';
    $groupLabel = 'Direcciones nacionales';
    $groupHref = 'unidades.php?grupo=direcciones&source_id=' . $sourceId;
} elseif (str_starts_with($code, 'ZP-') || stripos((string)($unit['parent_name'] ?? ''), 'Zona Policial') !== false) {
    $active = 'zonas';
    $groupLabel = 'Zonas policiales';
    $groupHref = 'unidades.php?grupo=zonas&source_id=' . $sourceId;
} elseif (str_starts_with($code, 'SP-')) {
    $active = 'servicios';
    $groupLabel = 'Servicios policiales';
    $groupHref = 'unidades.php?grupo=servicios&source_id=' . $sourceId;
} else {
    $active = 'estructura';
    $groupLabel = 'Estructura institucional';
    $groupHref = 'unidades.php?grupo=todas&source_id=' . $sourceId;
}

$page = max(1, (int)($_GET['pagina'] ?? 1));
$perPage = 50;
$totalPeople = $dependencyExists;
$totalPages = max(1, (int)ceil($totalPeople / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$params = [
    'source_id' => $sourceId,
    'unit_id' => $unitId,
    'dependency_name' => $dependencyName,
];

$people = rows(
    $pdo,
    "SELECT d.*
     FROM vw_workforce_match_detail d
     WHERE d.source_id = :source_id
       AND d.matched_unit_id = :unit_id
       AND {$dependencyExpression} = :dependency_name
     ORDER BY d.full_name, d.position_number
     LIMIT {$perPage} OFFSET {$offset}",
    $params
);

$firstShown = $totalPeople > 0 ? $offset + 1 : 0;
$lastShown = min($offset + count($people), $totalPeople);

render_header($dependencyName, $active, 'Personal organizado por dependencia o sección.');
render_breadcrumbs([
    ['label' => 'Inicio', 'href' => 'index.php'],
    ['label' => $groupLabel, 'href' => $groupHref],
    ['label' => $unit['name'], 'href' => 'unidad_detalle.php?id=' . $unitId . '&source_id=' . $sourceId],
    ['label' => $dependencyName],
]);
?>

<div class="page-intro">
    <div>
        <h2><?= h($dependencyName) ?></h2>
        <p><?= h($unit['name']) ?><?= !empty($unit['parent_name']) ? ' · ' . h($unit['parent_name']) : '' ?></p>
    </div>
    <a class="button" href="unidad_detalle.php?id=<?= h($unitId) ?>&source_id=<?= h($sourceId) ?>#dependencias-secciones">← Volver a la unidad</a>
</div>

<div class="kpi-grid">
    <article class="kpi-card card">
        <span class="kpi-label">Personal de la dependencia</span>
        <strong class="kpi-value"><?= h(format_number($totalPeople)) ?></strong>
        <span class="kpi-note">Funcionarios registrados en esta dependencia o sección.</span>
    </article>
    <article class="kpi-card card info">
        <span class="kpi-label">Unidad principal</span>
        <strong class="kpi-value" style="font-size:1.1rem"><?= h($unit['name']) ?></strong>
        <span class="kpi-note">Unidad funcional a la que pertenece esta dependencia.</span>
    </article>
</div>

<section class="panel">
    <div class="panel-header">
        <div>
            <h2>Personal</h2>
            <p>Mostrando <?= h(format_number($firstShown)) ?>–<?= h(format_number($lastShown)) ?> de <?= h(format_number($totalPeople)) ?> funcionarios.</p>
        </div>
    </div>

    <?php if (!$people): ?>
        <div class="notice info">No se encontraron funcionarios en esta dependencia.</div>
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
                        <td><?= h($dependencyName) ?></td>
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
                        <a class="button" href="<?= h(query_url('dependencia_detalle.php', [
                            'unit_id' => $unitId,
                            'source_id' => $sourceId,
                            'dependencia' => $dependencyName,
                            'pagina' => $page - 1,
                        ])) ?>">← Anterior</a>
                    <?php endif; ?>
                    <?php if ($page < $totalPages): ?>
                        <a class="button primary" href="<?= h(query_url('dependencia_detalle.php', [
                            'unit_id' => $unitId,
                            'source_id' => $sourceId,
                            'dependencia' => $dependencyName,
                            'pagina' => $page + 1,
                        ])) ?>">Siguiente →</a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>

<?php render_footer(); ?>
