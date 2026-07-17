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
$selectedId = max(0, (int)($_GET['id'] ?? $_POST['unit_id'] ?? 0));
$search = trim((string)($_GET['q'] ?? ''));
$errorMessage = '';

function admin_unit_is_protected(array $unit): bool
{
    $legacyTable = strtoupper(trim((string)($unit['legacy_table'] ?? '')));
    $source = strtolower(trim((string)($unit['structure_source'] ?? '')));

    return $legacyTable === 'TABCUAR' || $source === 'legacy';
}

function admin_descendant_ids(PDO $pdo, int $unitId): array
{
    if ($unitId <= 0) {
        return [];
    }

    $pairs = rows(
        $pdo,
        "SELECT id, parent_id
         FROM organizational_units"
    );

    $childrenByParent = [];
    foreach ($pairs as $pair) {
        $id = (int)$pair['id'];
        $parentId = (int)($pair['parent_id'] ?? 0);
        if ($id > 0 && $parentId > 0) {
            $childrenByParent[$parentId][] = $id;
        }
    }

    $descendants = [];
    $pending = $childrenByParent[$unitId] ?? [];
    while ($pending) {
        $currentId = (int)array_shift($pending);
        if ($currentId <= 0 || isset($descendants[$currentId])) {
            continue;
        }
        $descendants[$currentId] = true;
        foreach ($childrenByParent[$currentId] ?? [] as $childId) {
            $pending[] = $childId;
        }
    }

    return array_map('intval', array_keys($descendants));
}

function admin_record_event(
    PDO $pdo,
    int $unitId,
    string $eventType,
    string $notes,
    ?int $replacementUnitId = null
): void {
    $statement = $pdo->prepare(
        "INSERT INTO organizational_unit_lifecycle_events
            (organizational_unit_id, event_type, effective_from, replacement_unit_id, notes, created_by)
         VALUES
            (:unit_id, :event_type, CURDATE(), :replacement_unit_id, :notes, :created_by)"
    );
    $statement->execute([
        'unit_id' => $unitId,
        'event_type' => $eventType,
        'replacement_unit_id' => $replacementUnitId,
        'notes' => mb_substr($notes, 0, 255),
        'created_by' => 'administrador_local',
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($csrfToken, $submittedToken)) {
        $errorMessage = 'La sesión del formulario venció. Recargue la página e intente nuevamente.';
    } else {
        $action = trim((string)($_POST['action'] ?? ''));
        $unitId = max(0, (int)($_POST['unit_id'] ?? 0));

        try {
            $pdo->beginTransaction();

            $currentUnit = $unitId > 0
                ? one($pdo, "SELECT * FROM organizational_units WHERE id = :id FOR UPDATE", ['id' => $unitId])
                : [];

            if (in_array($action, ['update', 'move', 'deactivate', 'reactivate'], true) && !$currentUnit) {
                throw new RuntimeException('La unidad seleccionada ya no existe.');
            }

            if ($currentUnit && admin_unit_is_protected($currentUnit)) {
                throw new RuntimeException('Este registro heredado está protegido. Administre la unidad institucional vigente equivalente.');
            }

            if ($action === 'update') {
                $name = trim((string)($_POST['name'] ?? ''));
                $shortName = trim((string)($_POST['short_name'] ?? ''));
                $code = trim((string)($_POST['code'] ?? ''));
                $moiCode = trim((string)($_POST['moi_code'] ?? ''));
                $unitTypeId = max(0, (int)($_POST['unit_type_id'] ?? 0));
                $notes = trim((string)($_POST['notes'] ?? ''));

                if ($name === '' || $unitTypeId <= 0) {
                    throw new RuntimeException('El nombre oficial y el tipo de unidad son obligatorios.');
                }

                $typeExists = (int)(one($pdo, 'SELECT COUNT(*) AS total FROM unit_types WHERE id = :id', ['id' => $unitTypeId])['total'] ?? 0);
                if ($typeExists <= 0) {
                    throw new RuntimeException('El tipo de unidad seleccionado no existe.');
                }

                $statement = $pdo->prepare(
                    "UPDATE organizational_units
                     SET name = :name,
                         short_name = :short_name,
                         code = :code,
                         moi_code = :moi_code,
                         unit_type_id = :unit_type_id,
                         lifecycle_status = 'vigente',
                         status = 'active',
                         updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id"
                );
                $statement->execute([
                    'name' => $name,
                    'short_name' => $shortName !== '' ? $shortName : null,
                    'code' => $code !== '' ? $code : null,
                    'moi_code' => $moiCode !== '' ? $moiCode : null,
                    'unit_type_id' => $unitTypeId,
                    'id' => $unitId,
                ]);

                $renamed = trim((string)$currentUnit['name']) !== $name;
                $eventType = $renamed ? 'renombre' : 'actualizacion';
                $eventNotes = $renamed
                    ? 'Nombre anterior: ' . (string)$currentUnit['name'] . '. Nombre nuevo: ' . $name . '.'
                    : 'Datos institucionales actualizados.';
                if ($notes !== '') {
                    $eventNotes .= ' Motivo: ' . $notes;
                }
                admin_record_event($pdo, $unitId, $eventType, $eventNotes);

                $pdo->commit();
                header('Location: estructura_admin.php?id=' . $unitId . '&ok=actualizada');
                exit;
            }

            if ($action === 'create') {
                $parentId = max(0, (int)($_POST['parent_id'] ?? 0));
                $name = trim((string)($_POST['name'] ?? ''));
                $shortName = trim((string)($_POST['short_name'] ?? ''));
                $code = trim((string)($_POST['code'] ?? ''));
                $unitTypeId = max(0, (int)($_POST['unit_type_id'] ?? 0));
                $notes = trim((string)($_POST['notes'] ?? ''));

                if ($parentId <= 0 || $name === '' || $unitTypeId <= 0) {
                    throw new RuntimeException('La unidad superior, el nombre y el tipo son obligatorios.');
                }

                $parent = one($pdo, 'SELECT * FROM organizational_units WHERE id = :id FOR UPDATE', ['id' => $parentId]);
                if (!$parent || (string)$parent['status'] !== 'active' || (string)$parent['lifecycle_status'] !== 'vigente') {
                    throw new RuntimeException('La unidad superior debe estar activa y vigente.');
                }
                if (admin_unit_is_protected($parent)) {
                    throw new RuntimeException('No se pueden crear unidades bajo un registro heredado protegido.');
                }

                $typeExists = (int)(one($pdo, 'SELECT COUNT(*) AS total FROM unit_types WHERE id = :id', ['id' => $unitTypeId])['total'] ?? 0);
                if ($typeExists <= 0) {
                    throw new RuntimeException('El tipo de unidad seleccionado no existe.');
                }

                $nextLevel = max(0, (int)($parent['level'] ?? 0)) + 1;
                $nextMoiLevel = max(0, (int)($parent['moi_level'] ?? 0)) + 1;

                $statement = $pdo->prepare(
                    "INSERT INTO organizational_units
                        (parent_id, unit_type_id, code, name, short_name, level, moi_level,
                         is_operational, is_administrative, command_structure, command_relationship,
                         territorial_scope, valid_from, lifecycle_status, structure_source,
                         legacy_frozen, status, lifecycle_notes, created_at, updated_at)
                     VALUES
                        (:parent_id, :unit_type_id, :code, :name, :short_name, :level, :moi_level,
                         0, 1, 'no_definido', 'no_definido', 'no_definido', CURDATE(),
                         'vigente', 'accion_posterior', 0, 'active', :lifecycle_notes,
                         CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
                );
                $statement->execute([
                    'parent_id' => $parentId,
                    'unit_type_id' => $unitTypeId,
                    'code' => $code !== '' ? $code : null,
                    'name' => $name,
                    'short_name' => $shortName !== '' ? $shortName : null,
                    'level' => $nextLevel,
                    'moi_level' => $nextMoiLevel,
                    'lifecycle_notes' => $notes !== '' ? mb_substr($notes, 0, 255) : 'Creada desde el administrador de estructura.',
                ]);

                $newUnitId = (int)$pdo->lastInsertId();
                admin_record_event(
                    $pdo,
                    $newUnitId,
                    'creacion',
                    $notes !== '' ? 'Unidad creada. Motivo: ' . $notes : 'Unidad creada desde el administrador de estructura.'
                );

                $pdo->commit();
                header('Location: estructura_admin.php?id=' . $newUnitId . '&ok=creada');
                exit;
            }

            if ($action === 'move') {
                $newParentId = max(0, (int)($_POST['new_parent_id'] ?? 0));
                $notes = trim((string)($_POST['notes'] ?? ''));
                if ($newParentId <= 0 || $newParentId === $unitId) {
                    throw new RuntimeException('Seleccione una unidad superior válida.');
                }

                $newParent = one($pdo, 'SELECT * FROM organizational_units WHERE id = :id FOR UPDATE', ['id' => $newParentId]);
                if (!$newParent || (string)$newParent['status'] !== 'active' || (string)$newParent['lifecycle_status'] !== 'vigente') {
                    throw new RuntimeException('La nueva unidad superior debe estar activa y vigente.');
                }
                if (admin_unit_is_protected($newParent)) {
                    throw new RuntimeException('La nueva unidad superior no puede ser un registro heredado protegido.');
                }

                $descendants = admin_descendant_ids($pdo, $unitId);
                if (in_array($newParentId, $descendants, true)) {
                    throw new RuntimeException('No puede mover una unidad dentro de una de sus propias dependencias.');
                }

                $oldParentId = (int)($currentUnit['parent_id'] ?? 0);
                $statement = $pdo->prepare(
                    "UPDATE organizational_units
                     SET parent_id = :parent_id,
                         level = :level,
                         moi_level = :moi_level,
                         updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id"
                );
                $statement->execute([
                    'parent_id' => $newParentId,
                    'level' => max(0, (int)($newParent['level'] ?? 0)) + 1,
                    'moi_level' => max(0, (int)($newParent['moi_level'] ?? 0)) + 1,
                    'id' => $unitId,
                ]);

                $eventNotes = 'Unidad superior anterior: ' . ($oldParentId > 0 ? (string)$oldParentId : 'sin unidad')
                    . '. Nueva unidad superior: ' . $newParentId . '.';
                if ($notes !== '') {
                    $eventNotes .= ' Motivo: ' . $notes;
                }
                admin_record_event($pdo, $unitId, 'actualizacion', $eventNotes);

                $pdo->commit();
                header('Location: estructura_admin.php?id=' . $unitId . '&ok=movida');
                exit;
            }

            if ($action === 'deactivate') {
                $notes = trim((string)($_POST['notes'] ?? ''));
                if ($notes === '') {
                    throw new RuntimeException('Indique el motivo de la desactivación.');
                }

                $activeChildren = (int)(one(
                    $pdo,
                    "SELECT COUNT(*) AS total
                     FROM organizational_units
                     WHERE parent_id = :id
                       AND status = 'active'
                       AND lifecycle_status = 'vigente'",
                    ['id' => $unitId]
                )['total'] ?? 0);
                if ($activeChildren > 0) {
                    throw new RuntimeException('Primero debe mover o desactivar las unidades subordinadas vigentes.');
                }

                $statement = $pdo->prepare(
                    "UPDATE organizational_units
                     SET status = 'inactive',
                         lifecycle_status = 'suprimida',
                         valid_to = CURDATE(),
                         lifecycle_notes = :notes,
                         updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id"
                );
                $statement->execute(['notes' => mb_substr($notes, 0, 255), 'id' => $unitId]);
                admin_record_event($pdo, $unitId, 'supresion', 'Unidad desactivada. Motivo: ' . $notes);

                $pdo->commit();
                header('Location: estructura_admin.php?id=' . $unitId . '&ok=desactivada');
                exit;
            }

            if ($action === 'reactivate') {
                $notes = trim((string)($_POST['notes'] ?? ''));
                $statement = $pdo->prepare(
                    "UPDATE organizational_units
                     SET status = 'active',
                         lifecycle_status = 'vigente',
                         valid_to = NULL,
                         lifecycle_notes = :notes,
                         updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id"
                );
                $statement->execute([
                    'notes' => $notes !== '' ? mb_substr($notes, 0, 255) : 'Unidad reactivada desde el administrador.',
                    'id' => $unitId,
                ]);
                admin_record_event(
                    $pdo,
                    $unitId,
                    'reactivacion',
                    $notes !== '' ? 'Unidad reactivada. Motivo: ' . $notes : 'Unidad reactivada desde el administrador.'
                );

                $pdo->commit();
                header('Location: estructura_admin.php?id=' . $unitId . '&ok=reactivada');
                exit;
            }

            throw new RuntimeException('La acción solicitada no está disponible.');
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errorMessage = $error->getMessage();
        }
    }
}

$unitTypes = rows($pdo, 'SELECT id, name, description FROM unit_types ORDER BY name');
$allCurrentUnits = rows(
    $pdo,
    "SELECT id, parent_id, name, short_name, code, level, status, lifecycle_status,
            structure_source, legacy_table
     FROM organizational_units
     ORDER BY name, id"
);

$selectedUnit = $selectedId > 0
    ? one(
        $pdo,
        "SELECT u.*, t.name AS unit_type_name, parent.name AS parent_name
         FROM organizational_units u
         JOIN unit_types t ON t.id = u.unit_type_id
         LEFT JOIN organizational_units parent ON parent.id = u.parent_id
         WHERE u.id = :id
         LIMIT 1",
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
        "SELECT u.*, t.name AS unit_type_name,
                (SELECT COUNT(*) FROM organizational_units child WHERE child.parent_id = u.id) AS child_count,
                (SELECT COUNT(*) FROM workforce_unit_matches m WHERE m.matched_unit_id = u.id) AS workforce_count
         FROM organizational_units u
         JOIN unit_types t ON t.id = u.unit_type_id
         WHERE u.parent_id = :parent_id
         ORDER BY
            CASE WHEN u.status = 'active' AND u.lifecycle_status = 'vigente' THEN 1 ELSE 2 END,
            COALESCE(u.level, 99), u.name",
        ['parent_id' => $selectedId]
    )
    : [];

$impact = $selectedId > 0
    ? one(
        $pdo,
        "SELECT
            (SELECT COUNT(*) FROM workforce_unit_matches WHERE matched_unit_id = :workforce_id) AS workforce_total,
            (SELECT COUNT(*) FROM unit_assignments WHERE organizational_unit_id = :assignments_id) AS assignments_total,
            (SELECT COUNT(*) FROM positions WHERE organizational_unit_id = :positions_id) AS positions_total,
            (SELECT COUNT(*) FROM structure_action_routing WHERE old_unit_id = :old_id OR new_unit_id = :new_id) AS actions_total,
            (SELECT COUNT(*) FROM organizational_units WHERE parent_id = :children_id) AS children_total",
        [
            'workforce_id' => $selectedId,
            'assignments_id' => $selectedId,
            'positions_id' => $selectedId,
            'old_id' => $selectedId,
            'new_id' => $selectedId,
            'children_id' => $selectedId,
        ]
    )
    : [];

$history = $selectedId > 0
    ? rows(
        $pdo,
        "SELECT e.*, replacement.name AS replacement_name
         FROM organizational_unit_lifecycle_events e
         LEFT JOIN organizational_units replacement ON replacement.id = e.replacement_unit_id
         WHERE e.organizational_unit_id = :unit_id
         ORDER BY e.effective_from DESC, e.id DESC
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

$descendantIds = $selectedId > 0 ? admin_descendant_ids($pdo, $selectedId) : [];
$parentOptions = array_values(array_filter(
    $allCurrentUnits,
    static function (array $candidate) use ($selectedId, $descendantIds): bool {
        $candidateId = (int)$candidate['id'];
        $legacyTable = strtoupper(trim((string)($candidate['legacy_table'] ?? '')));
        $source = strtolower(trim((string)($candidate['structure_source'] ?? '')));
        return $candidateId > 0
            && $candidateId !== $selectedId
            && !in_array($candidateId, $descendantIds, true)
            && (string)$candidate['status'] === 'active'
            && (string)$candidate['lifecycle_status'] === 'vigente'
            && $legacyTable !== 'TABCUAR'
            && $source !== 'legacy';
    }
));

$searchResults = [];
if ($search !== '') {
    $searchResults = rows(
        $pdo,
        "SELECT u.*, t.name AS unit_type_name, parent.name AS parent_name
         FROM organizational_units u
         JOIN unit_types t ON t.id = u.unit_type_id
         LEFT JOIN organizational_units parent ON parent.id = u.parent_id
         WHERE u.name LIKE :search
            OR u.short_name LIKE :search
            OR u.code LIKE :search
            OR u.moi_code LIKE :search
         ORDER BY
            CASE WHEN u.status = 'active' AND u.lifecycle_status = 'vigente' THEN 1 ELSE 2 END,
            u.name
         LIMIT 100",
        ['search' => '%' . $search . '%']
    );
}

$rootUnits = $selectedId === 0 && $search === ''
    ? rows(
        $pdo,
        "SELECT u.*, t.name AS unit_type_name,
                (SELECT COUNT(*) FROM organizational_units child WHERE child.parent_id = u.id) AS child_count
         FROM organizational_units u
         JOIN unit_types t ON t.id = u.unit_type_id
         WHERE (u.parent_id IS NULL OR COALESCE(u.level, 99) <= 2)
           AND u.status = 'active'
           AND u.lifecycle_status = 'vigente'
           AND UPPER(TRIM(COALESCE(u.legacy_table, ''))) <> 'TABCUAR'
         ORDER BY COALESCE(u.level, 99), u.name
         LIMIT 200"
    )
    : [];

$successMessages = [
    'actualizada' => 'La unidad fue actualizada y el cambio quedó registrado en el historial.',
    'creada' => 'La nueva unidad fue creada dentro de la estructura institucional.',
    'movida' => 'La unidad fue movida a su nueva unidad superior.',
    'desactivada' => 'La unidad fue desactivada. Los registros históricos se conservaron.',
    'reactivada' => 'La unidad fue reactivada correctamente.',
];
$successKey = trim((string)($_GET['ok'] ?? ''));

render_header('Administrar estructura', 'estructura_admin', 'Edición controlada de zonas, áreas, unidades y dependencias institucionales.');
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
    Los cambios afectan los nombres y destinos institucionales utilizados por el sistema. Los registros heredados permanecen protegidos.
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
        <div class="table-wrap">
            <table>
                <thead><tr><th>Unidad</th><th>Tipo</th><th>Unidad superior</th><th>Estado</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($searchResults as $result): ?>
                    <tr>
                        <td><span class="person-name"><?= h($result['name']) ?></span><span class="subtext"><?= h($result['code'] ?: 'Sin código') ?></span></td>
                        <td><?= h(str_replace('_', ' ', (string)$result['unit_type_name'])) ?></td>
                        <td><?= h($result['parent_name'] ?: 'Nivel principal') ?></td>
                        <td><span class="badge <?= (string)$result['status'] === 'active' && (string)$result['lifecycle_status'] === 'vigente' ? 'success' : 'warning' ?>"><?= h((string)$result['status'] . ' · ' . (string)$result['lifecycle_status']) ?></span></td>
                        <td><a class="button soft" href="estructura_admin.php?id=<?= h($result['id']) ?>">Administrar</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
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
        <article class="kpi-card card"><span class="kpi-label">Personal relacionado</span><strong class="kpi-value"><?= h(format_number($impact['workforce_total'] ?? 0)) ?></strong><span class="kpi-note">Registros actuales del pie de fuerza vinculados directamente.</span></article>
        <article class="kpi-card card info"><span class="kpi-label">Unidades subordinadas</span><strong class="kpi-value"><?= h(format_number($impact['children_total'] ?? 0)) ?></strong><span class="kpi-note">Dependencias registradas inmediatamente debajo.</span></article>
        <article class="kpi-card card warning"><span class="kpi-label">Acciones relacionadas</span><strong class="kpi-value"><?= h(format_number($impact['actions_total'] ?? 0)) ?></strong><span class="kpi-note">Traslados u otras acciones que utilizan esta unidad.</span></article>
        <article class="kpi-card card success"><span class="kpi-label">Asignaciones y posiciones</span><strong class="kpi-value"><?= h(format_number((int)($impact['assignments_total'] ?? 0) + (int)($impact['positions_total'] ?? 0))) ?></strong><span class="kpi-note">Relaciones estructurales que se conservan al cambiar el nombre.</span></article>
    </div>

    <?php if ($protected): ?>
        <div class="notice warning">
            <strong>Registro heredado protegido.</strong> Este registro no puede editarse, moverse ni desactivarse desde esta pantalla. Busque la unidad institucional vigente equivalente.
        </div>
    <?php endif; ?>

    <div class="two-column admin-columns">
        <section class="panel">
            <div class="panel-header"><div><h2>Editar unidad</h2><p>El identificador interno <?= h($selectedId) ?> no cambia al corregir el nombre o el código.</p></div></div>
            <form method="post" class="admin-form-grid">
                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="unit_id" value="<?= h($selectedId) ?>">
                <div class="field admin-span-2"><label>Nombre oficial</label><input name="name" value="<?= h($selectedUnit['name']) ?>" required <?= $protected ? 'disabled' : '' ?>></div>
                <div class="field"><label>Nombre corto</label><input name="short_name" value="<?= h($selectedUnit['short_name']) ?>" <?= $protected ? 'disabled' : '' ?>></div>
                <div class="field"><label>Tipo de unidad</label><select name="unit_type_id" required <?= $protected ? 'disabled' : '' ?>><?php foreach ($unitTypes as $type): ?><option value="<?= h($type['id']) ?>" <?= (int)$selectedUnit['unit_type_id'] === (int)$type['id'] ? 'selected' : '' ?>><?= h(str_replace('_', ' ', (string)$type['name'])) ?></option><?php endforeach; ?></select></div>
                <div class="field"><label>Código institucional</label><input name="code" value="<?= h($selectedUnit['code']) ?>" <?= $protected ? 'disabled' : '' ?>></div>
                <div class="field"><label>Código MOI</label><input name="moi_code" value="<?= h($selectedUnit['moi_code']) ?>" <?= $protected ? 'disabled' : '' ?>></div>
                <div class="field admin-span-2"><label>Motivo u observación</label><input name="notes" placeholder="Ejemplo: corrección ortográfica autorizada" <?= $protected ? 'disabled' : '' ?>></div>
                <?php if (!$protected): ?><div class="admin-span-2"><button class="button primary" type="submit">Guardar cambios</button></div><?php endif; ?>
            </form>
        </section>

        <section class="panel">
            <div class="panel-header"><div><h2>Estado y procedencia</h2><p>Información de control para proteger la estructura histórica.</p></div></div>
            <div class="detail-grid admin-detail-grid">
                <div class="detail-card card"><span>Estado</span><strong><?= h((string)$selectedUnit['status']) ?></strong></div>
                <div class="detail-card card"><span>Vigencia</span><strong><?= h((string)$selectedUnit['lifecycle_status']) ?></strong></div>
                <div class="detail-card card"><span>Fuente</span><strong><?= h((string)$selectedUnit['structure_source']) ?></strong></div>
                <div class="detail-card card"><span>Tabla heredada</span><strong><?= h($selectedUnit['legacy_table'] ?: 'No aplica') ?></strong></div>
            </div>
        </section>
    </div>

    <section class="panel">
        <div class="panel-header"><div><h2>Unidades subordinadas</h2><p>Navegue a cada nivel para editarlo o agregar nuevas dependencias.</p></div></div>
        <?php if (!$children): ?>
            <div class="notice info">Esta unidad no tiene dependencias subordinadas registradas.</div>
        <?php else: ?>
            <div class="table-wrap"><table><thead><tr><th>Unidad</th><th>Tipo</th><th>Personal directo</th><th>Dependencias</th><th>Estado</th><th></th></tr></thead><tbody>
            <?php foreach ($children as $child): ?>
                <tr>
                    <td><span class="person-name"><?= h($child['name']) ?></span><span class="subtext"><?= h($child['code'] ?: 'Sin código') ?><?= strtoupper((string)$child['legacy_table']) === 'TABCUAR' ? ' · Registro heredado protegido' : '' ?></span></td>
                    <td><?= h(str_replace('_', ' ', (string)$child['unit_type_name'])) ?></td>
                    <td><?= h(format_number($child['workforce_count'])) ?></td>
                    <td><?= h(format_number($child['child_count'])) ?></td>
                    <td><span class="badge <?= (string)$child['status'] === 'active' && (string)$child['lifecycle_status'] === 'vigente' ? 'success' : 'warning' ?>"><?= h((string)$child['status'] . ' · ' . (string)$child['lifecycle_status']) ?></span></td>
                    <td><a class="button soft" href="estructura_admin.php?id=<?= h($child['id']) ?>">Abrir</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div>
        <?php endif; ?>
    </section>

    <?php if (!$protected && (string)$selectedUnit['status'] === 'active' && (string)$selectedUnit['lifecycle_status'] === 'vigente'): ?>
        <div class="two-column admin-columns">
            <section class="panel">
                <div class="panel-header"><div><h2>Agregar dependencia</h2><p>La nueva unidad quedará subordinada directamente a <?= h($selectedUnit['name']) ?>.</p></div></div>
                <form method="post" class="admin-form-grid">
                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>"><input type="hidden" name="action" value="create"><input type="hidden" name="parent_id" value="<?= h($selectedId) ?>">
                    <div class="field admin-span-2"><label>Nombre oficial</label><input name="name" required></div>
                    <div class="field"><label>Nombre corto</label><input name="short_name"></div>
                    <div class="field"><label>Tipo de unidad</label><select name="unit_type_id" required><option value="">Seleccione</option><?php foreach ($unitTypes as $type): ?><option value="<?= h($type['id']) ?>"><?= h(str_replace('_', ' ', (string)$type['name'])) ?></option><?php endforeach; ?></select></div>
                    <div class="field"><label>Código institucional</label><input name="code"></div>
                    <div class="field"><label>Motivo u observación</label><input name="notes" placeholder="Creación, reorganización u otra razón"></div>
                    <div class="admin-span-2"><button class="button primary" type="submit">Agregar dependencia</button></div>
                </form>
            </section>

            <section class="panel">
                <div class="panel-header"><div><h2>Mover unidad</h2><p>Cambia la unidad superior sin modificar su identificador ni sus referencias históricas.</p></div></div>
                <form method="post" class="admin-form-grid">
                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>"><input type="hidden" name="action" value="move"><input type="hidden" name="unit_id" value="<?= h($selectedId) ?>">
                    <div class="field admin-span-2"><label>Nueva unidad superior</label><select name="new_parent_id" required><option value="">Seleccione</option><?php foreach ($parentOptions as $option): ?><option value="<?= h($option['id']) ?>"><?= h($option['name']) ?><?= !empty($option['code']) ? ' · ' . h($option['code']) : '' ?></option><?php endforeach; ?></select></div>
                    <div class="field admin-span-2"><label>Motivo del movimiento</label><input name="notes" required placeholder="Ejemplo: corrección de dependencia jerárquica"></div>
                    <div class="admin-span-2"><button class="button" type="submit" data-confirm="¿Confirma que desea mover esta unidad y todas sus dependencias subordinadas?">Mover unidad</button></div>
                </form>
            </section>
        </div>
    <?php endif; ?>

    <?php if (!$protected): ?>
        <section class="panel">
            <div class="panel-header"><div><h2><?= (string)$selectedUnit['status'] === 'active' ? 'Desactivar unidad' : 'Reactivar unidad' ?></h2><p>No se elimina el registro. Los traslados, asignaciones y referencias históricas se mantienen.</p></div></div>
            <form method="post" class="admin-form-grid admin-status-form">
                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>"><input type="hidden" name="action" value="<?= (string)$selectedUnit['status'] === 'active' ? 'deactivate' : 'reactivate' ?>"><input type="hidden" name="unit_id" value="<?= h($selectedId) ?>">
                <div class="field"><label>Motivo</label><input name="notes" <?= (string)$selectedUnit['status'] === 'active' ? 'required' : '' ?> placeholder="Explique la razón administrativa"></div>
                <div><button class="button <?= (string)$selectedUnit['status'] === 'active' ? 'danger' : 'primary' ?>" type="submit" data-confirm="¿Confirma este cambio de estado?"><?= (string)$selectedUnit['status'] === 'active' ? 'Desactivar' : 'Reactivar' ?></button></div>
            </form>
        </section>
    <?php endif; ?>

    <section class="panel">
        <div class="panel-header"><div><h2>Historial de cambios</h2><p>Últimos eventos registrados para esta unidad.</p></div></div>
        <?php if (!$history): ?>
            <div class="notice info">Todavía no hay eventos administrativos registrados para esta unidad.</div>
        <?php else: ?>
            <div class="table-wrap"><table><thead><tr><th>Fecha</th><th>Evento</th><th>Detalle</th><th>Responsable</th></tr></thead><tbody>
            <?php foreach ($history as $event): ?>
                <tr><td><?= h($event['effective_from']) ?></td><td><span class="badge info"><?= h($event['event_type']) ?></span></td><td><?= h($event['notes'] ?: 'Sin observación') ?></td><td><?= h($event['created_by'] ?: 'No indicado') ?></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php render_footer(); ?>
