<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['estructura_admin_csrf'])) {
    $_SESSION['estructura_admin_csrf'] = bin2hex(random_bytes(24));
}

$csrfToken = (string)$_SESSION['estructura_admin_csrf'];
$selectedId = max(0, (int)($_GET['id'] ?? $_POST['unit_id'] ?? $_POST['parent_id'] ?? 0));
$search = trim((string)($_GET['q'] ?? ''));
$errorMessage = '';

function admin_routine_exists(PDO $pdo, string $routineName): bool
{
    $statement = $pdo->prepare(
        "SELECT COUNT(*) AS total
         FROM information_schema.ROUTINES
         WHERE ROUTINE_SCHEMA = DATABASE()
           AND ROUTINE_TYPE = 'PROCEDURE'
           AND ROUTINE_NAME = :routine_name"
    );
    $statement->execute(['routine_name' => $routineName]);
    return (int)($statement->fetch()['total'] ?? 0) > 0;
}

function admin_database_ready(PDO $pdo): bool
{
    $requiredViews = [
        'vw_structure_admin_units',
        'vw_structure_admin_impact',
        'vw_structure_admin_history',
    ];
    $requiredProcedures = [
        'sp_structure_get_allowed_child_types',
        'sp_structure_get_valid_parents',
        'sp_structure_create_unit',
        'sp_structure_update_unit',
        'sp_structure_move_unit',
        'sp_structure_deactivate_unit',
        'sp_structure_reactivate_unit',
    ];

    foreach ($requiredViews as $viewName) {
        if (!table_exists($pdo, $viewName)) {
            return false;
        }
    }

    foreach ($requiredProcedures as $procedureName) {
        if (!admin_routine_exists($pdo, $procedureName)) {
            return false;
        }
    }

    return true;
}

function admin_call_procedure(PDO $pdo, string $procedureName, array $parameters = []): array
{
    $allowed = [
        'sp_structure_get_allowed_child_types',
        'sp_structure_get_valid_parents',
        'sp_structure_create_unit',
        'sp_structure_update_unit',
        'sp_structure_move_unit',
        'sp_structure_deactivate_unit',
        'sp_structure_reactivate_unit',
    ];

    if (!in_array($procedureName, $allowed, true)) {
        throw new InvalidArgumentException('El procedimiento solicitado no está permitido.');
    }

    $placeholders = implode(', ', array_fill(0, count($parameters), '?'));
    $statement = $pdo->prepare("CALL {$procedureName}({$placeholders})");
    $statement->execute(array_values($parameters));
    $result = $statement->fetchAll();

    while ($statement->nextRowset()) {
        // Consumir todos los resultados para liberar la conexión de MySQL.
    }
    $statement->closeCursor();

    return $result;
}

function admin_error_message(Throwable $error): string
{
    $message = trim($error->getMessage());

    if (preg_match('/1644\s+(.+)$/s', $message, $matches) === 1) {
        return trim($matches[1]);
    }

    if (preg_match('/SQLSTATE\[45000\].*?:\s*(.+)$/s', $message, $matches) === 1) {
        return trim($matches[1]);
    }

    return $message !== '' ? $message : 'No fue posible completar la operación.';
}

function admin_unit_is_protected(array $unit): bool
{
    return (int)($unit['is_protected'] ?? 0) === 1;
}

$databaseReady = admin_database_ready($pdo);

if (!$databaseReady) {
    render_header(
        'Administrar estructura',
        'estructura_admin',
        'Edición controlada de zonas, áreas, unidades y dependencias institucionales.'
    );
    render_breadcrumbs([
        ['label' => 'Inicio', 'href' => 'index.php'],
        ['label' => 'Administrar estructura', 'href' => ''],
    ]);
    render_empty_state(
        'Falta instalar la lógica de base de datos',
        'Ejecute database/estructura_admin_db.sql sobre la base estructura_zonas_test y vuelva a abrir esta pantalla.'
    );
    render_footer();
    return;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = (string)($_POST['csrf_token'] ?? '');

    if (!hash_equals($csrfToken, $submittedToken)) {
        $errorMessage = 'La sesión del formulario venció. Recargue la página e intente nuevamente.';
    } else {
        $action = trim((string)($_POST['action'] ?? ''));
        $unitId = max(0, (int)($_POST['unit_id'] ?? 0));
        $actor = 'administrador_local';

        try {
            if ($action === 'update') {
                $result = admin_call_procedure($pdo, 'sp_structure_update_unit', [
                    $unitId,
                    max(0, (int)($_POST['unit_type_id'] ?? 0)),
                    trim((string)($_POST['name'] ?? '')),
                    trim((string)($_POST['short_name'] ?? '')),
                    trim((string)($_POST['code'] ?? '')),
                    trim((string)($_POST['moi_code'] ?? '')),
                    trim((string)($_POST['notes'] ?? '')),
                    $actor,
                ]);

                $updatedId = (int)($result[0]['unit_id'] ?? $unitId);
                header('Location: estructura_admin.php?id=' . $updatedId . '&ok=actualizada');
                exit;
            }

            if ($action === 'create') {
                $parentId = max(0, (int)($_POST['parent_id'] ?? 0));
                $result = admin_call_procedure($pdo, 'sp_structure_create_unit', [
                    $parentId,
                    max(0, (int)($_POST['unit_type_id'] ?? 0)),
                    trim((string)($_POST['name'] ?? '')),
                    trim((string)($_POST['short_name'] ?? '')),
                    trim((string)($_POST['code'] ?? '')),
                    trim((string)($_POST['moi_code'] ?? '')),
                    trim((string)($_POST['notes'] ?? '')),
                    $actor,
                ]);

                $newUnitId = (int)($result[0]['unit_id'] ?? 0);
                if ($newUnitId <= 0) {
                    throw new RuntimeException('La base de datos no devolvió el identificador de la nueva unidad.');
                }

                header('Location: estructura_admin.php?id=' . $newUnitId . '&ok=creada');
                exit;
            }

            if ($action === 'move') {
                admin_call_procedure($pdo, 'sp_structure_move_unit', [
                    $unitId,
                    max(0, (int)($_POST['new_parent_id'] ?? 0)),
                    trim((string)($_POST['notes'] ?? '')),
                    $actor,
                ]);

                header('Location: estructura_admin.php?id=' . $unitId . '&ok=movida');
                exit;
            }

            if ($action === 'deactivate') {
                admin_call_procedure($pdo, 'sp_structure_deactivate_unit', [
                    $unitId,
                    trim((string)($_POST['notes'] ?? '')),
                    $actor,
                ]);

                header('Location: estructura_admin.php?id=' . $unitId . '&ok=desactivada');
                exit;
            }

            if ($action === 'reactivate') {
                admin_call_procedure($pdo, 'sp_structure_reactivate_unit', [
                    $unitId,
                    trim((string)($_POST['notes'] ?? '')),
                    $actor,
                ]);

                header('Location: estructura_admin.php?id=' . $unitId . '&ok=reactivada');
                exit;
            }

            throw new RuntimeException('La acción solicitada no está disponible.');
        } catch (Throwable $error) {
            $errorMessage = admin_error_message($error);
        }
    }
}

$unitTypes = rows($pdo, 'SELECT id, name, description FROM unit_types ORDER BY name');
$allCurrentUnits = rows(
    $pdo,
    "SELECT id, parent_id, name, short_name, code, level, status, lifecycle_status,
            structure_source, legacy_table, is_protected
     FROM vw_structure_admin_units
     ORDER BY name, id"
);

$selectedUnit = $selectedId > 0
    ? one(
        $pdo,
        'SELECT * FROM vw_structure_admin_units WHERE id = :id LIMIT 1',
        ['id' => $selectedId]
    )
    : [];

if ($selectedId > 0 && !$selectedUnit) {
    http_response_code(404);
    $selectedId = 0;
}

$children = $selectedId > 0
    ? rows(
        $pdo,
        "SELECT *
         FROM vw_structure_admin_units
         WHERE parent_id = :parent_id
         ORDER BY
            CASE WHEN status = 'active' AND lifecycle_status = 'vigente' THEN 1 ELSE 2 END,
            COALESCE(level, 99), name, id",
        ['parent_id' => $selectedId]
    )
    : [];

$impact = $selectedId > 0
    ? one(
        $pdo,
        'SELECT * FROM vw_structure_admin_impact WHERE unit_id = :unit_id LIMIT 1',
        ['unit_id' => $selectedId]
    )
    : [];

$history = $selectedId > 0
    ? rows(
        $pdo,
        "SELECT *
         FROM vw_structure_admin_history
         WHERE organizational_unit_id = :unit_id
         ORDER BY effective_from DESC, id DESC
         LIMIT 30",
        ['unit_id' => $selectedId]
    )
    : [];

$breadcrumbs = [];
if ($selectedUnit) {
    $lookup = [];
    foreach ($allCurrentUnits as $candidate) {
        $lookup[(int)$candidate['id']] = $candidate;
    }

    $current = $selectedUnit;
    $seen = [];
    while ($current) {
        $currentId = (int)$current['id'];
        if ($currentId <= 0 || isset($seen[$currentId])) {
            break;
        }

        $seen[$currentId] = true;
        array_unshift($breadcrumbs, [
            'label' => (string)$current['name'],
            'href' => $currentId === $selectedId ? '' : 'estructura_admin.php?id=' . $currentId,
        ]);

        $parentId = (int)($current['parent_id'] ?? 0);
        $current = $parentId > 0 ? ($lookup[$parentId] ?? []) : [];
    }
}

$parentOptions = [];
$allowedChildTypes = [];
if ($selectedUnit) {
    try {
        $parentOptions = admin_call_procedure($pdo, 'sp_structure_get_valid_parents', [$selectedId]);

        if (!admin_unit_is_protected($selectedUnit)
            && (string)$selectedUnit['status'] === 'active'
            && (string)$selectedUnit['lifecycle_status'] === 'vigente') {
            $allowedChildTypes = admin_call_procedure(
                $pdo,
                'sp_structure_get_allowed_child_types',
                [$selectedId]
            );
        }
    } catch (Throwable $error) {
        if ($errorMessage === '') {
            $errorMessage = admin_error_message($error);
        }
    }
}

$searchResults = [];
if ($search !== '') {
    $searchResults = rows(
        $pdo,
        "SELECT *
         FROM vw_structure_admin_units
         WHERE name LIKE :search
            OR short_name LIKE :search
            OR code LIKE :search
            OR moi_code LIKE :search
         ORDER BY
            CASE WHEN status = 'active' AND lifecycle_status = 'vigente' THEN 1 ELSE 2 END,
            name, id
         LIMIT 100",
        ['search' => '%' . $search . '%']
    );
}

$rootUnits = $selectedId === 0 && $search === ''
    ? rows(
        $pdo,
        "SELECT *
         FROM vw_structure_admin_units
         WHERE (parent_id IS NULL OR COALESCE(level, 99) <= 2)
           AND status = 'active'
           AND lifecycle_status = 'vigente'
           AND is_protected = 0
         ORDER BY COALESCE(level, 99), name, id
         LIMIT 200"
    )
    : [];

$successMessages = [
    'actualizada' => 'La unidad fue actualizada y el cambio quedó registrado por la base de datos.',
    'creada' => 'La nueva unidad fue creada dentro de la estructura institucional.',
    'movida' => 'La unidad y sus dependencias fueron movidas al nuevo nivel.',
    'desactivada' => 'La unidad fue desactivada. Los registros históricos se conservaron.',
    'reactivada' => 'La unidad fue reactivada correctamente.',
];
$successKey = trim((string)($_GET['ok'] ?? ''));

render_header(
    'Administrar estructura',
    'estructura_admin',
    'Edición controlada de zonas, áreas, unidades y dependencias institucionales.'
);
render_breadcrumbs(array_merge(
    [
        ['label' => 'Inicio', 'href' => 'index.php'],
        ['label' => 'Administrar estructura', 'href' => $selectedUnit ? 'estructura_admin.php' : ''],
    ],
    $breadcrumbs
));
?>

<div class="notice warning admin-mode-banner">
    <strong>Modo administración.</strong>
    Las reglas, validaciones, movimientos y el historial son controlados directamente por MySQL.
</div>

<?php if ($errorMessage !== ''): ?>
    <div class="notice danger"><?= h($errorMessage) ?></div>
<?php endif; ?>

<?php if (isset($successMessages[$successKey])): ?>
    <div class="notice success"><?= h($successMessages[$successKey]) ?></div>
<?php endif; ?>

<section class="panel">
    <div class="panel-header">
        <div>
            <h2>Buscar dentro de la estructura</h2>
            <p>Busque por nombre oficial, nombre corto, código institucional o código MOI.</p>
        </div>
        <a class="button" href="unidades.php?grupo=todas">Volver a la estructura de consulta</a>
    </div>
    <form class="search-bar" method="get" action="estructura_admin.php">
        <input type="search" name="q" value="<?= h($search) ?>" placeholder="Ejemplo: Chiriquí, Área A, Puerto Armuelles o Z04">
        <button class="button primary" type="submit">Buscar</button>
        <?php if ($search !== ''): ?><a class="button" href="estructura_admin.php">Limpiar</a><?php endif; ?>
    </form>
</section>

<?php if ($search !== ''): ?>
    <section class="panel">
        <div class="panel-header">
            <div>
                <h2>Resultados</h2>
                <p><?= h(format_number(count($searchResults))) ?> unidades encontradas.</p>
            </div>
        </div>
        <?php if (!$searchResults): ?>
            <div class="notice info">No se encontraron unidades con ese criterio.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Unidad</th><th>Tipo</th><th>Unidad superior</th><th>Estado</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($searchResults as $result): ?>
                        <tr>
                            <td>
                                <span class="person-name"><?= h($result['name']) ?></span>
                                <span class="subtext"><?= h($result['code'] ?: 'Sin código') ?><?= (int)$result['is_protected'] === 1 ? ' · Registro protegido' : '' ?></span>
                            </td>
                            <td><?= h(str_replace('_', ' ', (string)$result['unit_type_name'])) ?></td>
                            <td><?= h($result['parent_name'] ?: 'Nivel principal') ?></td>
                            <td><span class="badge <?= (string)$result['status'] === 'active' && (string)$result['lifecycle_status'] === 'vigente' ? 'success' : 'warning' ?>"><?= h($result['status_label']) ?></span></td>
                            <td><a class="button soft" href="estructura_admin.php?id=<?= h($result['id']) ?>">Administrar</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
<?php elseif (!$selectedUnit): ?>
    <section class="panel">
        <div class="panel-header">
            <div>
                <h2>Unidades principales</h2>
                <p>Seleccione una dirección, zona o servicio para navegar por sus niveles subordinados.</p>
            </div>
        </div>
        <div class="unit-list">
            <?php foreach ($rootUnits as $root): ?>
                <article class="unit-card card">
                    <div>
                        <h3><a href="estructura_admin.php?id=<?= h($root['id']) ?>"><?= h($root['name']) ?></a></h3>
                        <p><?= h(str_replace('_', ' ', (string)$root['unit_type_name'])) ?> · <?= h($root['code'] ?: 'Sin código') ?></p>
                    </div>
                    <div class="unit-count">
                        <strong><?= h(format_number($root['child_count'])) ?></strong>
                        <span>dependencias</span>
                        <a class="button soft" href="estructura_admin.php?id=<?= h($root['id']) ?>">Abrir</a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<?php if ($selectedUnit): ?>
    <?php $protected = admin_unit_is_protected($selectedUnit); ?>
    <div class="page-intro">
        <div>
            <h2><?= h($selectedUnit['name']) ?></h2>
            <p><?= h($selectedUnit['parent_name'] ?: 'Unidad de nivel principal') ?> · <?= h($selectedUnit['code'] ?: 'Sin código') ?></p>
        </div>
        <div class="button-row">
            <a class="button" href="unidad_detalle.php?id=<?= h($selectedId) ?>">Ver en modo consulta</a>
            <?php if (!empty($selectedUnit['parent_id'])): ?>
                <a class="button" href="estructura_admin.php?id=<?= h($selectedUnit['parent_id']) ?>">Subir un nivel</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="kpi-grid">
        <article class="kpi-card card">
            <span class="kpi-label">Personal relacionado</span>
            <strong class="kpi-value"><?= h(format_number($impact['workforce_total'] ?? 0)) ?></strong>
            <span class="kpi-note">Personal vinculado directamente a esta unidad.</span>
        </article>
        <article class="kpi-card card info">
            <span class="kpi-label">Unidades subordinadas</span>
            <strong class="kpi-value"><?= h(format_number($impact['children_total'] ?? 0)) ?></strong>
            <span class="kpi-note">Dependencias registradas inmediatamente debajo.</span>
        </article>
        <article class="kpi-card card warning">
            <span class="kpi-label">Acciones relacionadas</span>
            <strong class="kpi-value"><?= h(format_number($impact['actions_total'] ?? 0)) ?></strong>
            <span class="kpi-note">Traslados u otras acciones que utilizan esta unidad.</span>
        </article>
        <article class="kpi-card card success">
            <span class="kpi-label">Asignaciones y posiciones</span>
            <strong class="kpi-value"><?= h(format_number((int)($impact['assignments_total'] ?? 0) + (int)($impact['positions_total'] ?? 0))) ?></strong>
            <span class="kpi-note">Relaciones que se conservan al cambiar el nombre.</span>
        </article>
    </div>

    <?php if ($protected): ?>
        <div class="notice warning">
            <strong>Registro heredado protegido.</strong>
            Puede consultarlo, pero MySQL bloqueará cualquier intento de editarlo, moverlo o desactivarlo.
        </div>
    <?php endif; ?>

    <div class="two-column admin-columns">
        <section class="panel">
            <div class="panel-header">
                <div>
                    <h2>Editar unidad</h2>
                    <p>El identificador interno <?= h($selectedId) ?> no cambia al corregir el nombre o el código.</p>
                </div>
            </div>
            <form method="post" class="admin-form-grid">
                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="unit_id" value="<?= h($selectedId) ?>">
                <div class="field admin-span-2">
                    <label>Nombre oficial</label>
                    <input name="name" value="<?= h($selectedUnit['name']) ?>" required <?= $protected ? 'disabled' : '' ?>>
                </div>
                <div class="field">
                    <label>Nombre corto</label>
                    <input name="short_name" value="<?= h($selectedUnit['short_name']) ?>" <?= $protected ? 'disabled' : '' ?>>
                </div>
                <div class="field">
                    <label>Tipo de unidad</label>
                    <select name="unit_type_id" required <?= $protected ? 'disabled' : '' ?>>
                        <?php foreach ($unitTypes as $type): ?>
                            <option value="<?= h($type['id']) ?>" <?= (int)$selectedUnit['unit_type_id'] === (int)$type['id'] ? 'selected' : '' ?>><?= h(str_replace('_', ' ', (string)$type['name'])) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>Código institucional</label>
                    <input name="code" value="<?= h($selectedUnit['code']) ?>" <?= $protected ? 'disabled' : '' ?>>
                </div>
                <div class="field">
                    <label>Código MOI</label>
                    <input name="moi_code" value="<?= h($selectedUnit['moi_code']) ?>" <?= $protected ? 'disabled' : '' ?>>
                </div>
                <div class="field admin-span-2">
                    <label>Motivo u observación</label>
                    <input name="notes" placeholder="Ejemplo: corrección ortográfica autorizada" <?= $protected ? 'disabled' : '' ?>>
                </div>
                <?php if (!$protected): ?>
                    <div class="admin-span-2"><button class="button primary" type="submit">Guardar cambios</button></div>
                <?php endif; ?>
            </form>
        </section>

        <section class="panel">
            <div class="panel-header">
                <div>
                    <h2>Estado y procedencia</h2>
                    <p>Información técnica de control.</p>
                </div>
            </div>
            <div class="detail-grid admin-detail-grid">
                <div class="detail-card card"><span>Estado</span><strong><?= h($selectedUnit['status_label']) ?></strong></div>
                <div class="detail-card card"><span>Vigencia</span><strong><?= h((string)$selectedUnit['lifecycle_status']) ?></strong></div>
                <div class="detail-card card"><span>Fuente</span><strong><?= h((string)$selectedUnit['structure_source']) ?></strong></div>
                <div class="detail-card card"><span>Tabla heredada</span><strong><?= h($selectedUnit['legacy_table'] ?: 'No aplica') ?></strong></div>
            </div>
        </section>
    </div>

    <section class="panel">
        <div class="panel-header">
            <div>
                <h2>Unidades subordinadas</h2>
                <p>Navegue a cada nivel para administrarlo.</p>
            </div>
        </div>
        <?php if (!$children): ?>
            <div class="notice info">Esta unidad no tiene dependencias subordinadas registradas.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Unidad</th><th>Tipo</th><th>Personal directo</th><th>Dependencias</th><th>Estado</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($children as $child): ?>
                        <tr>
                            <td>
                                <span class="person-name"><?= h($child['name']) ?></span>
                                <span class="subtext"><?= h($child['code'] ?: 'Sin código') ?><?= (int)$child['is_protected'] === 1 ? ' · Registro protegido' : '' ?></span>
                            </td>
                            <td><?= h(str_replace('_', ' ', (string)$child['unit_type_name'])) ?></td>
                            <td><?= h(format_number($child['workforce_count'])) ?></td>
                            <td><?= h(format_number($child['child_count'])) ?></td>
                            <td><span class="badge <?= (string)$child['status'] === 'active' && (string)$child['lifecycle_status'] === 'vigente' ? 'success' : 'warning' ?>"><?= h($child['status_label']) ?></span></td>
                            <td><a class="button soft" href="estructura_admin.php?id=<?= h($child['id']) ?>">Abrir</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <?php if (!$protected && (string)$selectedUnit['status'] === 'active' && (string)$selectedUnit['lifecycle_status'] === 'vigente'): ?>
        <div class="two-column admin-columns">
            <section class="panel">
                <div class="panel-header">
                    <div>
                        <h2>Agregar dependencia</h2>
                        <p>MySQL muestra únicamente los tipos permitidos debajo de esta unidad.</p>
                    </div>
                </div>
                <form method="post" class="admin-form-grid">
                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                    <input type="hidden" name="action" value="create">
                    <input type="hidden" name="parent_id" value="<?= h($selectedId) ?>">
                    <div class="field admin-span-2"><label>Nombre oficial</label><input name="name" required></div>
                    <div class="field"><label>Nombre corto</label><input name="short_name"></div>
                    <div class="field">
                        <label>Tipo de unidad</label>
                        <select name="unit_type_id" required>
                            <option value="">Seleccione</option>
                            <?php foreach ($allowedChildTypes as $type): ?>
                                <option value="<?= h($type['id']) ?>"><?= h(str_replace('_', ' ', (string)$type['name'])) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field"><label>Código institucional</label><input name="code"></div>
                    <div class="field"><label>Código MOI</label><input name="moi_code"></div>
                    <div class="field admin-span-2"><label>Motivo u observación</label><input name="notes" placeholder="Creación, reorganización u otra razón"></div>
                    <div class="admin-span-2"><button class="button primary" type="submit">Agregar dependencia</button></div>
                </form>
            </section>

            <section class="panel">
                <div class="panel-header">
                    <div>
                        <h2>Mover unidad</h2>
                        <p>MySQL excluye la misma unidad, sus descendientes y destinos no permitidos.</p>
                    </div>
                </div>
                <form method="post" class="admin-form-grid">
                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                    <input type="hidden" name="action" value="move">
                    <input type="hidden" name="unit_id" value="<?= h($selectedId) ?>">
                    <div class="field admin-span-2">
                        <label>Nueva unidad superior</label>
                        <select name="new_parent_id" required>
                            <option value="">Seleccione</option>
                            <?php foreach ($parentOptions as $option): ?>
                                <option value="<?= h($option['id']) ?>"><?= h($option['name']) ?><?= !empty($option['code']) ? ' · ' . h($option['code']) : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field admin-span-2"><label>Motivo del movimiento</label><input name="notes" required placeholder="Ejemplo: corrección de dependencia jerárquica"></div>
                    <div class="admin-span-2"><button class="button" type="submit" data-confirm="¿Confirma que desea mover esta unidad y todas sus dependencias subordinadas?">Mover unidad</button></div>
                </form>
            </section>
        </div>
    <?php endif; ?>

    <?php if (!$protected): ?>
        <section class="panel">
            <div class="panel-header">
                <div>
                    <h2><?= (string)$selectedUnit['status'] === 'active' ? 'Desactivar unidad' : 'Reactivar unidad' ?></h2>
                    <p>No se elimina el registro. MySQL conserva los traslados, asignaciones y referencias históricas.</p>
                </div>
            </div>
            <form method="post" class="admin-form-grid admin-status-form">
                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                <input type="hidden" name="action" value="<?= (string)$selectedUnit['status'] === 'active' ? 'deactivate' : 'reactivate' ?>">
                <input type="hidden" name="unit_id" value="<?= h($selectedId) ?>">
                <div class="field">
                    <label>Motivo</label>
                    <input name="notes" <?= (string)$selectedUnit['status'] === 'active' ? 'required' : '' ?> placeholder="Explique la razón administrativa">
                </div>
                <div>
                    <button class="button <?= (string)$selectedUnit['status'] === 'active' ? 'danger' : 'primary' ?>" type="submit" data-confirm="¿Confirma este cambio de estado?"><?= (string)$selectedUnit['status'] === 'active' ? 'Desactivar' : 'Reactivar' ?></button>
                </div>
            </form>
        </section>
    <?php endif; ?>

    <section class="panel">
        <div class="panel-header">
            <div>
                <h2>Historial de cambios</h2>
                <p>Eventos registrados automáticamente por los procedimientos de MySQL.</p>
            </div>
        </div>
        <?php if (!$history): ?>
            <div class="notice info">Todavía no hay eventos administrativos registrados para esta unidad.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Fecha</th><th>Evento</th><th>Detalle</th><th>Responsable</th></tr></thead>
                    <tbody>
                    <?php foreach ($history as $event): ?>
                        <tr>
                            <td><?= h($event['effective_from']) ?></td>
                            <td><span class="badge info"><?= h($event['event_type']) ?></span></td>
                            <td><?= h($event['notes'] ?: 'Sin observación') ?></td>
                            <td><?= h($event['created_by'] ?: 'No indicado') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php render_footer(); ?>
