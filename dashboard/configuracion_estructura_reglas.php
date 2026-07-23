<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (empty($_SESSION['estructura_config_csrf'])) {
    $_SESSION['estructura_config_csrf'] = bin2hex(random_bytes(24));
}

$csrfToken = (string)$_SESSION['estructura_config_csrf'];
$errorMessage = '';
$successMessage = '';
$ready = table_exists($pdo, 'vw_structure_type_rules');

function config_rule_call(PDO $pdo, array $parameters): array
{
    $statement = $pdo->prepare('CALL sp_structure_set_type_rule(?, ?, ?, ?, ?)');
    $statement->execute(array_values($parameters));
    $result = $statement->fetchAll();
    while ($statement->nextRowset()) {
        // Liberar todos los resultados producidos por MySQL.
    }
    $statement->closeCursor();
    return $result;
}

function config_rule_error(Throwable $error): string
{
    $message = trim($error->getMessage());
    if (preg_match('/1644\s+(.+)$/s', $message, $matches) === 1) {
        return trim($matches[1]);
    }
    if (preg_match('/SQLSTATE\[45000\].*?:\s*(.+)$/s', $message, $matches) === 1) {
        return trim($matches[1]);
    }
    return $message !== '' ? $message : 'No fue posible guardar la regla.';
}

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($csrfToken, $submittedToken)) {
        $errorMessage = 'La sesión del formulario venció. Recargue la página.';
    } else {
        try {
            $result = config_rule_call($pdo, [
                max(0, (int)($_POST['parent_type_id'] ?? 0)),
                max(0, (int)($_POST['child_type_id'] ?? 0)),
                (int)($_POST['is_allowed'] ?? 0) === 1 ? 1 : 0,
                trim((string)($_POST['notes'] ?? '')),
                'administrador_local',
            ]);
            $successMessage = (string)($result[0]['message'] ?? 'La regla fue guardada correctamente.');
        } catch (Throwable $error) {
            $errorMessage = config_rule_error($error);
        }
    }
}

$unitTypes = rows($pdo, 'SELECT id, name, description FROM unit_types ORDER BY name');
$filter = trim((string)($_GET['q'] ?? ''));
$statusFilter = trim((string)($_GET['estado'] ?? ''));

$rules = [];
if ($ready) {
    $where = [];
    $params = [];
    if ($filter !== '') {
        $where[] = '(parent_type_name LIKE :search OR child_type_name LIKE :search OR notes LIKE :search)';
        $params['search'] = '%' . $filter . '%';
    }
    if ($statusFilter === 'permitida') {
        $where[] = 'is_allowed = 1';
    } elseif ($statusFilter === 'bloqueada') {
        $where[] = 'is_allowed = 0';
    }

    $rules = rows(
        $pdo,
        'SELECT * FROM vw_structure_type_rules'
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
        . ' ORDER BY parent_type_name, child_type_name',
        $params
    );
}

render_header(
    'Reglas de jerarquía',
    'configuracion_reglas',
    'Configura qué tipos de unidades pueden depender de otros tipos.'
);
render_breadcrumbs([
    ['label' => 'Inicio', 'href' => 'index.php'],
    ['label' => 'Configuración del sistema', 'href' => 'configuracion.php'],
    ['label' => 'Reglas de jerarquía', 'href' => ''],
]);
?>

<nav class="configuration-tabs" aria-label="Configuración de estructura">
    <a href="configuracion.php">Resumen</a>
    <a href="estructura_admin.php">Estructura organizacional</a>
    <a href="configuracion_estructura_reglas.php" class="active">Reglas de jerarquía</a>
    <a href="configuracion_estructura_historial.php">Historial</a>
</nav>

<?php if (!$ready): ?>
    <?php render_empty_state(
        'Falta instalar las reglas de configuración',
        'Ejecute database/estructura_configuracion_modulo.sql sobre estructura_zonas_test.'
    ); ?>
    <?php render_footer(); return; ?>
<?php endif; ?>

<?php if ($errorMessage !== ''): ?><div class="notice danger"><?= h($errorMessage) ?></div><?php endif; ?>
<?php if ($successMessage !== ''): ?><div class="notice success"><?= h($successMessage) ?></div><?php endif; ?>

<div class="two-column configuration-rule-layout">
    <section class="panel configuration-rule-form">
        <div class="panel-header">
            <div>
                <h2>Crear o modificar una regla</h2>
                <p>La base de datos utilizará esta regla al agregar, editar o mover unidades.</p>
            </div>
        </div>
        <form method="post" class="admin-form-grid">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
            <div class="field admin-span-2">
                <label>Tipo de unidad superior</label>
                <select name="parent_type_id" required>
                    <option value="">Seleccione</option>
                    <?php foreach ($unitTypes as $type): ?>
                        <option value="<?= h($type['id']) ?>"><?= h(str_replace('_', ' ', (string)$type['name'])) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field admin-span-2">
                <label>Tipo de unidad subordinada</label>
                <select name="child_type_id" required>
                    <option value="">Seleccione</option>
                    <?php foreach ($unitTypes as $type): ?>
                        <option value="<?= h($type['id']) ?>"><?= h(str_replace('_', ' ', (string)$type['name'])) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Resultado</label>
                <select name="is_allowed" required>
                    <option value="1">Permitir relación</option>
                    <option value="0">Bloquear relación</option>
                </select>
            </div>
            <div class="field">
                <label>Motivo u observación</label>
                <input name="notes" placeholder="Ejemplo: estructura institucional aprobada">
            </div>
            <div class="admin-span-2">
                <button class="button primary" type="submit">Guardar regla</button>
            </div>
        </form>
    </section>

    <section class="panel">
        <div class="panel-header">
            <div>
                <h2>Cómo se aplican</h2>
                <p>Las reglas se consultan directamente desde MySQL.</p>
            </div>
        </div>
        <div class="configuration-help-list">
            <div><span>1</span><p>Al agregar una dependencia, solo aparecen los tipos permitidos.</p></div>
            <div><span>2</span><p>Al mover una unidad, se excluyen destinos incompatibles.</p></div>
            <div><span>3</span><p>Las modificaciones quedan registradas en el historial.</p></div>
        </div>
    </section>
</div>

<section class="panel">
    <div class="panel-header">
        <div>
            <h2>Reglas configuradas</h2>
            <p><?= h(format_number(count($rules))) ?> relaciones encontradas.</p>
        </div>
    </div>
    <form class="search-bar" method="get">
        <input type="search" name="q" value="<?= h($filter) ?>" placeholder="Buscar tipo superior, subordinado u observación">
        <select name="estado">
            <option value="">Todos los estados</option>
            <option value="permitida" <?= $statusFilter === 'permitida' ? 'selected' : '' ?>>Permitidas</option>
            <option value="bloqueada" <?= $statusFilter === 'bloqueada' ? 'selected' : '' ?>>Bloqueadas</option>
        </select>
        <button class="button" type="submit">Filtrar</button>
        <?php if ($filter !== '' || $statusFilter !== ''): ?><a class="button" href="configuracion_estructura_reglas.php">Limpiar</a><?php endif; ?>
    </form>

    <?php if (!$rules): ?>
        <div class="notice info">No se encontraron reglas con esos filtros.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Unidad superior</th><th>Puede contener</th><th>Estado</th><th>Observación</th><th>Actualizada</th></tr></thead>
                <tbody>
                <?php foreach ($rules as $rule): ?>
                    <tr>
                        <td><span class="person-name"><?= h(str_replace('_', ' ', (string)$rule['parent_type_name'])) ?></span></td>
                        <td><?= h(str_replace('_', ' ', (string)$rule['child_type_name'])) ?></td>
                        <td><span class="badge <?= (int)$rule['is_allowed'] === 1 ? 'success' : 'warning' ?>"><?= h($rule['status_label']) ?></span></td>
                        <td><?= h($rule['notes'] ?: 'Sin observación') ?></td>
                        <td><?= h($rule['updated_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php render_footer(); ?>
