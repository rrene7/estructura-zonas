<?php
declare(strict_types=1);

$configPath = __DIR__ . '/config.php';
if (!is_file($configPath)) {
    http_response_code(500);
    die('Falta dashboard/config.php');
}
$config = require $configPath;
$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $config['db_host'], $config['db_port'], $config['db_name'], $config['charset']);
$pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');

function h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function rows(PDO $pdo, string $sql, array $params = []): array { $s=$pdo->prepare($sql); $s->execute($params); return $s->fetchAll(); }
function one(PDO $pdo, string $sql, array $params = []): array { $r=rows($pdo,$sql,$params); return $r[0] ?? []; }
function table_exists(PDO $pdo, string $table): bool { $s=$pdo->prepare('SELECT COUNT(*) total FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=:table'); $s->execute(['table'=>$table]); return (int)($s->fetch()['total'] ?? 0)>0; }

$hasModule = table_exists($pdo,'workforce_sources') && table_exists($pdo,'workforce_personnel_staging') && table_exists($pdo,'workforce_unit_matches');
$sources = $hasModule ? rows($pdo,'SELECT * FROM workforce_sources ORDER BY document_date DESC,id DESC') : [];
$sourceId = (int)($_GET['source_id'] ?? ($sources[0]['id'] ?? 0));
$source = $sourceId>0 ? one($pdo,'SELECT * FROM workforce_sources WHERE id=:id',['id'=>$sourceId]) : [];
$status = trim((string)($_GET['status'] ?? ''));
$level = trim((string)($_GET['level'] ?? ''));
$review = trim((string)($_GET['review'] ?? ''));
$search = trim((string)($_GET['buscar'] ?? ''));
$summary = $sourceId>0 && $hasModule ? one($pdo,'SELECT * FROM vw_workforce_summary WHERE source_id=:source_id',['source_id'=>$sourceId]) : [];

$where=['d.source_id=:source_id']; $params=['source_id'=>$sourceId];
if($status!==''){ $where[]='d.assignment_status=:status'; $params['status']=$status; }
if($level!==''){ $where[]='d.matched_level=:level'; $params['level']=$level; }
if($review!==''){ $where[]='d.review_status=:review'; $params['review']=$review; }
if($search!==''){ $where[]='(d.full_name LIKE :buscar OR d.position_number LIKE :buscar OR d.location_original LIKE :buscar OR d.matched_unit_name LIKE :buscar OR d.rank_text LIKE :buscar)'; $params['buscar']='%'.$search.'%'; }

$detail=[]; $byUnit=[];
if($sourceId>0 && $hasModule){
    $detail=rows($pdo,'SELECT d.* FROM vw_workforce_match_detail d WHERE '.implode(' AND ',$where).' ORDER BY d.row_number LIMIT 1000',$params);
    $byUnit=rows($pdo,"SELECT COALESCE(d.matched_unit_name,'SIN UNIDAD') unidad,d.matched_level,d.assignment_status,COUNT(*) total FROM vw_workforce_match_detail d WHERE d.source_id=:source_id GROUP BY unidad,d.matched_level,d.assignment_status ORDER BY total DESC,unidad LIMIT 100",['source_id'=>$sourceId]);
}

if(($_GET['descargar'] ?? '')==='csv' && $hasModule && $sourceId>0){
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=pie_fuerza_match_'.$sourceId.'.csv');
    $out=fopen('php://output','wb'); fwrite($out,"\xEF\xBB\xBF");
    fputcsv($out,['Fila','Rango','Posicion','Nombre','Apellido','Ubicacion original','Tipo Policia','Unidad asignada','Nivel confirmado','Estado','Nivel pendiente','Metodo','Confianza','Revision']);
    foreach($detail as $r){ fputcsv($out,[$r['row_number'],$r['rank_text'],$r['position_number'],$r['first_name'],$r['last_name'],$r['location_original'],$r['police_type_original'],$r['matched_unit_name'],$r['matched_level'],$r['assignment_status'] ?: 'pendiente_revision',$r['pending_level'],$r['match_method'],$r['confidence_level'],$r['review_status']]); }
    fclose($out); exit;
}
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>PIE DE FUERZA</title><style>
body{font-family:Arial,sans-serif;margin:0;background:#f4f6f8;color:#1f2937}header{background:#111827;color:#fff;padding:18px 28px}header h1{margin:0;font-size:22px}.top{margin:7px 0 0}.top a{color:#d1d5db;margin-right:14px;font-weight:bold}main{padding:22px}.card,section{background:#fff;border-radius:10px;padding:15px;box-shadow:0 1px 4px #0002;margin-bottom:16px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(155px,1fr));gap:10px}.kpi{border:1px solid #e5e7eb;border-radius:8px;padding:12px;background:#f9fafb}.kpi .label{font-size:12px;color:#6b7280;text-transform:uppercase}.kpi .value{font-size:25px;font-weight:bold;margin-top:5px}.filters{display:flex;gap:8px;flex-wrap:wrap}.filters input,.filters select,.filters button{padding:8px;border:1px solid #d1d5db;border-radius:6px}.filters button,.btn{background:#047857;color:#fff;text-decoration:none;border:0;padding:9px 11px;border-radius:7px;display:inline-block}.btn.blue{background:#1d4ed8}.btn.orange{background:#b45309}.warn{background:#fffbeb;border:1px solid #f59e0b}.ok{color:#047857;font-weight:bold}.bad{color:#b91c1c;font-weight:bold}.partial{color:#a16207;font-weight:bold}.muted{color:#6b7280;font-size:12px}table{width:100%;border-collapse:collapse;font-size:12.5px}th,td{border-bottom:1px solid #e5e7eb;padding:7px;text-align:left;vertical-align:top}th{background:#f9fafb;position:sticky;top:0}.scroll{overflow:auto;max-height:650px}.pill{display:inline-block;padding:3px 7px;border-radius:999px;background:#eef2ff;font-size:11px}.source-select a{display:inline-block;margin:3px;padding:7px 9px;border-radius:7px;background:#e5e7eb;color:#111827;text-decoration:none}.source-select a.active{background:#1d4ed8;color:#fff}
</style></head><body><header><h1>PIE DE FUERZA — asignación contra estructura vigente</h1><p class="top"><a href="index.php">Dashboard</a><a href="trabajo_zonas.php">Trabajo por zona</a><a href="asignar_unidades_direccion.php">Direcciones</a><a href="pie_fuerza_masiva.php?source_id=<?=h($sourceId)?>">Revisión masiva</a></p></header><main>
<?php if(!$hasModule): ?><section class="warn"><h2>Módulo no instalado</h2><p>Ejecute <code>database/pie_fuerza_20260626.sql</code>. Este módulo no crea ni modifica unidades de la estructura.</p></section><?php else: ?>
<section class="source-select"><h2>Fuente</h2><?php foreach($sources as $item): ?><a class="<?=((int)$item['id']===$sourceId)?'active':''?>" href="?source_id=<?=h($item['id'])?>"><?=h($item['source_key'])?> — <?=h($item['document_date'])?></a><?php endforeach; ?><?php if(!$sources): ?><p>No hay fuentes importadas.</p><?php endif; ?></section>
<?php if($source): ?><section><h2><?=h($source['document_name'])?> — <?=h($source['sheet_name'])?></h2><p class="muted">Archivo privado: <?=h($source['uploaded_file_name'])?> | Estado: <?=h($source['source_status'])?>. La estructura <code>organizational_units</code> se usa solamente como catálogo de destino.</p><div class="grid"><div class="kpi"><div class="label">Total personas</div><div class="value"><?=h($summary['total_personas'] ?? 0)?></div></div><div class="kpi"><div class="label">Asignación completa</div><div class="value ok"><?=h($summary['asignados_completos'] ?? 0)?></div></div><div class="kpi"><div class="label">Asignación parcial</div><div class="value partial"><?=h($summary['asignados_parciales'] ?? 0)?></div></div><div class="kpi"><div class="label">Pendientes</div><div class="value bad"><?=h($summary['pendientes_revision'] ?? 0)?></div></div><div class="kpi"><div class="label">Sin coincidencia</div><div class="value bad"><?=h($summary['sin_coincidencia'] ?? 0)?></div></div></div></section>
<section><h2>Filtros</h2><form class="filters" method="get"><input type="hidden" name="source_id" value="<?=h($sourceId)?>"><input name="buscar" value="<?=h($search)?>" placeholder="Nombre, posición o ubicación"><select name="status"><option value="">Todos los estados</option><?php foreach(['asignado_completo','asignado_parcial','pendiente_revision','sin_coincidencia'] as $o): ?><option value="<?=h($o)?>" <?=$status===$o?'selected':''?>><?=h($o)?></option><?php endforeach; ?></select><select name="level"><option value="">Todos los niveles</option><?php foreach(['zona','direccion','area','dependencia','servicio','unidad','ninguno'] as $o): ?><option value="<?=h($o)?>" <?=$level===$o?'selected':''?>><?=h($o)?></option><?php endforeach; ?></select><select name="review"><option value="">Toda revisión</option><?php foreach(['automatico','pendiente','aprobado','rechazado'] as $o): ?><option value="<?=h($o)?>" <?=$review===$o?'selected':''?>><?=h($o)?></option><?php endforeach; ?></select><button>Filtrar</button><a class="btn blue" href="?<?=h(http_build_query(['source_id'=>$sourceId,'status'=>$status,'level'=>$level,'review'=>$review,'buscar'=>$search,'descargar'=>'csv']))?>">Descargar CSV</a><a class="btn orange" href="pie_fuerza_masiva.php?source_id=<?=h($sourceId)?>">Revisión masiva por ubicación</a></form></section>
<section><h2>Personas y asignación</h2><div class="scroll"><table><thead><tr><th>Fila</th><th>Funcionario</th><th>Rango / Posición</th><th>Ubicación original</th><th>Tipo Policía</th><th>Unidad confirmada</th><th>Resultado</th><th>Revisión</th></tr></thead><tbody><?php foreach($detail as $r): $st=$r['assignment_status'] ?: 'pendiente_revision'; $cl=$st==='asignado_completo'?'ok':($st==='asignado_parcial'?'partial':'bad'); ?><tr><td><?=h($r['row_number'])?></td><td><strong><?=h($r['full_name'])?></strong></td><td><?=h($r['rank_text'])?><br><span class="muted"><?=h($r['position_number'])?></span></td><td><?=h($r['location_original'])?></td><td><?=h($r['police_type_original'])?></td><td><strong><?=h($r['matched_unit_name'] ?: 'Sin unidad')?></strong><br><span class="muted"><?=h($r['matched_unit_type'])?> / <?=h($r['matched_level'] ?: 'ninguno')?></span></td><td><span class="<?=$cl?>"><?=h($st)?></span><br><span class="muted">Pendiente: <?=h($r['pending_level'] ?: 'ninguno')?><br><?=h($r['match_method'])?> / <?=h($r['confidence_level'])?></span></td><td><span class="pill"><?=h($r['review_status'] ?: 'pendiente')?></span><br><a href="pie_fuerza_revision.php?id=<?=h($r['personnel_staging_id'])?>">Revisar / asignar</a></td></tr><?php endforeach; ?></tbody></table></div></section>
<section><h2>Conteo por unidad confirmada</h2><table><thead><tr><th>Unidad</th><th>Nivel</th><th>Estado</th><th>Total</th></tr></thead><tbody><?php foreach($byUnit as $item): ?><tr><td><?=h($item['unidad'])?></td><td><?=h($item['matched_level'])?></td><td><?=h($item['assignment_status'])?></td><td><?=h($item['total'])?></td></tr><?php endforeach; ?></tbody></table></section><?php endif; ?><?php endif; ?></main></body></html>
