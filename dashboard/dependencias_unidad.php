<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

header('Content-Type: text/html; charset=utf-8');

$unitId = (int)($_GET['unit_id'] ?? 0);
$source = current_workforce_source($pdo, (int)($_GET['source_id'] ?? 0));
$sourceId = (int)($source['id'] ?? 0);

if ($unitId <= 0 || $sourceId <= 0) {
    exit;
}

$unit = one(
    $pdo,
    "SELECT id, name, code
     FROM organizational_units
     WHERE id = :id
       AND status = 'active'
       AND lifecycle_status = 'vigente'
     LIMIT 1",
    ['id' => $unitId]
);

if (!$unit || (string)($unit['code'] ?? '') === 'DN-01') {
    exit;
}

$dependencies = rows(
    $pdo,
    "SELECT
        COALESCE(NULLIF(TRIM(d.internal_detail), ''), 'Sin detalle interno') AS dependency_name,
        COUNT(*) AS total
     FROM vw_workforce_match_detail d
     WHERE d.source_id = :source_id
       AND d.matched_unit_id = :unit_id
     GROUP BY dependency_name
     ORDER BY
        CASE WHEN dependency_name = 'Sin detalle interno' THEN 2 ELSE 1 END,
        total DESC,
        dependency_name",
    [
        'source_id' => $sourceId,
        'unit_id' => $unitId,
    ]
);

if (!$dependencies) {
    exit;
}
?>
<section class="panel" id="dependencias-secciones">
    <div class="panel-header">
        <div>
            <h2>Dependencias o secciones</h2>
            <p>Último nivel de organización registrado dentro de <?= h($unit['name']) ?>. Abra una dependencia para consultar únicamente su personal.</p>
        </div>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>Dependencia o sección</th>
                <th>Personal</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($dependencies as $dependency): ?>
                <?php
                $dependencyName = (string)$dependency['dependency_name'];
                $dependencyUrl = query_url('dependencia_detalle.php', [
                    'unit_id' => $unitId,
                    'source_id' => $sourceId,
                    'dependencia' => $dependencyName,
                ]);
                ?>
                <tr>
                    <td><span class="person-name"><?= h($dependencyName) ?></span></td>
                    <td><?= h(format_number($dependency['total'])) ?></td>
                    <td><a class="button soft" href="<?= h($dependencyUrl) ?>">Abrir</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
