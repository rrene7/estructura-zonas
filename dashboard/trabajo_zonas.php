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
function table_exists($pdo,$table){ $s=$pdo->prepare('SELECT COUNT(*) total FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=:t'); $s->execute(['t'=>$table]); return ((int)($s->fetch()['total'] ?? 0))>0; }
function total($pdo,$sql,$p=[]){ $r=one($pdo,$sql,$p); return (int)($r['total'] ?? 0); }

$hasCatalogo = table_exists($pdo,'moi_area_sector_catalog');
$hasAsign = table_exists($pdo,'moi_area_letter_assignments');
$hasAudit = table_exists($pdo,'moi_area_assignment_audit');
$hasDinsec = table_exists($pdo,'dinsec_personnel_reference');
$zonaJoin = "BINARY cab.legacy_table = BINARY 'MOI_CABECERA_ZONA' AND CAST(cab.legacy_id AS UNSIGNED) = z.zone_number";
$zonas = q($pdo, "SELECT z.id,z.zone_number,z.zone_label,z.normalized_name,cab.id AS cabecera_unit_id FROM moi_zonas_cabecera_vigentes z LEFT JOIN organizational_units cab ON $zonaJoin WHERE z.lifecycle_status='vigente' ORDER BY z.zone_number");
$zonaId = (int)($_GET['zona_id'] ?? ($zonas[0]['id'] ?? 0));
$zona = one($pdo, "SELECT z.id,z.zone_number,z.zone_label,z.normalized_name,cab.id AS cabecera_unit_id FROM moi_zonas_cabecera_vigentes z LEFT JOIN organizational_units cab ON $zonaJoin WHERE z.id=:id", ['id'=>$zonaId]);

function metricas_zona($pdo,$z,$hasCatalogo,$hasAsign,$hasDinsec){
    $cab=(int)($z['cabecera_unit_id'] ?? 0);
    $zn=(int)($z['zone_number'] ?? 0);
    $norm=$z['normalized_name'] ?? '';
    $out=['catalogo'=>0,'asignadas'=>0,'sin_sector'=>0,'cab_mal'=>0,'pendientes'=>0,'dinsec'=>0,'dinsec_pend'=>0];
    if ($hasCatalogo) { $out['catalogo']=total($pdo,"SELECT COUNT(*) total FROM moi_area_sector_catalog WHERE active=1 AND zone_number=:zn",['zn'=>$zn]); }
    if ($hasAsign && $cab) {
        $out['asignadas']=total($pdo,"SELECT COUNT(*) total FROM moi_area_letter_assignments aa JOIN organizational_units ou ON ou.id=aa.organizational_unit_id WHERE aa.zone_unit_id=:cab AND BINARY ou.legacy_table <> BINARY 'MOI_CABECERA_ZONA' AND BINARY ou.legacy_table <> BINARY 'MOI_CABECERA_DIRECCION' AND BINARY ou.legacy_table <> BINARY 'MOI_CABECERA_AREA'",['cab'=>$cab]);
        $out['sin_sector']=total($pdo,"SELECT COUNT(*) total FROM moi_area_letter_assignments aa JOIN organizational_units ou ON ou.id=aa.organizational_unit_id WHERE aa.zone_unit_id=:cab AND (aa.location_sector IS NULL OR aa.location_sector='') AND BINARY ou.legacy_table <> BINARY 'MOI_CABECERA_ZONA' AND BINARY ou.legacy_table <> BINARY 'MOI_CABECERA_DIRECCION' AND BINARY ou.legacy_table <> BINARY 'MOI_CABECERA_AREA'",['cab'=>$cab]);
        $out['cab_mal']=total($pdo,"SELECT COUNT(*) total FROM moi_area_letter_assignments aa JOIN organizational_units ou ON ou.id=aa.organizational_unit_id WHERE aa.zone_unit_id=:cab AND (BINARY ou.legacy_table=BINARY 'MOI_CABECERA_ZONA' OR BINARY ou.legacy_table=BINARY 'MOI_CABECERA_DIRECCION' OR BINARY ou.legacy_table=BINARY 'MOI_CABECERA_AREA')",['cab'=>$cab]);
    }
    if ($cab && $norm !== '') {
        $pat='%'.$norm.'%';
        $noAsign="AND NOT EXISTS (SELECT 1 FROM organizational_units c WHERE c.id=ou.parent_id AND (BINARY c.legacy_table=BINARY 'MOI_CABECERA_ZONA' OR BINARY c.legacy_table=BINARY 'MOI_CABECERA_DIRECCION' OR BINARY c.legacy_table=BINARY 'MOI_CABECERA_AREA')) AND NOT EXISTS (SELECT 1 FROM organizational_unit_relationships r JOIN organizational_units zc ON zc.id=r.target_unit_id WHERE r.source_unit_id=ou.id AND r.status='active' AND (BINARY zc.legacy_table=BINARY 'MOI_CABECERA_ZONA' OR BINARY zc.legacy_table=BINARY 'MOI_CABECERA_AREA'))";
        $out['pendientes']=total($pdo,"SELECT COUNT(*) total FROM organizational_units ou WHERE ou.lifecycle_status='vigente' $noAsign AND BINARY ou.legacy_table <> BINARY 'MOI_CABECERA_AREA' AND UPPER(ou.name COLLATE utf8mb4_unicode_ci) LIKE :pat",['pat'=>$pat]);
    }
    if ($hasDinsec) {
        $out['dinsec']=total($pdo,"SELECT COUNT(*) total FROM dinsec_personnel_reference WHERE zone_unit_id=:cab OR zone_label LIKE :zl",['cab'=>$cab,'zl'=>'%'.$z['zone_label'].'%']);
        $out['dinsec_pend']=total($pdo,"SELECT COUNT(*) total FROM dinsec_personnel_reference WHERE review_status='pendiente' AND (zone_unit_id=:cab OR zone_label LIKE :zl)",['cab'=>$cab,'zl'=>'%'.$z['zone_label'].'%']);
    }
    return $out;
}
$metricas = $zona ? metricas_zona($pdo,$zona,$hasCatalogo,$hasAsign,$hasDinsec) : [];
$tablero=[]; foreach($zonas as $z){ $m=metricas_zona($pdo,$z,$hasCatalogo,$hasAsign,$hasDinsec); $tablero[]=['z'=>$z,'m'=>$m]; }
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><title>Trabajo por zona</title><style>
body{font-family:Arial;margin:0;background:#f4f6f8;color:#1f2937}header{background:#111827;color:white;padding:18px 28px}main{padding:20px}.top a{color:#d1d5db;margin-right:14px}.layout{display:grid;grid-template-columns:330px 1fr;gap:18px}.card,section{background:white;border-radius:10px;padding:14px;box-shadow:0 1px 4px #0002;margin-bottom:16px}.cab a{display:block;padding:8px;border-bottom:1px solid #e5e7eb;color:#111827;text-decoration:none}.cab a.act{background:#e0f2fe;font-weight:bold}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:10px}.kpi{background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:12px}.kpi .n{font-size:24px;font-weight:bold}.muted{font-size:12px;color:#6b7280}.btn{display:inline-block;background:#047857;color:white;text-decoration:none;border-radius:8px;padding:9px 11px;margin:4px}.btn2{background:#1d4ed8}.warn{background:#fffbeb;border:1px solid #f59e0b}.bad{color:#b91c1c;font-weight:bold}.ok{color:#047857;font-weight:bold}table{width:100%;border-collapse:collapse;font-size:13px}th,td{border-bottom:1px solid #e5e7eb;text-align:left;padding:7px;vertical-align:top}th{background:#f9fafb}
</style></head><body><header><h1>Trabajo por zona</h1><p class="top"><a href="index.php">Dashboard</a><a href="catalogo_sectores.php">Catalogo sectores</a><a href="asignar_areas_catalogo.php">Asignar con catalogo</a><a href="reasignar_areas_catalogo.php">Corregir areas</a><a href="dinsec_personal.php">DINSEC personal</a></p></header><main>
<div class="layout"><div class="card cab"><h2>Zonas</h2><?php foreach($zonas as $z):?><a class="<?=((int)$z['id']===(int)($zona['id']??0))?'act':''?>" href="?zona_id=<?=h($z['id'])?>"><?=h($z['zone_number'])?> - <?=h($z['zone_label'])?></a><?php endforeach;?></div><div>
<section><h2><?=h($zona['zone_label'] ?? 'Seleccione zona')?></h2><p class="muted">Orden de trabajo: catalogo → limpiar cabeceras → corregir sectores → asignar pendientes → revisar personal DINSEC.</p><div class="grid"><div class="kpi"><div class="muted">Sectores catalogo</div><div class="n"><?=h($metricas['catalogo'] ?? 0)?></div></div><div class="kpi"><div class="muted">Unidades asignadas</div><div class="n"><?=h($metricas['asignadas'] ?? 0)?></div></div><div class="kpi"><div class="muted">Sin sector</div><div class="n <?=($metricas['sin_sector']??0)>0?'bad':'ok'?>"><?=h($metricas['sin_sector'] ?? 0)?></div></div><div class="kpi"><div class="muted">Cabeceras mal</div><div class="n <?=($metricas['cab_mal']??0)>0?'bad':'ok'?>"><?=h($metricas['cab_mal'] ?? 0)?></div></div><div class="kpi"><div class="muted">Pendientes posibles</div><div class="n"><?=h($metricas['pendientes'] ?? 0)?></div></div><div class="kpi"><div class="muted">DINSEC pendiente</div><div class="n"><?=h($metricas['dinsec_pend'] ?? 0)?></div></div></div></section>
<section><h2>Acciones de esta zona</h2><a class="btn" href="catalogo_sectores.php?zone_number=<?=h($zona['zone_number'] ?? '')?>">1. Revisar catalogo</a><a class="btn btn2" href="reasignar_areas_catalogo.php?zona_id=<?=h($zona['id'] ?? '')?>&solo=incompletas">2. Corregir sin sector</a><a class="btn btn2" href="reasignar_areas_catalogo.php?zona_id=<?=h($zona['id'] ?? '')?>&solo=todas">3. Ver todas asignadas</a><a class="btn" href="asignar_areas_catalogo.php?zona_id=<?=h($zona['id'] ?? '')?>&buscar=<?=h($zona['normalized_name'] ?? '')?>">4. Asignar pendientes</a><a class="btn" href="dinsec_personal.php?buscar=<?=h($zona['zone_label'] ?? '')?>">5. Revisar DINSEC</a></section>
<?php if(($metricas['cab_mal'] ?? 0)>0):?><section class="warn"><h2>Atencion</h2><p>Esta zona tiene cabeceras metidas como si fueran unidades. Entre a <b>Corregir sin sector</b> y use el boton <b>Limpiar cabeceras</b>. No borra unidades ni personal.</p></section><?php endif;?>
<section><h2>Tablero general por zona</h2><table><thead><tr><th>Zona</th><th>Catalogo</th><th>Asignadas</th><th>Sin sector</th><th>Cabeceras mal</th><th>Pendientes</th><th>DINSEC pendiente</th><th>Accion</th></tr></thead><tbody><?php foreach($tablero as $row): $z=$row['z']; $m=$row['m'];?><tr><td><?=h($z['zone_number'])?> - <?=h($z['zone_label'])?></td><td><?=h($m['catalogo'])?></td><td><?=h($m['asignadas'])?></td><td class="<?=($m['sin_sector']>0?'bad':'ok')?>"><?=h($m['sin_sector'])?></td><td class="<?=($m['cab_mal']>0?'bad':'ok')?>"><?=h($m['cab_mal'])?></td><td><?=h($m['pendientes'])?></td><td><?=h($m['dinsec_pend'])?></td><td><a href="?zona_id=<?=h($z['id'])?>">Abrir</a></td></tr><?php endforeach;?></tbody></table></section>
</div></div></main></body></html>
