<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$hasWorkforce = workforce_is_available($pdo);
$source = $hasWorkforce ? current_workforce_source($pdo, (int)($_GET['source_id'] ?? 0)) : [];
$sourceId = (int)($source['id'] ?? 0);

$summary = $sourceId > 0
    ? one($pdo, 'SELECT * FROM vw_workforce_summary WHERE source_id = :source_id', ['source_id' => $sourceId])
    : [];

$validation = $sourceId > 0
    ? one(
        $pdo,
        "SELECT
            SUM(m.review_status = 'aprobado') AS aprobados,
            SUM(COALESCE(m.review_status, '') <> 'aprobado') AS pendientes,
            SUM(m.territorial_zone_unit_id IS NOT NULL) AS con_zona,
            SUM(NULLIF(TRIM(COALESCE(m.internal_detail, '')), '') IS NOT NULL) AS con_detalle
         FROM workforce_personnel_staging p
         LEFT JOIN workforce_unit_matches m ON m.personnel_staging_id = p.id
         WHERE p.source_id = :source_id
           AND p.import_status = 'importado'",
        ['source_id' => $sourceId]
    )
    : [];

$catalogCounts = one(
    $pdo,
    "SELECT
        SUM(status = 'active' AND lifecycle_status = 'vigente' AND code LIKE 'DN-%') AS direcciones,
        SUM(status = 'active' AND lifecycle_status = 'vigente' AND code LIKE 'ZP-%') AS zonas,
        SUM(status = 'active' AND lifecycle_status = 'vigente' AND code LIKE 'SP-%') AS servicios,
        SUM(status = 'active' AND lifecycle_status = 'vigente') AS unidades_vigentes
     FROM organizational_units"
);

$topUnits = $sourceId > 0
    ? rows(
        $pdo,
        "SELECT
            d.matched_unit_id,
            COALESCE(d.matched_unit_name, 'Sin unidad funcional') AS unidad,
            COUNT(*) AS total
         FROM vw_workforce_match_detail d
         WHERE d.source_id = :source_id
         GROUP BY d.matched_unit_id, d.matched_unit_name
         ORDER BY total DESC, unidad
         LIMIT 8",
        ['source_id' => $sourceId]
    )
    : [];

$topZones = $sourceId > 0
    ? rows(
        $pdo,
        "SELECT
            d.territorial_zone_unit_id,
            COALESCE(d.territorial_zone_name, 'Sin referencia territorial') AS zona,
            COUNT(*) AS total
         FROM vw_workforce_match_detail d
         WHERE d.source_id = :source_id
         GROUP BY d.territorial_zone_unit_id, d.territorial_zone_name
         ORDER BY total DESC, zona
         LIMIT 8",
        ['source_id' => $sourceId]
    )
    : [];

$totalPeople = (int)($summary['total_personas'] ?? 0);
$maxUnit = max(array_map(static fn (array $row): int => (int)$row['total'], $topUnits) ?: [1]);
$maxZone = max(array_map(static fn (array $row): int => (int)$row['total'], $topZones) ?: [1]);

render_header(
    'Panel general',
    'inicio',
    'Resumen sencillo para consultar el personal y su ubicación institucional.'
);
?>

<?php if (!$hasWorkforce): ?>
    <?php render_empty_state(
        'El módulo de pie de fuerza todavía no está instalado',
        'Ejecute el archivo database/pie_fuerza_20260626.sql en la base de datos local.',
        '../database/pie_fuerza_20260626.sql',
        'Ver referencia del módulo'
    ); ?>
<?php elseif (!$source): ?>
    <?php render_empty_state(
        'No hay una fuente de personal cargada',
        'Cuando se importe un listado de pie de fuerza, esta pantalla mostrará el resumen y los accesos de consulta.'
    ); ?>
<?php else: ?>
    <section class="search-hero card">
        <h2>¿A quién o qué dependencia desea consultar?</h2>
        <p>Escriba un nombre, apellido, número de posición, rango, dirección, zona o dependencia.</p>
        <form class="search-bar" action="pie_fuerza.php" method="get">
            <input type="hidden" name="source_id" value="<?= h($sourceId) ?>">
            <input
                type="search"
                name="buscar"
                placeholder="Ejemplo: Juan Pérez, 17830, Telemática o Chiriquí"
                autocomplete="off"
                aria-label="Buscar personal o dependencia"
            >
            <button class="button primary" type="submit">Buscar</button>
        </form>
    </section>

    <div class="kpi-grid">
        <article class="kpi-card card">
            <span class="kpi-label">Personal registrado</span>
            <strong class="kpi-value"><?= h(format_number($totalPeople)) ?></strong>
            <span class="kpi-note">Funcionarios incluidos en la fuente seleccionada.</span>
        </article>
        <article class="kpi-card card success">
            <span class="kpi-label">Ubicación completa</span>
            <strong class="kpi-value"><?= h(format_number($summary['asignados_completos'] ?? 0)) ?></strong>
            <span class="kpi-note">Unidad y nivel organizacional identificados.</span>
        </article>
        <article class="kpi-card card info">
            <span class="kpi-label">Unidad confirmada</span>
            <strong class="kpi-value"><?= h(format_number($summary['asignados_parciales'] ?? 0)) ?></strong>
            <span class="kpi-note">La dirección o unidad principal está validada y conserva su detalle interno.</span>
        </article>
        <article class="kpi-card card <?= (int)($validation['pendientes'] ?? 0) > 0 ? 'warning' : 'success' ?>">
            <span class="kpi-label">Pendientes de validar</span>
            <strong class="kpi-value"><?= h(format_number($validation['pendientes'] ?? 0)) ?></strong>
            <span class="kpi-note"><?= (int)($validation['pendientes'] ?? 0) === 0 ? 'Todo el listado está validado.' : 'Registros que necesitan revisión.' ?></span>
        </article>
    </div>

    <section class="panel">
        <div class="panel-header">
            <div>
                <h2>¿Qué desea revisar?</h2>
                <p>Entre por el tipo de consulta. No necesita conocer términos técnicos.</p>
            </div>
        </div>
        <div class="action-grid">
            <a class="action-card card" href="pie_fuerza.php?source_id=<?= h($sourceId) ?>">
                <span class="action-icon">P</span>
                <h3>Buscar personal</h3>
                <p>Consulte funcionarios por nombre, posición, rango o lugar de trabajo.</p>
                <span class="action-link">Abrir listado →</span>
            </a>
            <a class="action-card card" href="unidades.php?grupo=direcciones&source_id=<?= h($sourceId) ?>">
                <span class="action-icon">D</span>
                <h3>Direcciones nacionales</h3>
                <p>Vea el personal asignado a cada dirección y sus dependencias internas.</p>
                <span class="action-link"><?= h(format_number($catalogCounts['direcciones'] ?? 0)) ?> direcciones →</span>
            </a>
            <a class="action-card card" href="unidades.php?grupo=zonas&source_id=<?= h($sourceId) ?>">
                <span class="action-icon">Z</span>
                <h3>Zonas policiales</h3>
                <p>Revise la distribución territorial por zona, área y unidad.</p>
                <span class="action-link"><?= h(format_number($catalogCounts['zonas'] ?? 0)) ?> zonas →</span>
            </a>
            <a class="action-card card" href="unidades.php?grupo=servicios&source_id=<?= h($sourceId) ?>">
                <span class="action-icon">S</span>
                <h3>Servicios policiales</h3>
                <p>Consulte los servicios especializados y su cantidad de personal.</p>
                <span class="action-link"><?= h(format_number($catalogCounts['servicios'] ?? 0)) ?> servicios →</span>
            </a>
            <a class="action-card card" href="unidades.php?grupo=todas&source_id=<?= h($sourceId) ?>">
                <span class="action-icon">E</span>
                <h3>Estructura institucional</h3>
                <p>Navegue la estructura vigente sin mezclarla con los nombres históricos.</p>
                <span class="action-link"><?= h(format_number($catalogCounts['unidades_vigentes'] ?? 0)) ?> unidades vigentes →</span>
            </a>
            <a class="action-card card" href="reportes.php?source_id=<?= h($sourceId) ?>">
                <span class="action-icon">R</span>
                <h3>Reportes</h3>
                <p>Descargue listados y abra consultas preparadas para supervisión.</p>
                <span class="action-link">Ver reportes →</span>
            </a>
        </div>
    </section>

    <div class="two-column">
        <section class="panel">
            <div class="panel-header">
                <div>
                    <h2>Unidades con más personal</h2>
                    <p>El conteo corresponde a la unidad funcional principal.</p>
                </div>
                <a class="button soft" href="unidades.php?grupo=todas&source_id=<?= h($sourceId) ?>">Ver todas</a>
            </div>
            <div class="bar-list">
                <?php foreach ($topUnits as $item): ?>
                    <div class="bar-row">
                        <a class="bar-label" href="<?= h(query_url('pie_fuerza.php', ['source_id' => $sourceId, 'unit_id' => $item['matched_unit_id']])) ?>" title="<?= h($item['unidad']) ?>">
                            <?= h($item['unidad']) ?>
                        </a>
                        <div class="bar-track" aria-hidden="true">
                            <div class="bar-fill" style="width: <?= h((string)max(1, round(((int)$item['total'] / $maxUnit) * 100, 1))) ?>%"></div>
                        </div>
                        <span class="bar-value"><?= h(format_number($item['total'])) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="panel">
            <div class="panel-header">
                <div>
                    <h2>Referencias territoriales principales</h2>
                    <p>Muestra dónde presta servicio el personal cuando existe una zona asociada.</p>
                </div>
            </div>
            <div class="bar-list">
                <?php foreach ($topZones as $item): ?>
                    <div class="bar-row">
                        <a class="bar-label" href="<?= h(query_url('pie_fuerza.php', ['source_id' => $sourceId, 'zone_id' => $item['territorial_zone_unit_id']])) ?>" title="<?= h($item['zona']) ?>">
                            <?= h($item['zona']) ?>
                        </a>
                        <div class="bar-track" aria-hidden="true">
                            <div class="bar-fill" style="width: <?= h((string)max(1, round(((int)$item['total'] / $maxZone) * 100, 1))) ?>%"></div>
                        </div>
                        <span class="bar-value"><?= h(format_number($item['total'])) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    </div>

    <section class="notice info">
        <strong>Fuente consultada:</strong>
        <?= h($source['document_name']) ?>
        <?php if (!empty($source['document_date'])): ?> — <?= h($source['document_date']) ?><?php endif; ?>.
        El archivo original se mantiene privado; este panel muestra únicamente la información procesada en la base local.
    </section>
<?php endif; ?>

<?php render_footer(); ?>
