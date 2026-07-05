<?php
$configPath = __DIR__ . '/config.php';
if (!file_exists($configPath)) { die('Falta dashboard/config.php'); }
$config = require $configPath;
$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $config['db_host'], $config['db_port'], $config['db_name'], $config['charset']);
$pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function q(PDO $pdo, string $sql, array $p = []) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchAll(); }
function x(PDO $pdo, string $sql, array $p = []) { $s = $pdo->prepare($sql); $s->execute($p); }

$msg = '';
$err = '';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';

        if ($action === 'vigencia') {
            $id = (int)($_POST['id'] ?? 0);
            $estado = $_POST['estado'] ?? '';
            $nota = trim($_POST['nota'] ?? 'Decision desde dashboard');
            $fecha = trim($_POST['fecha'] ?? date('Y-m-d'));
            if (!in_array($estado, ['vigente','suprimida','fusionada','renombrada'], true)) { throw new RuntimeException('Estado invalido'); }
            if ($estado === 'vigente') {
                x($pdo, "UPDATE moi_unit_vigencia_review SET proposed_lifecycle_status='vigente', proposed_valid_to=NULL, decision_status='aprobado', review_reason=:nota, reviewed_by='dashboard', reviewed_at=NOW() WHERE id=:id", ['nota'=>$nota, 'id'=>$id]);
            } else {
                x($pdo, "UPDATE moi_unit_vigencia_review SET proposed_lifecycle_status=:estado, proposed_valid_to=:fecha, decision_status='aprobado', review_reason=:nota, reviewed_by='dashboard', reviewed_at=NOW() WHERE id=:id", ['estado'=>$estado, 'fecha'=>$fecha, 'nota'=>$nota, 'id'=>$id]);
            }
            $msg = 'Decision de vigencia guardada.';
        }

        if ($action === 'rechazar_vigencia') {
            $id = (int)($_POST['id'] ?? 0);
            $nota = trim($_POST['nota'] ?? 'Desaprobado desde dashboard');
            x($pdo, "UPDATE moi_unit_vigencia_review SET decision_status='rechazado', review_reason=:nota, reviewed_by='dashboard', reviewed_at=NOW() WHERE id=:id", ['nota'=>$nota, 'id'=>$id]);
            $msg = 'Vigencia desaprobada.';
        }

        if ($action === 'relacion') {
            $id = (int)($_POST['id'] ?? 0);
            $decision = $_POST['decision'] ?? '';
            $nota = trim($_POST['nota'] ?? 'Decision desde dashboard');
            if (!in_array($decision, ['aprobado','rechazado'], true)) { throw new RuntimeException('Decision invalida'); }
            x($pdo, "UPDATE moi_unit_relationship_review SET decision_status=:decision, review_reason=:nota, reviewed_by='dashboard', reviewed_at=NOW() WHERE id=:id", ['decision'=>$decision, 'nota'=>$nota, 'id'=>$id]);
            $msg = 'Decision de relacion guardada.';
        }

        if ($action === 'editar_nombre') {
            $id = (int)($_POST['id'] ?? 0);
            $nombre = trim($_POST['nombre'] ?? '');
            if ($id <= 0 || $nombre === '') { throw new RuntimeException('Nombre invalido'); }
            $pdo->beginTransaction();
            x($pdo, "INSERT INTO organizational_unit_lifecycle_events (organizational_unit_id, event_type, effective_from, source_document, notes, created_by) VALUES (:id, 'renombre', CURRENT_DATE, 'dashboard', 'Nombre vigente editado; legacy intacto', 'dashboard')", ['id'=>$id]);
            x($pdo, "UPDATE organizational_units SET name=:nombre, short_name=LEFT(:nombre2,100), lifecycle_notes='Nombre vigente editado desde dashboard; legacy intacto', updated_at=NOW() WHERE id=:id", ['nombre'=>$nombre, 'nombre2'=>$nombre, 'id'=>$id]);
            $pdo->commit();
            $msg = 'Nombre actualizado sin modificar legacy.';
        }

        if ($action === 'aplicar_vigencia') {
            $pdo->exec("UPDATE organizational_units ou JOIN moi_unit_vigencia_review r ON r.organizational_unit_id=ou.id SET ou.lifecycle_status=r.proposed_lifecycle_status, ou.valid_to=r.proposed_valid_to, ou.lifecycle_notes=r.review_reason, ou.status=CASE WHEN r.proposed_lifecycle_status='vigente' THEN 'active' ELSE 'inactive' END, ou.updated_at=NOW() WHERE r.decision_status='aprobado'");
            $msg = 'Decisiones de vigencia aplicadas al modelo vigente.';
        }

        if ($action === 'aplicar_relaciones') {
            $pdo->exec("INSERT INTO organizational_unit_relationships (source_unit_id,target_unit_id,relationship_type,valid_from,status,notes,created_at,updated_at) SELECT r.child_unit_id,r.parent_unit_id,r.relationship_type,CURRENT_DATE,'active',r.review_reason,NOW(),NOW() FROM moi_unit_relationship_review r LEFT JOIN organizational_unit_relationships e ON e.source_unit_id=r.child_unit_id AND e.target_unit_id=r.parent_unit_id AND e.relationship_type=r.relationship_type AND e.status='active' WHERE r.decision_status='aprobado' AND r.parent_unit_id IS NOT NULL AND e.id IS NULL");
            $pdo->exec("UPDATE organizational_units child JOIN moi_unit_relationship_review r ON r.child_unit_id=child.id SET child.parent_id=r.parent_unit_id, child.updated_at=NOW() WHERE r.decision_status='aprobado' AND r.parent_unit_id IS NOT NULL AND r.relationship_type='jerarquica'");
            $msg = 'Relaciones aprobadas aplicadas.';
        }
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    $err = $e->getMessage();
}

$vigencias = q($pdo, "SELECT * FROM vw_moi_revision_vigencia WHERE decision_status='pendiente' ORDER BY proposed_lifecycle_status, unit_type, name LIMIT 60");
$relaciones = q($pdo, "SELECT * FROM vw_moi_revision_relaciones WHERE decision_status='pendiente' ORDER BY confidence_level DESC, source_rule, child_name LIMIT 60");
$unidades = q($pdo, "SELECT ou.id, ou.code, ou.name, ut.name AS unit_type, ou.territorial_scope, ou.legacy_table, ou.legacy_id FROM organizational_units ou LEFT JOIN unit_types ut ON ut.id=ou.unit_type_id WHERE ou.lifecycle_status='vigente' ORDER BY ou.name LIMIT 80");
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Revision MOI</title>
<style>body{font-family:Arial,sans-serif;margin:0;background:#f4f6f8;color:#1f2937}header{background:#111827;color:white;padding:18px 28px}main{padding:24px}section{background:white;border-radius:10px;padding:16px;margin-bottom:20px;box-shadow:0 1px 4px #0002}table{width:100%;border-collapse:collapse;font-size:13px}th,td{border-bottom:1px solid #e5e7eb;text-align:left;padding:8px;vertical-align:top}th{background:#f9fafb}.btn{border:0;border-radius:6px;padding:7px 10px;cursor:pointer;color:white;margin:2px}.ok{background:#047857}.bad{background:#b91c1c}.warn{background:#b45309}.neutral{background:#374151}.msg{background:#ecfdf5;border:1px solid #10b981;padding:10px;border-radius:8px}.err{background:#fef2f2;border:1px solid #ef4444;padding:10px;border-radius:8px}.small{font-size:12px;color:#6b7280}input{padding:7px;border:1px solid #d1d5db;border-radius:6px}form{display:inline}</style></head><body>
<header><h1>Revision MOI</h1><p><a style="color:#d1d5db" href="index.php">Volver al dashboard</a> | Aprobar, desaprobar y editar nombres sin cambiar legacy.</p></header><main>
<?php if ($msg): ?><p class="msg"><?= h($msg) ?></p><?php endif; ?><?php if ($err): ?><p class="err"><?= h($err) ?></p><?php endif; ?>
<section><h2>Vigencia: aprobar / desaprobar</h2><table><thead><tr><th>ID</th><th>Unidad</th><th>Tipo</th><th>Propuesta</th><th>Acciones</th></tr></thead><tbody>
<?php foreach($vigencias as $r): ?><tr><td><?= h($r['id']) ?></td><td><b><?= h($r['name']) ?></b><br><span class="small"><?= h($r['code']) ?> | <?= h($r['territorial_scope']) ?></span></td><td><?= h($r['unit_type']) ?></td><td><?= h($r['proposed_lifecycle_status']) ?><br><span class="small"><?= h($r['review_reason']) ?></span></td><td>
<form method="post"><input type="hidden" name="action" value="vigencia"><input type="hidden" name="id" value="<?= h($r['id']) ?>"><input type="hidden" name="estado" value="vigente"><button class="btn ok">Aprobar vigente</button></form>
<form method="post"><input type="hidden" name="action" value="vigencia"><input type="hidden" name="id" value="<?= h($r['id']) ?>"><input type="hidden" name="estado" value="suprimida"><input type="date" name="fecha" value="<?= date('Y-m-d') ?>"><input type="text" name="nota" placeholder="Motivo"><button class="btn warn">Suprimir</button></form>
<form method="post"><input type="hidden" name="action" value="rechazar_vigencia"><input type="hidden" name="id" value="<?= h($r['id']) ?>"><input type="text" name="nota" placeholder="Motivo"><button class="btn bad">Desaprobar</button></form>
</td></tr><?php endforeach; ?></tbody></table><form method="post"><input type="hidden" name="action" value="aplicar_vigencia"><button class="btn neutral">Aplicar decisiones de vigencia</button></form></section>
<section><h2>Relaciones: aprobar / desaprobar</h2><table><thead><tr><th>ID</th><th>Unidad</th><th>Superior sugerido</th><th>Regla</th><th>Acciones</th></tr></thead><tbody>
<?php foreach($relaciones as $r): ?><tr><td><?= h($r['id']) ?></td><td><b><?= h($r['child_name']) ?></b><br><span class="small"><?= h($r['child_type']) ?> | <?= h($r['child_scope']) ?></span></td><td><?= h($r['parent_name'] ?: 'Sin candidato') ?><br><span class="small"><?= h($r['parent_type']) ?></span></td><td><?= h($r['source_rule']) ?><br><span class="small"><?= h($r['confidence_level']) ?></span></td><td>
<?php if ($r['parent_unit_id']): ?><form method="post"><input type="hidden" name="action" value="relacion"><input type="hidden" name="id" value="<?= h($r['id']) ?>"><input type="hidden" name="decision" value="aprobado"><button class="btn ok">Aprobar</button></form><?php endif; ?>
<form method="post"><input type="hidden" name="action" value="relacion"><input type="hidden" name="id" value="<?= h($r['id']) ?>"><input type="hidden" name="decision" value="rechazado"><input type="text" name="nota" placeholder="Motivo"><button class="btn bad">Desaprobar</button></form>
</td></tr><?php endforeach; ?></tbody></table><form method="post"><input type="hidden" name="action" value="aplicar_relaciones"><button class="btn neutral">Aplicar relaciones aprobadas</button></form></section>
<section><h2>Editar nombres vigentes</h2><p class="small">Cambia el nombre usado por la nueva estructura. El legacy_table y legacy_id no se modifican.</p><table><thead><tr><th>ID</th><th>Unidad actual</th><th>Origen legacy</th><th>Editar</th></tr></thead><tbody>
<?php foreach($unidades as $r): ?><tr><td><?= h($r['id']) ?></td><td><b><?= h($r['name']) ?></b><br><span class="small"><?= h($r['unit_type']) ?> | <?= h($r['territorial_scope']) ?></span></td><td><?= h($r['legacy_table']) ?>: <?= h($r['legacy_id']) ?></td><td><form method="post"><input type="hidden" name="action" value="editar_nombre"><input type="hidden" name="id" value="<?= h($r['id']) ?>"><input type="text" name="nombre" value="<?= h($r['name']) ?>" size="45"><button class="btn neutral">Guardar nombre</button></form></td></tr><?php endforeach; ?></tbody></table></section>
</main></body></html>
