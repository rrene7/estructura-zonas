<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$source = current_workforce_source($pdo, (int)($_GET['source_id'] ?? 0));
$sourceId = (int)($source['id'] ?? 0);
$summary = $sourceId > 0
    ? one($pdo, 'SELECT * FROM vw_workforce_summary WHERE source_id = :source_id', ['source_id' => $sourceId])
    : [];

$byLevel = $sourceId > 0
    ? rows(
        $pdo,
        "SELECT matched_level, assignment_status, COUNT(*) AS total
         FROM vw_workforce_match_detail
         WHERE source_id = :source_id
         GROUP BY matched_level, assignment_status
         ORDER BY total DESC",
        ['source_id' => $sourceId]
    )
    : [];

$byReview = $sourceId > 0
    ? rows(
        $pdo,
        "SELECT review_status, COUNT(*) AS total
         FROM vw_workforce_match_detail
         WHERE source_id = :source_id
         GROUP BY review_status
         ORDER BY total DESC",
        ['source_id' => $sourceId]
    )
    : [];

render_header('Reportes', 'reportes', 'Descargas y consultas preparadas para supervisión.');
render_breadcrumbs([
    ['label' => 'Inicio', 'href' => 'index.php'],
    ['label' => 'Reportes'],
]);
?>

<div class="page-intro">
    <div>
        <h2>Centro de reportes</h2>
        <p>Seleccione el reporte que necesita. Las descargas respetan los datos de la fuente actualmente seleccionada.</p>
    </div>
    <a class="button" href="index.php?source_id=<?= h($sourceId) ?>">← Volver al inicio</a>
</div>

<?php if (!$source): ?>
    <?php render_empty_state('No hay una fuente disponible', 'Cargue una fuente de pie de fuerza antes de generar reportes.'); ?>
<?php else: ?>
    <div class="kpi-grid">
        <article class="kpi-card card">
            <span class="kpi-label">Total de personal</span>
            <strong class="kpi-value"><?= h(format_number($summary['total_personas'] ?? 0)) ?></strong>
            <span class="kpi-note">Registros incluidos en la fuente.</span>
        </article>
        <article class="kpi-card card success">
            <span class="kpi-label">Ubicación completa</span>
            <strong class="kpi-value"><?= h(format_number($summary['asignados_completos'] ?? 0)) ?></strong>
            <span class="kpi-note">Unidad y nivel completamente identificados.</span>
        </article>
        <article class="kpi-card card info">
            <span class="kpi-label">Unidad confirmada</span>
            <strong class="kpi-value"><?= h(format_number($summary['asignados_parciales'] ?? 0)) ?></strong>
            <span class="kpi-note">Con detalle interno o territorial.</span>
        </article>
        <article class="kpi-card card">
            <span class="kpi-label">Pendientes</span>
            <strong class="kpi-value"><?= h(format_number($summary['pendientes_revision'] ?? 0)) ?></strong>
            <span class="kpi-note">Registros que requieren revisión.</span>
        </article>
    </div>

    <section class="panel">
        <div class="panel-header">
            <div>
                <h2>Descargas rápidas</h2>
                <p>Los archivos se generan en formato CSV y pueden abrirse en Excel.</p>
            </div>
        </div>
        <div class="action-grid">
            <a class="action-card card" href="pie_fuerza.php?source_id=<?= h($sourceId) ?>&descargar=csv">
                <span class="action-icon">T</span>
                <h3>Todo el personal</h3>
                <p>Listado completo con unidad funcional, zona territorial y dependencia interna.</p>
                <span class="action-link">Descargar CSV →</span>
            </a>
            <a class="action-card card" href="pie_fuerza.php?source_id=<?= h($sourceId) ?>&status=asignado_completo&descargar=csv">
                <span class="action-icon">C</span>
                <h3>Ubicación completa</h3>
                <p>Funcionarios con unidad y nivel organizacional completamente identificados.</p>
                <span class="action-link">Descargar CSV →</span>
            </a>
            <a class="action-card card" href="pie_fuerza.php?source_id=<?= h($sourceId) ?>&status=asignado_parcial&descargar=csv">
                <span class="action-icon">U</span>
                <h3>Unidad confirmada</h3>
                <p>Funcionarios con dirección o unidad principal validada y detalle adicional.</p>
                <span class="action-link">Descargar CSV →</span>
            </a>
            <a class="action-card card" href="unidades.php?grupo=direcciones&source_id=<?= h($sourceId) ?>">
                <span class="action-icon">D</span>
                <h3>Resumen por dirección</h3>
                <p>Abra cada dirección y consulte su personal y dependencias.</p>
                <span class="action-link">Abrir consulta →</span>
            </a>
            <a class="action-card card" href="unidades.php?grupo=zonas&source_id=<?= h($sourceId) ?>">
                <span class="action-icon">Z</span>
                <h3>Resumen por zona</h3>
                <p>Revise personal directo y referencias territoriales por zona policial.</p>
                <span class="action-link">Abrir consulta →</span>
            </a>
            <a class="action-card card" href="unidades.php?grupo=servicios&source_id=<?= h($sourceId) ?>">
                <span class="action-icon">S</span>
                <h3>Resumen por servicio</h3>
                <p>Consulte los servicios policiales especializados.</p>
                <span class="action-link">Abrir consulta →</span>
            </a>
        </div>
    </section>

    <div class="two-column">
        <section class="panel">
            <div class="panel-header">
                <div>
                    <h2>Distribución por nivel</h2>
                    <p>Indica el nivel organizacional confirmado y el tipo de ubicación.</p>
                </div>
            </div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Nivel</th><th>Estado</th><th>Total</th><th>Consulta</th></tr></thead>
                    <tbody>
                    <?php foreach ($byLevel as $item): ?>
                        <tr>
                            <td><?= h(level_label($item['matched_level'])) ?></td>
                            <td><span class="badge <?= h(assignment_class($item['assignment_status'])) ?>"><?= h(assignment_label($item['assignment_status'])) ?></span></td>
                            <td><?= h(format_number($item['total'])) ?></td>
                            <td><a href="pie_fuerza.php?source_id=<?= h($sourceId) ?>&level=<?= h($item['matched_level']) ?>&status=<?= h($item['assignment_status']) ?>">Ver personal</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="panel">
            <div class="panel-header">
                <div>
                    <h2>Estado de validación</h2>
                    <p>Control general de los registros de la fuente.</p>
                </div>
            </div>
            <div class="unit-list">
                <?php foreach ($byReview as $item): ?>
                    <article class="unit-card card">
                        <div>
                            <h3><?= h(review_label($item['review_status'])) ?></h3>
                            <p>Estado interno: <?= h($item['review_status'] ?: 'sin estado') ?></p>
                        </div>
                        <div class="unit-count">
                            <strong><?= h(format_number($item['total'])) ?></strong>
                            <span>registros</span>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    </div>

    <section class="notice info">
        <strong>Fuente del reporte:</strong> <?= h($source['document_name']) ?><?= !empty($source['document_date']) ? ' — ' . h($source['document_date']) : '' ?>.
    </section>
<?php endif; ?>

<?php render_footer(); ?>
