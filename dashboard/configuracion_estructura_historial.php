<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$ready = table_exists($pdo, 'vw_structure_configuration_history');
$search = trim((string)($_GET['q'] ?? ''));
$category = trim((string)($_GET['categoria'] ?? ''));

$events = [];
if ($ready) {
    $where = [];
    $params = [];

    if ($search !== '') {
        $where[] = '(target_name LIKE :search OR action_name LIKE :search OR notes LIKE :search OR created_by LIKE :search)';
        $params['search'] = '%' . $search . '%';
    }
    if (in_array($category, ['estructura', 'regla_jerarquia'], true)) {
        $where[] = 'category = :category';
        $params['category'] = $category;
    }

    $events = rows(
        $pdo,
        'SELECT * FROM vw_structure_configuration_history'
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
        . ' ORDER BY event_at DESC LIMIT 500',
        $params
    );
}

render_header(
    'Historial de configuración',
    'configuracion_historial',
    'Consulta los cambios realizados sobre la estructura y sus reglas.'
);
render_breadcrumbs([
    ['label' => 'Inicio', 'href' => 'index.php'],
    ['label' => 'Configuración del sistema', 'href' => 'configuracion.php'],
    ['label' => 'Historial', 'href' => ''],
]);
?>

<nav class="configuration-tabs" aria-label="Configuración de estructura">
    <a href="configuracion.php">Resumen</a>
    <a href="estructura_admin.php">Estructura organizacional</a>
    <a href="configuracion_estructura_reglas.php">Reglas de jerarquía</a>
    <a href="configuracion_estructura_historial.php" class="active">Historial</a>
</nav>

<?php if (!$ready): ?>
    <?php render_empty_state(
        'Falta instalar el historial centralizado',
        'Ejecute database/estructura_configuracion_modulo.sql sobre estructura_zonas_test.'
    ); ?>
    <?php render_footer(); return; ?>
<?php endif; ?>

<section class="panel">
    <div class="panel-header">
        <div>
            <h2>Buscar cambios</h2>
            <p>Puede localizar una unidad, una regla, una acción o un responsable.</p>
        </div>
    </div>
    <form class="search-bar" method="get">
        <input type="search" name="q" value="<?= h($search) ?>" placeholder="Ejemplo: Chiriquí, renombre, movimiento o administrador">
        <select name="categoria">
            <option value="">Todos los cambios</option>
            <option value="estructura" <?= $category === 'estructura' ? 'selected' : '' ?>>Estructura institucional</option>
            <option value="regla_jerarquia" <?= $category === 'regla_jerarquia' ? 'selected' : '' ?>>Reglas de jerarquía</option>
        </select>
        <button class="button primary" type="submit">Buscar</button>
        <?php if ($search !== '' || $category !== ''): ?><a class="button" href="configuracion_estructura_historial.php">Limpiar</a><?php endif; ?>
    </form>
</section>

<section class="panel">
    <div class="panel-header">
        <div>
            <h2>Cambios registrados</h2>
            <p>Se muestran hasta 500 eventos, del más reciente al más antiguo.</p>
        </div>
        <span class="badge info"><?= h(format_number(count($events))) ?> eventos</span>
    </div>

    <?php if (!$events): ?>
        <div class="notice info">No se encontraron cambios con esos criterios.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Fecha</th><th>Categoría</th><th>Elemento</th><th>Acción</th><th>Detalle</th><th>Responsable</th></tr></thead>
                <tbody>
                <?php foreach ($events as $event): ?>
                    <tr>
                        <td><?= h($event['event_at']) ?></td>
                        <td><span class="badge <?= (string)$event['category'] === 'estructura' ? 'info' : 'success' ?>"><?= h((string)$event['category'] === 'estructura' ? 'Estructura' : 'Regla') ?></span></td>
                        <td><span class="person-name"><?= h($event['target_name'] ?: 'Sin referencia') ?></span></td>
                        <td><?= h(str_replace('_', ' ', (string)$event['action_name'])) ?></td>
                        <td><?= h($event['notes'] ?: 'Sin observación') ?></td>
                        <td><?= h($event['created_by'] ?: 'No indicado') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php render_footer(); ?>
