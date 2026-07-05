<?php
// Importador local de CSV exportados desde Access hacia tablas stg_*.
// Ejecutar desde Git Bash con: /c/xampp/php/php.exe scripts/importar_csv_access.php

$configPath = __DIR__ . '/../dashboard/config.php';
if (!file_exists($configPath)) {
    $configPath = __DIR__ . '/../dashboard/config.example.php';
}
$config = require $configPath;

$baseDir = __DIR__ . '/../data/access_csv';
if (!is_dir($baseDir)) {
    mkdir($baseDir, 0777, true);
    echo "Carpeta creada: data/access_csv\n";
    echo "Coloque alli los CSV exportados desde Access y vuelva a ejecutar.\n";
    exit(0);
}

$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=%s',
    $config['db_host'],
    $config['db_port'],
    $config['db_name'],
    $config['charset']
);

$pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$map = [
    'TABLUGAR.csv' => 'stg_tablugar',
    'TABDIR.csv' => 'stg_tabdir',
    'TABCUAR.csv' => 'stg_tabcuar',
    'BDFUERZA.csv' => 'stg_bdfuerza',
    'DIR.csv' => 'stg_dir',
    'POLPLANI.csv' => 'stg_polplani',
    'DOTA.csv' => 'stg_dota',
    'VACANTES.csv' => 'stg_vacantes',
    'CARGOS.csv' => 'stg_cargos',
    'TABRAN.csv' => 'stg_tabran',
    'TABSTATUS.csv' => 'stg_tabstatus',
];

function normalize_name(string $name): string {
    $name = trim($name);
    $name = preg_replace('/^\xEF\xBB\xBF/', '', $name);
    $name = strtolower($name);
    $name = str_replace([' ', '-', '.'], '_', $name);
    $name = preg_replace('/[^a-z0-9_]/', '', $name);
    return $name;
}

function table_columns(PDO $pdo, string $table): array {
    $stmt = $pdo->query("SHOW COLUMNS FROM `$table`");
    $cols = [];
    foreach ($stmt as $row) {
        if ($row['Field'] === 'source_loaded_at') {
            continue;
        }
        $cols[] = $row['Field'];
    }
    return $cols;
}

function detect_delimiter(string $line): string {
    $comma = substr_count($line, ',');
    $semi = substr_count($line, ';');
    return $semi > $comma ? ';' : ',';
}

foreach ($map as $file => $table) {
    $path = $baseDir . '/' . $file;
    if (!file_exists($path)) {
        echo "Saltando $file: no existe.\n";
        continue;
    }

    echo "Importando $file -> $table ...\n";
    $handle = fopen($path, 'r');
    if (!$handle) {
        echo "No se pudo abrir $file\n";
        continue;
    }

    $firstLine = fgets($handle);
    if ($firstLine === false) {
        fclose($handle);
        echo "Archivo vacio: $file\n";
        continue;
    }
    $delimiter = detect_delimiter($firstLine);
    rewind($handle);

    $headers = fgetcsv($handle, 0, $delimiter);
    if (!$headers) {
        fclose($handle);
        echo "No se pudo leer encabezado: $file\n";
        continue;
    }

    $headers = array_map('normalize_name', $headers);
    $dbCols = table_columns($pdo, $table);
    $dbColsLookup = array_flip($dbCols);

    $insertCols = [];
    $csvIndexes = [];
    foreach ($headers as $idx => $header) {
        if (isset($dbColsLookup[$header])) {
            $insertCols[] = $header;
            $csvIndexes[] = $idx;
        }
    }

    if (!$insertCols) {
        fclose($handle);
        echo "No hay columnas coincidentes para $file. Revise encabezados.\n";
        continue;
    }

    $pdo->exec("TRUNCATE TABLE `$table`");
    $placeholders = implode(',', array_fill(0, count($insertCols), '?'));
    $colsSql = '`' . implode('`,`', $insertCols) . '`';
    $stmt = $pdo->prepare("INSERT INTO `$table` ($colsSql) VALUES ($placeholders)");

    $count = 0;
    $pdo->beginTransaction();
    while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
        if (count($row) === 1 && trim((string)$row[0]) === '') {
            continue;
        }
        $values = [];
        foreach ($csvIndexes as $idx) {
            $value = $row[$idx] ?? null;
            if ($value === '') {
                $value = null;
            }
            $values[] = $value;
        }
        $stmt->execute($values);
        $count++;
        if ($count % 1000 === 0) {
            $pdo->commit();
            $pdo->beginTransaction();
            echo ".";
        }
    }
    $pdo->commit();
    fclose($handle);
    echo "\n$table: $count registros importados.\n";
}

echo "Importacion finalizada.\n";
