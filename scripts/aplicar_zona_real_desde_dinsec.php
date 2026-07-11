<?php
// Aplica estructura real y vincula personal DINSEC ya cargado para una zona.
// Uso: php scripts/aplicar_zona_real_desde_dinsec.php --zona=2

$options = getopt('', ['zona:']);
$zonaNumero = (int)($options['zona'] ?? 0);
if ($zonaNumero <= 0) { fwrite(STDERR, "Uso: php scripts/aplicar_zona_real_desde_dinsec.php --zona=2\n"); exit(1); }

$configPath = __DIR__ . '/../dashboard/config.php';
if (!file_exists($configPath)) { fwrite(STDERR, "Falta dashboard/config.php\n"); exit(1); }
$config = require $configPath;
$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $config['db_host'], $config['db_port'], $config['db_name'], $config['charset']);
$pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');

function q(PDO $pdo, string $sql, array $p=[]): array { $s=$pdo->prepare($sql); $s->execute($p); return $s->fetchAll(); }
function one(PDO $pdo, string $sql, array $p=[]): ?array { $r=q($pdo,$sql,$p); return $r[0] ?? null; }
function execp(PDO $pdo, string $sql, array $p=[]): void { $s=$pdo->prepare($sql); $s->execute($p); }
function upper_text($v): string { return function_exists('mb_strtoupper') ? mb_strtoupper((string)$v, 'UTF-8') : strtoupper((string)$v); }
function slug_unit($v): string {
    $v = upper_text($v);
    $v = strtr($v, ['Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N','ª'=>'A','ᵃ'=>'A']);
    $v = preg_replace('/[^A-Z0-9]+/', '-', $v);
    return trim($v, '-');
}

$zona = one($pdo, "SELECT z.zone_number,z.zone_label,cab.id AS cabecera_unit_id FROM moi_zonas_cabecera_vigentes z LEFT JOIN organizational_units cab ON BINARY cab.legacy_table=BINARY 'MOI_CABECERA_ZONA' AND CAST(cab.legacy_id AS UNSIGNED)=z.zone_number WHERE z.zone_number=:zn LIMIT 1", ['zn'=>$zonaNumero]);
if (!$zona || !$zona['cabecera_unit_id']) { fwrite(STDERR, "La zona $zonaNumero no tiene cabecera canonica.\n"); exit(1); }
$zp = 'Z'.str_pad((string)$zonaNumero, 2, '0', STR_PAD_LEFT);

execp($pdo, "INSERT IGNORE INTO unit_types (name, description, created_at, updated_at) VALUES ('area_policial','Area policial',NOW(),NOW()),('sector_policial','Sector o ubicacion policial dentro de un area',NOW(),NOW()),('servicio_zonal','Servicio o funcion dentro de zona policial',NOW(),NOW())");
$pdo->exec("CREATE TABLE IF NOT EXISTS dinsec_personnel_unit_links (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, dinsec_personnel_reference_id BIGINT UNSIGNED NOT NULL, zone_unit_id BIGINT UNSIGNED NULL, area_unit_id BIGINT UNSIGNED NULL, sector_catalog_id BIGINT UNSIGNED NULL, assignment_unit_id BIGINT UNSIGNED NULL, assignment_scope ENUM('zona','area','sector','servicio','administrativo','pendiente') NOT NULL DEFAULT 'pendiente', position_number VARCHAR(50) NULL, full_name VARCHAR(180) NOT NULL, rank_text VARCHAR(80) NULL, assignment_text VARCHAR(220) NULL, location_sector VARCHAR(180) NULL, source_name VARCHAR(180) NOT NULL DEFAULT 'DINSEC 04AGO2025', status ENUM('active','inactive','review') NOT NULL DEFAULT 'active', notes VARCHAR(255) NULL, created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uq_dinsec_link (dinsec_personnel_reference_id), INDEX idx_link_zone (zone_unit_id), INDEX idx_link_area (area_unit_id), INDEX idx_link_sector (sector_catalog_id), INDEX idx_link_assignment (assignment_unit_id), INDEX idx_link_position (position_number), INDEX idx_link_scope (assignment_scope)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("CREATE TABLE IF NOT EXISTS moi_zone_apply_audit (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, zone_number INT NOT NULL, zone_label VARCHAR(180) NOT NULL, action_name VARCHAR(120) NOT NULL, affected_rows INT NOT NULL DEFAULT 0, notes VARCHAR(255) NULL, created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_zone_apply (zone_number, action_name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$areas = q($pdo, "SELECT DISTINCT area_code FROM moi_area_sector_catalog WHERE active=1 AND zone_number=:zn AND area_code IS NOT NULL ORDER BY area_code", ['zn'=>$zonaNumero]);
foreach ($areas as $a) {
    $code = $a['area_code'];
    $legacyId = "$zp-AREA-$code";
    $exists = one($pdo, "SELECT id FROM organizational_units WHERE BINARY legacy_table=BINARY 'MOI_CABECERA_AREA' AND BINARY legacy_id=BINARY :legacy", ['legacy'=>$legacyId]);
    if (!$exists) {
        $ut = one($pdo, "SELECT id FROM unit_types WHERE name='area_policial' LIMIT 1");
        execp($pdo, "INSERT INTO organizational_units (parent_id, unit_type_id, code, name, short_name, level, is_operational, is_administrative, status, legacy_table, legacy_id, created_at, updated_at) VALUES (:parent,:ut,:code,:name,:short,4,1,1,'active','MOI_CABECERA_AREA',:legacy,NOW(),NOW())", ['parent'=>$zona['cabecera_unit_id'], 'ut'=>$ut['id'], 'code'=>$legacyId, 'name'=>'Area '.$code, 'short'=>'Area '.$code, 'legacy'=>$legacyId]);
    }
}
execp($pdo, "UPDATE organizational_units SET moi_code=COALESCE(moi_code, code), moi_level=4, command_structure='mando_directo', command_relationship='tactico', territorial_scope='area', is_decision_center=1, is_operational_executor=1, valid_from=COALESCE(valid_from, CURRENT_DATE), lifecycle_status=COALESCE(lifecycle_status,'vigente'), structure_source=COALESCE(structure_source,'dinsec_04ago2025'), legacy_frozen=1, lifecycle_notes=CONCAT('Cabecera real dentro de ',:zl) WHERE BINARY legacy_table=BINARY 'MOI_CABECERA_AREA' AND legacy_id LIKE :pref", ['zl'=>$zona['zone_label'], 'pref'=>$zp.'-AREA-%']);

$sectores = q($pdo, "SELECT * FROM moi_area_sector_catalog WHERE active=1 AND zone_number=:zn AND area_code IS NOT NULL", ['zn'=>$zonaNumero]);
foreach ($sectores as $s) {
    $area = one($pdo, "SELECT id FROM organizational_units WHERE BINARY legacy_table=BINARY 'MOI_CABECERA_AREA' AND BINARY legacy_id=BINARY :legacy", ['legacy'=>"$zp-AREA-{$s['area_code']}"]);
    if (!$area) { continue; }
    $legacyId = $zp.'-'.$s['area_code'].'-'.slug_unit($s['sector_name']);
    $exists = one($pdo, "SELECT id FROM organizational_units WHERE BINARY legacy_table=BINARY 'DINSEC_SECTOR' AND BINARY legacy_id=BINARY :legacy", ['legacy'=>$legacyId]);
    if (!$exists) {
        $ut = one($pdo, "SELECT id FROM unit_types WHERE name='sector_policial' LIMIT 1");
        execp($pdo, "INSERT INTO organizational_units (parent_id, unit_type_id, code, name, short_name, level, is_operational, is_administrative, status, legacy_table, legacy_id, created_at, updated_at) VALUES (:parent,:ut,:code,:name,:short,5,1,1,'active','DINSEC_SECTOR',:legacy,NOW(),NOW())", ['parent'=>$area['id'], 'ut'=>$ut['id'], 'code'=>$legacyId, 'name'=>$s['sector_name'], 'short'=>$s['sector_name'], 'legacy'=>$legacyId]);
    }
}
execp($pdo, "UPDATE organizational_units SET moi_code=COALESCE(moi_code, code), moi_level=5, command_structure='mando_directo', command_relationship='tactico', territorial_scope='sector', is_decision_center=0, is_operational_executor=1, valid_from=COALESCE(valid_from, CURRENT_DATE), lifecycle_status=COALESCE(lifecycle_status,'vigente'), structure_source=COALESCE(structure_source,'dinsec_04ago2025'), legacy_frozen=1, lifecycle_notes='Sector real DINSEC dentro de Area/Zona' WHERE BINARY legacy_table=BINARY 'DINSEC_SECTOR' AND legacy_id LIKE :pref", ['pref'=>$zp.'-%']);

$areaUnits = q($pdo, "SELECT id FROM organizational_units WHERE BINARY legacy_table=BINARY 'MOI_CABECERA_AREA' AND legacy_id LIKE :pref", ['pref'=>$zp.'-AREA-%']);
foreach ($areaUnits as $area) {
    execp($pdo, "INSERT INTO organizational_unit_relationships (source_unit_id,target_unit_id,relationship_type,valid_from,status,notes,created_at,updated_at) SELECT :area,:zona,'jerarquica',CURRENT_DATE,'active','Area cabecera pertenece a zona',NOW(),NOW() WHERE NOT EXISTS (SELECT 1 FROM organizational_unit_relationships WHERE source_unit_id=:area2 AND target_unit_id=:zona2 AND relationship_type='jerarquica' AND status='active')", ['area'=>$area['id'], 'zona'=>$zona['cabecera_unit_id'], 'area2'=>$area['id'], 'zona2'=>$zona['cabecera_unit_id']]);
}
$sectorUnits = q($pdo, "SELECT id,parent_id FROM organizational_units WHERE BINARY legacy_table=BINARY 'DINSEC_SECTOR' AND legacy_id LIKE :pref", ['pref'=>$zp.'-%']);
foreach ($sectorUnits as $sec) {
    execp($pdo, "INSERT INTO organizational_unit_relationships (source_unit_id,target_unit_id,relationship_type,valid_from,status,notes,created_at,updated_at) SELECT :s,:a,'jerarquica',CURRENT_DATE,'active','Sector DINSEC pertenece al area real',NOW(),NOW() WHERE NOT EXISTS (SELECT 1 FROM organizational_unit_relationships WHERE source_unit_id=:s2 AND target_unit_id=:a2 AND relationship_type='jerarquica' AND status='active')", ['s'=>$sec['id'], 'a'=>$sec['parent_id'], 's2'=>$sec['id'], 'a2'=>$sec['parent_id']]);
}

$personas = q($pdo, "SELECT * FROM dinsec_personnel_reference WHERE zone_unit_id=:cab OR zone_label LIKE :zl", ['cab'=>$zona['cabecera_unit_id'], 'zl'=>'%'.$zona['zone_label'].'%']);
$vinc = 0; $sin = 0;
foreach ($personas as $d) {
    $area = null; $sectorCatalog = null; $sectorUnit = null;
    if ($d['area_code']) {
        $area = one($pdo, "SELECT id FROM organizational_units WHERE BINARY legacy_table=BINARY 'MOI_CABECERA_AREA' AND BINARY legacy_id=BINARY :legacy", ['legacy'=>"$zp-AREA-{$d['area_code']}"]);
        if ($d['location_sector']) {
            $sectorCatalog = one($pdo, "SELECT id FROM moi_area_sector_catalog WHERE active=1 AND zone_number=:zn AND area_code=:ac AND UPPER(sector_name COLLATE utf8mb4_unicode_ci)=UPPER(:sector COLLATE utf8mb4_unicode_ci) LIMIT 1", ['zn'=>$zonaNumero, 'ac'=>$d['area_code'], 'sector'=>$d['location_sector']]);
            $legacySec = $zp.'-'.$d['area_code'].'-'.slug_unit($d['location_sector']);
            $sectorUnit = one($pdo, "SELECT id FROM organizational_units WHERE BINARY legacy_table=BINARY 'DINSEC_SECTOR' AND BINARY legacy_id=BINARY :legacy", ['legacy'=>$legacySec]);
        }
    }
    $assignmentUnitId = $sectorUnit['id'] ?? $area['id'] ?? $zona['cabecera_unit_id'];
    $scope = $sectorUnit ? 'sector' : ($area ? 'area' : ($d['service_label'] ? 'servicio' : 'zona'));
    execp($pdo, "INSERT INTO dinsec_personnel_unit_links (dinsec_personnel_reference_id, zone_unit_id, area_unit_id, sector_catalog_id, assignment_unit_id, assignment_scope, position_number, full_name, rank_text, assignment_text, location_sector, status, notes) VALUES (:did,:zu,:area,:cat,:assign,:scope,:pos,:name,:rank,:atext,:sector,'active',:notes) ON DUPLICATE KEY UPDATE zone_unit_id=VALUES(zone_unit_id), area_unit_id=VALUES(area_unit_id), sector_catalog_id=VALUES(sector_catalog_id), assignment_unit_id=VALUES(assignment_unit_id), assignment_scope=VALUES(assignment_scope), position_number=VALUES(position_number), full_name=VALUES(full_name), rank_text=VALUES(rank_text), assignment_text=VALUES(assignment_text), location_sector=VALUES(location_sector), status='active', notes=VALUES(notes), updated_at=NOW()", ['did'=>$d['id'], 'zu'=>$zona['cabecera_unit_id'], 'area'=>$area['id'] ?? null, 'cat'=>$sectorCatalog['id'] ?? null, 'assign'=>$assignmentUnitId, 'scope'=>$scope, 'pos'=>$d['position_number'], 'name'=>$d['full_name'], 'rank'=>$d['rank_text'], 'atext'=>$d['assignment_text'], 'sector'=>$d['location_sector'], 'notes'=>'Vinculo aplicado desde DINSEC a estructura real de trabajo']);
    execp($pdo, "UPDATE dinsec_personnel_reference SET review_status='validado', review_notes='Validado automaticamente contra estructura real DINSEC', updated_at=NOW() WHERE id=:id", ['id'=>$d['id']]);
    $vinc++;
}
execp($pdo, "INSERT INTO moi_zone_apply_audit (zone_number, zone_label, action_name, affected_rows, notes) VALUES (:zn,:zl,'aplicar_zona_real_desde_dinsec',:rows,'Estructura y personal DINSEC vinculados')", ['zn'=>$zonaNumero, 'zl'=>$zona['zone_label'], 'rows'=>$vinc]);

echo "Zona aplicada: {$zona['zone_label']}\nPersonal vinculado/validado: $vinc\n";
