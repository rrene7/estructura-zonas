<?php
$configPath = __DIR__ . '/config.php';
if (!file_exists($configPath)) { die('Falta dashboard/config.php'); }
$config = require $configPath;
$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $config['db_host'], $config['db_port'], $config['db_name'], $config['charset']);
$pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function q($pdo,$sql,$p=[]){ $s=$pdo->prepare($sql); $s->execute($p); return $s->fetchAll(); }
function one($pdo,$sql,$p=[]){ $r=q($pdo,$sql,$p); return $r[0] ?? []; }
function total($pdo,$sql,$p=[]){ $r=one($pdo,$sql,$p); return (int)($r['total'] ?? 0); }
function table_exists($pdo,$table){ $s=$pdo->prepare('SELECT COUNT(*) total FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=:t'); $s->execute(['t'=>$table]); return ((int)($s->fetch()['total'] ?? 0))>0; }
function csv_out($v){ return (string)$v; }

$hasCatalogo = table_exists($pdo,'moi_area_sector_catalog');
$hasDinsec = table_exists($pdo,'dinsec_personnel_reference');
$hasLinks = table_exists($pdo,'dinsec_personnel_unit_links');
$zonaJoin = "BINARY cab.legacy_table = BINARY 'MOI_CABECERA_ZONA' AND CAST(cab.legacy_id AS UNSIGNED) = z.zone_number";
$zonas = q($pdo, "SELECT z.id,z.zone_number,z.zone_label,z.normalized_name,cab.id AS cabecera_unit_id FROM moi_zonas_cabecera_vigentes z LEFT JOIN organizational_units cab ON $zonaJoin WHERE z.lifecycle_status='vigente' ORDER BY z.zone_number");
$zonaId = (int)($_GET['zona_id'] ?? ($zonas[0]['id'] ?? 0));
$zona = one($pdo, "SELECT z.id,z.zone_number,z.zone_label,z.normalized_name,cab.id AS cabecera_unit_id FROM moi_zonas_cabecera_vigentes z LEFT JOIN organizational_units cab ON $zonaJoin WHERE z.id=:id", ['id'=>$zonaId]);
$cab = (int)($zona['cabecera_unit_id'] ?? 0);
$zn = (int)($zona['zone_number'] ?? 0);
$zl = $zona['zone_label'] ?? '';

$buscar = trim($_GET['buscar'] ?? '');
$areaFiltro = trim($_GET['area_code'] ?? '');
$scopeFiltro = trim($_GET['scope'] ?? '');
$estadoFiltro = trim($_GET['estado'] ?? '');

$sectores = [];
$areasCatalogo = [];
if ($hasCatalogo && $zn) {
    $sectores = q($pdo, "SELECT c.*, ou.id AS sector_unit_id, ou.name AS sector_unit_name, area.id AS area_unit_id, area.name AS area_unit_name FROM moi_area_sector_catalog c LEFT JOIN organizational_units ou ON BINARY ou.legacy_table=BINARY 'DINSEC_SECTOR' AND BINARY ou.legacy_id=BINARY CONCAT('Z',LPAD(c.zone_number,2,'0'),'-',c.area_code,'-',REPLACE(REPLACE(REPLACE(UPPER(c.sector_name),' ','-'),'Í','I'),'Á','A')) LEFT JOIN organizational_units area ON BINARY area.legacy_table=BINARY 'MOI_CABECERA_AREA' AND BINARY area.legacy_id=BINARY CONCAT('Z',LPAD(c.zone_number,2,'0'),'-AREA-',c.area_code) WHERE c.active=1 AND c.zone_number=:zn ORDER BY COALESCE(c.area_code,'ZZ'), c.sector_name", ['zn'=>$zn]);
    $areasCatalogo = q($pdo, "SELECT area_code, area_name, COUNT(*) total FROM moi_area_sector_catalog WHERE active=1 AND zone_number=:zn AND area_code IS NOT NULL GROUP BY area_code, area_name ORDER BY area_code", ['zn'=>$zn]);
}

$where = "1=0";
$params = [];
if ($hasDinsec && $zona) {
    $where = "(d.zone_unit_id=:cab OR d.zone_label LIKE :zl)";
    $params = ['cab'=>$cab,'zl'=>'%'.$zl.'%'];
    if ($buscar !== '') { $where .= " AND (d.full_name LIKE :b OR d.position_number LIKE :b OR d.assignment_text LIKE :b OR d.location_sector LIKE :b OR d.rank_text LIKE :b)"; $params['b']='%'.$buscar.'%'; }
    if ($areaFiltro !== '') { $where .= " AND COALESCE(d.area_code,'')=:area"; $params['area']=$areaFiltro; }
    if ($scopeFiltro !== '') { $where .= " AND COALESCE(l.assignment_scope,'sin_vinculo')=:scope"; $params['scope']=$scopeFiltro; }
    if ($estadoFiltro !== '') { $where .= " AND d.review_status=:estado"; $params['estado']=$estadoFiltro; }
}

$linkJoin = $hasLinks ? "LEFT JOIN dinsec_personnel_unit_links l ON l.dinsec_personnel_reference_id=d.id AND l.status='active'" : "LEFT JOIN (SELECT NULL dinsec_personnel_reference_id, NULL zone_unit_id, NULL area_unit_id, NULL assignment_unit_id, NULL assignment_scope, NULL status, NULL notes) l ON 1=0";
$personal = [];
if ($hasDinsec) {
    $personal = q($pdo, "SELECT d.id,d.row_number,d.page_number,d.rank_text,d.position_number,d.full_name,d.assignment_text,d.observation_text,d.area_code,d.area_name,d.location_sector,d.service_label,d.op_status,d.review_status,d.review_notes,d.raw_text, l.assignment_scope,l.status AS link_status,l.notes AS link_notes, au.name AS assignment_unit, au.legacy_table AS assignment_legacy_table, au.legacy_id AS assignment_legacy_id, area.name AS area_unit, zone.name AS zone_unit FROM dinsec_personnel_reference d $linkJoin LEFT JOIN organizational_units au ON au.id=l.assignment_unit_id LEFT JOIN organizational_units area ON area.id=l.area_unit_id LEFT JOIN organizational_units zone ON zone.id=l.zone_unit_id WHERE $where ORDER BY COALESCE(d.area_code,'ZZ'), COALESCE(d.location_sector,d.service_label,d.assignment_text), d.row_number, d.rank_text, d.full_name LIMIT 800", $params);
}

if (($_GET['descargar'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=detalle_personal_zona_'.$zn.'.csv');
    $out=fopen('php://output','w');
    fputcsv($out,['Zona','Pagina','Fila','Rango','Posicion','Nombre','Asignacion PDF','Area','Sector/Servicio','Ambito vinculo','Unidad asignada','Estado revision','Observacion']);
    foreach($personal as $p){
        fputcsv($out,array_map('csv_out',[$zl,$p['page_number'],$p['row_number'],$p['rank_text'],$p['position_number'],$p['full_name'],$p['assignment_text'],$p['area_code'],$p['location_sector'] ?: $p['service_label'],$p['assignment_scope'] ?: 'sin_vinculo',$p['assignment_unit'],$p['review_status'],$p['observation_text']]));
    }
    fclose($out); exit;
}

$resumen = [
    'sectores'=>count($sectores),
    'personal'=>count($personal),
    'total_dinsec'=>$hasDinsec ? total($pdo,"SELECT COUNT(*) total FROM dinsec_personnel_reference d WHERE (d.zone_unit_id=:cab OR d.zone_label LIKE :zl)",['cab'=>$cab,'zl'=>'%'.$zl.'%']) : 0,
    'vinculado'=>($hasDinsec && $hasLinks) ? total($pdo,"SELECT COUNT(*) total FROM dinsec_personnel_reference d JOIN dinsec_personnel_unit_links l ON l.dinsec_personnel_reference_id=d.id AND l.status='active' WHERE (d.zone_unit_id=:cab OR d.zone_label LIKE :zl)",['cab'=>$cab,'zl'=>'%'.$zl.'%']) : 0,
    'sin_vinculo'=>($hasDinsec && $hasLinks) ? total($pdo,"SELECT COUNT(*) total FROM dinsec_personnel_reference d LEFT JOIN dinsec_personnel_unit_links l ON l.dinsec_personnel_reference_id=d.id AND l.status='active' WHERE (d.zone_unit_id=:cab OR d.zone_label LIKE :zl) AND l.id IS NULL",['cab'=>$cab,'zl'=>'%'.$zl.'%']) : 0,
    'pendiente'=> $hasDinsec ? total($pdo,"SELECT COUNT(*) total FROM dinsec_personnel_reference d WHERE d.review_status='pendiente' AND (d.zone_unit_id=:cab OR d.zone_label LIKE :zl)",['cab'=>$cab,'zl'=>'%'.$zl.'%']) : 0,
];
$porArea = $hasDinsec ? q($pdo,"SELECT COALESCE(d.area_code,'SERV/ZONA') area, COALESCE(d.location_sector,d.service_label,d.assignment_text,'SIN UBICACION') sector, COUNT(*) total FROM dinsec_personnel_reference d WHERE (d.zone_unit_id=:cab OR d.zone_label LIKE :zl) GROUP BY area, sector ORDER BY area, sector",['cab'=>$cab,'zl'=>'%'.$zl.'%']) : [];
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><title>Detalle zona y personal</title><style>
body{font-family:Arial;margin:0;background:#f4f6f8;color:#1f2937}header{background:#111827;color:white;padding:18px 28px}main{padding:20px}.top a{color:#d1d5db;margin-right:14px}.layout{display:grid;grid-template-columns:330px 1fr;gap:18px}.card,section{background:white;border-radius:10px;padding:14px;box-shadow:0 1px 4px #0002;margin-bottom:16px}.cab a{display:block;padding:8px;border-bottom:1px solid #e5e7eb;color:#111827;text-decoration:none}.cab a.act{background:#e0f2fe;font-weight:bold}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px}.kpi{background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:12px}.kpi .n{font-size:24px;font-weight:bold}.muted{font-size:12px;color:#6b7280}.btn{display:inline-block;background:#047857;color:white;text-decoration:none;border-radius:8px;padding:9px 11px;margin:4px}.btn2{background:#1d4ed8}.bad{color:#b91c1c;font-weight:bold}.ok{color:#047857;font-weight:bold}table{width:100%;border-collapse:collapse;font-size:13px}th,td{border-bottom:1px solid #e5e7eb;text-align:left;padding:7px;vertical-align:top}th{background:#f9fafb}input,select,button{padding:7px;border:1px solid #d1d5db;border-radius:6px}button{background:#111827;color:white}.pill{display:inline-block;border-radius:999px;background:#eef2ff;padding:3px 8px;font-size:12px}.warn{background:#fffbeb;border:1px solid #f59e0b;padding:10px;border-radius:8px}
</style></head><body><header><h1>Detalle de zona y personal</h1><p class="top"><a href="index.php">Dashboard</a><a href="trabajo_zonas.php?zona_id=<?=h($zona['id'] ?? '')?>">Trabajo por zona</a><a href="catalogo_sectores.php?zone_number=<?=h($zn)?>">Catalogo sectores</a><a href="dinsec_personal.php?buscar=<?=h($zl)?>">DINSEC personal</a></p></header><main>
<div class="layout"><div class="card cab"><h2>Zonas</h2><?php foreach($zonas as $z):?><a class="<?=((int)$z['id']===(int)($zona['id']??0))?'act':''?>" href="?zona_id=<?=h($z['id'])?>"><?=h($z['zone_number'])?> - <?=h($z['zone_label'])?></a><?php endforeach;?></div><div>
<section><h2><?=h($zl ?: 'Seleccione zona')?></h2><div class="grid"><div class="kpi"><div class="muted">Sectores catalogo</div><div class="n"><?=h($resumen['sectores'])?></div></div><div class="kpi"><div class="muted">Personal mostrado</div><div class="n"><?=h($resumen['personal'])?></div></div><div class="kpi"><div class="muted">DINSEC total</div><div class="n"><?=h($resumen['total_dinsec'])?></div></div><div class="kpi"><div class="muted">Vinculado</div><div class="n ok"><?=h($resumen['vinculado'])?></div></div><div class="kpi"><div class="muted">Sin vinculo</div><div class="n <?=($resumen['sin_vinculo']>0?'bad':'ok')?>"><?=h($resumen['sin_vinculo'])?></div></div><div class="kpi"><div class="muted">Pendiente revision</div><div class="n <?=($resumen['pendiente']>0?'bad':'ok')?>"><?=h($resumen['pendiente'])?></div></div></div></section>
<?php if(!$hasDinsec):?><p class="warn">No existe la tabla DINSEC. Primero cargue la referencia DINSEC.</p><?php endif;?>
<section><h2>Filtros</h2><form method="get"><input type="hidden" name="zona_id" value="<?=h($zona['id'] ?? '')?>"><input name="buscar" value="<?=h($buscar)?>" placeholder="Nombre, posicion, asignacion"><select name="area_code"><option value="">Todas las areas</option><?php foreach($areasCatalogo as $a):?><option value="<?=h($a['area_code'])?>" <?=$areaFiltro===$a['area_code']?'selected':''?>>Area <?=h($a['area_code'])?> - <?=h($a['area_name'])?></option><?php endforeach;?></select><select name="scope"><option value="">Todos los vinculos</option><?php foreach(['sector'=>'sector','area'=>'area','servicio'=>'servicio','zona'=>'zona','sin_vinculo'=>'sin vinculo'] as $k=>$v):?><option value="<?=h($k)?>" <?=$scopeFiltro===$k?'selected':''?>><?=h($v)?></option><?php endforeach;?></select><select name="estado"><option value="">Todos los estados</option><?php foreach(['pendiente','validado','ignorado'] as $e):?><option value="<?=h($e)?>" <?=$estadoFiltro===$e?'selected':''?>><?=h($e)?></option><?php endforeach;?></select><button>Filtrar</button><a class="btn" href="?zona_id=<?=h($zona['id'] ?? '')?>&descargar=csv&buscar=<?=h($buscar)?>&area_code=<?=h($areaFiltro)?>&scope=<?=h($scopeFiltro)?>&estado=<?=h($estadoFiltro)?>">Descargar CSV</a></form></section>
<section><h2>Estructura de areas y sectores</h2><table><thead><tr><th>Area</th><th>Sector / Servicio</th><th>Unidad creada</th><th>Unidad area</th><th>Estado</th></tr></thead><tbody><?php foreach($sectores as $s):?><tr><td><?=h($s['area_code'] ? 'Area '.$s['area_code'] : 'Servicio/Zona')?></td><td><b><?=h($s['sector_name'])?></b><br><span class="muted"><?=h($s['service_label'])?></span></td><td><?=h($s['sector_unit_name'] ?: 'No creada como unidad')?></td><td><?=h($s['area_unit_name'])?></td><td><?=h($s['op_status'])?></td></tr><?php endforeach;?></tbody></table></section>
<section><h2>Resumen de personal por area / sector</h2><table><thead><tr><th>Area</th><th>Sector / Servicio</th><th>Total</th></tr></thead><tbody><?php foreach($porArea as $r):?><tr><td><?=h($r['area'])?></td><td><?=h($r['sector'])?></td><td><?=h($r['total'])?></td></tr><?php endforeach;?></tbody></table></section>
<section><h2>Detalle del personal DINSEC</h2><table><thead><tr><th>#</th><th>Rango / Posicion</th><th>Nombre</th><th>Asignacion PDF</th><th>Area / Sector</th><th>Vinculo aplicado</th><th>Estado</th></tr></thead><tbody><?php foreach($personal as $p):?><tr><td><?=h($p['row_number'])?></td><td><?=h($p['rank_text'])?><br><span class="muted"><?=h($p['position_number'])?></span></td><td><b><?=h($p['full_name'])?></b></td><td><?=h($p['assignment_text'])?><br><span class="muted"><?=h($p['observation_text'])?></span></td><td><span class="pill"><?=h($p['area_code'] ? 'Area '.$p['area_code'] : 'Servicio/Zona')?></span><br><?=h($p['location_sector'] ?: $p['service_label'])?></td><td><b><?=h($p['assignment_scope'] ?: 'sin_vinculo')?></b><br><?=h($p['assignment_unit'])?><br><span class="muted"><?=h(($p['assignment_legacy_table'] ?? '').': '.($p['assignment_legacy_id'] ?? ''))?></span></td><td><?=h($p['review_status'])?><br><span class="muted"><?=h($p['review_notes'])?></span></td></tr><?php endforeach;?></tbody></table></section>
</div></div></main></body></html>
