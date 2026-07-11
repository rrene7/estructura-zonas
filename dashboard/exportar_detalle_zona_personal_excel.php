<?php
$configPath = __DIR__ . '/config.php';
if (!file_exists($configPath)) { die('Falta dashboard/config.php'); }
$config = require $configPath;
$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $config['db_host'], $config['db_port'], $config['db_name'], $config['charset']);
$pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');

function q($pdo,$sql,$p=[]){ $s=$pdo->prepare($sql); $s->execute($p); return $s->fetchAll(); }
function one($pdo,$sql,$p=[]){ $r=q($pdo,$sql,$p); return $r[0] ?? []; }
function table_exists($pdo,$table){ $s=$pdo->prepare('SELECT COUNT(*) total FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=:t'); $s->execute(['t'=>$table]); return ((int)($s->fetch()['total'] ?? 0))>0; }
function csv_text($v){
    $v = (string)($v ?? '');
    $v = str_replace(["\r\n","\r","\n"], ' ', $v);
    return $v;
}

$zonaJoin = "BINARY cab.legacy_table = BINARY 'MOI_CABECERA_ZONA' AND CAST(cab.legacy_id AS UNSIGNED) = z.zone_number";
$zonaId = (int)($_GET['zona_id'] ?? 0);
$zona = one($pdo, "SELECT z.id,z.zone_number,z.zone_label,z.normalized_name,cab.id AS cabecera_unit_id FROM moi_zonas_cabecera_vigentes z LEFT JOIN organizational_units cab ON $zonaJoin WHERE z.id=:id", ['id'=>$zonaId]);
if (!$zona) { die('Zona no encontrada.'); }

$hasLinks = table_exists($pdo,'dinsec_personnel_unit_links');
$buscar = trim($_GET['buscar'] ?? '');
$areaFiltro = trim($_GET['area_code'] ?? '');
$scopeFiltro = trim($_GET['scope'] ?? '');
$estadoFiltro = trim($_GET['estado'] ?? '');
$cab = (int)($zona['cabecera_unit_id'] ?? 0);
$zn = (int)($zona['zone_number'] ?? 0);
$zl = $zona['zone_label'] ?? '';

$where = "(d.zone_unit_id=:cab OR d.zone_label LIKE :zl)";
$params = ['cab'=>$cab,'zl'=>'%'.$zl.'%'];
if ($buscar !== '') { $where .= " AND (d.full_name LIKE :b OR d.position_number LIKE :b OR d.assignment_text LIKE :b OR d.location_sector LIKE :b OR d.rank_text LIKE :b)"; $params['b']='%'.$buscar.'%'; }
if ($areaFiltro !== '') { $where .= " AND COALESCE(d.area_code,'')=:area"; $params['area']=$areaFiltro; }
if ($scopeFiltro !== '') { $where .= " AND COALESCE(l.assignment_scope,'sin_vinculo')=:scope"; $params['scope']=$scopeFiltro; }
if ($estadoFiltro !== '') { $where .= " AND d.review_status=:estado"; $params['estado']=$estadoFiltro; }
$linkJoin = $hasLinks ? "LEFT JOIN dinsec_personnel_unit_links l ON l.dinsec_personnel_reference_id=d.id AND l.status='active'" : "LEFT JOIN (SELECT NULL dinsec_personnel_reference_id, NULL zone_unit_id, NULL area_unit_id, NULL assignment_unit_id, NULL assignment_scope, NULL status, NULL notes) l ON 1=0";

$rows = q($pdo, "SELECT d.page_number,d.row_number,d.rank_text,d.position_number,d.full_name,d.assignment_text,d.area_code,d.area_name,d.location_sector,d.service_label,d.review_status,d.observation_text,d.review_notes,l.assignment_scope,au.name AS assignment_unit, au.legacy_table AS assignment_legacy_table, au.legacy_id AS assignment_legacy_id, area.name AS area_unit, zone.name AS zone_unit FROM dinsec_personnel_reference d $linkJoin LEFT JOIN organizational_units au ON au.id=l.assignment_unit_id LEFT JOIN organizational_units area ON area.id=l.area_unit_id LEFT JOIN organizational_units zone ON zone.id=l.zone_unit_id WHERE $where ORDER BY COALESCE(d.area_code,'ZZ'), COALESCE(d.location_sector,d.service_label,d.assignment_text), d.row_number, d.rank_text, d.full_name", $params);

$filename = 'detalle_personal_zona_'.str_pad((string)$zn,2,'0',STR_PAD_LEFT).'_excel.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename='.$filename);
header('Pragma: no-cache');
header('Expires: 0');

// BOM UTF-8 + separador para que Excel en español abra en columnas.
echo "\xEF\xBB\xBF";
echo "sep=;\r\n";
$out = fopen('php://output','w');
$delimiter = ';';
fputcsv($out, ['Zona','Pagina','Fila','Rango','Posicion','Nombre','Asignacion PDF','Area','Area nombre','Sector/Servicio','Ambito vinculo','Unidad asignada','Unidad area','Unidad zona','Legacy unidad','Estado revision','Observacion','Notas revision'], $delimiter);
foreach ($rows as $p) {
    $legacy = trim(($p['assignment_legacy_table'] ?? '').': '.($p['assignment_legacy_id'] ?? ''), ': ');
    fputcsv($out, array_map('csv_text', [
        $zl,
        $p['page_number'],
        $p['row_number'],
        $p['rank_text'],
        $p['position_number'],
        $p['full_name'],
        $p['assignment_text'],
        $p['area_code'],
        $p['area_name'],
        $p['location_sector'] ?: $p['service_label'],
        $p['assignment_scope'] ?: 'sin_vinculo',
        $p['assignment_unit'],
        $p['area_unit'],
        $p['zone_unit'],
        $legacy,
        $p['review_status'],
        $p['observation_text'],
        $p['review_notes'],
    ]), $delimiter);
}
fclose($out);
exit;
