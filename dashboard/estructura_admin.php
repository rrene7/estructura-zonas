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

$csrfToken = (string) $_SESSION['estructura_admin_csrf'];
$selectedId = max(0, (int) ($_GET['id'] ?? $_POST['unit_id'] ?? $_POST['parent_id'] ?? 0));
$search = trim((string) ($_GET['q'] ?? ''));
$actionView = trim((string) ($_GET['accion'] ?? ''));
$group = trim((string) ($_GET['grupo'] ?? $_POST['grupo'] ?? 'zonas'));
$errorMessage = '';

if (!in_array($group, ['zonas', 'direcciones', 'servicios'], true)) {
    $group = 'zonas';
}

function structure_routine_exists(PDO $pdo, string $routineName): bool
{
    $statement = $pdo->prepare(
        "SELECT COUNT(*) AS total
         FROM information_schema.ROUTINES
         WHERE ROUTINE_SCHEMA = DATABASE()
           AND ROUTINE_TYPE = 'PROCEDURE'
           AND ROUTINE_NAME = :routine_name"
    );
    $statement->execute(['routine_name' => $routineName]);
    return (int) ($statement->fetch()['total'] ?? 0) > 0;
}

function structure_database_ready(PDO $pdo): bool
{
    foreach ([
        'vw_structure_admin_units',
        'vw_structure_code_previews',
        'structure_default_parents',
        'structure_zone_number_registry',
    ] as $resourceName) {
        if (!table_exists($pdo, $resourceName)) {
            return false;
        }
    }

    foreach ([
        'sp_structure_get_allowed_child_types',
        'sp_structure_create_principal_unit',
        'sp_structure_create_unit',
        'sp_structure_update_unit',
        'sp_structure_deactivate_unit',
        'sp_structure_reactivate_unit',
    ] as $procedureName) {
        if (!structure_routine_exists($pdo, $procedureName)) {
            return false;
        }
    }

    return true;
}

function structure_call(PDO $pdo, string $procedureName, array $parameters = []): array
{
    $allowed = [
        'sp_structure_get_allowed_child_types',
        'sp_structure_create_principal_unit',
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
        // Consumir todos los resultados devueltos por MySQL.
    }
    $statement->closeCursor();

    return $result;
}

function structure_error_message(Throwable $error): string
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

function structure_group_label(string $group): string
{
    if ($group === 'direcciones') {
        return 'Direcciones';
    }
    if ($group === 'servicios') {
        return 'Servicios';
    }
    return 'Zonas policiales';
}

function structure_is_active(array $unit): bool
{
    return (string) ($unit['status'] ?? '') === 'active'
        && (string) ($unit['lifecycle_status'] ?? '') === 'vigente';
}

function structure_is_protected(array $unit): bool
{
    return (int) ($unit['is_protected'] ?? 0) === 1;
}

function structure_guided_name(string $typeName, string $label, string $description): string
{
    $typeName = strtolower(trim($typeName));
    $label = strtoupper(trim($label));
    $description = trim($description);

    if (str_contains($typeName, 'area')) {
        if ($label === '') {
            throw new RuntimeException('Indique la letra o número del área.');
        }
        return 'Área ' . $label . ($description !== '' ? ' - ' . $description : '');
    }

    if (str_contains($typeName, 'seccion')) {
        if ($description === '') {
            throw new RuntimeException('Escriba el nombre de la sección.');
        }
        return 'Sección de ' . $description;
    }

    if (str_contains($typeName, 'servicio')) {
        if ($description === '') {
            throw new RuntimeException('Escriba el nombre del servicio.');
        }
        return 'Servicio ' . $description;
    }

    if (str_contains($typeName, 'departamento')) {
        if ($description === '') {
            throw new RuntimeException('Escriba el nombre del departamento.');
        }
        return 'Departamento de ' . $description;
    }

    if (str_contains($typeName, 'oficina')) {
        if ($description === '') {
            throw new RuntimeException('Escriba el nombre de la oficina.');
        }
        return 'Oficina de ' . $description;
    }

    if (str_contains($typeName, 'subestacion')) {
        if ($description === '') {
            throw new RuntimeException('Escriba el nombre de la subestación.');
        }
        return 'Subestación Policial de ' . $description;
    }

    if (str_contains($typeName, 'estacion')) {
        if ($description === '') {
            throw new RuntimeException('Escriba el nombre de la estación.');
        }
        return 'Estación Policial de ' . $description;
    }

    if (str_contains($typeName, 'sector')) {
        if ($description === '') {
            throw new RuntimeException('Escriba el nombre del sector.');
        }
        return 'Sector ' . $description;
    }

    if (str_contains($typeName, 'puesto')) {
        if ($description === '') {
            throw new RuntimeException('Escriba el nombre del puesto.');
        }
        return 'Puesto Policial de ' . $description;
    }

    if ($description === '') {
        throw new RuntimeException('Escriba el nombre o descripción de la dependencia.');
    }

    return $description;
}

if (!structure_database_ready($pdo)) {
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
        'Falta instalar la actualización de estructura',
        'Ejecute database/estructura_nombres_y_padres_automaticos.sql sobre estructura_zonas_test.'
    );
    render_footer();
    return;
}

$unitTypes = rows($pdo, 'SELECT id, name, description FROM unit_types ORDER BY name');
$unitTypeLookup = [];
foreach ($unitTypes as $typeRow) {
    $unitTypeLookup[(int) $typeRow['id']] = (string) $typeRow['name'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = (string) ($_POST['csrf_token'] ?? '');

    if (!hash_equals($csrfToken, $submittedToken)) {
        $errorMessage = 'La sesión del formulario venció. Recargue la página e intente nuevamente.';
    } else {
        $postedAction = trim((string) ($_POST['action'] ?? ''));
        $unitId = max(0, (int) ($_POST['unit_id'] ?? 0));
        $actor = 'administrador_local';

        try {
            if ($postedAction === 'create_root') {
                $unitTypeId = max(0, (int) ($_POST['unit_type_id'] ?? 0));
                $typeName = $unitTypeLookup[$unitTypeId] ?? '';
                $name = trim((string) ($_POST['name'] ?? ''));

                if ($group === 'zonas') {
                    $zoneNumber = max(0, (int) ($_POST['zone_number'] ?? 0));
                    $description = trim((string) ($_POST['description'] ?? ''));

                    if ($zoneNumber <= 0) {
                        throw new RuntimeException('Indique un número de zona válido.');
                    }
                    if ($description === '') {
                        throw new RuntimeException('Escriba la descripción o ubicación de la zona.');
                    }
                    if (!in_array($typeName, ['zona_policial', 'region_policial'], true)) {
                        throw new RuntimeException('Seleccione un tipo de zona válido.');
                    }

                    $name = $zoneNumber . ' Zona Policial - ' . $description;
                } elseif ($name === '') {
                    throw new RuntimeException('Escriba el nombre oficial de la unidad.');
                }

                $result = structure_call($pdo, 'sp_structure_create_principal_unit', [
                    $unitTypeId,
                    $name,
                    trim((string) ($_POST['notes'] ?? '')),
                    $actor,
                ]);

                $newUnitId = (int) ($result[0]['unit_id'] ?? 0);
                if ($newUnitId <= 0) {
                    throw new RuntimeException('MySQL no devolvió el identificador de la nueva unidad.');
                }

                header('Location: estructura_admin.php?id=' . $newUnitId . '&grupo=' . urlencode($group) . '&ok=creada');
                exit;
            }

            if ($postedAction === 'update') {
                structure_call($pdo, 'sp_structure_update_unit', [
                    $unitId,
                    max(0, (int) ($_POST['unit_type_id'] ?? 0)),
                    trim((string) ($_POST['name'] ?? '')),
                    trim((string) ($_POST['short_name'] ?? '')),
                    trim((string) ($_POST['code'] ?? '')),
                    trim((string) ($_POST['moi_code'] ?? '')),
                    trim((string) ($_POST['notes'] ?? '')),
                    $actor,
                ]);

                header('Location: estructura_admin.php?id=' . $unitId . '&grupo=' . urlencode($group) . '&ok=actualizada');
                exit;
            }

            if ($postedAction === 'create') {
                $parentId = max(0, (int) ($_POST['parent_id'] ?? 0));
                $unitTypeId = max(0, (int) ($_POST['unit_type_id'] ?? 0));
                $typeName = $unitTypeLookup[$unitTypeId] ?? '';
                $name = structure_guided_name(
                    $typeName,
                    (string) ($_POST['unit_label'] ?? ''),
                    (string) ($_POST['description'] ?? '')
                );

                $result = structure_call($pdo, 'sp_structure_create_unit', [
                    $parentId,
                    $unitTypeId,
                    $name,
                    '',
                    '',
                    '',
                    trim((string) ($_POST['notes'] ?? '')),
                    $actor,
                ]);

                $newUnitId = (int) ($result[0]['unit_id'] ?? 0);
                if ($newUnitId <= 0) {
                    throw new RuntimeException('MySQL no devolvió el identificador de la nueva dependencia.');
                }

                header('Location: estructura_admin.php?id=' . $newUnitId . '&grupo=' . urlencode($group) . '&ok=creada');
                exit;
            }

            if ($postedAction === 'deactivate') {
                structure_call($pdo, 'sp_structure_deactivate_unit', [
                    $unitId,
                    trim((string) ($_POST['notes'] ?? '')),
                    $actor,
                ]);

                header('Location: estructura_admin.php?id=' . $unitId . '&grupo=' . urlencode($group) . '&ok=desactivada');
                exit;
            }

            if ($postedAction === 'reactivate') {
                structure_call($pdo, 'sp_structure_reactivate_unit', [
                    $unitId,
                    trim((string) ($_POST['notes'] ?? '')),
                    $actor,
                ]);

                header('Location: estructura_admin.php?id=' . $unitId . '&grupo=' . urlencode($group) . '&ok=reactivada');
                exit;
            }

            throw new RuntimeException('La acción solicitada no está disponible.');
        } catch (Throwable $error) {
            $errorMessage = structure_error_message($error);
        }
    }
}

$rootTypeNames = [
    'zonas' => ['zona_policial'],
    'direcciones' => ['direccion_nacional', 'subdireccion_nacional', 'directorio_general'],
    'servicios' => ['servicio_policial'],
];
$rootTypeParams = $rootTypeNames[$group];
$rootUnitTypes = rows(
    $pdo,
    "SELECT id, name, description
     FROM unit_types
     WHERE name IN (:type_one, :type_two, :type_three)
     ORDER BY name",
    [
        'type_one' => $rootTypeParams[0] ?? '',
        'type_two' => $rootTypeParams[1] ?? '',
        'type_three' => $rootTypeParams[2] ?? '',
    ]
);

$defaultParent = one(
    $pdo,
    "SELECT
        parent_config.parent_unit_id,
        parent_unit.name AS parent_name
     FROM structure_default_parents parent_config
     LEFT JOIN organizational_units parent_unit
       ON parent_unit.id = parent_config.parent_unit_id
     WHERE parent_config.group_key = :group_key
     LIMIT 1",
    ['group_key' => $group]
);

$codePreviewRows = rows($pdo, 'SELECT unit_type_id, prefix, next_code FROM vw_structure_code_previews');
$codePreviews = [];
foreach ($codePreviewRows as $previewRow) {
    $codePreviews[(int) $previewRow['unit_type_id']] = [
        'prefix' => (string) $previewRow['prefix'],
        'next_code' => (string) $previewRow['next_code'],
    ];
}

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
if ($selectedUnit && !structure_is_protected($selectedUnit) && structure_is_active($selectedUnit)) {
    try {
        $allowedChildTypes = structure_call(
            $pdo,
            'sp_structure_get_allowed_child_types',
            [$selectedId]
        );
    } catch (Throwable $error) {
        if ($errorMessage === '') {
            $errorMessage = structure_error_message($error);
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
    $allUnits = rows($pdo, 'SELECT id, parent_id, name FROM vw_structure_admin_units ORDER BY id');
    $lookup = [];
    foreach ($allUnits as $unitRow) {
        $lookup[(int) $unitRow['id']] = $unitRow;
    }

    $current = $selectedUnit;
    $seen = [];
    while ($current) {
        $currentId = (int) $current['id'];
        if ($currentId <= 0 || isset($seen[$currentId])) {
            break;
        }

        $seen[$currentId] = true;
        array_unshift($breadcrumbs, [
            'label' => (string) $current['name'],
            'href' => $currentId === $selectedId
                ? ''
                : 'estructura_admin.php?id=' . $currentId . '&grupo=' . urlencode($group),
        ]);

        $parentId = (int) ($current['parent_id'] ?? 0);
        $current = $parentId > 0 ? ($lookup[$parentId] ?? []) : [];
    }
}

$successMessages = [
    'actualizada' => 'Los cambios fueron guardados.',
    'creada' => 'La nueva unidad fue agregada y recibió su código definitivo.',
    'desactivada' => 'La unidad fue desactivada sin borrar su historial.',
    'reactivada' => 'La unidad fue reactivada.',
];
$successKey = trim((string) ($_GET['ok'] ?? ''));
$protected = $selectedUnit ? structure_is_protected($selectedUnit) : false;

render_header(
    'Configurar estructura',
    'estructura_admin',
    'Agrega, edita o desactiva zonas, direcciones, servicios y dependencias.'
);
render_breadcrumbs(array_merge(
    [
        ['label' => 'Inicio', 'href' => 'index.php'],
        ['label' => 'Configuración del sistema', 'href' => 'configuracion.php'],
        [
            'label' => 'Estructura organizacional',
            'href' => $selectedUnit ? 'estructura_admin.php?grupo=' . urlencode($group) : '',
        ],
    ],
    $breadcrumbs
));
?>

<div data-simple-structure class="simple-structure-page">
    <div class="notice info">
        <strong>Administración sencilla.</strong>
        Los nombres se forman de manera guiada y los códigos son automáticos, permanentes e irrepetibles.
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
                        <h2>Agregar <?= h(strtolower(structure_group_label($group))) ?></h2>
                        <p>El sistema formará el nombre, asignará el código y usará la unidad superior oficial.</p>
                    </div>
                    <a class="button" href="estructura_admin.php?grupo=<?= h($group) ?>">Cancelar</a>
                </div>

                <form method="post" class="admin-form-grid" data-guided-root-form data-group="<?= h($group) ?>">
                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                    <input type="hidden" name="action" value="create_root">
                    <input type="hidden" name="grupo" value="<?= h($group) ?>">

                    <?php if ($group === 'zonas'): ?>
                        <div class="field">
                            <label>Número de zona</label>
                            <input type="number" min="1" max="999" name="zone_number" data-zone-number required placeholder="Ejemplo: 22">
                        </div>
                        <div class="field">
                            <label>Descripción o ubicación</label>
                            <input name="description" data-zone-description required placeholder="Ejemplo: Chiriquí Occidente">
                        </div>
                        <div class="field admin-span-2">
                            <label>Nombre que se guardará</label>
                            <input class="system-readonly" data-name-preview value="__ Zona Policial - __________________" readonly>
                        </div>
                    <?php else: ?>
                        <div class="field admin-span-2">
                            <label>Nombre oficial</label>
                            <input name="name" required placeholder="Escriba el nombre completo">
                        </div>
                    <?php endif; ?>

                    <div class="field">
                        <label>Tipo</label>
                        <select name="unit_type_id" data-code-type required>
                            <option value="">Seleccione</option>
                            <?php foreach ($rootUnitTypes as $type): ?>
                                <?php $preview = $codePreviews[(int) $type['id']]['next_code'] ?? ''; ?>
                                <option
                                    value="<?= h($type['id']) ?>"
                                    data-type-name="<?= h($type['name']) ?>"
                                    data-next-code="<?= h($preview) ?>"
                                ><?= h(ucwords(str_replace('_', ' ', (string) $type['name']))) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label>Código previsto</label>
                        <input class="system-readonly" data-code-preview value="Seleccione el tipo" readonly>
                        <span class="field-help">El código definitivo se confirma al guardar y nunca se reutiliza.</span>
                    </div>
                    <div class="field admin-span-2">
                        <label>Unidad superior</label>
                        <input
                            class="system-readonly"
                            value="<?= h($defaultParent['parent_name'] ?? 'Nivel principal') ?>"
                            readonly
                        >
                        <span class="field-help">Se determina automáticamente; no se muestran registros antiguos.</span>
                    </div>
                    <div class="field admin-span-2">
                        <label>Motivo</label>
                        <input name="notes" placeholder="Explique brevemente la creación">
                    </div>
                    <div class="admin-span-2">
                        <?php $parentMissing = $group === 'zonas' && empty($defaultParent['parent_unit_id']); ?>
                        <button class="button primary" type="submit" <?= $parentMissing ? 'disabled' : '' ?>>Agregar</button>
                        <?php if ($parentMissing): ?>
                            <div class="notice warning" style="margin-top:12px">No se encontró la Dirección Nacional de Operaciones Policiales vigente. Revise la configuración del padre automático.</div>
                        <?php endif; ?>
                    </div>
                </form>
            </section>
        <?php else: ?>
            <section class="panel">
                <div class="panel-header">
                    <div>
                        <h2><?= h(structure_group_label($group)) ?></h2>
                        <p>Abra una unidad para administrarla y ver lo que contiene.</p>
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
                                    <p><?= h(ucwords(str_replace('_', ' ', (string) $unit['unit_type_name']))) ?><?= !empty($unit['code']) ? ' · ' . h($unit['code']) : '' ?></p>
                                </div>
                                <div class="simple-structure-row-actions">
                                    <span class="badge <?= structure_is_active($unit) ? 'success' : 'warning' ?>"><?= structure_is_active($unit) ? 'Activa' : 'Inactiva' ?></span>
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
                    <span class="badge <?= structure_is_active($selectedUnit) ? 'success' : 'warning' ?>"><?= structure_is_active($selectedUnit) ? 'Activa' : 'Inactiva' ?></span>
                    <h2><?= h($selectedUnit['name']) ?></h2>
                    <p><?= h(ucwords(str_replace('_', ' ', (string) $selectedUnit['unit_type_name']))) ?><?= !empty($selectedUnit['code']) ? ' · Código ' . h($selectedUnit['code']) : '' ?></p>
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
                <div class="notice warning">Este registro heredado está protegido y no puede modificarse.</div>
            <?php else: ?>
                <div class="simple-structure-actions">
                    <a class="button <?= $actionView === 'editar' ? 'primary' : '' ?>" href="estructura_admin.php?id=<?= h($selectedId) ?>&grupo=<?= h($group) ?>&accion=editar">Editar</a>
                    <?php if (structure_is_active($selectedUnit)): ?>
                        <a class="button <?= $actionView === 'agregar' ? 'primary' : '' ?>" href="estructura_admin.php?id=<?= h($selectedId) ?>&grupo=<?= h($group) ?>&accion=agregar">Agregar dependencia</a>
                    <?php endif; ?>
                    <a class="button <?= $actionView === 'estado' ? 'danger' : '' ?>" href="estructura_admin.php?id=<?= h($selectedId) ?>&grupo=<?= h($group) ?>&accion=estado"><?= structure_is_active($selectedUnit) ? 'Desactivar' : 'Reactivar' ?></a>
                </div>
            <?php endif; ?>
        </section>

        <?php if (!$protected && $actionView === 'editar'): ?>
            <section class="panel simple-structure-form">
                <div class="panel-header">
                    <div><h2>Editar unidad</h2><p>El código es permanente y no puede cambiarse.</p></div>
                    <a class="button" href="estructura_admin.php?id=<?= h($selectedId) ?>&grupo=<?= h($group) ?>">Cancelar</a>
                </div>
                <form method="post" class="admin-form-grid">
                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="unit_id" value="<?= h($selectedId) ?>">
                    <input type="hidden" name="grupo" value="<?= h($group) ?>">
                    <input type="hidden" name="short_name" value="<?= h($selectedUnit['short_name']) ?>">
                    <input type="hidden" name="moi_code" value="<?= h($selectedUnit['moi_code']) ?>">
                    <input type="hidden" name="code" value="<?= h($selectedUnit['code']) ?>">

                    <div class="field admin-span-2"><label>Nombre</label><input name="name" value="<?= h($selectedUnit['name']) ?>" required></div>
                    <div class="field">
                        <label>Tipo</label>
                        <select name="unit_type_id" required>
                            <?php foreach ($unitTypes as $type): ?>
                                <option value="<?= h($type['id']) ?>" <?= (int) $selectedUnit['unit_type_id'] === (int) $type['id'] ? 'selected' : '' ?>><?= h(ucwords(str_replace('_', ' ', (string) $type['name']))) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field"><label>Código del sistema</label><input class="system-readonly" value="<?= h($selectedUnit['code'] ?: 'Sin código') ?>" readonly></div>
                    <div class="field admin-span-2"><label>Motivo</label><input name="notes" placeholder="Ejemplo: corrección de nombre"></div>
                    <div class="admin-span-2"><button class="button primary" type="submit">Guardar cambios</button></div>
                </form>
            </section>
        <?php endif; ?>

        <?php if (!$protected && $actionView === 'agregar' && structure_is_active($selectedUnit)): ?>
            <section class="panel simple-structure-form">
                <div class="panel-header">
                    <div><h2>Agregar dentro de <?= h($selectedUnit['name']) ?></h2><p>El nombre y el código se formarán automáticamente.</p></div>
                    <a class="button" href="estructura_admin.php?id=<?= h($selectedId) ?>&grupo=<?= h($group) ?>">Cancelar</a>
                </div>
                <form method="post" class="admin-form-grid" data-guided-child-form>
                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                    <input type="hidden" name="action" value="create">
                    <input type="hidden" name="parent_id" value="<?= h($selectedId) ?>">
                    <input type="hidden" name="grupo" value="<?= h($group) ?>">

                    <div class="field admin-span-2">
                        <label>Se agregará dentro de</label>
                        <input class="system-readonly" value="<?= h($selectedUnit['name']) ?>" readonly>
                    </div>
                    <div class="field">
                        <label>Tipo</label>
                        <select name="unit_type_id" data-code-type data-guided-type required>
                            <option value="">Seleccione</option>
                            <?php foreach ($allowedChildTypes as $type): ?>
                                <?php $preview = $codePreviews[(int) $type['id']]['next_code'] ?? ''; ?>
                                <option
                                    value="<?= h($type['id']) ?>"
                                    data-type-name="<?= h($type['name']) ?>"
                                    data-next-code="<?= h($preview) ?>"
                                ><?= h(ucwords(str_replace('_', ' ', (string) $type['name']))) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field" data-label-field hidden>
                        <label>Letra o número</label>
                        <input name="unit_label" data-unit-label placeholder="Ejemplo: A">
                    </div>
                    <div class="field admin-span-2">
                        <label>Descripción o nombre</label>
                        <input name="description" data-unit-description required placeholder="Ejemplo: David, Operaciones o Motorizado">
                    </div>
                    <div class="field admin-span-2">
                        <label>Nombre que se guardará</label>
                        <input class="system-readonly" data-name-preview value="Seleccione el tipo y escriba la descripción" readonly>
                    </div>
                    <div class="field admin-span-2">
                        <label>Código previsto</label>
                        <input class="system-readonly" data-code-preview value="Seleccione el tipo" readonly>
                        <span class="field-help">El código definitivo se confirma al guardar y nunca se reutiliza.</span>
                    </div>
                    <div class="field admin-span-2"><label>Motivo</label><input name="notes" placeholder="Explique brevemente la creación"></div>
                    <div class="admin-span-2"><button class="button primary" type="submit">Agregar dependencia</button></div>
                </form>
            </section>
        <?php endif; ?>

        <?php if (!$protected && $actionView === 'estado'): ?>
            <section class="panel simple-structure-form">
                <div class="panel-header">
                    <div>
                        <h2><?= structure_is_active($selectedUnit) ? 'Desactivar unidad' : 'Reactivar unidad' ?></h2>
                        <p><?= structure_is_active($selectedUnit) ? 'La unidad no se borrará; dejará de estar vigente.' : 'La unidad volverá a estar disponible.' ?></p>
                    </div>
                    <a class="button" href="estructura_admin.php?id=<?= h($selectedId) ?>&grupo=<?= h($group) ?>">Cancelar</a>
                </div>
                <form method="post" class="admin-form-grid">
                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                    <input type="hidden" name="action" value="<?= structure_is_active($selectedUnit) ? 'deactivate' : 'reactivate' ?>">
                    <input type="hidden" name="unit_id" value="<?= h($selectedId) ?>">
                    <input type="hidden" name="grupo" value="<?= h($group) ?>">
                    <div class="field admin-span-2"><label>Motivo</label><input name="notes" required placeholder="Explique brevemente la razón"></div>
                    <div class="admin-span-2">
                        <button class="button <?= structure_is_active($selectedUnit) ? 'danger' : 'primary' ?>" type="submit" data-confirm="¿Confirma este cambio de estado?"><?= structure_is_active($selectedUnit) ? 'Desactivar' : 'Reactivar' ?></button>
                    </div>
                </form>
            </section>
        <?php endif; ?>

        <section class="panel">
            <div class="panel-header">
                <div><h2>Contenido de esta unidad</h2><p>Abra un elemento para administrarlo.</p></div>
                <?php if (!$protected && structure_is_active($selectedUnit)): ?>
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
                                <p><?= h(ucwords(str_replace('_', ' ', (string) $child['unit_type_name']))) ?><?= !empty($child['code']) ? ' · ' . h($child['code']) : '' ?></p>
                            </div>
                            <div class="simple-structure-row-actions">
                                <span class="badge <?= structure_is_active($child) ? 'success' : 'warning' ?>"><?= structure_is_active($child) ? 'Activa' : 'Inactiva' ?></span>
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
