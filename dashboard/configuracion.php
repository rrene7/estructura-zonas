<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$structureReady = table_exists($pdo, 'vw_structure_admin_units');
$rulesReady = table_exists($pdo, 'vw_structure_type_rules');
$historyReady = table_exists($pdo, 'vw_structure_configuration_history');

$unitStats = $structureReady
    ? one(
        $pdo,
        "SELECT
            COUNT(*) AS total_units,
            SUM(CASE WHEN status = 'active' AND lifecycle_status = 'vigente' THEN 1 ELSE 0 END) AS active_units,
            SUM(CASE WHEN is_protected = 1 THEN 1 ELSE 0 END) AS protected_units,
            SUM(CASE WHEN parent_id IS NULL THEN 1 ELSE 0 END) AS root_units
         FROM vw_structure_admin_units"
    )
    : [];

$ruleStats = $rulesReady
    ? one(
        $pdo,
        "SELECT
            COUNT(*) AS total_rules,
            SUM(CASE WHEN is_allowed = 1 THEN 1 ELSE 0 END) AS active_rules,
            SUM(CASE WHEN is_allowed = 0 THEN 1 ELSE 0 END) AS blocked_rules
         FROM vw_structure_type_rules"
    )
    : [];

$historyStats = $historyReady
    ? one(
        $pdo,
        "SELECT
            COUNT(*) AS total_events,
            SUM(CASE WHEN category = 'estructura' THEN 1 ELSE 0 END) AS structure_events,
            SUM(CASE WHEN category = 'regla_jerarquia' THEN 1 ELSE 0 END) AS rule_events
         FROM vw_structure_configuration_history"
    )
    : [];

render_header(
    'Configuración del sistema',
    'configuracion',
    'Administra la estructura institucional, sus reglas y el historial de cambios.'
);
render_breadcrumbs([
    ['label' => 'Inicio', 'href' => 'index.php'],
    ['label' => 'Configuración del sistema', 'href' => ''],
]);
?>

<div class="notice info configuration-banner">
    <strong>Módulo de configuración.</strong>
    Los cambios se aplican mediante procedimientos de MySQL y mantienen los identificadores históricos.
</div>

<section class="configuration-grid">
    <a class="configuration-card card" href="estructura_admin.php">
        <span class="configuration-card-icon" aria-hidden="true">⌘</span>
        <div>
            <span class="configuration-card-kicker">Estructura institucional</span>
            <h2>Zonas, direcciones y dependencias</h2>
            <p>Busca una unidad, corrige sus datos, agrega dependencias, mueve niveles o cambia su estado.</p>
        </div>
        <div class="configuration-card-stats">
            <span><strong><?= h(format_number($unitStats['active_units'] ?? 0)) ?></strong> activas</span>
            <span><strong><?= h(format_number($unitStats['protected_units'] ?? 0)) ?></strong> protegidas</span>
        </div>
        <span class="configuration-card-link">Abrir configuración</span>
    </a>

    <a class="configuration-card card" href="configuracion_estructura_reglas.php">
        <span class="configuration-card-icon" aria-hidden="true">⇄</span>
        <div>
            <span class="configuration-card-kicker">Reglas de jerarquía</span>
            <h2>Qué tipo puede depender de otro</h2>
            <p>Define, por ejemplo, qué unidades pueden crearse debajo de una zona, área, dirección o estación.</p>
        </div>
        <div class="configuration-card-stats">
            <span><strong><?= h(format_number($ruleStats['active_rules'] ?? 0)) ?></strong> permitidas</span>
            <span><strong><?= h(format_number($ruleStats['blocked_rules'] ?? 0)) ?></strong> bloqueadas</span>
        </div>
        <span class="configuration-card-link">Administrar reglas</span>
    </a>

    <a class="configuration-card card" href="configuracion_estructura_historial.php">
        <span class="configuration-card-icon" aria-hidden="true">↺</span>
        <div>
            <span class="configuration-card-kicker">Auditoría</span>
            <h2>Historial de configuración</h2>
            <p>Consulta cambios de nombres, movimientos, altas, bajas y modificaciones de reglas jerárquicas.</p>
        </div>
        <div class="configuration-card-stats">
            <span><strong><?= h(format_number($historyStats['structure_events'] ?? 0)) ?></strong> de estructura</span>
            <span><strong><?= h(format_number($historyStats['rule_events'] ?? 0)) ?></strong> de reglas</span>
        </div>
        <span class="configuration-card-link">Ver historial</span>
    </a>
</section>

<section class="panel">
    <div class="panel-header">
        <div>
            <h2>Estado del módulo</h2>
            <p>Verificación rápida de los componentes instalados en la base de datos.</p>
        </div>
    </div>
    <div class="configuration-status-grid">
        <div class="configuration-status-item">
            <span class="status-dot <?= $structureReady ? '' : 'status-dot-warning' ?>" aria-hidden="true"></span>
            <div><strong>Estructura editable</strong><span><?= $structureReady ? 'Disponible' : 'Falta instalar estructura_admin_db.sql' ?></span></div>
        </div>
        <div class="configuration-status-item">
            <span class="status-dot <?= $rulesReady ? '' : 'status-dot-warning' ?>" aria-hidden="true"></span>
            <div><strong>Reglas jerárquicas</strong><span><?= $rulesReady ? 'Disponible' : 'Falta instalar estructura_configuracion_modulo.sql' ?></span></div>
        </div>
        <div class="configuration-status-item">
            <span class="status-dot <?= $historyReady ? '' : 'status-dot-warning' ?>" aria-hidden="true"></span>
            <div><strong>Historial centralizado</strong><span><?= $historyReady ? 'Disponible' : 'Pendiente de instalación' ?></span></div>
        </div>
    </div>
</section>

<?php render_footer(); ?>
