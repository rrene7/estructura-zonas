<?php
$configPath = __DIR__ . '/config.php';
if (!file_exists($configPath)) { die('Falta dashboard/config.php'); }
$config = require $configPath;
$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $config['db_host'], $config['db_port'], $config['db_name'], $config['charset']);
$pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function q($pdo,$sql,$p=[]){ $s=$pdo->prepare($sql); $s->execute($p); return $s->fetchAll(); }
function one($pdo,$sql,$p=[]){ $r=q($pdo,$sql,$p); return $r[0] ?? null; }
function x($pdo,$sql,$p=[]){ $s=$pdo->prepare($sql); $s->execute($p); }

$pdo->exec("CREATE TABLE IF NOT EXISTS moi_area_sector_catalog (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, source_name VARCHAR(180) NOT NULL DEFAULT 'DINSEC 04AGO2025', zone_number INT NOT NULL, zone_label VARCHAR(180) NOT NULL, area_code VARCHAR(10) NULL, area_name VARCHAR(120) NULL, sector_name VARCHAR(180) NOT NULL, service_label VARCHAR(180) NULL, op_status ENUM('OP','NO OP','OA','NO DEFINIDO') NOT NULL DEFAULT 'NO DEFINIDO', notes VARCHAR(255) NULL, active TINYINT(1) NOT NULL DEFAULT 1, created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uq_sector (zone_number, area_code, sector_name, service_label), INDEX idx_sector_zone (zone_number), INDEX idx_sector_area (area_code), INDEX idx_sector_name (sector_name), INDEX idx_sector_active (active)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$msg=''; $err='';
try {
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        $accion = $_POST['accion'] ?? '';
        if ($accion === 'guardar') {
            $zoneNumber=(int)($_POST['zone_number'] ?? 0);
            $zona = one($pdo, "SELECT zone_number, zone_label FROM moi_zonas_cabecera_vigentes WHERE zone_number=:z LIMIT 1", ['z'=>$zoneNumber]);
            $zoneLabel = $zona['zone_label'] ?? trim($_POST['zone_label'] ?? '');
            $areaCode = trim($_POST['area_code'] ?? '');
            $areaName = $areaCode !== '' ? 'Area '.$areaCode : null;
            $sector = mb_strtoupper(trim($_POST['sector_name'] ?? ''), 'UTF-8');
            if ($zoneNumber <= 0 || $zoneLabel === '' || $sector === '') { throw new RuntimeException('Debe indicar zona y sector.'); }
            x($pdo, "INSERT INTO moi_area_sector_catalog (zone_number, zone_label, area_code, area_name, sector_name, service_label, op_status, notes, active) VALUES (:zn,:zl,:ac,:an,:sector,:service,:op,:notes,1) ON DUPLICATE KEY UPDATE area_name=VALUES(area_name), op_status=VALUES(op_status), notes=VALUES(notes), active=1, updated_at=NOW()", ['zn'=>$zoneNumber,'zl'=>$zoneLabel,'ac'=>$areaCode !== '' ? $areaCode : null,'an'=>$areaName,'sector'=>$sector,'service'=>trim($_POST['service_label'] ?? '') ?: null,'op'=>$_POST['op_status'] ?? 'NO DEFINIDO','notes'=>trim($_POST['notes'] ?? '')]);
            $msg='Sector guardado en catalogo.';
        }
        if ($accion === 'toggle') {
            x($pdo, "UPDATE moi_area_sector_catalog SET active=IF(active=1,0,1), updated_at=NOW() WHERE id=:id", ['id'=>(int)$_POST['id']]);
            $msg='Estado actualizado.';
        }
    }
} catch(Throwable $e){ $err=$e->getMessage(); }
$zonas = q($pdo, "SELECT zone_number, zone_label FROM moi_zonas_cabecera_vigentes WHERE lifecycle_status='vigente' ORDER BY zone_number");
$zonaSel=(int)($_GET['zona'] ?? 0);
$where=''; $params=[];
if ($zonaSel > 0) { $where='WHERE zone_number=:z'; $params['z']=$zonaSel; }
$catalogo = q($pdo, "SELECT * FROM moi_area_sector_catalog $where ORDER BY zone_number, area_code IS NULL, area_code, sector_name LIMIT 500", $params);
$resumen = q($pdo, "SELECT zone_number, zone_label, COALESCE(area_code,'SERV') area, COUNT(*) total FROM moi_area_sector_catalog GROUP BY zone_number, zone_label, COALESCE(area_code,'SERV') ORDER BY zone_number, area");
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><title>Catalogo de sectores DINSEC</title><style>body{font-family:Arial;margin:0;background:#f4f6f8;color:#1f2937}header{background:#111827;color:white;padding:18px 28px}main{padding:20px}.grid{display:grid;grid-template-columns:340px 1fr;gap:18px}.card,section{background:white;border-radius:10px;padding:14px;box-shadow:0 1px 4px #0002;margin-bottom:16px}.top a{color:#d1d5db;margin-right:14px}input,select,textarea,button{padding:7px;border:1px solid #d1d5db;border-radius:6px;width:100%;box-sizing:border-box}button,.btn{background:#047857;color:white;text-decoration:none;border-radius:6px;padding:8px;display:inline-block;width:auto}table{width:100%;border-collapse:collapse;font-size:13px}th,td{border-bottom:1px solid #e5e7eb;text-align:left;padding:7px;vertical-align:top}th{background:#f9fafb}.msg{background:#ecfdf5;border:1px solid #10b981;padding:10px;border-radius:8px}.err{background:#fef2f2;border:1px solid #ef4444;padding:10px;border-radius:8px}.muted{font-size:12px;color:#6b7280}.row{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px}.off{opacity:.55}</style></head><body><header><h1>Catalogo de sectores por area</h1><p class="top"><a href="index.php">Dashboard</a><a href="asignar_areas.php">Asignar areas</a><a href="dinsec_personal.php">DINSEC personal</a></p></header><main><?php if($msg):?><p class="msg"><?=h($msg)?></p><?php endif;?><?php if($err):?><p class="err"><?=h($err)?></p><?php endif;?><div class="grid"><section><h2>Agregar sector</h2><p class="muted">Area A es una sola; aqui se registran sus ubicaciones o sectores.</p><form method="post"><input type="hidden" name="accion" value="guardar"><label>Zona<select name="zone_number"><?php foreach($zonas as $z):?><option value="<?=h($z['zone_number'])?>"><?=h($z['zone_number'])?> - <?=h($z['zone_label'])?></option><?php endforeach;?></select></label><div class="row"><label>Area<select name="area_code"><option value="">Servicio / sin area</option><?php foreach(range('A','P') as $a):?><option value="<?=h($a)?>">Area <?=h($a)?></option><?php endforeach;?></select></label><label>OP/NO OP<select name="op_status"><option>NO DEFINIDO</option><option>OP</option><option>NO OP</option><option>OA</option></select></label></div><label>Sector / ubicacion<input name="sector_name" placeholder="PENONOME, COCLESITO, SABANITAS" required></label><label>Servicio<input name="service_label" placeholder="LINCE, TURISMO, DNIP..."></label><label>Notas<textarea name="notes" rows="2"></textarea></label><button>Guardar sector</button></form><h2>Resumen</h2><table><thead><tr><th>Zona</th><th>Area</th><th>Total</th></tr></thead><tbody><?php foreach($resumen as $r):?><tr><td><?=h($r['zone_number'])?> - <?=h($r['zone_label'])?></td><td><?=h($r['area'])?></td><td><?=h($r['total'])?></td></tr><?php endforeach;?></tbody></table></section><section><h2>Catalogo</h2><form method="get" style="margin-bottom:10px"><select name="zona"><option value="0">Todas las zonas</option><?php foreach($zonas as $z):?><option value="<?=h($z['zone_number'])?>" <?=$zonaSel===(int)$z['zone_number']?'selected':''?>><?=h($z['zone_number'])?> - <?=h($z['zone_label'])?></option><?php endforeach;?></select><button>Filtrar</button></form><table><thead><tr><th>Zona</th><th>Area</th><th>Sector</th><th>Servicio</th><th>OP</th><th>Nota</th><th>Activo</th></tr></thead><tbody><?php foreach($catalogo as $r):?><tr class="<?=$r['active']?'':'off'?>"><td><?=h($r['zone_number'])?> - <?=h($r['zone_label'])?></td><td><?=h($r['area_code'] ? 'Area '.$r['area_code'] : 'Servicio')?></td><td><b><?=h($r['sector_name'])?></b></td><td><?=h($r['service_label'])?></td><td><?=h($r['op_status'])?></td><td><?=h($r['notes'])?></td><td><form method="post"><input type="hidden" name="accion" value="toggle"><input type="hidden" name="id" value="<?=h($r['id'])?>"><button><?=$r['active']?'Activo':'Inactivo'?></button></form></td></tr><?php endforeach;?></tbody></table></section></div></main></body></html>
