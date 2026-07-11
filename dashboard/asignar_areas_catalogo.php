<?php
$configPath = __DIR__ . '/config.php';
if (!file_exists($configPath)) { die('Falta dashboard/config.php'); }
$config = require $configPath;
$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $config['db_host'], $config['db_port'], $config['db_name'], $config['charset']);
$pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');

$areaCodes = range('A', 'P');
$areaEnum = "'" . implode("','", $areaCodes) . "'";

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function q($pdo,$sql,$p=[]){ $s=$pdo->prepare($sql); $s->execute($p); return $s->fetchAll(); }
function one($pdo,$sql,$p=[]){ $r=q($pdo,$sql,$p); return $r[0] ?? null; }
function x($pdo,$sql,$p=[]){ $s=$pdo->prepare($sql); $s->execute($p); }
function upper_text($v){ $v=(string)$v; return function_exists('mb_strtoupper') ? mb_strtoupper($v,'UTF-8') : strtoupper($v); }
function table_exists($pdo,$table){ $s=$pdo->prepare('SELECT COUNT(*) total FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=:t'); $s->execute(['t'=>$table]); return ((int)($s->fetch()['total'] ?? 0))>0; }
function area_default($name){ return preg_match('/AREA\s*([A-P])\b/i', (string)$name, $m) ? strtoupper($m[1]) : 'A'; }
function sector_default($name){ $name=trim((string)$name); return preg_match('/AREA\s*[A-P]\s+(.+)$/i', $name, $m) ? trim($m[1]) : ''; }
function ensure_area_unit($pdo, $zoneUnitId, $zoneNumber, $zoneLabel, $areaCode){
    $legacyId = 'Z'.str_pad((string)$zoneNumber,2,'0',STR_PAD_LEFT).'-AREA-'.$areaCode;
    $found = one($pdo, "SELECT id FROM organizational_units WHERE BINARY legacy_table=BINARY 'MOI_CABECERA_AREA' AND BINARY legacy_id=BINARY :legacy_id", ['legacy_id'=>$legacyId]);
    if ($found) { return (int)$found['id']; }
    x($pdo, "INSERT IGNORE INTO unit_types (name, description, created_at, updated_at) VALUES ('area_policial','Area policial',NOW(),NOW())");
    $ut = one($pdo, "SELECT id FROM unit_types WHERE name='area_policial' LIMIT 1");
    $code = 'Z'.str_pad((string)$zoneNumber,2,'0',STR_PAD_LEFT).'-AREA-'.$areaCode;
    $name = 'Area '.$areaCode;
    x($pdo, "INSERT INTO organizational_units (parent_id, unit_type_id, code, name, short_name, level, is_operational, is_administrative, status, legacy_table, legacy_id, created_at, updated_at) VALUES (:parent_id,:unit_type_id,:code,:name,:short_name,4,1,1,'active','MOI_CABECERA_AREA',:legacy_id,NOW(),NOW())", ['parent_id'=>$zoneUnitId,'unit_type_id'=>$ut['id'],'code'=>$code,'name'=>$name,'short_name'=>$name,'legacy_id'=>$legacyId]);
    $areaId = (int)$pdo->lastInsertId();
    try { x($pdo, "UPDATE organizational_units SET moi_code=:code, moi_level=4, command_structure='mando_directo', command_relationship='tactico', territorial_scope='area', is_decision_center=1, is_operational_executor=1, valid_from=CURRENT_DATE, lifecycle_status='vigente', structure_source='moi_65_16', legacy_frozen=1, lifecycle_notes=:note WHERE id=:id", ['code'=>$code,'note'=>'Cabecera real de '.$name.' dentro de '.$zoneLabel,'id'=>$areaId]); } catch (Throwable $e) {}
    try { x($pdo, "INSERT INTO organizational_unit_relationships (source_unit_id,target_unit_id,relationship_type,valid_from,status,notes,created_at,updated_at) SELECT :area,:zona,'jerarquica',CURRENT_DATE,'active','Area cabecera pertenece a zona',NOW(),NOW() WHERE NOT EXISTS (SELECT 1 FROM organizational_unit_relationships WHERE source_unit_id=:area2 AND target_unit_id=:zona2 AND relationship_type='jerarquica' AND status='active')", ['area'=>$areaId,'zona'=>$zoneUnitId,'area2'=>$areaId,'zona2'=>$zoneUnitId]); } catch (Throwable $e) {}
    return $areaId;
}

$pdo->exec("CREATE TABLE IF NOT EXISTS moi_area_letter_assignments (organizational_unit_id BIGINT UNSIGNED PRIMARY KEY, area_code ENUM($areaEnum) NOT NULL, area_unit_id BIGINT UNSIGNED NULL, location_sector VARCHAR(180) NULL, direction_unit_id BIGINT UNSIGNED NULL, zone_unit_id BIGINT UNSIGNED NOT NULL, notes VARCHAR(255) NULL, created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, INDEX idx_area_letter (area_code), INDEX idx_area_unit (area_unit_id), INDEX idx_area_sector (location_sector), INDEX idx_area_zone (zone_unit_id), INDEX idx_area_direction (direction_unit_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
try { $pdo->exec("ALTER TABLE moi_area_letter_assignments ADD COLUMN location_sector VARCHAR(180) NULL AFTER area_unit_id"); } catch(Throwable $e) {}
$pdo->exec("CREATE TABLE IF NOT EXISTS moi_area_assignment_audit (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, organizational_unit_id BIGINT UNSIGNED NOT NULL, legacy_table VARCHAR(80) NULL, legacy_id VARCHAR(80) NULL, nombre_antes VARCHAR(255) NULL, nombre_despues VARCHAR(255) NULL, area_code ENUM($areaEnum) NULL, area_unit_id BIGINT UNSIGNED NULL, area_name VARCHAR(150) NULL, location_sector VARCHAR(180) NULL, direction_unit_id BIGINT UNSIGNED NULL, direction_name VARCHAR(220) NULL, zone_unit_id BIGINT UNSIGNED NULL, zone_name VARCHAR(220) NULL, accion VARCHAR(80) NOT NULL DEFAULT 'asignar_area_catalogo', notes VARCHAR(255) NULL, created_by VARCHAR(100) NULL DEFAULT 'dashboard', created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_audit_unit (organizational_unit_id), INDEX idx_audit_created (created_at), INDEX idx_audit_area (area_code), INDEX idx_audit_sector (location_sector), INDEX idx_audit_zone (zone_unit_id), INDEX idx_audit_direction (direction_unit_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
try { $pdo->exec("ALTER TABLE moi_area_assignment_audit ADD COLUMN location_sector VARCHAR(180) NULL AFTER area_name"); } catch(Throwable $e) {}

$msg = isset($_GET['ok']) ? 'Unidad asignada usando catalogo de sectores.' : '';
$err = '';
$zonaJoin = "BINARY cab.legacy_table = BINARY 'MOI_CABECERA_ZONA' AND CAST(cab.legacy_id AS UNSIGNED) = z.zone_number";
$dirJoin = "BINARY dcab.legacy_table = BINARY 'MOI_CABECERA_DIRECCION' AND CAST(dcab.legacy_id AS UNSIGNED) = d.direction_number";
$zonas = q($pdo, "SELECT z.id,z.zone_number,z.zone_label,z.normalized_name,cab.id AS cabecera_unit_id FROM moi_zonas_cabecera_vigentes z LEFT JOIN organizational_units cab ON $zonaJoin WHERE z.lifecycle_status='vigente' ORDER BY z.zone_number");
$direcciones = q($pdo, "SELECT d.id,d.direction_number,d.direction_label,dcab.id AS cabecera_unit_id FROM moi_direcciones_cabecera_vigentes d LEFT JOIN organizational_units dcab ON $dirJoin WHERE d.lifecycle_status='vigente' ORDER BY d.direction_number");
$zonaId = (int)($_GET['zona_id'] ?? ($_POST['zona_id'] ?? ($zonas[0]['id'] ?? 0)));
$zona = one($pdo, "SELECT z.id,z.zone_number,z.zone_label,z.normalized_name,cab.id AS cabecera_unit_id FROM moi_zonas_cabecera_vigentes z LEFT JOIN organizational_units cab ON $zonaJoin WHERE z.id=:id", ['id'=>$zonaId]);
$catalogoExiste = table_exists($pdo, 'moi_area_sector_catalog');

try {
    if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['accion'] ?? '')==='asignar_catalogo') {
        if (!$catalogoExiste) { throw new RuntimeException('Falta cargar el catalogo de sectores. Ejecute bash scripts/aplicar_catalogo_sectores_dinsec.sh'); }
        $unitId = (int)($_POST['unit_id'] ?? 0);
        $sectorId = (int)($_POST['sector_id'] ?? 0);
        $direccionTargetId = (int)($_POST['direccion_target_id'] ?? 0);
        $unitBefore = one($pdo, "SELECT id, name, legacy_table, legacy_id FROM organizational_units WHERE id=:id", ['id'=>$unitId]);
        if (!$unitBefore) { throw new RuntimeException('Unidad no encontrada.'); }
        $sector = one($pdo, "SELECT * FROM moi_area_sector_catalog WHERE id=:id AND active=1", ['id'=>$sectorId]);
        if (!$sector || empty($sector['area_code'])) { throw new RuntimeException('Seleccione un sector valido del catalogo con area real.'); }
        $zonaTarget = one($pdo, "SELECT z.id,z.zone_number,z.zone_label,cab.id AS cabecera_unit_id FROM moi_zonas_cabecera_vigentes z LEFT JOIN organizational_units cab ON $zonaJoin WHERE z.zone_number=:zn LIMIT 1", ['zn'=>$sector['zone_number']]);
        if (!$zonaTarget || !$zonaTarget['cabecera_unit_id']) { throw new RuntimeException('La zona del catalogo no tiene cabecera canonica.'); }
        $areaCode = strtoupper(trim($sector['area_code']));
        if (!in_array($areaCode, $areaCodes, true)) { throw new RuntimeException('Area fuera de rango A-P.'); }
        $locationSector = trim($sector['sector_name']);
        $areaUnitId = ensure_area_unit($pdo, (int)$zonaTarget['cabecera_unit_id'], (int)$zonaTarget['zone_number'], $zonaTarget['zone_label'], $areaCode);
        $areaName = 'Area '.$areaCode;
        $nombre = trim($_POST['nombre'] ?? '');
        $nombreDespues = $nombre !== '' ? $nombre : upper_text($locationSector);
        $direccionTarget = null;
        if ($direccionTargetId > 0) {
            $direccionTarget = one($pdo, "SELECT d.id,d.direction_label,dcab.id AS cabecera_unit_id FROM moi_direcciones_cabecera_vigentes d LEFT JOIN organizational_units dcab ON $dirJoin WHERE d.id=:id", ['id'=>$direccionTargetId]);
            if (!$direccionTarget || !$direccionTarget['cabecera_unit_id']) { throw new RuntimeException('La direccion seleccionada no tiene cabecera canonica.'); }
        }
        $parentId = $direccionTarget ? $direccionTarget['cabecera_unit_id'] : $areaUnitId;
        $directionUnitId = $direccionTarget ? $direccionTarget['cabecera_unit_id'] : null;
        $directionName = $direccionTarget ? $direccionTarget['direction_label'] : null;
        $nota = $direccionTarget ? 'Catalogo DINSEC: pertenece a '.$directionName.'; area '.$areaCode.'; sector '.$locationSector.'; ubicada en '.$zonaTarget['zone_label'] : 'Catalogo DINSEC: pertenece al Area '.$areaCode.'; sector '.$locationSector.' de '.$zonaTarget['zone_label'];
        x($pdo, 'UPDATE organizational_units SET name=:n, short_name=LEFT(:n2,100), parent_id=:p, lifecycle_notes=:nota, updated_at=NOW() WHERE id=:id', ['n'=>$nombreDespues,'n2'=>$nombreDespues,'p'=>$parentId,'nota'=>$nota,'id'=>$unitId]);
        x($pdo, "INSERT INTO organizational_unit_relationships (source_unit_id,target_unit_id,relationship_type,valid_from,status,notes,created_at,updated_at) SELECT :h,:p,'jerarquica',CURRENT_DATE,'active',:nota,NOW(),NOW() WHERE NOT EXISTS (SELECT 1 FROM organizational_unit_relationships WHERE source_unit_id=:h2 AND target_unit_id=:p2 AND relationship_type='jerarquica' AND status='active')", ['h'=>$unitId,'p'=>$parentId,'nota'=>$nota,'h2'=>$unitId,'p2'=>$parentId]);
        x($pdo, "INSERT INTO organizational_unit_relationships (source_unit_id,target_unit_id,relationship_type,valid_from,status,notes,created_at,updated_at) SELECT :h,:area,'ubicacion_fisica',CURRENT_DATE,'active',:nota,NOW(),NOW() WHERE NOT EXISTS (SELECT 1 FROM organizational_unit_relationships WHERE source_unit_id=:h2 AND target_unit_id=:area2 AND relationship_type='ubicacion_fisica' AND status='active')", ['h'=>$unitId,'area'=>$areaUnitId,'nota'=>$nota,'h2'=>$unitId,'area2'=>$areaUnitId]);
        x($pdo, "INSERT INTO organizational_unit_relationships (source_unit_id,target_unit_id,relationship_type,valid_from,status,notes,created_at,updated_at) SELECT :h,:z,'ubicacion_fisica',CURRENT_DATE,'active',:nota,NOW(),NOW() WHERE NOT EXISTS (SELECT 1 FROM organizational_unit_relationships WHERE source_unit_id=:h2 AND target_unit_id=:z2 AND relationship_type='ubicacion_fisica' AND status='active')", ['h'=>$unitId,'z'=>$zonaTarget['cabecera_unit_id'],'nota'=>$nota,'h2'=>$unitId,'z2'=>$zonaTarget['cabecera_unit_id']]);
        x($pdo, "INSERT INTO moi_area_letter_assignments (organizational_unit_id, area_code, area_unit_id, location_sector, direction_unit_id, zone_unit_id, notes) VALUES (:u,:a,:area,:sector,:d,:z,:n) ON DUPLICATE KEY UPDATE area_code=VALUES(area_code), area_unit_id=VALUES(area_unit_id), location_sector=VALUES(location_sector), direction_unit_id=VALUES(direction_unit_id), zone_unit_id=VALUES(zone_unit_id), notes=VALUES(notes), updated_at=NOW()", ['u'=>$unitId,'a'=>$areaCode,'area'=>$areaUnitId,'sector'=>$locationSector,'d'=>$directionUnitId,'z'=>$zonaTarget['cabecera_unit_id'],'n'=>$nota]);
        x($pdo, "INSERT INTO moi_area_assignment_audit (organizational_unit_id, legacy_table, legacy_id, nombre_antes, nombre_despues, area_code, area_unit_id, area_name, location_sector, direction_unit_id, direction_name, zone_unit_id, zone_name, accion, notes, created_by) VALUES (:u,:lt,:li,:antes,:despues,:area_code,:area_unit_id,:area_name,:location_sector,:direction_unit_id,:direction_name,:zone_unit_id,:zone_name,'asignar_area_catalogo',:notes,'dashboard')", ['u'=>$unitId,'lt'=>$unitBefore['legacy_table'],'li'=>$unitBefore['legacy_id'],'antes'=>$unitBefore['name'],'despues'=>$nombreDespues,'area_code'=>$areaCode,'area_unit_id'=>$areaUnitId,'area_name'=>$areaName,'location_sector'=>$locationSector,'direction_unit_id'=>$directionUnitId,'direction_name'=>$directionName,'zone_unit_id'=>$zonaTarget['cabecera_unit_id'],'zone_name'=>$zonaTarget['zone_label'],'notes'=>$nota]);
        header('Location: asignar_areas_catalogo.php?zona_id='.(int)$zonaTarget['id'].'&ok=1'); exit;
    }
} catch(Throwable $e) { $err = $e->getMessage(); }

$buscar = trim($_GET['buscar'] ?? '');
$clave = $buscar !== '' ? upper_text($buscar) : ($zona['normalized_name'] ?? '');
$extra = '';
if (($zona['zone_number'] ?? 0)==8) { $extra=" AND UPPER(ou.name COLLATE utf8mb4_unicode_ci) NOT LIKE '%PANAMA OESTE%'"; }
$noAsignada = "AND NOT EXISTS (SELECT 1 FROM organizational_units c WHERE c.id=ou.parent_id AND (BINARY c.legacy_table=BINARY 'MOI_CABECERA_ZONA' OR BINARY c.legacy_table=BINARY 'MOI_CABECERA_DIRECCION' OR BINARY c.legacy_table=BINARY 'MOI_CABECERA_AREA')) AND NOT EXISTS (SELECT 1 FROM organizational_unit_relationships r JOIN organizational_units zc ON zc.id=r.target_unit_id WHERE r.source_unit_id=ou.id AND r.status='active' AND (BINARY zc.legacy_table=BINARY 'MOI_CABECERA_ZONA' OR BINARY zc.legacy_table=BINARY 'MOI_CABECERA_AREA'))";
$devuelta = "EXISTS (SELECT 1 FROM moi_area_assignment_audit ar WHERE ar.organizational_unit_id=ou.id AND ar.accion='regresar_pendiente' AND ar.zone_unit_id=:zonaActual AND ar.id=(SELECT MAX(ar2.id) FROM moi_area_assignment_audit ar2 WHERE ar2.organizational_unit_id=ou.id))";
$candidatos = $zona ? q($pdo, "SELECT ou.id,ou.name,ou.legacy_table,ou.legacy_id,ut.name AS tipo,parent.name AS superior FROM organizational_units ou LEFT JOIN unit_types ut ON ut.id=ou.unit_type_id LEFT JOIN organizational_units parent ON parent.id=ou.parent_id WHERE ou.lifecycle_status='vigente' $noAsignada AND ((BINARY ou.legacy_table <> BINARY 'MOI_CABECERA_AREA' AND UPPER(ou.name COLLATE utf8mb4_unicode_ci) LIKE :pat $extra) OR $devuelta) ORDER BY ou.name LIMIT 150", ['pat'=>'%'.$clave.'%','zonaActual'=>$zona['cabecera_unit_id'] ?? 0]) : [];
$sectores = ($catalogoExiste && $zona) ? q($pdo, "SELECT id, area_code, area_name, sector_name, service_label, op_status FROM moi_area_sector_catalog WHERE active=1 AND zone_number=:zn AND area_code IS NOT NULL ORDER BY area_code, sector_name", ['zn'=>$zona['zone_number']]) : [];
$asignadas = $zona ? q($pdo, "SELECT DISTINCT ou.id,ou.name,ou.legacy_table,ou.legacy_id,ut.name AS tipo,parent.name AS superior,aa.area_code,aa.location_sector,area.name AS area_name,aud.nombre_antes,aud.nombre_despues FROM organizational_units ou LEFT JOIN unit_types ut ON ut.id=ou.unit_type_id LEFT JOIN organizational_units parent ON parent.id=ou.parent_id LEFT JOIN moi_area_letter_assignments aa ON aa.organizational_unit_id=ou.id LEFT JOIN organizational_units area ON area.id=aa.area_unit_id LEFT JOIN moi_area_assignment_audit aud ON aud.organizational_unit_id=ou.id AND aud.id=(SELECT MAX(a2.id) FROM moi_area_assignment_audit a2 WHERE a2.organizational_unit_id=ou.id) WHERE aa.zone_unit_id=:cab ORDER BY aa.area_code, aa.location_sector, ou.name LIMIT 100", ['cab'=>$zona['cabecera_unit_id'] ?? 0]) : [];
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><title>Asignar por catalogo DINSEC</title><style>
body{font-family:Arial;margin:0;background:#f4f6f8;color:#1f2937}header{background:#111827;color:white;padding:18px 28px}main{padding:20px}.layout{display:grid;grid-template-columns:330px 1fr;gap:18px}.card,section{background:white;border-radius:10px;padding:14px;box-shadow:0 1px 4px #0002;margin-bottom:16px}.cab a{display:block;padding:8px;border-bottom:1px solid #e5e7eb;color:#111827;text-decoration:none}.cab a.act{background:#e0f2fe;font-weight:bold}.top a{color:#d1d5db;margin-right:14px}.msg{background:#ecfdf5;border:1px solid #10b981;padding:10px;border-radius:8px}.err{background:#fef2f2;border:1px solid #ef4444;padding:10px;border-radius:8px}.warn{background:#fffbeb;border:1px solid #f59e0b;padding:10px;border-radius:8px}table{width:100%;border-collapse:collapse;font-size:13px}th,td{border-bottom:1px solid #e5e7eb;text-align:left;padding:7px;vertical-align:top}th{background:#f9fafb}input,select,button{padding:7px;border:1px solid #d1d5db;border-radius:6px;max-width:100%}button,.btn{background:#047857;color:white;text-decoration:none;border-radius:6px;padding:8px;display:inline-block}.muted{font-size:12px;color:#6b7280}.wide{min-width:280px}.mini{font-size:12px;color:#374151}
</style></head><body><header><h1>Asignar areas usando catalogo DINSEC</h1><p class="top"><a href="index.php">Dashboard</a><a href="asignar_areas.php">Asignar areas manual</a><a href="catalogo_sectores.php">Catalogo sectores</a><a href="dinsec_personal.php">DINSEC personal</a></p></header><main>
<?php if($msg):?><p class="msg"><?=h($msg)?></p><?php endif;?><?php if($err):?><p class="err"><?=h($err)?></p><?php endif;?><?php if(!$catalogoExiste):?><p class="warn">Falta cargar el catalogo. Ejecute: <b>bash scripts/aplicar_catalogo_sectores_dinsec.sh</b></p><?php endif;?>
<div class="layout"><div class="card cab"><h2>Zona de ubicacion</h2><?php foreach($zonas as $z):?><a class="<?=((int)$z['id']===(int)($zona['id']??0))?'act':''?>" href="?zona_id=<?=h($z['id'])?>"><?=h($z['zone_number'])?> - <?=h($z['zone_label'])?></a><?php endforeach;?></div><div>
<section><h2><?=h($zona['zone_label'] ?? 'Zona')?>: sectores disponibles</h2><p class="muted">Este paso usa el catalogo para evitar escribir mal el sector. Area A sigue siendo una sola area; el sector identifica Penonome, Coclesito, Sabanitas, etc.</p><form method="get"><input type="hidden" name="zona_id" value="<?=h($zona['id'] ?? '')?>"><input name="buscar" value="<?=h($buscar)?>" placeholder="Buscar unidad"><button>Buscar</button></form><p class="mini">Filtro usado: <?=h($clave)?> | Sectores en catalogo: <?=count($sectores)?></p></section>
<section><h2>Pendientes para aprobar/asignar con catalogo</h2><table><thead><tr><th>Unidad encontrada</th><th>Superior actual</th><th>Asignar</th></tr></thead><tbody><?php foreach($candidatos as $r): $sectorSel=upper_text(sector_default($r['name'])); ?><tr><td><b><?=h($r['name'])?></b><br><span class="muted"><?=h($r['tipo'])?> | <?=h($r['legacy_table'])?>: <?=h($r['legacy_id'])?></span></td><td><?=h($r['superior'] ?: 'Sin superior')?></td><td><form method="post"><input type="hidden" name="accion" value="asignar_catalogo"><input type="hidden" name="zona_id" value="<?=h($zona['id'] ?? '')?>"><input type="hidden" name="unit_id" value="<?=h($r['id'])?>"><span class="muted">Nombre despues:</span><br><input name="nombre" value="<?=h($sectorSel ?: $r['name'])?>" size="28" placeholder="Vacio = sector del catalogo"><br><span class="muted">Sector del catalogo:</span><br><select class="wide" name="sector_id" required><option value="">Seleccione sector</option><?php foreach($sectores as $s): $sel=$sectorSel && upper_text($s['sector_name'])===$sectorSel; ?><option value="<?=h($s['id'])?>" <?=$sel?'selected':''?>>Area <?=h($s['area_code'])?> - <?=h($s['sector_name'])?></option><?php endforeach;?></select><br><span class="muted">Pertenece a direccion:</span><br><select class="wide" name="direccion_target_id"><option value="0">No aplica / pertenece al area de zona</option><?php foreach($direcciones as $d):?><option value="<?=h($d['id'])?>"><?=h($d['direction_number'])?> - <?=h($d['direction_label'])?></option><?php endforeach;?></select><br><button>Aprobar con catalogo</button></form></td></tr><?php endforeach;?></tbody></table></section>
<section><h2>Ya asignadas en esta zona</h2><table><thead><tr><th>Area real</th><th>Sector</th><th>Nombre antes</th><th>Nombre despues</th><th>Superior</th><th>Legacy</th></tr></thead><tbody><?php foreach($asignadas as $r):?><tr><td><?=h($r['area_name'] ?: ($r['area_code'] ? 'Area '.$r['area_code'] : ''))?></td><td><?=h($r['location_sector'])?></td><td><?=h($r['nombre_antes'] ?: $r['name'])?></td><td><?=h($r['nombre_despues'] ?: $r['name'])?></td><td><?=h($r['superior'])?></td><td><?=h($r['legacy_table'])?>: <?=h($r['legacy_id'])?></td></tr><?php endforeach;?></tbody></table></section>
</div></div></main></body></html>
