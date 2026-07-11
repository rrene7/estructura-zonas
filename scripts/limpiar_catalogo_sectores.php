<?php
$configPath = __DIR__ . '/../dashboard/config.php';
if (!file_exists($configPath)) { fwrite(STDERR, "Falta dashboard/config.php\n"); exit(1); }
$config = require $configPath;
$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $config['db_host'], $config['db_port'], $config['db_name'], $config['charset']);
$pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');

$exists = $pdo->query("SELECT COUNT(*) total FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='moi_area_sector_catalog'")->fetch();
if ((int)($exists['total'] ?? 0) === 0) { echo "No existe moi_area_sector_catalog.\n"; exit(0); }

$pdo->exec("UPDATE moi_area_sector_catalog SET service_label='' WHERE service_label IS NULL");
$pdo->exec("DELETE c1 FROM moi_area_sector_catalog c1 JOIN moi_area_sector_catalog c2 ON c1.id > c2.id AND c1.zone_number=c2.zone_number AND COALESCE(c1.area_code,'')=COALESCE(c2.area_code,'') AND UPPER(c1.sector_name COLLATE utf8mb4_unicode_ci)=UPPER(c2.sector_name COLLATE utf8mb4_unicode_ci) AND COALESCE(c1.service_label,'')=COALESCE(c2.service_label,'')");
try { $pdo->exec("ALTER TABLE moi_area_sector_catalog DROP INDEX uq_sector"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE moi_area_sector_catalog MODIFY service_label VARCHAR(180) NOT NULL DEFAULT ''"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE moi_area_sector_catalog ADD UNIQUE KEY uq_sector (zone_number, area_code, sector_name, service_label)"); } catch (Throwable $e) {}

$rows = $pdo->query("SELECT zone_number, zone_label, COALESCE(area_code,'SERV') area, COUNT(*) total FROM moi_area_sector_catalog GROUP BY zone_number, zone_label, COALESCE(area_code,'SERV') ORDER BY zone_number, area")->fetchAll();
foreach ($rows as $r) {
    echo $r['zone_number'].' | '.$r['zone_label'].' | '.$r['area'].' | '.$r['total'].PHP_EOL;
}
