<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

function zone_status_total(PDO $pdo, string $sql, array $params = []): int
{
    return (int)(one($pdo, $sql, $params)['total'] ?? 0);
}

function zone_status_metrics(
    PDO $pdo,
    array $zone,
    bool $hasAssignments,
    bool $hasDinsec,
    bool $hasLinks
): array {
    $zoneUnitId = (int)($zone['cabecera_unit_id'] ?? 0);
    $normalizedName = trim((string)($zone['normalized_name'] ?? ''));

    $metrics = [
        'assigned_units' => 0,
        'without_sector' => 0,
        'invalid_headers' => 0,
        'possible_pending' => 0,
        'dinsec_total' => 0,
        'dinsec_linked' => 0,
        'dinsec_unlinked' => 0,
        'dinsec_pending' => 0,
    ];

    if ($hasAssignments && $zoneUnitId > 0) {
        $validUnitCondition = "BINARY unit_row.legacy_table <> BINARY 'MOI_CABECERA_ZONA'
            AND BINARY unit_row.legacy_table <> BINARY 'MOI_CABECERA_DIRECCION'
            AND BINARY unit_row.legacy_table <> BINARY 'MOI_CABECERA_AREA'";

        $metrics['assigned_units'] = zone_status_total(
            $pdo,
            "SELECT COUNT(*) AS total
             FROM moi_area_letter_assignments assignment_row
             JOIN organizational_units unit_row
               ON unit_row.id = assignment_row.organizational_unit_id
             WHERE assignment_row.zone_unit_id = :zone_unit_id
               AND {$validUnitCondition}",
            ['zone_unit_id' => $zoneUnitId]
        );

        $metrics['without_sector'] = zone_status_total(
            $pdo,
            "SELECT COUNT(*) AS total
             FROM moi_area_letter_assignments assignment_row
             JOIN organizational_units unit_row
               ON unit_row.id = assignment_row.organizational_unit_id
             WHERE assignment_row.zone_unit_id = :zone_unit_id
               AND (assignment_row.location_sector IS NULL OR assignment_row.location_sector = '')
               AND {$validUnitCondition}",
            ['zone_unit_id' => $zoneUnitId]
        );

        $metrics['invalid_headers'] = zone_status_total(
            $pdo,
            "SELECT COUNT(*) AS total
             FROM moi_area_letter_assignments assignment_row
             JOIN organizational_units unit_row
               ON unit_row.id = assignment_row.organizational_unit_id
             WHERE assignment_row.zone_unit_id = :zone_unit_id
               AND (
                    BINARY unit_row.legacy_table = BINARY 'MOI_CABECERA_ZONA'
                    OR BINARY unit_row.legacy_table = BINARY 'MOI_CABECERA_DIRECCION'
                    OR BINARY unit_row.legacy_table = BINARY 'MOI_CABECERA_AREA'
               )",
            ['zone_unit_id' => $zoneUnitId]
        );
    }

    if ($zoneUnitId > 0 && $normalizedName !== '') {
        $metrics['possible_pending'] = zone_status_total(
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
                      AND (
                            BINARY parent_row.legacy_table = BINARY 'MOI_CABECERA_ZONA'
                            OR BINARY parent_row.legacy_table = BINARY 'MOI_CABECERA_DIRECCION'
                            OR BINARY parent_row.legacy_table = BINARY 'MOI_CABECERA_AREA'
                      )
               )
               AND NOT EXISTS (
                    SELECT 1
                    FROM organizational_unit_relationships relationship_row
                    JOIN organizational_units target_row
                      ON target_row.id = relationship_row.target_unit_id
                    WHERE relationship_row.source_unit_id = unit_row.id
                      AND relationship_row.status = 'active'
                      AND (
                            BINARY target_row.legacy_table = BINARY 'MOI_CABECERA_ZONA'
                            OR BINARY target_row.legacy_table = BINARY 'MOI_CABECERA_AREA'
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

        $metrics['dinsec_total'] = zone_status_total(
            $pdo,
            "SELECT COUNT(*) AS total
             FROM dinsec_personnel_reference reference_row
             WHERE {$zoneCondition}",
            $parameters
        );

        $metrics['dinsec_pending'] = zone_status_total(
            $pdo,
            "SELECT COUNT(*) AS total
             FROM dinsec_personnel_reference reference_row
             WHERE reference_row.review_status = 'pendiente'
               AND {$zoneCondition}",
            $parameters
        );

        if ($hasLinks) {
            $metrics['dinsec_linked'] = zone_status_total(
                $pdo,
                "SELECT COUNT(*) AS total
                 FROM dinsec_personnel_reference reference_row
                 JOIN dinsec_personnel_unit_links link_row
                   ON link_row.dinsec_personnel_reference_id = reference_row.id
                  AND link_row.status = 'active'
                 WHERE {$zoneCondition}",
                $parameters
            );

            $metrics['dinsec_unlinked'] = zone_status_total(
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
        + ($hasDinsec ? $metrics['personnel_issues'] : 0);

    return $metrics;
}

render_header(
    'Estado de las zonas',
    'trabajo_zonas',
    'Muestra de forma sencilla cuáles zonas están correctas y cuáles requieren revisión.'
);
render_breadcrumbs([
    ['label' => 'Inicio', 'href' => 'index.php'],
    ['label' => 'Herramientas técnicas', 'href' => ''],
    ['label' => 'Estado de las zonas', 'href' => ''],
]);

if (!table_exists($pdo, 'moi_zonas_cabecera_vigentes')) {
    render_empty_state(
        'No se puede revisar las zonas',
        'Falta la vista moi_zonas_cabecera_vigentes en la base de datos.'
    );
    render_footer();
    return;
}

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
$selectedMetrics = [];
$board = [];

foreach ($zones as $zone) {
    $metrics = zone_status_metrics(
        $pdo,
        $zone,
        $hasAssignments,
        $hasDinsec,
        $hasLinks
    );

    $board[] = ['zone' => $zone, 'metrics' => $metrics];

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
?>

<div class="notice info">
    <strong>Esta pantalla solo responde una pregunta:</strong>
    ¿La zona está correcta o necesita revisión? No cambia nombres, áreas ni personal.
</div>

<section class="panel">
    <div class="panel-header">
        <div>
            <h2>Escoger zona</h2>
            <p>Seleccione una zona y presione “Ver estado”.</p>
        </div>
    </div>
    <form class="search-bar" method="get" action="trabajo_zonas.php">
        <select name="zona_id" aria-label="Zona policial">
            <?php foreach ($zones as $zone): ?>
                <option value="<?= h($zone['id']) ?>" <?= (int)$zone['id'] === $selectedZoneId ? 'selected' : '' ?>>
                    <?= h($zone['zone_number']) ?> - <?= h($zone['zone_label']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button class="button primary" type="submit">Ver estado</button>
    </form>
</section>

<?php if ($selectedZone): ?>
    <?php
    $structureIssues = (int)$selectedMetrics['structure_issues'];
    $personnelIssues = (int)$selectedMetrics['personnel_issues'];
    $totalIssues = (int)$selectedMetrics['total_issues'];
    $zoneIsReady = $totalIssues === 0;
    ?>

    <section class="panel">
        <div class="page-intro">
            <div>
                <h2><?= h($selectedZone['zone_label']) ?></h2>
                <p>
                    <?= $zoneIsReady
                        ? 'La revisión no encontró asuntos pendientes.'
                        : 'Hay ' . h(format_number($totalIssues)) . ' asuntos que deben revisarse.' ?>
                </p>
            </div>
            <span class="badge <?= $zoneIsReady ? 'success' : 'warning' ?>">
                <?= $zoneIsReady ? 'Todo bien' : 'Revisar' ?>
            </span>
        </div>

        <div class="action-grid">
            <article class="action-card card">
                <div class="action-icon" aria-hidden="true"><?= $structureIssues === 0 ? '✓' : '!' ?></div>
                <h3>Ubicación de las unidades</h3>
                <p>
                    <?= $structureIssues === 0
                        ? 'Las unidades de esta zona están ubicadas correctamente.'
                        : 'Hay ' . h(format_number($structureIssues)) . ' unidades o registros por revisar.' ?>
                </p>
                <span class="action-link"><?= $structureIssues === 0 ? 'Correcta' : 'Necesita revisión' ?></span>
            </article>

            <article class="action-card card">
                <div class="action-icon" aria-hidden="true">
                    <?= !$hasDinsec ? '—' : ($personnelIssues === 0 ? '✓' : '!') ?>
                </div>
                <h3>Vínculo del personal</h3>
                <p>
                    <?php if (!$hasDinsec): ?>
                        Esta revisión no está disponible en la base actual.
                    <?php elseif ($personnelIssues === 0): ?>
                        Las referencias de personal están vinculadas correctamente.
                    <?php else: ?>
                        Hay <?= h(format_number($personnelIssues)) ?> referencias de personal por revisar.
                    <?php endif; ?>
                </p>
                <span class="action-link">
                    <?= !$hasDinsec ? 'No disponible' : ($personnelIssues === 0 ? 'Correcto' : 'Necesita revisión') ?>
                </span>
            </article>
        </div>

        <div class="button-row" style="margin-top:18px">
            <a class="button primary" href="detalle_zona_personal.php?zona_id=<?= h($selectedZoneId) ?>">Ver zona y personal</a>
            <a class="button" href="unidades.php?grupo=zonas">Volver a zonas policiales</a>
        </div>
    </section>

    <section class="panel">
        <details class="advanced">
            <summary>Ver detalle técnico</summary>
            <div class="detail-grid">
                <article class="detail-card card">
                    <span class="kpi-label">Unidades ubicadas</span>
                    <div class="value"><?= h(format_number($selectedMetrics['assigned_units'])) ?></div>
                </article>
                <article class="detail-card card">
                    <span class="kpi-label">Sin área o sector</span>
                    <div class="value"><?= h(format_number($selectedMetrics['without_sector'])) ?></div>
                </article>
                <article class="detail-card card">
                    <span class="kpi-label">Posibles pendientes</span>
                    <div class="value"><?= h(format_number($selectedMetrics['possible_pending'])) ?></div>
                </article>
                <article class="detail-card card">
                    <span class="kpi-label">Personal vinculado</span>
                    <div class="value"><?= h(format_number($selectedMetrics['dinsec_linked'])) ?></div>
                </article>
                <article class="detail-card card">
                    <span class="kpi-label">Personal sin vínculo</span>
                    <div class="value"><?= h(format_number($selectedMetrics['dinsec_unlinked'])) ?></div>
                </article>
                <article class="detail-card card">
                    <span class="kpi-label">Pendiente de revisión</span>
                    <div class="value"><?= h(format_number($selectedMetrics['dinsec_pending'])) ?></div>
                </article>
            </div>

            <div class="button-row" style="margin-top:16px">
                <a class="button" href="reasignar_areas_catalogo.php?zona_id=<?= h($selectedZoneId) ?>&solo=incompletas">Corregir unidades pendientes</a>
                <a class="button" href="dinsec_personal.php?buscar=<?= h($selectedZone['zone_label']) ?>">Revisar vínculos de personal</a>
            </div>
        </details>
    </section>
<?php endif; ?>

<section class="panel">
    <div class="panel-header">
        <div>
            <h2>Todas las zonas</h2>
            <p>Verde significa que está correcta. Amarillo significa que debe revisarse.</p>
        </div>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>Zona</th>
                <th>Estado</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($board as $row): ?>
                <?php
                $zone = $row['zone'];
                $issues = (int)$row['metrics']['total_issues'];
                ?>
                <tr>
                    <td><span class="person-name"><?= h($zone['zone_number']) ?> - <?= h($zone['zone_label']) ?></span></td>
                    <td>
                        <span class="badge <?= $issues === 0 ? 'success' : 'warning' ?>">
                            <?= $issues === 0 ? 'Todo bien' : 'Revisar' ?>
                        </span>
                    </td>
                    <td><a class="button soft" href="trabajo_zonas.php?zona_id=<?= h($zone['id']) ?>">Abrir</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php render_footer(); ?>
