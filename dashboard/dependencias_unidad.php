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

$dependencyText = "UPPER(TRIM(COALESCE(d.internal_detail, '')))";
$dependencyExpression = "
CASE
    WHEN {$dependencyText} IN ('CICLISTA', 'GRU CICLISTA', 'GRUPO CICLISTA')
        THEN 'CICLISTA'
    WHEN {$dependencyText} REGEXP '^GUARNIC|^GUARNICION$'
        THEN 'Guarnición'
    WHEN {$dependencyText} REGEXP '^(G[ .]*POL[ .]*[A-Z]|GRUPO[[:space:]]+POLICIAL[[:space:]]+[A-Z]|GRUPO[[:space:]]+[A-Z])$'
        THEN CONCAT(
            'GRUPO POLICIAL ',
            RIGHT(REPLACE(REPLACE({$dependencyText}, ' ', ''), '.', ''), 1)
        )
    WHEN {$dependencyText} REGEXP '^(S[ .]*ESPEC|SERVICIO[[:space:]]+ESPECIAL)$'
        THEN 'SERVICIO ESPECIAL'
    WHEN {$dependencyText} REGEXP '^(B[ .]*DE[ .]*MUSIC|BANDA[[:space:]]+DE[[:space:]]+MUSICA)$'
        THEN 'BANDA DE MÚSICA'
    WHEN {$dependencyText} REGEXP '^(S[ .]*CARLO|SAN[[:space:]]+CARLOS)$'
        THEN 'SAN CARLOS'
    WHEN NULLIF(TRIM(COALESCE(d.internal_detail, '')), '') IS NULL
        THEN 'Sin detalle interno'
    ELSE TRIM(d.internal_detail)
END";

$dependencies = rows(
    $pdo,
    "SELECT
        {$dependencyExpression} AS dependency_name,
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

$meaningfulDependencies = array_values(array_filter(
    $dependencies,
    static fn (array $dependency): bool => (string)($dependency['dependency_name'] ?? '') !== 'Sin detalle interno'
));

if (count($meaningfulDependencies) < 2) {
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
                <th>Acción</th>
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
