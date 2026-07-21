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
$actionView = trim((string)($_GET['accion'] ?? ''));
$group = trim((string)($_GET['grupo'] ?? $_POST['grupo'] ?? 'zonas'));
$errorMessage = '';

if (!in_array($group, ['zonas', 'direcciones', 'servicios'], true)) {
    $group = 'zonas';
}

function simple_structure_routine_exists(PDO $pdo, string $routineName): bool
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

function simple_structure_ready(PDO $pdo): bool
{
    if (!table_exists($pdo, 'vw_structure_admin_units')) {
        return false;
    }

    foreach ([
        'sp_structure_get_allowed_child_types',
        'sp_structure_create_root_unit',
        'sp_structure_create_unit',
        'sp_structure_update_unit',
        'sp_structure_deactivate_unit',
        'sp_structure_reactivate_unit',
    ] as $procedureName) {
        if (!simple_structure_routine_exists($pdo, $procedureName)) {
            return false;
        }
    }

    return true;
}

function simple_structure_call(PDO $pdo, string $procedureName, array $parameters = []): array
{
    $allowed = [
        'sp_structure_get_allowed_child_types',
        'sp_structure_create_root_unit',
        'sp_structure_create_unit',
        'sp_structure_update_unit',
        'sp_structure_deactivate_unit',
        'sp_structure_reactivate_unit',
    ];

    if (!in_array($procedureName, $allowed, true)) {
        throw new InvalidArgumentException('La operación solicitada no está permitida.');
    }

    $placeholders = implode(', ', array_fill(0, count($parameters), '?'));
    $statement = $pdo->prepare("CALL {$procedureName}({$placeholders})");
    $statement->execute(array_values($parameters));
    $result = $statement->fetchAll();

    while ($statement->nextRowset()) {
        // Liberar todos los resultados devueltos por MySQL.
    }
    $statement->closeCursor();

    return $result;
}

function simple_structure_error(Throwable $error): string
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

function simple_structure_is_protected(array $unit): bool
{
    return (int)($unit['is_protected'] ?? 0) === 1;
}

function simple_structure_group_label(string $group): string
{
    return match ($group) {
        'direcciones' => 'Direcciones',
        'servicios' => 'Servicios',
        default => 'Zonas policiales',
    };
}

$databaseReady = simple_structure_ready($pdo);

if (!$databaseReady) {
    render_header(
        'Configurar estructura',
        'estructura_admin',
        'Administra zonas, direcciones, servicios y sus dependencias.'
    );
    render_breadcrumbs([
        ['label' => 'Inicio', 'href' => 'index.php'],
        ['label' => 'Configuración del sistema', 'href' => 'configuracion.php'],
        ['label' => 'Estructura organizacional', 'href' => ''],
    ]);
    render_empty_state(
        'Falta instalar la configuración sencilla',
        'Ejecute database/estructura_admin_db.sql y database/estructura_configuracion_simple.sql sobre estructura_zonas_test.'
    );
    render_footer();
    return;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = (string)($_POST['csrf_token'] ?? '');

    if (!hash_equals($csrfToken, $submittedToken)) {
        $errorMessage = 'La sesión del formulario venció. Recargue la página e intente nuevamente.';
    } else {
        $postedAction = trim((string)($_POST['action'] ?? ''));
        $unitId = max(0, (int)($_POST['unit_id'] ?? 0));
        $actor = 'administrador_local';

        try {
            if ($postedAction === 'create_root') {
                $result = simple_structure_call($pdo, 'sp_structure_create_root_unit', [
                    max(0, (int)($_POST['root_parent_id'] ?? 0)),
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
                    throw new RuntimeException('MySQL no devolvió el identificador de la nueva unidad.');
                }

                header('Location: estructura_admin.php?id=' . $newUnitId . '&grupo=' . urlencode($group) . '&ok=creada');
                exit;
            }

            if ($postedAction === 'update') {
                simple_structure_call($pdo, 'sp_structure_update_unit', [
                    $unitId,
                    max(0, (int)($_POST['unit_type_id'] ?? 0)),
                    trim((string)($_POST['name'] ?? '')),
                    trim((string)($_POST['short_name'] ?? '')),
                    trim((string)($_POST['code'] ?? '')),
                    trim((string)($_POST['moi_code'] ?? '')),
                    trim((string)($_POST['notes'] ?? '')),
                    $actor,
                ]);

                header('Location: estructura_admin.php?id=' . $unitId . '&grupo=' . urlencode($group) . '&ok=actualizada');
                exit;
            }

            if ($postedAction === 'create') {
                $parentId = max(0, (int)($_POST['parent_id'] ?? 0));
                $result = simple_structure_call($pdo, 'sp_structure_create_unit', [
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
                    throw new RuntimeException('MySQL no devolvió el identificador de la nueva dependencia.');
                }

                header('Location: estructura_admin.php?id=' . $newUnitId . '&grupo=' . urlencode($group) . '&ok=creada');
                exit;
            }

            if ($postedAction === 'deactivate') {
                simple_structure_call($pdo, 'sp_structure_deactivate_unit', [
                    $unitId,
                    trim((string)($_POST['notes'] ?? '')),
                    $actor,
                ]);

                header('Location: estructura_admin.php?id=' . $unitId . '&grupo=' . urlencode($group) . '&ok=desactivada');
                exit;
            }

            if ($postedAction === 'reactivate') {
                simple_structure_call($pdo, 'sp_structure_reactivate_unit', [
                    $unitId,
                    trim((string)($_POST['notes'] ?? '')),
                    $actor,
                ]);

                header('Location: estructura_admin.php?id=' . $unitId . '&grupo=' . urlencode($group) . '&ok=reactivada');
                exit;
            }

            throw new RuntimeException('La acción solicitada no está disponible.');
        } catch (Throwable $error) {
            $errorMessage = simple_structure_error($error);
        }
    }
}

$unitTypes = rows($pdo, 'SELECT id, name, description FROM unit_types ORDER BY name');
$rootUnitTypes = rows(
    $pdo,
    "SELECT id, name, description
     FROM unit_types
     WHERE name IN (
        'zona_policial',
        'region_policial',
        'direccion_nacional',
        'subdireccion_nacional',
        'directorio_general',
        'servicio_policial'
     )
     ORDER BY name"
);
$rootParents = rows(
    $pdo,
    "SELECT id, name, unit_type_name
     FROM vw_structure_admin_units
     WHERE status = 'active'
       AND lifecycle_status = 'vigente'
       AND (
            parent_id IS NULL
            OR COALESCE(level, 99) <= 1
            OR unit_type_name IN ('institucion', 'directorio_general')
       )
     ORDER BY COALESCE(level, 99), name"
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

$children = $selectedUnit
    ? rows(
        $pdo,
        "SELECT *
         FROM vw_structure_admin_units
         WHERE parent_id = :parent_id
         ORDER BY
            CASE WHEN status = 'active' AND lifecycle_status = 'vigente' THEN 1 ELSE 2 END,
            name",
        ['parent_id' => $selectedId]
    )
    : [];

$allowedChildTypes = [];
if ($selectedUnit
    && !simple_structure_is_protected($selectedUnit)
    && (string)$selectedUnit['status'] === 'active'
    && (string)$selectedUnit['lifecycle_status'] === 'vigente') {
    try {
        $allowedChildTypes = simple_structure_call(
            $pdo,
            'sp_structure_get_allowed_child_types',
            [$selectedId]
        );
    } catch (Throwable $error) {
        if ($errorMessage === '') {
            $errorMessage = simple_structure_error($error);
        }
    }
}

$groupConditions = [
    'zonas' => "(unit_type_name IN ('zona_policial', 'region_policial') OR name LIKE '%Zona Policial%')",
    'direcciones' => "(unit_type_name IN ('direccion_nacional', 'subdireccion_nacional', 'directorio_general') OR name LIKE 'Dirección%')",
    'servicios' => "(unit_type_name IN ('servicio_policial', 'servicio_zonal') OR name LIKE '%Servicio%')",
];

$listWhere = [$groupConditions[$group]];
$listParams = [];
if ($search !== '') {
    $listWhere[] = '(name LIKE :search OR short_name LIKE :search OR code LIKE :search)';
    $listParams['search'] = '%' . $search . '%';
}

$principalUnits = rows(
    $pdo,
    "SELECT *
     FROM vw_structure_admin_units
     WHERE " . implode(' AND ', $listWhere) . "
     ORDER BY
        CASE WHEN status = 'active' AND lifecycle_status = 'vigente' THEN 1 ELSE 2 END,
        name
     LIMIT 250",
    $listParams
);

$breadcrumbs = [];
if ($selectedUnit) {
    $allUnits = rows(
        $pdo,
        'SELECT id, parent_id, name FROM vw_structure_admin_units ORDER BY id'
    );
    $lookup = [];
    foreach ($allUnits as $unitRow) {
        $lookup[(int)$unitRow['id']] = $unitRow;
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
            'href' => $currentId === $selectedId
                ? ''
                : 'estructura_admin.php?id=' . $currentId . '&grupo=' . urlencode($group),
        ]);

        $parentId = (int)($current['parent_id'] ?? 0);
        $current = $parentId > 0 ? ($lookup[$parentId] ?? []) : [];
    }
}

$successMessages = [
    'actualizada' => 'Los cambios fueron guardados.',
    'creada' => 'La nueva unidad fue agregada.',
    'desactivada' => 'La unidad fue desactivada sin borrar su historial.',
    'reactivada' => 'La unidad fue reactivada.',
];
$successKey = trim((string)($_GET['ok'] ?? ''));
$protected = $selectedUnit ? simple_structure_is_protected($selectedUnit) : false;

render_header(
    'Configurar estructura',
    'estructura_admin',
    'Agrega, edita o desactiva zonas, direcciones, servicios y dependencias.'
);
render_breadcrumbs(array_merge(
    [
        ['label' => 'Inicio', 'href' => 'index.php'],
        ['label' => 'Configuración del sistema', 'href' => 'configuracion.php'],
        ['label' => 'Estructura organizacional', 'href' => $selectedUnit ? 'estructura_admin.php?grupo=' . urlencode($group) : ''],
    ],
    $breadcrumbs
));
?>

<div data-simple-structure class="simple-structure-page">
    <div class="notice info">
        <strong>Administración sencilla.</strong>
        “Desactivar” reemplaza a eliminar: la unidad deja de usarse, pero no se borra su historial ni sus relaciones.
    </div>

    <?php if ($errorMessage !== ''): ?>
        <div class="notice danger"><?= h($errorMessage) ?></div>
    <?php endif; ?>

    <?php if (isset($successMessages[$successKey])): ?>
        <div class="notice success"><?= h($successMessages[$successKey]) ?></div>
    <?php endif; ?>

    <nav class="configuration-tabs" aria-label="Tipo de estructura">
        <a href="estructura_admin.php?grupo=zonas" class="<?= $group === 'zonas' ? 'active' : '' ?>">Zonas</a>
        <a href="estructura_admin.php?grupo=direcciones" class="<?= $group === 'direcciones' ? 'active' : '' ?>">Direcciones</a>
        <a href="estructura_admin.php?grupo=servicios" class="<?= $group === 'servicios' ? 'active' : '' ?>">Servicios</a>
    </nav>

    <?php if (!$selectedUnit): ?>
        <?php if ($actionView === 'agregar_principal'): ?>
            <section class="panel simple-structure-form">
                <div class="panel-header">
                    <div>
                        <h2>Agregar zona, dirección o servicio</h2>
                        <p>Complete solamente el nombre, el tipo y el código.</p>
                    </div>
                    <a class="button" href="estructura_admin.php?grupo=<?= h($group) ?>">Cancelar</a>
                </div>
                <form method="post" class="admin-form-grid">
                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                    <input type="hidden" name="action" value="create_root">
                    <input type="hidden" name="grupo" value="<?= h($group) ?>">
                    <input type="hidden" name="short_name" value="">
                    <input type="hidden" name="moi_code" value="">

                    <div class="field admin-span-2">
                        <label>Nombre</label>
                        <input name="name" required placeholder="Ejemplo: Zona Policial de Chiriquí">
                    </div>
                    <div class="field">
                        <label>Tipo</label>
                        <select name="unit_type_id" required>
                            <option value="">Seleccione</option>
                            <?php foreach ($rootUnitTypes as $type): ?>
                                <option value="<?= h($type['id']) ?>"><?= h(ucwords(str_replace('_', ' ', (string)$type['name']))) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label>Código</label>
                        <input name="code" placeholder="Ejemplo: Z04">
                    </div>
                    <div class="field admin-span-2">
                        <label>Unidad superior <span class="subtext">Opcional</span></label>
                        <select name="root_parent_id">
                            <option value="0">Nivel principal</option>
                            <?php foreach ($rootParents as $parent): ?>
                                <option value="<?= h($parent['id']) ?>"><?= h($parent['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field admin-span-2">
                        <label>Motivo</label>
                        <input name="notes" placeholder="Ejemplo: nueva estructura aprobada">
                    </div>
                    <div class="admin-span-2">
                        <button class="button primary" type="submit">Agregar unidad</button>
                    </div>
                </form>
            </section>
        <?php else: ?>
            <section class="panel">
                <div class="panel-header">
                    <div>
                        <h2><?= h(simple_structure_group_label($group)) ?></h2>
                        <p>Seleccione una unidad para ver y administrar lo que depende de ella.</p>
                    </div>
                    <a class="button primary" href="estructura_admin.php?grupo=<?= h($group) ?>&accion=agregar_principal">Agregar</a>
                </div>

                <form class="search-bar" method="get" action="estructura_admin.php">
                    <input type="hidden" name="grupo" value="<?= h($group) ?>">
                    <input type="search" name="q" value="<?= h($search) ?>" placeholder="Buscar por nombre o código">
                    <button class="button" type="submit">Buscar</button>
                    <?php if ($search !== ''): ?>
                        <a class="button" href="estructura_admin.php?grupo=<?= h($group) ?>">Limpiar</a>
                    <?php endif; ?>
                </form>

                <?php if (!$principalUnits): ?>
                    <div class="notice info" style="margin-top:18px">No se encontraron unidades.</div>
                <?php else: ?>
                    <div class="simple-structure-list">
                        <?php foreach ($principalUnits as $unit): ?>
                            <article class="simple-structure-row card">
                                <div>
                                    <h3><?= h($unit['name']) ?></h3>
                                    <p>
                                        <?= h(ucwords(str_replace('_', ' ', (string)$unit['unit_type_name']))) ?>
                                        <?= !empty($unit['code']) ? ' · ' . h($unit['code']) : '' ?>
                                    </p>
                                </div>
                                <div class="simple-structure-row-actions">
                                    <span class="badge <?= (string)$unit['status'] === 'active' && (string)$unit['lifecycle_status'] === 'vigente' ? 'success' : 'warning' ?>">
                                        <?= (string)$unit['status'] === 'active' && (string)$unit['lifecycle_status'] === 'vigente' ? 'Activa' : 'Inactiva' ?>
                                    </span>
                                    <span class="simple-child-count"><?= h(format_number($unit['child_count'])) ?> dependencias</span>
                                    <a class="button soft" href="estructura_admin.php?id=<?= h($unit['id']) ?>&grupo=<?= h($group) ?>">Abrir</a>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($selectedUnit): ?>
        <section class="panel simple-selected-unit">
            <div class="page-intro">
                <div>
                    <span class="badge <?= (string)$selectedUnit['status'] === 'active' && (string)$selectedUnit['lifecycle_status'] === 'vigente' ? 'success' : 'warning' ?>">
                        <?= (string)$selectedUnit['status'] === 'active' && (string)$selectedUnit['lifecycle_status'] === 'vigente' ? 'Activa' : 'Inactiva' ?>
                    </span>
                    <h2><?= h($selectedUnit['name']) ?></h2>
                    <p><?= h(ucwords(str_replace('_', ' ', (string)$selectedUnit['unit_type_name']))) ?><?= !empty($selectedUnit['code']) ? ' · ' . h($selectedUnit['code']) : '' ?></p>
                </div>
                <div class="button-row">
                    <?php if (!empty($selectedUnit['parent_id'])): ?>
                        <a class="button" href="estructura_admin.php?id=<?= h($selectedUnit['parent_id']) ?>&grupo=<?= h($group) ?>">Subir un nivel</a>
                    <?php else: ?>
                        <a class="button" href="estructura_admin.php?grupo=<?= h($group) ?>">Volver al listado</a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($protected): ?>
                <div class="notice warning">
                    Este es un registro heredado protegido. Puede consultarlo, pero no modificarlo.
                </div>
            <?php else: ?>
                <div class="simple-structure-actions">
                    <a class="button <?= $actionView === 'editar' ? 'primary' : '' ?>" href="estructura_admin.php?id=<?= h($selectedId) ?>&grupo=<?= h($group) ?>&accion=editar">Editar</a>
                    <?php if ((string)$selectedUnit['status'] === 'active' && (string)$selectedUnit['lifecycle_status'] === 'vigente'): ?>
                        <a class="button <?= $actionView === 'agregar' ? 'primary' : '' ?>" href="estructura_admin.php?id=<?= h($selectedId) ?>&grupo=<?= h($group) ?>&accion=agregar">Agregar dependencia</a>
                    <?php endif; ?>
                    <a class="button <?= $actionView === 'estado' ? 'danger' : '' ?>" href="estructura_admin.php?id=<?= h($selectedId) ?>&grupo=<?= h($group) ?>&accion=estado">
                        <?= (string)$selectedUnit['status'] === 'active' ? 'Desactivar' : 'Reactivar' ?>
                    </a>
                </div>
            <?php endif; ?>
        </section>

        <?php if (!$protected && $actionView === 'editar'): ?>
            <section class="panel simple-structure-form">
                <div class="panel-header">
                    <div>
                        <h2>Editar unidad</h2>
                        <p>Cambie únicamente los datos necesarios.</p>
                    </div>
                    <a class="button" href="estructura_admin.php?id=<?= h($selectedId) ?>&grupo=<?= h($group) ?>">Cancelar</a>
                </div>
                <form method="post" class="admin-form-grid">
                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="unit_id" value="<?= h($selectedId) ?>">
                    <input type="hidden" name="grupo" value="<?= h($group) ?>">
                    <input type="hidden" name="short_name" value="<?= h($selectedUnit['short_name']) ?>">
                    <input type="hidden" name="moi_code" value="<?= h($selectedUnit['moi_code']) ?>">

                    <div class="field admin-span-2">
                        <label>Nombre</label>
                        <input name="name" value="<?= h($selectedUnit['name']) ?>" required>
                    </div>
                    <div class="field">
                        <label>Tipo</label>
                        <select name="unit_type_id" required>
                            <?php foreach ($unitTypes as $type): ?>
                                <option value="<?= h($type['id']) ?>" <?= (int)$selectedUnit['unit_type_id'] === (int)$type['id'] ? 'selected' : '' ?>><?= h(ucwords(str_replace('_', ' ', (string)$type['name']))) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label>Código</label>
                        <input name="code" value="<?= h($selectedUnit['code']) ?>">
                    </div>
                    <div class="field admin-span-2">
                        <label>Motivo</label>
                        <input name="notes" placeholder="Ejemplo: corrección de nombre">
                    </div>
                    <div class="admin-span-2">
                        <button class="button primary" type="submit">Guardar cambios</button>
                    </div>
                </form>
            </section>
        <?php endif; ?>

        <?php if (!$protected && $actionView === 'agregar' && (string)$selectedUnit['status'] === 'active'): ?>
            <section class="panel simple-structure-form">
                <div class="panel-header">
                    <div>
                        <h2>Agregar dentro de <?= h($selectedUnit['name']) ?></h2>
                        <p>Puede agregar un área, sección, servicio, oficina, estación u otro tipo permitido.</p>
                    </div>
                    <a class="button" href="estructura_admin.php?id=<?= h($selectedId) ?>&grupo=<?= h($group) ?>">Cancelar</a>
                </div>
                <form method="post" class="admin-form-grid">
                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                    <input type="hidden" name="action" value="create">
                    <input type="hidden" name="parent_id" value="<?= h($selectedId) ?>">
                    <input type="hidden" name="grupo" value="<?= h($group) ?>">
                    <input type="hidden" name="short_name" value="">
                    <input type="hidden" name="moi_code" value="">

                    <div class="field admin-span-2">
                        <label>Nombre</label>
                        <input name="name" required placeholder="Ejemplo: Área A, Sección de Operaciones o Servicio Policial">
                    </div>
                    <div class="field">
                        <label>Tipo</label>
                        <select name="unit_type_id" required>
                            <option value="">Seleccione</option>
                            <?php foreach ($allowedChildTypes as $type): ?>
                                <option value="<?= h($type['id']) ?>"><?= h(ucwords(str_replace('_', ' ', (string)$type['name']))) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label>Código</label>
                        <input name="code" placeholder="Opcional">
                    </div>
                    <div class="field admin-span-2">
                        <label>Motivo</label>
                        <input name="notes" placeholder="Ejemplo: nueva dependencia aprobada">
                    </div>
                    <div class="admin-span-2">
                        <button class="button primary" type="submit">Agregar dependencia</button>
                    </div>
                </form>
            </section>
        <?php endif; ?>

        <?php if (!$protected && $actionView === 'estado'): ?>
            <section class="panel simple-structure-form">
                <div class="panel-header">
                    <div>
                        <h2><?= (string)$selectedUnit['status'] === 'active' ? 'Desactivar unidad' : 'Reactivar unidad' ?></h2>
                        <p>
                            <?= (string)$selectedUnit['status'] === 'active'
                                ? 'No se borra. Dejará de aparecer como unidad vigente.'
                                : 'La unidad volverá a estar disponible.' ?>
                        </p>
                    </div>
                    <a class="button" href="estructura_admin.php?id=<?= h($selectedId) ?>&grupo=<?= h($group) ?>">Cancelar</a>
                </div>
                <form method="post" class="admin-form-grid">
                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                    <input type="hidden" name="action" value="<?= (string)$selectedUnit['status'] === 'active' ? 'deactivate' : 'reactivate' ?>">
                    <input type="hidden" name="unit_id" value="<?= h($selectedId) ?>">
                    <input type="hidden" name="grupo" value="<?= h($group) ?>">
                    <div class="field admin-span-2">
                        <label>Motivo</label>
                        <input name="notes" required placeholder="Explique brevemente la razón">
                    </div>
                    <div class="admin-span-2">
                        <button
                            class="button <?= (string)$selectedUnit['status'] === 'active' ? 'danger' : 'primary' ?>"
                            type="submit"
                            data-confirm="¿Confirma este cambio de estado?"
                        >
                            <?= (string)$selectedUnit['status'] === 'active' ? 'Desactivar' : 'Reactivar' ?>
                        </button>
                    </div>
                </form>
            </section>
        <?php endif; ?>

        <section class="panel">
            <div class="panel-header">
                <div>
                    <h2>Contenido de esta unidad</h2>
                    <p>Abra un elemento para editarlo, agregar algo dentro o desactivarlo.</p>
                </div>
                <?php if (!$protected && (string)$selectedUnit['status'] === 'active': ?>
                    <a class="button primary" href="estructura_admin.php?id=<?= h($selectedId) ?>&grupo=<?= h($group) ?>&accion=agregar">Agregar dependencia</a>
                <?php endif; ?>
            </div>

            <?php if (!$children): ?>
                <div class="notice info">Esta unidad todavía no tiene dependencias registradas.</div>
            <?php else: ?>
                <div class="simple-structure-list">
                    <?php foreach ($children as $child): ?>
                        <article class="simple-structure-row card">
                            <div>
                                <h3><?= h($child['name']) ?></h3>
                                <p>
                                    <?= h(ucwords(str_replace('_', ' ', (string)$child['unit_type_name']))) ?>
                                    <?= !empty($child['code']) ? ' · ' . h($child['code']) : '' ?>
                                </p>
                            </div>
                            <div class="simple-structure-row-actions">
                                <span class="badge <?= (string)$child['status'] === 'active' && (string)$child['lifecycle_status'] === 'vigente' ? 'success' : 'warning' ?>">
                                    <?= (string)$child['status'] === 'active' && (string)$child['lifecycle_status'] === 'vigente' ? 'Activa' : 'Inactiva' ?>
                                </span>
                                <span class="simple-child-count"><?= h(format_number($child['child_count'])) ?> dentro</span>
                                <a class="button soft" href="estructura_admin.php?id=<?= h($child['id']) ?>&grupo=<?= h($group) ?>">Abrir</a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</div>

<?php render_footer(); ?>
