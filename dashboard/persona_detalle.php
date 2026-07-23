<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$personId = (int)($_GET['id'] ?? 0);
if ($personId <= 0 || !workforce_is_available($pdo)) {
    http_response_code(400);
    render_header('Ficha del funcionario', 'personal', 'Detalle de ubicación institucional.');
    render_empty_state('No se recibió un funcionario válido', 'Regrese al listado y seleccione “Ver ficha”.', 'pie_fuerza.php', 'Volver al personal');
    render_footer();
    exit;
}

$person = one(
    $pdo,
    'SELECT * FROM vw_workforce_match_detail WHERE personnel_staging_id = :id LIMIT 1',
    ['id' => $personId]
);

if (!$person) {
    http_response_code(404);
    render_header('Ficha del funcionario', 'personal', 'Detalle de ubicación institucional.');
    render_empty_state('No se encontró el funcionario', 'El registro solicitado no está disponible en la fuente actual.', 'pie_fuerza.php', 'Volver al personal');
    render_footer();
    exit;
}

$nameParts = preg_split('/\s+/', trim((string)$person['full_name'])) ?: [];
$initials = '';
foreach (array_slice($nameParts, 0, 2) as $part) {
    $initials .= function_exists('mb_substr') ? mb_substr($part, 0, 1, 'UTF-8') : substr($part, 0, 1);
}
$initials = strtoupper($initials ?: 'PF');

render_header('Ficha del funcionario', 'personal', 'Información presentada en lenguaje sencillo.');
render_breadcrumbs([
    ['label' => 'Inicio', 'href' => 'index.php'],
    ['label' => 'Personal', 'href' => 'pie_fuerza.php?source_id=' . (int)$person['source_id']],
    ['label' => $person['full_name']],
]);
?>

<div class="page-intro">
    <div>
        <h2>Información del funcionario</h2>
        <p>Esta ficha separa la dependencia a la que pertenece, el lugar donde presta servicio y el detalle interno registrado.</p>
    </div>
    <div class="button-row">
        <a class="button" href="pie_fuerza.php?source_id=<?= h($person['source_id']) ?>">← Volver al listado</a>
        <a class="button soft" href="pie_fuerza_revision.php?id=<?= h($personId) ?>">Corregir ubicación</a>
    </div>
</div>

<section class="identity-card card">
    <div class="avatar" aria-hidden="true"><?= h($initials) ?></div>
    <div>
        <h2><?= h($person['full_name']) ?></h2>
        <p><?= h($person['rank_text'] ?: 'Rango no indicado') ?> · Posición <?= h($person['position_number'] ?: 'sin número') ?></p>
        <span class="badge <?= h(assignment_class($person['assignment_status'])) ?>">
            <?= h(assignment_label($person['assignment_status'])) ?>
        </span>
        <span class="badge <?= $person['review_status'] === 'aprobado' ? 'success' : 'warning' ?>">
            <?= h(review_label($person['review_status'])) ?>
        </span>
    </div>
</section>

<div class="detail-grid">
    <article class="detail-card card">
        <span class="label">Unidad funcional</span>
        <div class="value"><?= h($person['matched_unit_name'] ?: 'Sin unidad confirmada') ?></div>
        <div class="description">Indica la dirección, servicio, zona o unidad a la que pertenece institucionalmente.</div>
    </article>

    <article class="detail-card card">
        <span class="label">Zona donde presta servicio</span>
        <div class="value"><?= h($person['territorial_zone_name'] ?: 'No aplica o no está indicada') ?></div>
        <div class="description">Puede ser distinta de la unidad funcional cuando el funcionario trabaja en otro territorio.</div>
    </article>

    <article class="detail-card card">
        <span class="label">Dependencia o sección</span>
        <div class="value"><?= h($person['internal_detail'] ?: 'Sin detalle interno adicional') ?></div>
        <div class="description">Conserva el departamento, sección, sede, comisión o centro específico.</div>
    </article>

    <article class="detail-card card">
        <span class="label">Ubicación registrada en la fuente</span>
        <div class="value"><?= h($person['location_original'] ?: 'No indicada') ?></div>
        <div class="description">Es el texto original recibido antes de organizarlo contra la estructura vigente.</div>
    </article>

    <article class="detail-card card">
        <span class="label">Nivel organizacional confirmado</span>
        <div class="value"><?= h(level_label($person['matched_level'])) ?></div>
        <div class="description"><?= h(assignment_help($person['assignment_status'])) ?></div>
    </article>

    <article class="detail-card card">
        <span class="label">Tipo de personal</span>
        <div class="value"><?= h($person['police_type_original'] ?: 'No indicado') ?></div>
        <div class="description">Clasificación que venía registrada en el documento de origen.</div>
    </article>
</div>

<?php if (!empty($person['review_notes'])): ?>
    <section class="panel">
        <div class="panel-header">
            <div>
                <h2>Observación de validación</h2>
                <p>Explicación registrada durante la clasificación.</p>
            </div>
        </div>
        <p><?= h($person['review_notes']) ?></p>
    </section>
<?php endif; ?>

<section class="panel">
    <div class="panel-header">
        <div>
            <h2>Resumen fácil de leer</h2>
            <p>Interpretación de la ubicación actual.</p>
        </div>
    </div>
    <div class="notice success">
        <strong><?= h($person['full_name']) ?></strong>
        pertenece funcionalmente a <strong><?= h($person['matched_unit_name'] ?: 'una unidad todavía no confirmada') ?></strong>.
        <?php if (!empty($person['territorial_zone_name'])): ?>
            Presta servicio con referencia territorial en <strong><?= h($person['territorial_zone_name']) ?></strong>.
        <?php endif; ?>
        <?php if (!empty($person['internal_detail'])): ?>
            Su dependencia o detalle registrado es <strong><?= h($person['internal_detail']) ?></strong>.
        <?php endif; ?>
    </div>

    <details class="advanced">
        <summary>Ver información técnica</summary>
        <dl class="technical-grid">
            <dt>ID del registro</dt><dd><?= h($person['personnel_staging_id']) ?></dd>
            <dt>ID de unidad funcional</dt><dd><?= h($person['matched_unit_id']) ?></dd>
            <dt>Código de unidad</dt><dd><?= h($person['matched_unit_code']) ?></dd>
            <dt>ID de zona territorial</dt><dd><?= h($person['territorial_zone_unit_id']) ?></dd>
            <dt>Estado interno</dt><dd><?= h($person['assignment_status']) ?></dd>
            <dt>Método de clasificación</dt><dd><?= h($person['match_method']) ?></dd>
            <dt>Confianza</dt><dd><?= h($person['confidence_level']) ?></dd>
            <dt>Nivel pendiente</dt><dd><?= h($person['pending_level']) ?></dd>
            <dt>Revisado por</dt><dd><?= h($person['reviewed_by']) ?></dd>
            <dt>Fecha de revisión</dt><dd><?= h($person['reviewed_at']) ?></dd>
        </dl>
    </details>
</section>

<?php render_footer(); ?>
