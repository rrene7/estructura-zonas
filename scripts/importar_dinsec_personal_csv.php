<?php
// Importador local de personal DINSEC desde CSV privado.
// Uso: php scripts/importar_dinsec_personal_csv.php --zona=2 --archivo=local/private/dinsec_zona_02_cocle.csv
// El CSV NO debe subirse a GitHub. Columnas esperadas:
// fila,rango,posicion,nombre,asignacion,observacion,pagina

$options = getopt('', ['zona:', 'archivo:']);
$zonaNumero = (int)($options['zona'] ?? 0);
$archivo = $options['archivo'] ?? '';
if ($zonaNumero <= 0 || $archivo === '' || !file_exists($archivo)) {
    fwrite(STDERR, "Uso: php scripts/importar_dinsec_personal_csv.php --zona=2 --archivo=local/private/dinsec_zona_02_cocle.csv\n");
    exit(1);
}

$configPath = __DIR__ . '/../dashboard/config.php';
if (!file_exists($configPath)) { fwrite(STDERR, "Falta dashboard/config.php\n"); exit(1); }
$config = require $configPath;
$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $config['db_host'], $config['db_port'], $config['db_name'], $config['charset']);
$pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');

function q(PDO $pdo, string $sql, array $p=[]): array { $s=$pdo->prepare($sql); $s->execute($p); return $s->fetchAll(); }
function one(PDO $pdo, string $sql, array $p=[]): ?array { $r=q($pdo,$sql,$p); return $r[0] ?? null; }
function execp(PDO $pdo, string $sql, array $p=[]): void { $s=$pdo->prepare($sql); $s->execute($p); }
function clean_text($v): string {
    $v = trim((string)$v);
    $v = preg_replace('/\s+/u', ' ', $v);
    return $v ?? '';
}
function upper_text($v): string { return function_exists('mb_strtoupper') ? mb_strtoupper((string)$v, 'UTF-8') : strtoupper((string)$v); }
function normalize_key($v): string {
    $v = upper_text($v);
    $map = ['Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N','ª'=>'A','ᵃ'=>'A'];
    $v = strtr($v, $map);
    $v = preg_replace('/[^A-Z0-9]+/', ' ', $v);
    return trim(preg_replace('/\s+/', ' ', $v));
}
function detectar_area_sector(PDO $pdo, int $zonaNumero, string $asignacion): array {
    $txt = clean_text($asignacion);
    $up = normalize_key($txt);
    $areaCode = null; $sector = null; $service = null;

    if (preg_match('/AREA\s*["\x{201c}\x{201d}]?\s*([A-P])\s*["\x{201c}\x{201d}]?\s*(.*)$/iu', $txt, $m)) {
        $areaCode = upper_text($m[1]);
        $sector = clean_text($m[2]);
        if ($sector === '') { $sector = null; }
    }

    $catalogo = q($pdo, "SELECT area_code, sector_name, service_label FROM moi_area_sector_catalog WHERE active=1 AND zone_number=:zn ORDER BY area_code IS NULL, LENGTH(sector_name) DESC", ['zn'=>$zonaNumero]);
    foreach ($catalogo as $c) {
        $sectorKey = normalize_key($c['sector_name']);
        if ($sectorKey !== '' && (normalize_key($sector ?? '') === $sectorKey || str_contains($up, $sectorKey))) {
            $areaCode = $c['area_code'] ?: $areaCode;
            $sector = $c['sector_name'];
            $service = $c['service_label'];
            break;
        }
    }

    if ($areaCode === null && $sector === null) {
        $service = $txt !== '' ? $txt : null;
    }
    $areaName = $areaCode ? 'Area '.$areaCode : null;
    return [$areaCode, $areaName, $sector, $service];
}

$zona = one($pdo, "SELECT z.zone_number,z.zone_label,cab.id AS cabecera_unit_id FROM moi_zonas_cabecera_vigentes z LEFT JOIN organizational_units cab ON BINARY cab.legacy_table=BINARY 'MOI_CABECERA_ZONA' AND CAST(cab.legacy_id AS UNSIGNED)=z.zone_number WHERE z.zone_number=:zn LIMIT 1", ['zn'=>$zonaNumero]);
if (!$zona) { fwrite(STDERR, "No existe zona numero $zonaNumero en moi_zonas_cabecera_vigentes\n"); exit(1); }

$pdo->exec("CREATE TABLE IF NOT EXISTS dinsec_document_sources (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, document_name VARCHAR(180) NOT NULL, document_date DATE NULL, uploaded_file_name VARCHAR(220) NULL, notes VARCHAR(255) NULL, created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uq_dinsec_source (document_name, document_date)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("CREATE TABLE IF NOT EXISTS dinsec_personnel_reference (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, source_id BIGINT UNSIGNED NOT NULL, page_number INT NULL, row_number INT NULL, zone_label VARCHAR(180) NULL, zone_unit_id BIGINT UNSIGNED NULL, area_code VARCHAR(10) NULL, area_name VARCHAR(120) NULL, location_sector VARCHAR(180) NULL, direction_label VARCHAR(220) NULL, direction_unit_id BIGINT UNSIGNED NULL, service_label VARCHAR(180) NULL, op_status ENUM('OP','NO OP','OA','NO DEFINIDO') NOT NULL DEFAULT 'NO DEFINIDO', rank_text VARCHAR(80) NULL, position_number VARCHAR(50) NULL, full_name VARCHAR(180) NOT NULL, assignment_text VARCHAR(220) NULL, observation_text VARCHAR(220) NULL, raw_text TEXT NULL, matched_employee_id BIGINT UNSIGNED NULL, review_status ENUM('pendiente','validado','ignorado') NOT NULL DEFAULT 'pendiente', review_notes VARCHAR(255) NULL, created_by VARCHAR(100) NULL DEFAULT 'local_csv', created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, INDEX idx_dinsec_source (source_id), INDEX idx_dinsec_zone (zone_unit_id), INDEX idx_dinsec_area (area_code, area_name), INDEX idx_dinsec_position (position_number)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
execp($pdo, "INSERT IGNORE INTO dinsec_document_sources (document_name, document_date, uploaded_file_name, notes) VALUES ('COMPOSICION OFICIALES DINSEC POR ZONAS Y SERVICIOS POLICIALES','2025-08-04','COMPOSICIÓN OFICIALES 04AGO25 DINSEC.pdf','Carga local desde CSV privado')");
$source = one($pdo, "SELECT id FROM dinsec_document_sources WHERE document_name='COMPOSICION OFICIALES DINSEC POR ZONAS Y SERVICIOS POLICIALES' AND document_date='2025-08-04'");

$fh = fopen($archivo, 'r');
if (!$fh) { fwrite(STDERR, "No se pudo abrir $archivo\n"); exit(1); }
$first = fgets($fh);
if ($first === false) { fwrite(STDERR, "Archivo vacío\n"); exit(1); }
$first = preg_replace('/^\xEF\xBB\xBF/', '', $first);
$delim = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
$headers = str_getcsv($first, $delim);
$map = [];
foreach ($headers as $i=>$h) { $map[normalize_key($h)] = $i; }
$col = function(array $row, array $names) use ($map) {
    foreach ($names as $n) {
        $k = normalize_key($n);
        if (isset($map[$k])) { return $row[$map[$k]] ?? ''; }
    }
    return '';
};

$count = 0; $skipped = 0;
while (($row = fgetcsv($fh, 0, $delim)) !== false) {
    $fila = (int)clean_text($col($row, ['fila','no','n']));
    $pagina = (int)clean_text($col($row, ['pagina','page']));
    $rango = clean_text($col($row, ['rango']));
    $posicion = clean_text($col($row, ['posicion','posición','placa']));
    $nombre = clean_text($col($row, ['nombre','nombre completo']));
    $asignacion = clean_text($col($row, ['asignacion','asignación','asignacion pdf','ubicacion','ubicación']));
    $observacion = clean_text($col($row, ['observacion','observación']));
    if ($nombre === '' || $posicion === '') { $skipped++; continue; }
    [$areaCode, $areaName, $sector, $service] = detectar_area_sector($pdo, $zonaNumero, $asignacion);
    $raw = trim(implode(' ', array_filter([$fila, $rango, $posicion, $nombre, $asignacion, $observacion])));
    execp($pdo, "INSERT INTO dinsec_personnel_reference (source_id,page_number,row_number,zone_label,zone_unit_id,area_code,area_name,location_sector,service_label,op_status,rank_text,position_number,full_name,assignment_text,observation_text,raw_text,review_status,review_notes,created_by) VALUES (:source,:page,:row,:zl,:zu,:ac,:an,:sector,:service,'NO DEFINIDO',:rank,:pos,:name,:assign,:obs,:raw,'pendiente','Carga local CSV privada','local_csv') ON DUPLICATE KEY UPDATE area_code=VALUES(area_code), area_name=VALUES(area_name), location_sector=VALUES(location_sector), service_label=VALUES(service_label), assignment_text=VALUES(assignment_text), observation_text=VALUES(observation_text), raw_text=VALUES(raw_text), review_status='pendiente', updated_at=NOW()", [
        'source'=>$source['id'], 'page'=>$pagina ?: null, 'row'=>$fila ?: null, 'zl'=>$zona['zone_label'], 'zu'=>$zona['cabecera_unit_id'], 'ac'=>$areaCode, 'an'=>$areaName, 'sector'=>$sector, 'service'=>$service, 'rank'=>$rango, 'pos'=>$posicion, 'name'=>$nombre, 'assign'=>$asignacion, 'obs'=>$observacion, 'raw'=>$raw
    ]);
    $count++;
}
fclose($fh);
echo "Importados: $count\nOmitidos: $skipped\nZona: {$zona['zone_label']}\n";
