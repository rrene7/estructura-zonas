<?php
declare(strict_types=1);

// Vincula personal DINSEC contra la estructura vigente ya existente.
// Regla: este script NO crea, renombra, mueve ni actualiza organizational_units.
// Uso: php scripts/aplicar_zona_real_desde_dinsec.php --zona=2

$options=getopt('',['zona:']);
$zonaNumero=(int)($options['zona']??0);
if($zonaNumero<=0){fwrite(STDERR,"Uso: php scripts/aplicar_zona_real_desde_dinsec.php --zona=2\n");exit(1);}
$configPath=__DIR__.'/../dashboard/config.php';
if(!is_file($configPath)){fwrite(STDERR,"Falta dashboard/config.php\n");exit(1);}
$config=require $configPath;
$dsn=sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s',$config['db_host'],$config['db_port'],$config['db_name'],$config['charset']);
$pdo=new PDO($dsn,$config['db_user'],$config['db_pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
function q(PDO $pdo,string $sql,array $p=[]):array{$s=$pdo->prepare($sql);$s->execute($p);return $s->fetchAll();}
function one(PDO $pdo,string $sql,array $p=[]):?array{$r=q($pdo,$sql,$p);return $r[0]??null;}
function x(PDO $pdo,string $sql,array $p=[]):void{$s=$pdo->prepare($sql);$s->execute($p);}
function norm(mixed $v):string{$t=trim((string)$v);$t=function_exists('mb_strtoupper')?mb_strtoupper($t,'UTF-8'):strtoupper($t);$t=strtr($t,['Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N']);$t=preg_replace('/[^A-Z0-9]+/u',' ',$t);return trim((string)preg_replace('/\s+/',' ',(string)$t));}
function slug(mixed $v):string{return str_replace(' ','-',norm($v));}

$zona=one($pdo,"SELECT z.zone_number,z.zone_label,cab.id AS cabecera_unit_id FROM moi_zonas_cabecera_vigentes z LEFT JOIN organizational_units cab ON BINARY cab.legacy_table=BINARY 'MOI_CABECERA_ZONA' AND CAST(cab.legacy_id AS UNSIGNED)=z.zone_number WHERE z.zone_number=:zn AND z.lifecycle_status='vigente' LIMIT 1",['zn'=>$zonaNumero]);
if(!$zona||empty($zona['cabecera_unit_id'])){fwrite(STDERR,"La zona {$zonaNumero} no tiene cabecera vigente existente.\n");exit(1);}
$zoneId=(int)$zona['cabecera_unit_id'];$prefix='Z'.str_pad((string)$zonaNumero,2,'0',STR_PAD_LEFT);

$pdo->exec("CREATE TABLE IF NOT EXISTS dinsec_personnel_unit_links (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,dinsec_personnel_reference_id BIGINT UNSIGNED NOT NULL,zone_unit_id BIGINT UNSIGNED NULL,area_unit_id BIGINT UNSIGNED NULL,sector_catalog_id BIGINT UNSIGNED NULL,assignment_unit_id BIGINT UNSIGNED NULL,assignment_scope ENUM('zona','area','sector','servicio','administrativo','pendiente') NOT NULL DEFAULT 'pendiente',position_number VARCHAR(50) NULL,full_name VARCHAR(180) NOT NULL,rank_text VARCHAR(80) NULL,assignment_text VARCHAR(220) NULL,location_sector VARCHAR(180) NULL,source_name VARCHAR(180) NOT NULL DEFAULT 'DINSEC 04AGO2025',status ENUM('active','inactive','review') NOT NULL DEFAULT 'active',notes VARCHAR(255) NULL,created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uq_dinsec_link (dinsec_personnel_reference_id),INDEX idx_link_zone (zone_unit_id),INDEX idx_link_area (area_unit_id),INDEX idx_link_sector (sector_catalog_id),INDEX idx_link_assignment (assignment_unit_id),INDEX idx_link_position (position_number),INDEX idx_link_scope (assignment_scope)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("CREATE TABLE IF NOT EXISTS moi_zone_apply_audit (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,zone_number INT NOT NULL,zone_label VARCHAR(180) NOT NULL,action_name VARCHAR(120) NOT NULL,affected_rows INT NOT NULL DEFAULT 0,notes VARCHAR(255) NULL,created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,INDEX idx_zone_apply (zone_number,action_name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$zoneUnits=q($pdo,"WITH RECURSIVE unit_tree AS (SELECT ou.id,ou.parent_id,ou.name,ou.short_name,ou.code,ou.moi_code,ou.legacy_table,ou.legacy_id,ut.name AS unit_type FROM organizational_units ou LEFT JOIN unit_types ut ON ut.id=ou.unit_type_id WHERE ou.id=:zone_id UNION ALL SELECT child.id,child.parent_id,child.name,child.short_name,child.code,child.moi_code,child.legacy_table,child.legacy_id,ut.name AS unit_type FROM organizational_units child JOIN unit_tree parent ON parent.id=child.parent_id LEFT JOIN unit_types ut ON ut.id=child.unit_type_id WHERE child.status='active' AND child.lifecycle_status='vigente' AND (child.valid_to IS NULL OR child.valid_to>=CURRENT_DATE)) SELECT * FROM unit_tree",['zone_id'=>$zoneId]);
$byName=[];foreach($zoneUnits as $u){foreach(['name','short_name','code','moi_code'] as $f){$k=norm($u[$f]??'');if($k!=='')$byName[$k][]=$u;}}
$people=q($pdo,'SELECT * FROM dinsec_personnel_reference WHERE zone_unit_id=:zone_id OR zone_label LIKE :zl',['zone_id'=>$zoneId,'zl'=>'%'.$zona['zone_label'].'%']);
$linked=0;$partial=0;$specificPending=0;
$pdo->beginTransaction();
try{
foreach($people as $p){
 $area=null;$catalog=null;$sector=null;$service=null;
 if(!empty($p['area_code']))$area=one($pdo,"SELECT id,name FROM organizational_units WHERE BINARY legacy_table=BINARY 'MOI_CABECERA_AREA' AND BINARY legacy_id=BINARY :legacy AND status='active' AND lifecycle_status='vigente' LIMIT 1",['legacy'=>$prefix.'-AREA-'.$p['area_code']]);
 if($area&&!empty($p['location_sector'])){
  $catalog=one($pdo,"SELECT id FROM moi_area_sector_catalog WHERE active=1 AND zone_number=:zn AND area_code=:ac AND UPPER(sector_name COLLATE utf8mb4_unicode_ci)=UPPER(:sector COLLATE utf8mb4_unicode_ci) LIMIT 1",['zn'=>$zonaNumero,'ac'=>$p['area_code'],'sector'=>$p['location_sector']]);
  $sector=one($pdo,"SELECT id,name FROM organizational_units WHERE BINARY legacy_table=BINARY 'DINSEC_SECTOR' AND BINARY legacy_id=BINARY :legacy AND status='active' AND lifecycle_status='vigente' LIMIT 1",['legacy'=>$prefix.'-'.$p['area_code'].'-'.slug($p['location_sector'])]);
 }
 if(!$sector&&!$area&&!empty($p['service_label'])){$c=$byName[norm($p['service_label'])]??[];if(count($c)===1)$service=$c[0];}
 $assignmentId=(int)($sector['id']??$area['id']??$service['id']??$zoneId);
 $scope=$sector?'sector':($area?'area':($service?'servicio':'zona'));
 $complete=$scope!=='zona';$linkStatus=$complete?'active':'review';
 $notes=$complete?'Vinculo confirmado contra una unidad vigente existente; no se creo estructura.':'Zona confirmada. Area, dependencia o servicio pendiente de revision; no se creo estructura.';
 x($pdo,"INSERT INTO dinsec_personnel_unit_links (dinsec_personnel_reference_id,zone_unit_id,area_unit_id,sector_catalog_id,assignment_unit_id,assignment_scope,position_number,full_name,rank_text,assignment_text,location_sector,status,notes) VALUES (:pid,:zone,:area,:catalog,:assignment,:scope,:position,:name,:rank,:text,:sector,:status,:notes) ON DUPLICATE KEY UPDATE zone_unit_id=VALUES(zone_unit_id),area_unit_id=VALUES(area_unit_id),sector_catalog_id=VALUES(sector_catalog_id),assignment_unit_id=VALUES(assignment_unit_id),assignment_scope=VALUES(assignment_scope),position_number=VALUES(position_number),full_name=VALUES(full_name),rank_text=VALUES(rank_text),assignment_text=VALUES(assignment_text),location_sector=VALUES(location_sector),status=VALUES(status),notes=VALUES(notes),updated_at=NOW()",['pid'=>$p['id'],'zone'=>$zoneId,'area'=>$area['id']??null,'catalog'=>$catalog['id']??null,'assignment'=>$assignmentId,'scope'=>$scope,'position'=>$p['position_number'],'name'=>$p['full_name'],'rank'=>$p['rank_text'],'text'=>$p['assignment_text'],'sector'=>$p['location_sector'],'status'=>$linkStatus,'notes'=>$notes]);
 x($pdo,"UPDATE dinsec_personnel_reference SET review_status=:rs,review_notes=:notes,updated_at=NOW() WHERE id=:id",['rs'=>$complete?'validado':'pendiente','notes'=>$notes,'id'=>$p['id']]);
 $linked++;if(!$complete){$partial++;if(!empty($p['area_code'])||!empty($p['location_sector'])||!empty($p['service_label']))$specificPending++;}
}
x($pdo,"INSERT INTO moi_zone_apply_audit (zone_number,zone_label,action_name,affected_rows,notes) VALUES (:zn,:zl,'vincular_dinsec_sin_crear_estructura',:rows,:notes)",['zn'=>$zonaNumero,'zl'=>$zona['zone_label'],'rows'=>$linked,'notes'=>"Vinculos: {$linked}; parciales: {$partial}; detalle no encontrado: {$specificPending}. organizational_units sin cambios."]);
$pdo->commit();
}catch(Throwable $e){$pdo->rollBack();fwrite(STDERR,'Error: '.$e->getMessage()."\n");exit(1);}
echo "Zona: {$zona['zone_label']}\nPersonal procesado: {$linked}\nAsignaciones parciales a zona: {$partial}\nUbicaciones especificas pendientes: {$specificPending}\norganizational_units no fue modificada.\n";
