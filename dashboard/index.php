<?php
$configPath = __DIR__ . '/config.php';
if (!file_exists($configPath)) {
    http_response_code(500);
    echo '<h1>Dashboard MOI</h1><p>Falta dashboard/config.php</p>';
    exit;
}
$config = require $configPath;
$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $config['db_host'], $config['db_port'], $config['db_name'], $config['charset']);
try {
    $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
} catch (Throwable $e) {
    http_response_code(500);
    echo '<h1>Error de conexion</h1><p>'.htmlspecialchars($e->getMessage()).'</p>';
    exit;
}
function rows(PDO $pdo, string $sql, array $params=[]): array { $s=$pdo->prepare($sql); $s->execute($params); return $s->fetchAll(); }
function one(PDO $pdo, string $sql, array $params=[]): array { $r=rows($pdo,$sql,$params); return $r[0] ?? []; }
function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function table_exists(PDO $pdo, string $table): bool { $s=$pdo->prepare('SELECT COUNT(*) total FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=:t'); $s->execute(['t'=>$table]); return ((int)($s->fetch()['total'] ?? 0))>0; }

$tipo = $_GET['tipo'] ?? '';
$alcance = $_GET['alcance'] ?? '';
$buscar = trim($_GET['buscar'] ?? '');
$resumen = one($pdo, 'SELECT * FROM vw_moi_resumen_general');
$porTipo = rows($pdo, 'SELECT * FROM vw_moi_unidades_por_tipo WHERE total > 0');
$porAlcance = rows($pdo, 'SELECT * FROM vw_moi_unidades_por_alcance');
$zonasCabeceraTotal = table_exists($pdo, 'moi_zonas_cabecera_vigentes') ? (one($pdo, "SELECT COUNT(*) total FROM moi_zonas_cabecera_vigentes WHERE lifecycle_status='vigente'")['total'] ?? 0) : 0;
$direccionesCabeceraTotal = table_exists($pdo, 'moi_direcciones_cabecera_vigentes') ? (one($pdo, "SELECT COUNT(*) total FROM moi_direcciones_cabecera_vigentes WHERE lifecycle_status='vigente'")['total'] ?? 0) : 0;
$where=[]; $params=[];
if ($tipo !== '') { $where[]='tipo_unidad=:tipo'; $params['tipo']=$tipo; }
if ($alcance !== '') { $where[]='territorial_scope=:alcance'; $params['alcance']=$alcance; }
if ($buscar !== '') { $where[]='(name LIKE :buscar OR code LIKE :buscar OR moi_code LIKE :buscar)'; $params['buscar']='%'.$buscar.'%'; }
$sql='SELECT * FROM vw_moi_arbol_unidades';
if ($where) { $sql .= ' WHERE '.implode(' AND ', $where); }
$sql .= ' LIMIT 300';
$arbol = rows($pdo,$sql,$params);
$tiposFiltro = rows($pdo, 'SELECT DISTINCT tipo_unidad FROM vw_moi_arbol_unidades WHERE tipo_unidad IS NOT NULL ORDER BY tipo_unidad');
$alcancesFiltro = rows($pdo, 'SELECT DISTINCT territorial_scope FROM vw_moi_arbol_unidades WHERE territorial_scope IS NOT NULL ORDER BY territorial_scope');
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Dashboard MOI 65.16</title><style>
body{font-family:Arial,sans-serif;margin:0;background:#f4f6f8;color:#1f2937}header{background:#111827;color:white;padding:18px 28px}header h1{margin:0;font-size:22px}header p{margin:6px 0 0;color:#d1d5db}header a{color:#d1d5db;font-weight:bold;margin-right:14px}main{padding:24px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-bottom:20px}.card,section{background:white;border-radius:10px;padding:16px;box-shadow:0 1px 4px #0002}.card .label{font-size:12px;color:#6b7280;text-transform:uppercase;letter-spacing:.04em}.card .value{font-size:26px;font-weight:bold;margin-top:6px}section{margin-bottom:20px}h2{font-size:18px;margin:0 0 12px}.two{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:20px}.filters{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:12px}input,select,button{padding:8px;border:1px solid #d1d5db;border-radius:6px}button{background:#111827;color:white}.badge{display:inline-block;padding:3px 7px;border-radius:999px;background:#eef2ff;font-size:12px}table{width:100%;border-collapse:collapse;font-size:13px}th,td{border-bottom:1px solid #e5e7eb;text-align:left;padding:8px;vertical-align:top}th{background:#f9fafb}.module a{display:inline-block;background:#047857;color:white;text-decoration:none;border-radius:8px;padding:10px 12px;margin:4px}.muted{color:#6b7280;font-size:12px}
</style></head><body><header><h1>Dashboard MOI 65.16</h1><p>Estructura vigente, trazabilidad legacy y cambios posteriores.<br><a href="index.php">Dashboard</a><a href="revision.php">Revision / aprobaciones</a><a href="asignar_zonas.php">Zonas</a><a href="asignar_direcciones.php">Direcciones</a><a href="asignar_areas.php">Areas</a><a href="asignar_unidades_direccion.php">Unidades por direccion</a></p></header><main>
<div class="grid"><div class="card"><div class="label">Unidades vigentes</div><div class="value"><?=e($resumen['total_unidades'] ?? '0')?></div></div><div class="card"><div class="label">Nacionales</div><div class="value"><?=e($resumen['unidades_nacionales'] ?? '0')?></div></div><div class="card"><div class="label">Regionales</div><div class="value"><?=e($resumen['unidades_regionales'] ?? '0')?></div></div><div class="card"><div class="label">Zonales</div><div class="value"><?=e($resumen['unidades_zonales'] ?? '0')?></div></div><div class="card"><div class="label">Sedes</div><div class="value"><?=e($resumen['sedes_detectadas'] ?? '0')?></div></div><div class="card"><div class="label">No vigentes</div><div class="value"><?=e($resumen['unidades_no_vigentes'] ?? '0')?></div></div><div class="card"><div class="label">Pendientes</div><div class="value"><?=e($resumen['pendientes_revision'] ?? '0')?></div></div><div class="card"><div class="label">Zonas cabecera</div><div class="value"><?=e($zonasCabeceraTotal)?></div></div><div class="card"><div class="label">Direcciones cabecera</div><div class="value"><?=e($direccionesCabeceraTotal)?></div></div></div>
<section class="module"><h2>Modulos de trabajo</h2><p class="muted">Accesos directos para normalizar la estructura paso a paso.</p><a href="asignar_zonas.php">Asignar zonas</a><a href="asignar_direcciones.php">Asignar direcciones</a><a href="asignar_areas.php">Asignar areas / respaldo</a><a href="asignar_unidades_direccion.php">Unidades internas por direccion</a><a href="revision.php">Revision y aprobaciones</a></section>
<div class="two"><section><h2>Unidades vigentes por tipo</h2><table><thead><tr><th>Tipo</th><th>Total</th></tr></thead><tbody><?php foreach($porTipo as $r):?><tr><td><?=e($r['tipo_unidad'])?></td><td><?=e($r['total'])?></td></tr><?php endforeach;?></tbody></table></section><section><h2>Unidades vigentes por alcance</h2><table><thead><tr><th>Alcance</th><th>Total</th></tr></thead><tbody><?php foreach($porAlcance as $r):?><tr><td><?=e($r['alcance'])?></td><td><?=e($r['total'])?></td></tr><?php endforeach;?></tbody></table></section></div>
<section><h2>Arbol / listado de unidades vigentes</h2><p class="muted">El legacy se conserva como origen historico. Este listado muestra la estructura vigente.</p><form class="filters" method="get"><input type="text" name="buscar" placeholder="Buscar unidad o codigo" value="<?=e($buscar)?>"><select name="tipo"><option value="">Todos los tipos</option><?php foreach($tiposFiltro as $r):?><option value="<?=e($r['tipo_unidad'])?>" <?=$tipo===$r['tipo_unidad']?'selected':''?>><?=e($r['tipo_unidad'])?></option><?php endforeach;?></select><select name="alcance"><option value="">Todos los alcances</option><?php foreach($alcancesFiltro as $r):?><option value="<?=e($r['territorial_scope'])?>" <?=$alcance===$r['territorial_scope']?'selected':''?>><?=e($r['territorial_scope'])?></option><?php endforeach;?></select><button>Filtrar</button></form><table><thead><tr><th>Unidad</th><th>Superior</th><th>Tipo</th><th>Alcance</th><th>Mando</th><th>Vigencia</th><th>Codigo</th><th>Origen</th></tr></thead><tbody><?php foreach($arbol as $r):?><tr><td><strong><?=e($r['name'])?></strong></td><td><?=e($r['unidad_superior'])?></td><td><span class="badge"><?=e($r['tipo_unidad'])?></span></td><td><?=e($r['territorial_scope'])?></td><td><?=e($r['command_structure'])?> / <?=e($r['command_relationship'])?></td><td><?=e($r['valid_from'])?> - <?=e($r['valid_to'] ?: 'vigente')?></td><td><?=e($r['code'])?></td><td><?=e($r['legacy_table'])?>: <?=e($r['legacy_id'])?></td></tr><?php endforeach;?></tbody></table></section>
</main></body></html>
