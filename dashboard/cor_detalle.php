<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$unitId = (int)($_GET['unit_id'] ?? 0);
$source = current_workforce_source($pdo, (int)($_GET['source_id'] ?? 0));
$sourceId = (int)($source['id'] ?? 0);

$unit = $unitId > 0
    ? one(
        $pdo,
        "SELECT id, name, code
         FROM organizational_units
         WHERE id = :id
         LIMIT 1",
        ['id' => $unitId]
    )
    : [];

if (!$unit || (string)($unit['code'] ?? '') !== 'DN-01') {
    http_response_code(404);
    render_header('Centros de Operaciones Regionales', 'direcciones', 'Consulta de los COR vinculados a Dirección General.');
    render_empty_state(
        'No se encontró la Dirección General',
        'Regrese al listado de direcciones y abra la Dirección General vigente.',
        'unidades.php?grupo=direcciones',
        'Volver a direcciones'
    );
    render_footer();
    exit;
}

$corText = "UPPER(CONCAT_WS(' ', COALESCE(d.internal_detail, ''), COALESCE(d.location_original, ''), COALESCE(d.territorial_zone_name, '')))";
$corExpression = "
CASE
    WHEN {$corText} REGEXP 'CENTRO DE OPERACIONES NACIONAL|MINSEG[.]?CON([^A-Z]|$)' THEN 'COR Nacional'
    WHEN {$corText} REGEXP 'COLON' THEN 'COR Colón'
    WHEN {$corText} REGEXP 'CHIRIQUI' THEN 'COR Chiriquí'
    WHEN {$corText} REGEXP 'CHORRERA' THEN 'COR La Chorrera'
    WHEN {$corText} REGEXP 'SAN[ .]*MIGUELITO|S[.]?[[:space:]]*MIGUELITO' THEN 'COR San Miguelito'
    WHEN {$corText} REGEXP 'ARRAIJAN' THEN 'COR Arraiján'
    WHEN {$corText} REGEXP 'COCLE' THEN 'COR Coclé'
    WHEN {$corText} REGEXP 'CHEPO' THEN 'COR Chepo'
    WHEN {$corText} REGEXP 'BOQUETE' THEN 'COR Boquete'
    WHEN {$corText} REGEXP 'PACORA' THEN 'COR Pacora'
    WHEN {$corText} REGEXP 'HERRERA' THEN 'COR Herrera'
    WHEN {$corText} REGEXP 'LOS SANTOS' THEN 'COR Los Santos'
    WHEN {$corText} REGEXP 'ENLACE DE CAMPO|ENC[.]?[[:space:]]*CAM' THEN 'COR - Enlace de Campo'
    WHEN NULLIF(TRIM(COALESCE(d.territorial_zone_name, '')), '') IS NOT NULL THEN CONCAT('COR - ', d.territorial_zone_name)
    ELSE 'COR - Sin ubicación específica'
END";

$corWhere = "({$corText} REGEXP 'CENTRO DE OPERACIONES REGIONAL|C[ .]*O[ .]*R')
             AND {$corText} NOT REGEXP 'PMI'";

$corGroups = $sourceId > 0
    ? rows(
        $pdo,
        "SELECT cor_name, COUNT(*) AS total
         FROM (
             SELECT {$corExpression} AS cor_name
             FROM vw_workforce_match_detail d
             WHERE d.source_id = :source_id
               AND d.matched_unit_id = :unit_id
               AND {$corWhere}
         ) cor_scope
         GROUP BY cor_name
         ORDER BY
             CASE WHEN cor_name = 'COR Nacional' THEN 1 ELSE 2 END,
             cor_name",
        ['source_id' => $sourceId, 'unit_id' => $unitId]
    )
    : [];

$corFilter = trim((string)($_GET['cor'] ?? ''));
$allowedCors = array_column($corGroups, 'cor_name');
if ($corFilter !== '' && !in_array($corFilter, $allowedCors, true)) {
    $corFilter = '';
}

$page = max(1, (int)($_GET['pagina'] ?? 1));
$perPage = 50;
$totalPeople = 0;
$totalPages = 1;
$people = [];
$firstShown = 0;
$lastShown = 0;

if ($sourceId > 0 && $corFilter !== '') {
    $baseSql = "SELECT d.*, {$corExpression} AS cor_name
                FROM vw_workforce_match_detail d
                WHERE d.source_id = :source_id
                  AND d.matched_unit_id = :unit_id
                  AND {$corWhere}";

    $params = [
        'source_id' => $sourceId,
        'unit_id' => $unitId,
        'cor_name' => $corFilter,
    ];

    $totalPeople = (int)(one(
        $pdo,
        "SELECT COUNT(*) AS total
         FROM ({$baseSql}) cor_people
         WHERE cor_people.cor_name = :cor_name",
        $params
    )['total'] ?? 0);

    $totalPages = max(1, (int)ceil($totalPeople / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $people = rows(
        $pdo,
        "SELECT cor_people.*
         FROM ({$baseSql}) cor_people
         WHERE cor_people.cor_name = :cor_name
         ORDER BY cor_people.full_name, cor_people.position_number
         LIMIT {$perPage} OFFSET {$offset}",
        $params
    );

    $firstShown = $totalPeople > 0 ? $offset + 1 : 0;
    $lastShown = min($offset + count($people), $totalPeople);
}

$totalCorPersonnel = array_sum(array_map(static fn (array $row): int => (int)$row['total'], $corGroups));

render_header('Centros de Operaciones Regionales', 'direcciones', 'Navegue los COR y consulte el personal de cada sede.');
render_breadcrumbs([
    ['label' => 'Inicio', 'href' => 'index.php'],
    ['label' => 'Direcciones nacionales', 'href' => 'unidades.php?grupo=direcciones&source_id=' . $sourceId],
    ['label' => 'Dirección General', 'href' => 'unidad_detalle.php?id=' . $unitId . '&source_id=' . $sourceId],
    ['label' => 'Centros de Operaciones Regionales'],
]);
?>

<div class="page-intro">
    <div>
        <h2>Centros de Operaciones Regionales (COR)</h2>
        <p>Seleccione un COR para consultar únicamente el personal registrado en esa sede.</p>
    </div>
    <a class="button" href="unidad_detalle.php?id=<?= h($unitId) ?>&source_id=<?= h($sourceId) ?>">← Volver a Dirección General</a>
</div>

<div class="kpi-grid">
    <article class="kpi-card card">
        <span class="kpi-label">COR identificados</span>
        <strong class="kpi-value"><?= h(format_number(count($corGroups))) ?></strong>
        <span class="kpi-note">Sedes o grupos regionales encontrados en el listado.</span>
    </article>
    <article class="kpi-card card info">
        <span class="kpi-label">Personal en COR</span>
        <strong class="kpi-value"><?= h(format_number($totalCorPersonnel)) ?></strong>
        <span class="kpi-note">Total de funcionarios vinculados a los centros de operaciones.</span>
    </article>
</div>

<section class="panel">
    <div class="panel-header">
        <div>
            <h2>Listado de COR</h2>
            <p>La pantalla principal muestra una sola entrada de COR; aquí se presentan las distintas sedes.</p>
        </div>
        <?php if ($corFilter !== ''): ?>
            <a class="button" href="cor_detalle.php?unit_id=<?= h($unitId) ?>&source_id=<?= h($sourceId) ?>">Mostrar todos</a>
        <?php endif; ?>
    </div>

    <?php if (!$corGroups): ?>
        <div class="notice info">No se encontraron registros de Centros de Operaciones Regionales.</div>
    <?php else: ?>
        <div class="unit-list">
            <?php foreach ($corGroups as $cor): ?>
                <?php
                $corName = (string)$cor['cor_name'];
                $corUrl = query_url('cor_detalle.php', [
                    'unit_id' => $unitId,
                    'source_id' => $sourceId,
                    'cor' => $corName,
                ]) . '#personal-cor';
                ?>
                <article class="unit-card card">
                    <div>
                        <h3><a href="<?= h($corUrl) ?>"><?= h($corName) ?></a></h3>
                        <p>Centro de operaciones identificado dentro del listado de Dirección General.</p>
                    </div>
                    <div class="unit-count">
                        <strong><?= h(format_number($cor['total'])) ?></strong>
                        <span>funcionarios</span>
                        <a class="button soft" href="<?= h($corUrl) ?>">Abrir</a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php if ($corFilter !== ''): ?>
    <section class="panel" id="personal-cor">
        <div class="panel-header">
            <div>
                <h2><?= h($corFilter) ?></h2>
                <p>Mostrando <?= h(format_number($firstShown)) ?>–<?= h(format_number($lastShown)) ?> de <?= h(format_number($totalPeople)) ?> funcionarios.</p>
            </div>
            <a class="button" href="cor_detalle.php?unit_id=<?= h($unitId) ?>&source_id=<?= h($sourceId) ?>">Cerrar detalle</a>
        </div>

        <?php if (!$people): ?>
            <div class="notice info">No se encontraron funcionarios en el COR seleccionado.</div>
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
                            <td><?= h($person['territorial_zone_name'] ?: 'No indicada') ?></td>
                            <td><?= h($person['internal_detail'] ?: 'Sin detalle interno') ?></td>
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
                            <a class="button" href="<?= h(query_url('cor_detalle.php', [
                                'unit_id' => $unitId,
                                'source_id' => $sourceId,
                                'cor' => $corFilter,
                                'pagina' => $page - 1,
                            ])) ?>#personal-cor">← Anterior</a>
                        <?php endif; ?>
                        <?php if ($page < $totalPages): ?>
                            <a class="button primary" href="<?= h(query_url('cor_detalle.php', [
                                'unit_id' => $unitId,
                                'source_id' => $sourceId,
                                'cor' => $corFilter,
                                'pagina' => $page + 1,
                            ])) ?>#personal-cor">Siguiente →</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php render_footer(); ?>
