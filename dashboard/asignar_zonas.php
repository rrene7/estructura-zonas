<?php
$configPath = __DIR__ . '/config.php';
if (!file_exists($configPath)) { die('Falta dashboard/config.php'); }
$config = require $configPath;
$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $config['db_host'], $config['db_port'], $config['db_name'], $config['charset']);
$pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function q($pdo,$sql,$p=[]){ $s=$pdo->prepare($sql); $s->execute($p); return $s->fetchAll(); }
function one($pdo,$sql,$p=[]){ $r=q($pdo,$sql,$p); return $r[0] ?? null; }
function x($pdo,$sql,$p=[]){ $s=$pdo->prepare($sql); $s->execute($p); }
$msg=''; $err='';

$zonaJoin = "BINARY cab.legacy_table = BINARY 'MOI_CABECERA_ZONA' AND CAST(cab.legacy_id AS UNSIGNED) = z.zone_number";
$zonas = q($pdo, "SELECT z.id, z.zone_number, z.zone_label, z.normalized_name, cab.id AS cabecera_unit_id FROM moi_zonas_cabecera_vigentes z LEFT JOIN organizational_units cab ON $zonaJoin WHERE z.lifecycle_status='vigente' ORDER BY z.zone_number");
$zonaId = (int)($_GET['zona_id'] ?? ($_POST['zona_id'] ?? ($zonas[0]['id'] ?? 0)));
$zona = one($pdo, "SELECT z.id, z.zone_number, z.zone_label, z.normalized_name, cab.id AS cabecera_unit_id FROM moi_zonas_cabecera_vigentes z LEFT JOIN organizational_units cab ON $zonaJoin WHERE z.id=:id", ['id'=>$zonaId]);

try {
    if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['accion'] ?? '')==='asignar') {
        $unitId=(int)($_POST['unit_id'] ?? 0);
        $targetId=(int)($_POST['target_id'] ?? 0);
        $nombre=trim($_POST['nombre'] ?? '');
        $target=one($pdo, "SELECT z.id, z.zone_label, cab.id AS cabecera_unit_id FROM moi_zonas_cabecera_vigentes z LEFT JOIN organizational_units cab ON $zonaJoin WHERE z.id=:id", ['id'=>$targetId]);
        if (!$target || !$target['cabecera_unit_id']) { throw new RuntimeException('La zona seleccionada no tiene cabecera canonica. Ejecute: bash scripts/preparar_absorcion_cabeceras.sh'); }
        $unit=one($pdo, "SELECT id, name FROM organizational_units WHERE id=:id", ['id'=>$unitId]);
        if (!$unit) { throw new RuntimeException('Unidad no encontrada.'); }
        $pdo->beginTransaction();
        if ($nombre!=='' && $nombre!==$unit['name']) {
            x($pdo, "INSERT INTO organizational_unit_lifecycle_events (organizational_unit_id,event_type,effective_from,source_document,notes,created_by) VALUES (:id,'renombre',CURRENT_DATE,'asignar_zonas','Nombre editado desde pantalla simple','dashboard')", ['id'=>$unitId]);
            x($pdo, "UPDATE organizational_units SET name=:n, short_name=LEFT(:n2,100), updated_at=NOW() WHERE id=:id", ['n'=>$nombre,'n2'=>$nombre,'id'=>$unitId]);
        }
        x($pdo, "UPDATE organizational_units SET parent_id=:p, lifecycle_notes=:nota, updated_at=NOW() WHERE id=:id", ['p'=>$target['cabecera_unit_id'],'nota'=>'Asignada a '.$target['zone_label'].' desde pantalla simple','id'=>$unitId]);
        x($pdo, "INSERT INTO organizational_unit_relationships (source_unit_id,target_unit_id,relationship_type,valid_from,status,notes,created_at,updated_at) SELECT :h,:p,'jerarquica',CURRENT_DATE,'active','Asignada desde pantalla simple',NOW(),NOW() WHERE NOT EXISTS (SELECT 1 FROM organizational_unit_relationships WHERE source_unit_id=:h2 AND target_unit_id=:p2 AND relationship_type='jerarquica' AND status='active')", ['h'=>$unitId,'p'=>$target['cabecera_unit_id'],'h2'=>$unitId,'p2'=>$target['cabecera_unit_id']]);
        $pdo->commit();
        $msg='Unidad aprobada/asignada a '.$target['zone_label'];
    }
} catch(Throwable $e){ if($pdo->inTransaction()){$pdo->rollBack();} $err=$e->getMessage(); }

$buscar=trim($_GET['buscar'] ?? '');
$clave=$buscar!=='' ? strtoupper($buscar) : ($zona['normalized_name'] ?? '');
$extra='';
if (($zona['zone_number'] ?? 0)==8) { $extra=" AND UPPER(ou.name COLLATE utf8mb4_unicode_ci) NOT LIKE '%PANAMA OESTE%'"; }
$candidatos=$zona ? q($pdo, "SELECT ou.id,ou.code,ou.name,ou.parent_id,ou.legacy_table,ou.legacy_id,ut.name AS tipo,parent.name AS superior FROM organizational_units ou LEFT JOIN unit_types ut ON ut.id=ou.unit_type_id LEFT JOIN organizational_units parent ON parent.id=ou.parent_id WHERE ou.lifecycle_status='vigente' AND ou.id<>:cab AND UPPER(ou.name COLLATE utf8mb4_unicode_ci) LIKE :pat $extra ORDER BY CASE WHEN ou.parent_id=:cab2 THEN 0 ELSE 1 END, ou.name LIMIT 200", ['cab'=>$zona['cabecera_unit_id'] ?? 0,'cab2'=>$zona['cabecera_unit_id'] ?? 0,'pat'=>'%'.$clave.'%']) : [];
$asignados=$zona ? q($pdo, "SELECT ou.id,ou.name,ou.legacy_table,ou.legacy_id,ut.name AS tipo FROM organizational_units ou LEFT JOIN unit_types ut ON ut.id=ou.unit_type_id WHERE ou.parent_id=:cab ORDER BY ou.name LIMIT 100", ['cab'=>$zona['cabecera_unit_id'] ?? 0]) : [];
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Asignar zonas</title><style>body{font-family:Arial,sans-serif;margin:0;background:#f4f6f8;color:#1f2937}header{background:#111827;color:white;padding:18px 28px}main{padding:20px}.layout{display:grid;grid-template-columns:330px 1fr;gap:18px}.card,section{background:white;border-radius:10px;padding:14px;box-shadow:0 1px 4px #0002;margin-bottom:16px}.cab a{display:block;padding:8px;border-bottom:1px solid #e5e7eb;color:#111827;text-decoration:none}.cab a.act{background:#e0f2fe;font-weight:bold}.top a{color:#d1d5db;margin-right:14px}.msg{background:#ecfdf5;border:1px solid #10b981;padding:10px;border-radius:8px}.err{background:#fef2f2;border:1px solid #ef4444;padding:10px;border-radius:8px}table{width:100%;border-collapse:collapse;font-size:13px}th,td{border-bottom:1px solid #e5e7eb;text-align:left;padding:7px;vertical-align:top}th{background:#f9fafb}input,select,button{padding:7px;border:1px solid #d1d5db;border-radius:6px}button{background:#047857;color:white;cursor:pointer}.muted{font-size:12px;color:#6b7280}.pill{background:#eef2ff;border-radius:999px;padding:3px 7px}</style></head><body><header><h1>Asignar zonas</h1><p class="top"><a href="index.php">Dashboard</a><a href="asignar_direcciones.php">Direcciones</a><a href="revision.php">Revision anterior</a></p></header><main><?php if($msg):?><p class="msg"><?=h($msg)?></p><?php endif;?><?php if($err):?><p class="err"><?=h($err)?></p><?php endif;?>
<div class="layout"><div class="card cab"><h2>Zonas cabecera</h2><?php foreach($zonas as $z):?><a class="<?=((int)$z['id']===(int)($zona['id']??0))?'act':''?>" href="?zona_id=<?=h($z['id'])?>"><?=h($z['zone_number'])?> - <?=h($z['zone_label'])?></a><?php endforeach;?></div><div><section><h2><?=h($zona['zone_label'] ?? 'Seleccione zona')?></h2><p class="muted">Seleccione una zona. A la derecha salen las unidades que contienen ese nombre. Puede editar, aprobar o enviarlas a otra zona.</p><form method="get"><input type="hidden" name="zona_id" value="<?=h($zona['id'] ?? '')?>"><input name="buscar" value="<?=h($buscar)?>" placeholder="Buscar otra palabra"><button>Buscar</button></form><p>Filtro usado: <span class="pill"><?=h($clave)?></span></p></section><section><h2>Lista para aprobar/asignar</h2><table><thead><tr><th>Unidad encontrada</th><th>Superior actual</th><th>Editar / asignar</th></tr></thead><tbody><?php foreach($candidatos as $r):?><tr><td><b><?=h($r['name'])?></b><br><span class="muted"><?=h($r['tipo'])?> | <?=h($r['legacy_table'])?>: <?=h($r['legacy_id'])?></span></td><td><?=h($r['superior'] ?: 'Sin superior')?></td><td><form method="post"><input type="hidden" name="accion" value="asignar"><input type="hidden" name="zona_id" value="<?=h($zona['id'] ?? '')?>"><input type="hidden" name="unit_id" value="<?=h($r['id'])?>"><input name="nombre" value="<?=h($r['name'])?>" size="36"><select name="target_id"><?php foreach($zonas as $z):?><option value="<?=h($z['id'])?>" <?=((int)$z['id']===(int)($zona['id']??0))?'selected':''?>><?=h($z['zone_number'])?> - <?=h($z['zone_label'])?></option><?php endforeach;?></select><button>Aprobar / asignar</button></form></td></tr><?php endforeach;?></tbody></table></section><section><h2>Ya asignados a esta zona</h2><table><thead><tr><th>Unidad</th><th>Tipo</th><th>Legacy</th></tr></thead><tbody><?php foreach($asignados as $r):?><tr><td><?=h($r['name'])?></td><td><?=h($r['tipo'])?></td><td><?=h($r['legacy_table'])?>: <?=h($r['legacy_id'])?></td></tr><?php endforeach;?></tbody></table></section></div></div></main></body></html>
