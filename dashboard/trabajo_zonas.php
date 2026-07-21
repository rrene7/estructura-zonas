<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

function zone_review_total(PDO $pdo, string $sql, array $params = []): int
{
    return (int)(one($pdo, $sql, $params)['total'] ?? 0);
}

function zone_review_metrics(
    PDO $pdo,
    array $zone,
    bool $hasCatalog,
    bool $hasAssignments,
    bool $hasDinsec,
    bool $hasLinks
): array {
    $zoneUnitId = (int)($zone['cabecera_unit_id'] ?? 0);
    $zoneNumber = (int)($zone['zone_number'] ?? 0);
    $normalizedName = trim((string)($zone['normalized_name'] ?? ''));

    $metrics = [
        'catalog_total' => 0,
        'assigned_units' => 0,
        'without_sector' => 0,
        'invalid_headers' => 0,
        'possible_pending' => 0,
        'dinsec_total' => 0,
        'dinsec_linked' => 0,
        'dinsec_unlinked' => 0,
        'dinsec_pending' => 0,
    ];

    if ($hasCatalog) {
        $metrics['catalog_total'] = zone_review_total(
            $pdo,
            'SELECT COUNT(*) AS total
             FROM moi_area_sector_catalog
             WHERE active = 1
               AND zone_number = :zone_number',
            ['zone_number' => $zoneNumber]
        );
    }

    if ($hasAssignments && $zoneUnitId > 0) {
        $excludedHeaders = "BINARY unit_row.legacy_table NOT IN (
            BINARY 'MOI_CABECERA_ZONA',
            BINARY 'MOI_CABECERA_DIRECCION',
            BINARY 'MOI_CABECERA_AREA'
        )";

        $metrics['assigned_units'] = zone_review_total(
            $pdo,
            "SELECT COUNT(*) AS total
             FROM moi_area_letter_assignments assignment_row
             JOIN organizational_units unit_row
               ON unit_row.id = assignment_row.organizational_unit_id
             WHERE assignment_row.zone_unit_id = :zone_unit_id
               AND {$excludedHeaders}",
            ['zone_unit_id' => $zoneUnitId]
        );

        $metrics['without_sector'] = zone_review_total(
            $pdo,
            "SELECT COUNT(*) AS total
             FROM moi_area_letter_assignments assignment_row
             JOIN organizational_units unit_row
               ON unit_row.id = assignment_row.organizational_unit_id
             WHERE assignment_row.zone_unit_id = :zone_unit_id
               AND (assignment_row.location_sector IS NULL OR assignment_row.location_sector = '')
               AND {$excludedHeaders}",
            ['zone_unit_id' => $zoneUnitId]
        );

        $metrics['invalid_headers'] = zone_review_total(
            $pdo,
            "SELECT COUNT(*) AS total
             FROM moi_area_letter_assignments assignment_row
             JOIN organizational_units unit_row
               ON unit_row.id = assignment_row.organizational_unit_id
             WHERE assignment_row.zone_unit_id = :zone_unit_id
               AND BINARY unit_row.legacy_table IN (
                    BINARY 'MOI_CABECERA_ZONA',
                    BINARY 'MOI_CABECERA_DIRECCION',
                    BINARY 'MOI_CABECERA_AREA'
               )",
            ['zone_unit_id' => $zoneUnitId]
        );
    }

    if ($zoneUnitId > 0 && $normalizedName !== '') {
        $metrics['possible_pending'] = zone_review_total(
            $pdo,
            "SELECT COUNT(*) AS total
             FROM organizational_units unit_row
             WHERE unit_row.lifecycle_status = 'vigente'
               AND BINARY unit_row.legacy_table <> BINARY 'MOI_CABECERA_AREA'
               AND UPPER(unit_row.name COLLATE utf8mb4_unicode_ci) LIKE :pattern
               AND NOT EXISTS (
                    SELECT 1
                    FROM organizational_units parent_row
                    WHERE parent_row.id = unit_row.parent_id
                      AND BINARY parent_row.legacy_table IN (
                            BINARY 'MOI_CABECERA_ZONA',
                            BINARY 'MOI_CABECERA_DIRECCION',
                            BINARY 'MOI_CABECERA_AREA'
                      )
               )
               AND NOT EXISTS (
                    SELECT 1
                    FROM organizational_unit_relationships relationship_row
                    JOIN organizational_units target_row
                      ON target_row.id = relationship_row.target_unit_id
                    WHERE relationship_row.source_unit_id = unit_row.id
                      AND relationship_row.status = 'active'
                      AND BINARY target_row.legacy_table IN (
                            BINARY 'MOI_CABECERA_ZONA',
                            BINARY 'MOI_CABECERA_AREA'
                      )
               )",
            ['pattern' => '%' . $normalizedName . '%']
        );
    }

    if ($hasDinsec) {
        $zoneCondition = '(reference_row.zone_unit_id = :zone_unit_id OR reference_row.zone_label LIKE :zone_label)';
        $parameters = [
            'zone_unit_id' => $zoneUnitId,
            'zone_label' => '%' . (string)($zone['zone_label'] ?? '') . '%',
        ];

        $metrics['dinsec_total'] = zone_review_total(
            $pdo,
            "SELECT COUNT(*) AS total
             FROM dinsec_personnel_reference reference_row
             WHERE {$zoneCondition}",
            $parameters
        );

        $metrics['dinsec_pending'] = zone_review_total(
            $pdo,
            "SELECT COUNT(*) AS total
             FROM dinsec_personnel_reference reference_row
             WHERE reference_row.review_status = 'pendiente'
               AND {$zoneCondition}",
            $parameters
        );

        if ($hasLinks) {
            $metrics['dinsec_linked'] = zone_review_total(
                $pdo,
                "SELECT COUNT(*) AS total
                 FROM dinsec_personnel_reference reference_row
                 JOIN dinsec_personnel_unit_links link_row
                   ON link_row.dinsec_personnel_reference_id = reference_row.id
                  AND link_row.status = 'active'
                 WHERE {$zoneCondition}",
                $parameters
            );

            $metrics['dinsec_unlinked'] = zone_review_total(
                $pdo,
                "SELECT COUNT(*) AS total
                 FROM dinsec_personnel_reference reference_row
                 LEFT JOIN dinsec_personnel_unit_links link_row
                   ON link_row.dinsec_personnel_reference_id = reference_row.id
                  AND link_row.status = 'active'
                 WHERE {$zoneCondition}
                   AND link_row.id IS NULL",
                $parameters
            );
        } else {
            $metrics['dinsec_unlinked'] = $metrics['dinsec_total'];
        }
    }

    $metrics['structure_issues'] =
        $metrics['without_sector']
        + $metrics['invalid_headers']
        + $metrics['possible_pending'];

    $metrics['personnel_issues'] =
        $metrics['dinsec_unlinked']
        + $metrics['dinsec_pending'];

    $metrics['total_issues'] =
        $metrics['structure_issues']
        + $metrics['personnel_issues'];

    return $metrics;
}

$requiredView = 'moi_zonas_cabecera_vigentes';
$zonesAvailable = table_exists($pdo, $requiredView);

render_header(
    'Revisión técnica por zona',
    'trabajo_zonas',
    'Comprueba que la estructura y las referencias de personal estén correctamente vinculadas.'
);
render_breadcrumbs([
    ['label' => 'Inicio', 'href' => 'index.php'],
    ['label' => 'Herramientas técnicas', 'href' => ''],
    ['label' => 'Revisión por zona', 'href' => ''],
]);

if (!$zonesAvailable) {
    render_empty_state(
        'No está disponible el catálogo de zonas',
        'Falta la vista moi_zonas_cabecera_vigentes en la base de datos.'
    );
    render_footer();
    return;
}

$hasCatalog = table_exists($pdo, 'moi_area_sector_catalog');
$hasAssignments = table_exists($pdo, 'moi_area_letter_assignments');
$hasDinsec = table_exists($pdo, 'dinsec_personnel_reference');
$hasLinks = table_exists($pdo, 'dinsec_personnel_unit_links');

$zoneJoin = "BINARY zone_unit.legacy_table = BINARY 'MOI_CABECERA_ZONA'
    AND CAST(zone_unit.legacy_id AS UNSIGNED) = zone_row.zone_number";

$zones = rows(
    $pdo,
    "SELECT
        zone_row.id,
        zone_row.zone_number,
        zone_row.zone_label,
        zone_row.normalized_name,
        zone_unit.id AS cabecera_unit_id
     FROM moi_zonas_cabecera_vigentes zone_row
     LEFT JOIN organizational_units zone_unit ON {$zoneJoin}
     WHERE zone_row.lifecycle_status = 'vigente'
     ORDER BY zone_row.zone_number"
);

$selectedZoneId = max(0, (int)($_GET['zona_id'] ?? ($zones[0]['id'] ?? 0)));
$selectedZone = [];
$board = [];

foreach ($zones as $zone) {
    $metrics = zone_review_metrics(
        $pdo,
        $zone,
        $hasCatalog,
        $hasAssignments,
        $hasDinsec,
        $hasLinks
    );

    $board[] = [
        'zone' => $zone,
        'metrics' => $metrics,
    ];

    if ((int)$zone['id'] === $selectedZoneId) {
        $selectedZone = $zone;
        $selectedMetrics = $metrics;
    }
}

if (!$selectedZone && $board) {
    $selectedZone = $board[0]['zone'];
    $selectedMetrics = $board[0]['metrics'];
    $selectedZoneId = (int)$selectedZone['id'];
}

$readyZones = 0;
$zonesToReview = 0;
$totalIssues = 0;
foreach ($board as $row) {
    $issues = (int)$row['metrics']['total_issues'];
    $totalIssues += $issues;
    if ($issues === 0) {
        $readyZones++;
    } else {
        $zonesToReview++;
    }
}
?>

<div class="notice info">
    <strong>¿Para qué sirve esta pantalla?</strong>
    Es una revisión técnica. No cambia los nombres de las zonas ni traslada personal. Solo identifica si una zona tiene unidades sin clasificar, registros técnicos incorrectos o referencias DINSEC sin vincular.
</div>

<div class="kpi-grid">
    <article class="kpi-card card">
        <span class="kpi-label">Zonas revisadas</span>
        <strong class="kpi-value"><?= h(format_number(count($board))) ?></strong>
        <span class="kpi-note">Zonas vigentes incluidas en la verificación.</span>
    </article>
    <article class="kpi-card card success">
        <span class="kpi-label">Sin pendientes</span>
        <strong class="kpi-value"><?= h(format_number($readyZones)) ?></strong>
        <span class="kpi-note">No presentan observaciones en esta revisión.</span>
    </article>
    <article class="kpi-card card warning">
        <span class="kpi-label">Requieren revisión</span>
        <strong class="kpi-value"><?= h(format_number($zonesToReview)) ?></strong>
        <span class="kpi-note">Tienen al menos una observación técnica.</span>
    </article>
    <article class="kpi-card card info">
        <span class="kpi-label">Observaciones totales</span>
        <strong class="kpi-value"><?= h(format_number($totalIssues)) ?></strong>
        <span class="kpi-note">Suma de estructura y referencias de personal.</span>
    </article>
</div>

<section class="panel">
    <div class="panel-header">
        <div>
            <h2>Seleccionar una zona</h2>
            <p>Elija la zona que desea revisar. La información se presenta en dos bloques sencillos.</p>
        </div>
    </div>
    <form class="filter-grid" method="get" action="trabajo_zonas.php">
        <div class="field">
            <label for="zona_id">Zona policial</label>
            <select id="zona_id" name="zona_id">
                <?php foreach ($zones as $zone): ?>
                    <option value="<?= h($zone['id']) ?>" <?= (int)$zone['id'] === $selectedZoneId ? 'selected' : '' ?>>
                        <?= h($zone['zone_number']) ?> - <?= h($zone['zone_label']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label>&nbsp;</label>
            <button class="button primary" type="submit">Revisar zona</button>
        </div>
    </form>
</section>

<?php if ($selectedZone): ?>
    <?php
    $structureIssues = (int)$selectedMetrics['structure_issues'];
    $personnelIssues = (int)$selectedMetrics['personnel_issues'];
    ?>
    <div class="page-intro">
        <div>
            <h2><?= h($selectedZone['zone_label']) ?></h2>
            <p>Resumen técnico de la estructura territorial y las referencias DINSEC.</p>
        </div>
        <div class="button-row">
            <a class="button primary" href="detalle_zona_personal.php?zona_id=<?= h($selectedZoneId) ?>">Ver detalle de la zona</a>
            <a class="button" href="unidades.php?grupo=zonas">Ver zonas en modo consulta</a>
        </div>
    </div>

    <div class="two-column">
        <section class="panel">
            <div class="panel-header">
                <div>
                    <h2>Estructura de la zona</h2>
                    <p>Comprueba que las unidades estén clasificadas dentro del área o sector correcto.</p>
                </div>
                <span class="badge <?= $structureIssues === 0 ? 'success' : 'warning' ?>">
                    <?= $structureIssues === 0 ? 'Correcta' : 'Revisar' ?>
                </span>
            </div>
            <div class="detail-grid">
                <article class="detail-card card">
                    <span class="kpi-label">Unidades ubicadas</span>
                    <div class="value"><?= h(format_number($selectedMetrics['assigned_units'])) ?></div>
                    <div class="description">Unidades ya asociadas con esta zona.</div>
                </article>
                <article class="detail-card card">
                    <span class="kpi-label">Sin área o sector</span>
                    <div class="value"><?= h(format_number($selectedMetrics['without_sector'])) ?></div>
                    <div class="description">Unidades que necesitan completar su clasificación territorial.</div>
                </article>
                <article class="detail-card card">
                    <span class="kpi-label">Posibles pendientes</span>
                    <div class="value"><?= h(format_number($selectedMetrics['possible_pending'])) ?></div>
                    <div class="description">Nombres relacionados con la zona que aún deben comprobarse.</div>
                </article>
            </div>
            <?php if ((int)$selectedMetrics['invalid_headers'] > 0): ?>
                <div class="notice warning">
                    Hay <?= h(format_number($selectedMetrics['invalid_headers'])) ?> registros técnicos de cabecera clasificados incorrectamente como unidades.
                </div>
            <?php endif; ?>
        </section>

        <section class="panel">
            <div class="panel-header">
                <div>
                    <h2>Referencias de personal DINSEC</h2>
                    <p>Comprueba que las referencias históricas de personal estén conectadas con una unidad.</p>
                </div>
                <?php if (!$hasDinsec): ?>
                    <span class="badge neutral">No disponible</span>
                <?php else: ?>
                    <span class="badge <?= $personnelIssues === 0 ? 'success' : 'warning' ?>">
                        <?= $personnelIssues === 0 ? 'Vinculado' : 'Revisar' ?>
                    </span>
                <?php endif; ?>
            </div>
            <div class="detail-grid">
                <article class="detail-card card">
                    <span class="kpi-label">Total DINSEC</span>
                    <div class="value"><?= h(format_number($selectedMetrics['dinsec_total'])) ?></div>
                    <div class="description">Referencias encontradas para esta zona.</div>
                </article>
                <article class="detail-card card">
                    <span class="kpi-label">Vinculadas</span>
                    <div class="value"><?= h(format_number($selectedMetrics['dinsec_linked'])) ?></div>
                    <div class="description">Referencias conectadas con una unidad vigente.</div>
                </article>
                <article class="detail-card card">
                    <span class="kpi-label">Pendientes</span>
                    <div class="value"><?= h(format_number(
                        (int)$selectedMetrics['dinsec_unlinked'] + (int)$selectedMetrics['dinsec_pending']
                    )) ?></div>
                    <div class="description">Sin vínculo o pendientes de revisión manual.</div>
                </article>
            </div>
        </section>
    </div>

    <section class="panel">
        <details class="advanced">
            <summary>Abrir herramientas técnicas de esta zona</summary>
            <p class="result-summary">Estas opciones son para correcciones técnicas. La consulta normal debe realizarse desde Zonas policiales.</p>
            <div class="button-row">
                <a class="button" href="catalogo_sectores.php?zone_number=<?= h($selectedZone['zone_number']) ?>">Catálogo de áreas y sectores</a>
                <a class="button" href="reasignar_areas_catalogo.php?zona_id=<?= h($selectedZoneId) ?>&solo=incompletas">Revisar unidades sin sector</a>
                <a class="button" href="asignar_areas_catalogo.php?zona_id=<?= h($selectedZoneId) ?>&buscar=<?= h($selectedZone['normalized_name']) ?>">Revisar posibles pendientes</a>
                <a class="button" href="dinsec_personal.php?buscar=<?= h($selectedZone['zone_label']) ?>">Revisar referencias DINSEC</a>
            </div>
        </details>
    </section>
<?php endif; ?>

<section class="panel">
    <div class="panel-header">
        <div>
            <h2>Estado general de las zonas</h2>
            <p>Vista resumida. Abra únicamente las zonas que aparezcan con estado “Revisar”.</p>
        </div>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>Zona</th>
                <th>Estructura</th>
                <th>Personal DINSEC</th>
                <th>Observaciones</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($board as $row): ?>
                <?php
                $zone = $row['zone'];
                $metrics = $row['metrics'];
                $zoneStructureIssues = (int)$metrics['structure_issues'];
                $zonePersonnelIssues = (int)$metrics['personnel_issues'];
                ?>
                <tr>
                    <td>
                        <span class="person-name"><?= h($zone['zone_number']) ?> - <?= h($zone['zone_label']) ?></span>
                    </td>
                    <td>
                        <span class="badge <?= $zoneStructureIssues === 0 ? 'success' : 'warning' ?>">
                            <?= $zoneStructureIssues === 0 ? 'Correcta' : 'Revisar' ?>
                        </span>
                    </td>
                    <td>
                        <?php if (!$hasDinsec): ?>
                            <span class="badge neutral">No disponible</span>
                        <?php else: ?>
                            <span class="badge <?= $zonePersonnelIssues === 0 ? 'success' : 'warning' ?>">
                                <?= $zonePersonnelIssues === 0 ? 'Vinculado' : 'Revisar' ?>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td><?= h(format_number($metrics['total_issues'])) ?></td>
                    <td><a class="button soft" href="trabajo_zonas.php?zona_id=<?= h($zone['id']) ?>">Abrir</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php render_footer(); ?>
