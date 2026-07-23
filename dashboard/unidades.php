<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$group = (string)($_GET['grupo'] ?? 'todas');
$allowedGroups = ['direcciones', 'zonas', 'servicios', 'todas'];
if (!in_array($group, $allowedGroups, true)) {
    $group = 'todas';
}

$source = current_workforce_source($pdo, (int)($_GET['source_id'] ?? 0));
$sourceId = (int)($source['id'] ?? 0);
$search = trim((string)($_GET['buscar'] ?? ''));

$groupInfo = [
    'direcciones' => [
        'title' => 'Direcciones nacionales',
        'active' => 'direcciones',
        'description' => 'Consulte el personal asignado directamente a cada dirección y abra sus dependencias internas.',
        'condition' => "(u.code LIKE 'DN-%' OR u.code = 'SG-1')",
    ],
    'zonas' => [
        'title' => 'Zonas policiales',
        'active' => 'zonas',
        'description' => 'Navegue las zonas y consulte tanto su personal directo como las referencias territoriales.',
        'condition' => "u.code LIKE 'ZP-%'",
    ],
    'servicios' => [
        'title' => 'Servicios policiales',
        'active' => 'servicios',
        'description' => 'Revise los servicios especializados y la cantidad de funcionarios vinculados.',
        'condition' => "u.code LIKE 'SP-%'",
    ],
    'todas' => [
        'title' => 'Estructura institucional',
        'active' => 'estructura',
        'description' => 'Listado de unidades vigentes organizado para consulta, sin mostrar primero los datos técnicos.',
        'condition' => '1 = 1',
    ],
][$group];

$where = [
    "u.status = 'active'",
    "u.lifecycle_status = 'vigente'",
    $groupInfo['condition'],
    "NOT (
        UPPER(TRIM(COALESCE(u.legacy_table, ''))) = 'TABCUAR'
        AND EXISTS (
            SELECT 1
            FROM organizational_units canonical
            WHERE canonical.id <> u.id
              AND canonical.status = 'active'
              AND canonical.lifecycle_status = 'vigente'
              AND canonical.legacy_table IN ('MOI_CABECERA_DIRECCION', 'MOI_CABECERA_ZONA')
              AND UPPER(TRIM(canonical.name)) = UPPER(TRIM(u.name))
        )
    )",
];
$params = [
    'source_direct' => $sourceId,
    'source_territorial' => $sourceId,
];

if ($search !== '') {
    $where[] = '(u.name LIKE :search_name OR u.short_name LIKE :search_short OR u.code LIKE :search_code OR u.moi_code LIKE :search_moi)';
    $params['search_name'] = '%' . $search . '%';
    $params['search_short'] = '%' . $search . '%';
    $params['search_code'] = '%' . $search . '%';
    $params['search_moi'] = '%' . $search . '%';
}

$units = rows(
    $pdo,
    "SELECT
        u.id,
        u.code,
        u.moi_code,
        u.name,
        u.short_name,
        u.level,
        u.moi_level,
        u.territorial_scope,
        u.functional_axis,
        parent.name AS parent_name,
        (SELECT COUNT(*)
         FROM workforce_unit_matches m1
         JOIN workforce_personnel_staging p1 ON p1.id = m1.personnel_staging_id
         WHERE m1.matched_unit_id = u.id
           AND p1.source_id = :source_direct) AS personal_directo,
        (SELECT COUNT(*)
         FROM workforce_unit_matches m2
         JOIN workforce_personnel_staging p2 ON p2.id = m2.personnel_staging_id
         WHERE m2.territorial_zone_unit_id = u.id
           AND p2.source_id = :source_territorial) AS referencia_territorial,
        (SELECT COUNT(*)
         FROM organizational_units child
         WHERE child.parent_id = u.id
           AND child.status = 'active'
           AND child.lifecycle_status = 'vigente') AS unidades_hijas
     FROM organizational_units u
     LEFT JOIN organizational_units parent ON parent.id = u.parent_id
     WHERE " . implode(' AND ', $where) . "
     ORDER BY
        COALESCE(u.moi_level, u.level, 99),
        CASE WHEN u.code LIKE 'ZP-%' THEN CAST(SUBSTRING(u.code, 4) AS UNSIGNED) ELSE 999 END,
        u.name
     LIMIT 600",
    $params
);

$totalDirect = array_sum(array_map(static fn (array $unit): int => (int)$unit['personal_directo'], $units));
$totalTerritorial = array_sum(array_map(static fn (array $unit): int => (int)$unit['referencia_territorial'], $units));
$totalChildren = array_sum(array_map(static fn (array $unit): int => (int)$unit['unidades_hijas'], $units));

render_header($groupInfo['title'], $groupInfo['active'], $groupInfo['description']);
render_breadcrumbs([
    ['label' => 'Inicio', 'href' => 'index.php'],
    ['label' => $groupInfo['title']],
]);
?>

<div class="page-intro">
    <div>
        <h2><?= h($groupInfo['title']) ?></h2>
        <p><?= h($groupInfo['description']) ?></p>
    </div>
    <a class="button soft" href="pie_fuerza.php?source_id=<?= h($sourceId) ?>">Buscar una persona</a>
</div>

<div class="kpi-grid">
    <article class="kpi-card card">
        <span class="kpi-label">Unidades encontradas</span>
        <strong class="kpi-value"><?= h(format_number(count($units))) ?></strong>
        <span class="kpi-note">Unidades vigentes que cumplen el criterio.</span>
    </article>
    <article class="kpi-card card success">
        <span class="kpi-label">Personal directo</span>
        <strong class="kpi-value"><?= h(format_number($totalDirect)) ?></strong>
        <span class="kpi-note">Funcionarios cuya unidad funcional es una de las mostradas.</span>
    </article>
    <article class="kpi-card card info">
        <span class="kpi-label">Referencias territoriales</span>
        <strong class="kpi-value"><?= h(format_number($totalTerritorial)) ?></strong>
        <span class="kpi-note">Personal de otras unidades que presta servicio en estas zonas.</span>
    </article>
    <article class="kpi-card card">
        <span class="kpi-label">Unidades dependientes</span>
        <strong class="kpi-value"><?= h(format_number($totalChildren)) ?></strong>
        <span class="kpi-note">Áreas, departamentos, secciones o unidades subordinadas.</span>
    </article>
</div>

<section class="panel filters-panel">
    <form method="get">
        <input type="hidden" name="grupo" value="<?= h($group) ?>">
        <input type="hidden" name="source_id" value="<?= h($sourceId) ?>">
        <div class="search-bar">
            <input type="search" name="buscar" value="<?= h($search) ?>" placeholder="Buscar por nombre o código de unidad" aria-label="Buscar unidad">
            <button class="button primary" type="submit">Buscar</button>
            <?php if ($search !== ''): ?>
                <a class="button" href="unidades.php?grupo=<?= h($group) ?>&source_id=<?= h($sourceId) ?>">Limpiar</a>
            <?php endif; ?>
        </div>
    </form>
</section>

<section class="panel">
    <div class="panel-header">
        <div>
            <h2>Unidades</h2>
            <p>Abra una unidad para ver su personal, sus dependencias y su distribución territorial.</p>
        </div>
    </div>

    <?php if (!$units): ?>
        <?php render_empty_state('No se encontraron unidades', 'Cambie el texto de búsqueda o seleccione otra sección del menú.'); ?>
    <?php else: ?>
        <div class="unit-list">
            <?php foreach ($units as $unit): ?>
                <article class="unit-card card">
                    <div>
                        <h3><a href="unidad_detalle.php?id=<?= h($unit['id']) ?>&source_id=<?= h($sourceId) ?>"><?= h($unit['name']) ?></a></h3>
                        <p>
                            <?= h($unit['parent_name'] ?: 'Unidad de nivel superior') ?>
                            <?php if (!empty($unit['code'])): ?> · Código <?= h($unit['code']) ?><?php endif; ?>
                        </p>
                        <div class="unit-meta">
                            <span class="badge neutral"><?= h(level_label(
                                $group === 'direcciones' ? 'direccion' : ($group === 'zonas' ? 'zona' : ($group === 'servicios' ? 'servicio' : 'unidad'))
                            )) ?></span>
                            <?php if ((int)$unit['unidades_hijas'] > 0): ?>
                                <span class="badge info"><?= h(format_number($unit['unidades_hijas'])) ?> dependencias</span>
                            <?php endif; ?>
                            <?php if ((int)$unit['referencia_territorial'] > 0): ?>
                                <span class="badge success"><?= h(format_number($unit['referencia_territorial'])) ?> referencias territoriales</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="unit-count">
                        <strong><?= h(format_number($unit['personal_directo'])) ?></strong>
                        <span>personal directo</span>
                        <a class="button soft" href="unidad_detalle.php?id=<?= h($unit['id']) ?>&source_id=<?= h($sourceId) ?>">Ver detalle</a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php render_footer(); ?>
