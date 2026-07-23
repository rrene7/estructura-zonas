<?php
declare(strict_types=1);
session_start();

$configPath=__DIR__.'/config.php';
if(!is_file($configPath)){http_response_code(500);die('Falta dashboard/config.php');}
$config=require $configPath;
$dsn=sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s',$config['db_host'],$config['db_port'],$config['db_name'],$config['charset']);
$pdo=new PDO($dsn,$config['db_user'],$config['db_pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');

function h(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function rows(PDO $pdo,string $sql,array $p=[]):array{$s=$pdo->prepare($sql);$s->execute($p);return $s->fetchAll();}
function one(PDO $pdo,string $sql,array $p=[]):array{$r=rows($pdo,$sql,$p);return $r[0]??[];}
function normalize_key(mixed $v):string{$t=trim((string)$v);$t=function_exists('mb_strtoupper')?mb_strtoupper($t,'UTF-8'):strtoupper($t);$t=strtr($t,['Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N']);$t=preg_replace('/[^A-Z0-9]+/u',' ',$t);return trim((string)preg_replace('/\s+/',' ',(string)$t));}
function matched_level(array $u):string{$t=normalize_key($u['unit_type']??'');$l=normalize_key($u['legacy_table']??'');if($t==='ZONA POLICIAL'||$l==='MOI CABECERA ZONA')return 'zona';if($t==='DIRECCION NACIONAL'||$t==='SUBDIRECCION NACIONAL'||$l==='MOI CABECERA DIRECCION')return 'direccion';if($t==='AREA'||$t==='AREA POLICIAL'||$l==='MOI CABECERA AREA')return 'area';if(str_contains($t,'SERVICIO'))return 'servicio';if(in_array($t,['DEPARTAMENTO','DIVISION','SECCION','OFICINA','DEPENDENCIA','ESTACION','ESTACION POLICIAL','SUBESTACION','SUBESTACION POLICIAL','PUESTO','PUESTO POLICIAL','DESTACAMENTO'],true))return 'dependencia';return 'unidad';}

$personId=(int)($_GET['id']??$_POST['id']??0);
if($personId<=0){http_response_code(400);die('Falta el identificador del registro.');}
if(empty($_SESSION['pie_fuerza_csrf']))$_SESSION['pie_fuerza_csrf']=bin2hex(random_bytes(24));
$csrf=(string)$_SESSION['pie_fuerza_csrf'];$message='';$error='';

try{
if($_SERVER['REQUEST_METHOD']==='POST'){
 if(!hash_equals($csrf,(string)($_POST['csrf']??'')))throw new RuntimeException('Token de seguridad inválido. Recargue la página.');
 $action=(string)($_POST['action']??'');$reviewedBy=trim((string)($_POST['reviewed_by']??'usuario_local'))?:'usuario_local';
 if($action==='assign'){
  $unitId=(int)($_POST['unit_id']??0);$assignmentStatus=(string)($_POST['assignment_status']??'asignado_completo');
  if(!in_array($assignmentStatus,['asignado_completo','asignado_parcial'],true))throw new RuntimeException('Estado de asignación no permitido.');
  $unit=one($pdo,"SELECT ou.id,ou.name,ou.legacy_table,ou.legacy_id,ut.name AS unit_type FROM organizational_units ou LEFT JOIN unit_types ut ON ut.id=ou.unit_type_id WHERE ou.id=:id AND ou.status='active' AND ou.lifecycle_status='vigente' AND (ou.valid_to IS NULL OR ou.valid_to>=CURRENT_DATE) LIMIT 1",['id'=>$unitId]);
  if(!$unit)throw new RuntimeException('La unidad seleccionada no existe o no está vigente.');
  $level=matched_level($unit);$pending=$assignmentStatus==='asignado_parcial'?(trim((string)($_POST['pending_level']??'nivel inferior'))?:'nivel inferior'):null;$notes=trim((string)($_POST['notes']??'Asignación revisada manualmente.'))?:'Asignación revisada manualmente.';
  $s=$pdo->prepare("INSERT INTO workforce_unit_matches (personnel_staging_id,matched_unit_id,matched_level,assignment_status,pending_level,match_method,confidence_level,candidate_count,candidate_data,review_status,review_notes,reviewed_by,reviewed_at) VALUES (:person_id,:unit_id,:level,:status,:pending,'revision_manual','alto',1,NULL,'aprobado',:notes,:reviewed_by,NOW()) ON DUPLICATE KEY UPDATE matched_unit_id=VALUES(matched_unit_id),matched_level=VALUES(matched_level),assignment_status=VALUES(assignment_status),pending_level=VALUES(pending_level),match_method='revision_manual',confidence_level='alto',review_status='aprobado',review_notes=VALUES(review_notes),reviewed_by=VALUES(reviewed_by),reviewed_at=NOW(),updated_at=NOW()");
  $s->execute(['person_id'=>$personId,'unit_id'=>$unitId,'level'=>$level,'status'=>$assignmentStatus,'pending'=>$pending,'notes'=>$notes,'reviewed_by'=>$reviewedBy]);
  $message='La persona fue asignada únicamente a una unidad vigente existente.';
 }elseif($action==='no_match'){
  $notes=trim((string)($_POST['notes']??'Ubicación no encontrada en la estructura vigente'))?:'Ubicación no encontrada en la estructura vigente';
  $s=$pdo->prepare("INSERT INTO workforce_unit_matches (personnel_staging_id,matched_unit_id,matched_level,assignment_status,pending_level,match_method,confidence_level,candidate_count,candidate_data,review_status,review_notes,reviewed_by,reviewed_at) VALUES (:person_id,NULL,'ninguno','sin_coincidencia',NULL,'revision_manual','alto',0,NULL,'aprobado',:notes,:reviewed_by,NOW()) ON DUPLICATE KEY UPDATE matched_unit_id=NULL,matched_level='ninguno',assignment_status='sin_coincidencia',pending_level=NULL,match_method='revision_manual',confidence_level='alto',review_status='aprobado',review_notes=VALUES(review_notes),reviewed_by=VALUES(reviewed_by),reviewed_at=NOW(),updated_at=NOW()");
  $s->execute(['person_id'=>$personId,'notes'=>$notes,'reviewed_by'=>$reviewedBy]);$message='El registro quedó marcado sin coincidencia; no se creó ninguna unidad.';
 }
}}
catch(Throwable $e){$error=$e->getMessage();}

$person=one($pdo,'SELECT * FROM vw_workforce_match_detail WHERE personnel_staging_id=:id',['id'=>$personId]);
if(!$person){http_response_code(404);die('No se encontró el registro del pie de fuerza.');}
$search=trim((string)($_GET['buscar']??''));$params=[];$where=["ou.status='active'","ou.lifecycle_status='vigente'",'(ou.valid_to IS NULL OR ou.valid_to>=CURRENT_DATE)'];
if($search!==''){$where[]='(ou.name LIKE :search OR ou.short_name LIKE :search OR ou.code LIKE :search OR ou.moi_code LIKE :search)';$params['search']='%'.$search.'%';}
elseif(!empty($person['matched_unit_id'])){$where[]='(ou.parent_id=:current OR ou.id=:current)';$params['current']=$person['matched_unit_id'];}
else{$where[]="(ou.legacy_table IN ('MOI_CABECERA_ZONA','MOI_CABECERA_DIRECCION') OR ut.name IN ('zona_policial','direccion_nacional'))";}
$candidates=rows($pdo,"SELECT ou.id,ou.name,ou.short_name,ou.code,ou.moi_code,ou.legacy_table,ou.legacy_id,ut.name AS unit_type,parent.name AS parent_name FROM organizational_units ou LEFT JOIN unit_types ut ON ut.id=ou.unit_type_id LEFT JOIN organizational_units parent ON parent.id=ou.parent_id WHERE ".implode(' AND ',$where)." ORDER BY COALESCE(ou.moi_level,ou.level,99),ut.name,ou.name LIMIT 200",$params);
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Revisar PIE DE FUERZA</title><style>
body{font-family:Arial,sans-serif;margin:0;background:#f4f6f8;color:#1f2937}header{background:#111827;color:#fff;padding:18px 28px}header a{color:#d1d5db;margin-right:14px}main{padding:22px}.card,section{background:#fff;border-radius:10px;padding:15px;box-shadow:0 1px 4px #0002;margin-bottom:16px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}.label{font-size:12px;text-transform:uppercase;color:#6b7280}.value{font-weight:bold;margin-top:4px}.msg{background:#ecfdf5;border:1px solid #10b981}.err{background:#fef2f2;border:1px solid #ef4444}.warn{background:#fffbeb;border:1px solid #f59e0b}.muted{font-size:12px;color:#6b7280}input,select,button{padding:8px;border:1px solid #d1d5db;border-radius:6px}button{background:#047857;color:#fff;border:0;cursor:pointer}.danger{background:#b91c1c}table{width:100%;border-collapse:collapse;font-size:13px}th,td{padding:8px;border-bottom:1px solid #e5e7eb;text-align:left;vertical-align:top}th{background:#f9fafb}.inline{display:flex;gap:7px;flex-wrap:wrap;align-items:center}.candidate-form{display:grid;gap:6px}.pill{display:inline-block;padding:3px 7px;background:#eef2ff;border-radius:999px;font-size:11px}
</style></head><body><header><h1>Revisión de ubicación</h1><p><a href="pie_fuerza.php?source_id=<?=h($person['source_id'])?>">Volver al PIE DE FUERZA</a><a href="index.php">Dashboard</a></p></header><main>
<?php if($message):?><section class="msg"><?=h($message)?></section><?php endif;?><?php if($error):?><section class="err"><?=h($error)?></section><?php endif;?>
<section><h2><?=h($person['full_name'])?></h2><div class="grid"><div><div class="label">Rango / posición</div><div class="value"><?=h($person['rank_text'])?> / <?=h($person['position_number'])?></div></div><div><div class="label">Ubicación original</div><div class="value"><?=h($person['location_original'])?></div></div><div><div class="label">Tipo Policía</div><div class="value"><?=h($person['police_type_original'])?></div></div><div><div class="label">Unidad confirmada actualmente</div><div class="value"><?=h($person['matched_unit_name']?:'Sin unidad')?></div><span class="pill"><?=h($person['assignment_status']?:'pendiente_revision')?></span></div></div><p class="muted"><?=h($person['review_notes'])?></p></section>
<section class="warn"><strong>Regla:</strong> aquí solo se puede seleccionar una unidad que ya exista y esté vigente. Esta pantalla no crea, renombra ni mueve la estructura.</section>
<section><h2>Buscar unidad existente</h2><form method="get" class="inline"><input type="hidden" name="id" value="<?=h($personId)?>"><input name="buscar" value="<?=h($search)?>" placeholder="Zona, dirección, área o dependencia" size="45"><button>Buscar</button></form><?php if($search===''&&$person['matched_unit_id']):?><p class="muted">Se muestran la unidad actual y sus hijos directos. Use la búsqueda para localizar otra unidad.</p><?php endif;?></section>
<section><h2>Unidades candidatas</h2><table><thead><tr><th>Unidad vigente</th><th>Superior</th><th>Tipo / código</th><th>Asignar</th></tr></thead><tbody><?php foreach($candidates as $c):?><tr><td><strong><?=h($c['name'])?></strong><br><span class="muted"><?=h($c['legacy_table'])?>: <?=h($c['legacy_id'])?></span></td><td><?=h($c['parent_name']?:'Sin superior')?></td><td><?=h($c['unit_type'])?><br><?=h($c['moi_code']?:$c['code'])?></td><td><form method="post" class="candidate-form"><input type="hidden" name="csrf" value="<?=h($csrf)?>"><input type="hidden" name="id" value="<?=h($personId)?>"><input type="hidden" name="action" value="assign"><input type="hidden" name="unit_id" value="<?=h($c['id'])?>"><div class="inline"><select name="assignment_status"><option value="asignado_completo">Asignación completa</option><option value="asignado_parcial">Asignación parcial</option></select><input name="pending_level" placeholder="Nivel pendiente"></div><div class="inline"><input name="reviewed_by" value="usuario_local" placeholder="Revisado por"><input name="notes" value="Asignación manual contra estructura vigente" size="35"><button>Asignar</button></div></form></td></tr><?php endforeach;?></tbody></table></section>
<section><h2>Confirmar que no existe coincidencia</h2><form method="post" class="inline"><input type="hidden" name="csrf" value="<?=h($csrf)?>"><input type="hidden" name="id" value="<?=h($personId)?>"><input type="hidden" name="action" value="no_match"><input name="reviewed_by" value="usuario_local"><input name="notes" value="Ubicación no encontrada en la estructura vigente" size="55"><button class="danger">Marcar sin coincidencia</button></form></section>
</main></body></html>
