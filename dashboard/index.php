<?php
$configPath = __DIR__ . '/config.php';
if (!file_exists($configPath)) {
    http_response_code(500);
    echo '<h1>Dashboard MOI</h1>';
    echo '<p>Falta el archivo <strong>dashboard/config.php</strong>.</p>';
    echo '<p>Copia <strong>dashboard/config.example.php</strong> como <strong>dashboard/config.php</strong> y ajusta las credenciales de MySQL.</p>';
    exit;
}

$config = require $configPath;
$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=%s',
    $config['db_host'],
    $config['db_port'],
    $config['db_name'],
    $config['charset']
);

try {
    $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo '<h1>Error de conexion</h1>';
    echo '<p>' . htmlspecialchars($e->getMessage()) . '</p>';
    exit;
}

function rows(PDO $pdo, string $sql, array $params = []): array {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function one(PDO $pdo, string $sql, array $params = []): array {
    $result = rows($pdo, $sql, $params);
    return $result[0] ?? [];
}

function e(?string $value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function table_exists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) AS total FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table');
    $stmt->execute(['table' => $table]);
    return ((int)($stmt->fetch()['total'] ?? 0)) > 0;
}

$tipo = $_GET['tipo'] ?? '';
$alcance = $_GET['alcance'] ?? '';
$buscar = trim($_GET['buscar'] ?? '');

$resumen = one($pdo, 'SELECT * FROM vw_moi_resumen_general');
$porTipo = rows($pdo, 'SELECT * FROM vw_moi_unidades_por_tipo WHERE total > 0');
$porAlcance = rows($pdo, 'SELECT * FROM vw_moi_unidades_por_alcance');
$pendientes = rows($pdo, 'SELECT * FROM vw_moi_pendientes_revision LIMIT 50');
$sinRelacion = rows($pdo, 'SELECT * FROM vw_moi_unidades_sin_relacion_superior LIMIT 50');
$sedesSinUbicacion = rows($pdo, 'SELECT * FROM vw_moi_sedes_sin_ubicacion LIMIT 50');
$noVigentes = rows($pdo, 'SELECT * FROM vw_moi_unidades_no_vigentes_dashboard LIMIT 50');

$zonasCabeceraTotal = table_exists($pdo, 'moi_zonas_cabecera_vigentes') ? (one($pdo, "SELECT COUNT(*) AS total FROM moi_zonas_cabecera_vigentes WHERE lifecycle_status = 'vigente'")['total'] ?? 0) : 0;
$direccionesCabeceraTotal = table_exists($pdo, 'moi_direcciones_cabecera_vigentes') ? (one($pdo, "SELECT COUNT(*) AS total FROM moi_direcciones_cabecera_vigentes WHERE lifecycle_status = 'vigente'")['total'] ?? 0) : 0;
$zonasCabecera = table_exists($pdo, 'vw_moi_zonas_cabecera_vigentes') ? rows($pdo, 'SELECT * FROM vw_moi_zonas_cabecera_vigentes LIMIT 80') : [];
$direccionesCabecera = table_exists($pdo, 'vw_moi_direcciones_cabecera_vigentes') ? rows($pdo, 'SELECT * FROM vw_moi_direcciones_cabecera_vigentes LIMIT 80') : [];
$zonasSinMatch = table_exists($pdo, 'vw_moi_zonas_cabecera_sin_match') ? rows($pdo, 'SELECT * FROM vw_moi_zonas_cabecera_sin_match') : [];
$direccionesSinMatch = table_exists($pdo, 'vw_moi_direcciones_cabecera_sin_match') ? rows($pdo, 'SELECT * FROM vw_moi_direcciones_cabecera_sin_match') : [];

$where = [];
$params = [];
if ($tipo !== '') {
    $where[] = 'tipo_unidad = :tipo';
    $params['tipo'] = $tipo;
}
if ($alcance !== '') {
    $where[] = 'territorial_scope = :alcance';
    $params['alcance'] = $alcance;
}
if ($buscar !== '') {
    $where[] = '(name LIKE :buscar OR code LIKE :buscar OR moi_code LIKE :buscar)';
    $params['buscar'] = '%' . $buscar . '%';
}
$sqlArbol = 'SELECT * FROM vw_moi_arbol_unidades';
if ($where) {
    $sqlArbol .= ' WHERE ' . implode(' AND ', $where);
}
$sqlArbol .= ' LIMIT 300';
$arbol = rows($pdo, $sqlArbol, $params);

$tiposFiltro = rows($pdo, 'SELECT DISTINCT tipo_unidad FROM vw_moi_arbol_unidades WHERE tipo_unidad IS NOT NULL ORDER BY tipo_unidad');
$alcancesFiltro = rows($pdo, 'SELECT DISTINCT territorial_scope FROM vw_moi_arbol_unidades WHERE territorial_scope IS NOT NULL ORDER BY territorial_scope');
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard MOI 65.16</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; background: #f4f6f8; color: #1f2937; }
        header { background: #111827; color: white; padding: 18px 28px; }
        header h1 { margin: 0; font-size: 22px; }
        header p { margin: 6px 0 0; color: #d1d5db; }
        header a { color: #d1d5db; font-weight: bold; margin-right: 14px; }
        main { padding: 24px; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 14px; margin-bottom: 20px; }
        .card { background: white; border-radius: 10px; padding: 16px; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
        .card .label { font-size: 12px; color: #6b7280; text-transform: uppercase; letter-spacing: .04em; }
        .card .value { font-size: 26px; font-weight: bold; margin-top: 6px; }
        section { background: white; border-radius: 10px; padding: 16px; margin-bottom: 20px; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
        h2 { font-size: 18px; margin: 0 0 12px; }
        h3 { font-size: 15px; margin: 12px 0 8px; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { border-bottom: 1px solid #e5e7eb; text-align: left; padding: 8px; vertical-align: top; }
        th { background: #f9fafb; color: #374151; }
        .two { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px; }
        .filters { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 12px; }
        input, select, button { padding: 8px; border: 1px solid #d1d5db; border-radius: 6px; }
        button { background: #111827; color: white; cursor: pointer; }
        .badge { display: inline-block; padding: 3px 7px; border-radius: 999px; background: #eef2ff; font-size: 12px; }
        .danger { background: #fef2f2; }
        .warn { background: #fffbeb; }
        .info { background: #eff6ff; }
        .muted { color: #6b7280; font-size: 12px; }
    </style>
</head>
<body>
<header>
    <h1>Dashboard MOI 65.16</h1>
    <p>
        Estructura vigente, trazabilidad legacy y cambios posteriores.
        <br>
        <a href="revision.php">Revision / aprobaciones</a>
        <a href="index.php">Dashboard</a>
    </p>
</header>
<main>
    <div class="grid">
        <div class="card"><div class="label">Unidades vigentes</div><div class="value"><?= e($resumen['total_unidades'] ?? '0') ?></div></div>
        <div class="card"><div class="label">Nacionales</div><div class="value"><?= e($resumen['unidades_nacionales'] ?? '0') ?></div></div>
        <div class="card"><div class="label">Regionales</div><div class="value"><?= e($resumen['unidades_regionales'] ?? '0') ?></div></div>
        <div class="card"><div class="label">Zonales</div><div class="value"><?= e($resumen['unidades_zonales'] ?? '0') ?></div></div>
        <div class="card"><div class="label">Sedes</div><div class="value"><?= e($resumen['sedes_detectadas'] ?? '0') ?></div></div>
        <div class="card"><div class="label">No vigentes</div><div class="value"><?= e($resumen['unidades_no_vigentes'] ?? '0') ?></div></div>
        <div class="card"><div class="label">Pendientes</div><div class="value"><?= e($resumen['pendientes_revision'] ?? '0') ?></div></div>
        <div class="card"><div class="label">Zonas cabecera</div><div class="value"><?= e((string)$zonasCabeceraTotal) ?></div></div>
        <div class="card"><div class="label">Direcciones cabecera</div><div class="value"><?= e((string)$direccionesCabeceraTotal) ?></div></div>
    </div>

    <div class="two">
        <section>
            <h2>Unidades vigentes por tipo</h2>
            <table>
                <thead><tr><th>Tipo</th><th>Total</th></tr></thead>
                <tbody>
                <?php foreach ($porTipo as $row): ?>
                    <tr><td><?= e($row['tipo_unidad']) ?></td><td><?= e($row['total']) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>
        <section>
            <h2>Unidades vigentes por alcance</h2>
            <table>
                <thead><tr><th>Alcance</th><th>Total</th></tr></thead>
                <tbody>
                <?php foreach ($porAlcance as $row): ?>
                    <tr><td><?= e($row['alcance']) ?></td><td><?= e($row['total']) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    </div>

    <div class="two">
        <section class="info">
            <h2>Cabeceras de zonas vigentes</h2>
            <p class="muted">Catalogo funcional de zonas vigentes. Se enlaza a unidades candidatas sin modificar legacy.</p>
            <table>
                <thead><tr><th>#</th><th>Cabecera</th><th>Unidad enlazada/candidata</th><th>Estado</th></tr></thead>
                <tbody>
                <?php foreach ($zonasCabecera as $row): ?>
                    <tr><td><?= e($row['zone_number']) ?></td><td><?= e($row['zone_label']) ?></td><td><?= e($row['unit_name']) ?></td><td><?= e($row['match_status']) ?> / <?= e($row['confidence_level']) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if ($zonasSinMatch): ?><h3>Sin candidato</h3><ul><?php foreach ($zonasSinMatch as $row): ?><li><?= e($row['zone_label']) ?></li><?php endforeach; ?></ul><?php endif; ?>
        </section>
        <section class="info">
            <h2>Cabeceras de direcciones vigentes</h2>
            <p class="muted">Catalogo funcional de direcciones vigentes. Se enlaza a unidades candidatas sin modificar legacy.</p>
            <table>
                <thead><tr><th>#</th><th>Cabecera</th><th>Unidad enlazada/candidata</th><th>Estado</th></tr></thead>
                <tbody>
                <?php foreach ($direccionesCabecera as $row): ?>
                    <tr><td><?= e($row['direction_number']) ?></td><td><?= e($row['direction_label']) ?></td><td><?= e($row['unit_name']) ?></td><td><?= e($row['match_status']) ?> / <?= e($row['confidence_level']) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if ($direccionesSinMatch): ?><h3>Sin candidato</h3><ul><?php foreach ($direccionesSinMatch as $row): ?><li><?= e($row['direction_label']) ?></li><?php endforeach; ?></ul><?php endif; ?>
        </section>
    </div>

    <section>
        <h2>Arbol / listado de unidades vigentes</h2>
        <p class="muted">El legacy se conserva como origen historico. Este listado muestra la estructura vigente.</p>
        <form class="filters" method="get">
            <input type="text" name="buscar" placeholder="Buscar unidad o codigo" value="<?= e($buscar) ?>">
            <select name="tipo">
                <option value="">Todos los tipos</option>
                <?php foreach ($tiposFiltro as $row): ?>
                    <option value="<?= e($row['tipo_unidad']) ?>" <?= $tipo === $row['tipo_unidad'] ? 'selected' : '' ?>><?= e($row['tipo_unidad']) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="alcance">
                <option value="">Todos los alcances</option>
                <?php foreach ($alcancesFiltro as $row): ?>
                    <option value="<?= e($row['territorial_scope']) ?>" <?= $alcance === $row['territorial_scope'] ? 'selected' : '' ?>><?= e($row['territorial_scope']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit">Filtrar</button>
        </form>
        <table>
            <thead><tr><th>Unidad</th><th>Superior</th><th>Tipo</th><th>Alcance</th><th>Mando</th><th>Vigencia</th><th>Codigo</th><th>Origen</th></tr></thead>
            <tbody>
            <?php foreach ($arbol as $row): ?>
                <tr>
                    <td><strong><?= e($row['name']) ?></strong></td>
                    <td><?= e($row['unidad_superior']) ?></td>
                    <td><span class="badge"><?= e($row['tipo_unidad']) ?></span></td>
                    <td><?= e($row['territorial_scope']) ?></td>
                    <td><?= e($row['command_structure']) ?> / <?= e($row['command_relationship']) ?></td>
                    <td><?= e($row['valid_from']) ?> - <?= e($row['valid_to'] ?: 'vigente') ?></td>
                    <td><?= e($row['code']) ?></td>
                    <td><?= e($row['legacy_table']) ?>: <?= e($row['legacy_id']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <div class="two">
        <section class="warn">
            <h2>Pendientes de revision</h2>
            <table>
                <thead><tr><th>Origen</th><th>Nombre</th><th>Sugerido</th><th>Confianza</th></tr></thead>
                <tbody>
                <?php foreach ($pendientes as $row): ?>
                    <tr><td><?= e($row['source_table']) ?></td><td><?= e($row['source_name']) ?></td><td><?= e($row['suggested_unit_type']) ?> / <?= e($row['suggested_scope']) ?></td><td><?= e($row['confidence_level']) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>
        <section class="danger">
            <h2>Alertas de calidad</h2>
            <h3>Sin relacion superior</h3>
            <table>
                <thead><tr><th>Codigo</th><th>Unidad</th><th>Tipo</th></tr></thead>
                <tbody>
                <?php foreach ($sinRelacion as $row): ?>
                    <tr><td><?= e($row['code']) ?></td><td><?= e($row['name']) ?></td><td><?= e($row['tipo_unidad']) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <h3>Sedes sin ubicacion</h3>
            <table>
                <thead><tr><th>Codigo</th><th>Unidad</th><th>Tipo sede</th></tr></thead>
                <tbody>
                <?php foreach ($sedesSinUbicacion as $row): ?>
                    <tr><td><?= e($row['code']) ?></td><td><?= e($row['name']) ?></td><td><?= e($row['tipo_sede']) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    </div>

    <section class="info">
        <h2>Unidades no vigentes</h2>
        <p class="muted">No se eliminan del sistema. Se conservan para trazabilidad historica.</p>
        <table>
            <thead><tr><th>Codigo</th><th>Unidad</th><th>Estado</th><th>Vigencia</th><th>Reemplazo</th><th>Nota</th></tr></thead>
            <tbody>
            <?php foreach ($noVigentes as $row): ?>
                <tr><td><?= e($row['code']) ?></td><td><?= e($row['name']) ?></td><td><?= e($row['lifecycle_status']) ?></td><td><?= e($row['valid_from']) ?> - <?= e($row['valid_to']) ?></td><td><?= e($row['unidad_reemplazo']) ?></td><td><?= e($row['lifecycle_notes']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
</main>
</body>
</html>
