<?php
declare(strict_types=1);

// Importa exclusivamente la hoja PIE DE FUERZA 26-6-2026 desde XLSX o CSV.
// No crea ni modifica unidades organizacionales.
//
// Uso:
// php scripts/importar_pie_fuerza.php \
//   --archivo="local/private/PIE_DE_FUERZA_2026-06-26.xlsx" \
//   --hoja="PIE DE FUERZA 26-6-2026" \
//   --fecha="2026-06-26" \
//   --source-key="PIE_FUERZA_20260626"

$options = getopt('', ['archivo:', 'hoja::', 'fecha::', 'source-key::', 'reemplazar::']);
$archivo = (string)($options['archivo'] ?? '');
$hoja = (string)($options['hoja'] ?? 'PIE DE FUERZA 26-6-2026');
$fecha = (string)($options['fecha'] ?? '2026-06-26');
$sourceKey = (string)($options['source-key'] ?? 'PIE_FUERZA_20260626');
$reemplazar = !isset($options['reemplazar']) || (string)$options['reemplazar'] !== '0';

if ($archivo === '' || !is_file($archivo)) {
    fwrite(STDERR, "No se encontro el archivo. Use --archivo=RUTA\n");
    exit(1);
}

$configPath = __DIR__ . '/../dashboard/config.php';
if (!is_file($configPath)) {
    fwrite(STDERR, "Falta dashboard/config.php\n");
    exit(1);
}
$config = require $configPath;
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
$pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');

function table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) total FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=:table');
    $stmt->execute(['table' => $table]);
    return (int)($stmt->fetch()['total'] ?? 0) > 0;
}

if (!table_exists($pdo, 'workforce_sources') || !table_exists($pdo, 'workforce_personnel_staging')) {
    fwrite(STDERR, "Faltan las tablas del modulo. Ejecute primero database/pie_fuerza_20260626.sql\n");
    exit(1);
}

function clean_text(mixed $value): string
{
    $text = trim((string)$value);
    $text = preg_replace('/\s+/u', ' ', $text);
    return $text ?? '';
}

function upper_text(string $value): string
{
    return function_exists('mb_strtoupper') ? mb_strtoupper($value, 'UTF-8') : strtoupper($value);
}

function normalize_key(mixed $value): string
{
    $text = upper_text(clean_text($value));
    $text = strtr($text, [
        'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U',
        'Ü' => 'U', 'Ñ' => 'N', 'ª' => 'A', 'º' => 'O',
    ]);
    $text = preg_replace('/[^A-Z0-9]+/u', ' ', $text);
    return trim((string)preg_replace('/\s+/', ' ', (string)$text));
}

function column_index_from_reference(string $reference): int
{
    if (!preg_match('/^([A-Z]+)/i', $reference, $matches)) {
        return 0;
    }
    $letters = strtoupper($matches[1]);
    $index = 0;
    foreach (str_split($letters) as $letter) {
        $index = ($index * 26) + (ord($letter) - 64);
    }
    return $index - 1;
}

/** @return array<int,string> */
function xlsx_shared_strings(ZipArchive $zip): array
{
    $xml = $zip->getFromName('xl/sharedStrings.xml');
    if ($xml === false) {
        return [];
    }
    $doc = simplexml_load_string($xml);
    if ($doc === false) {
        return [];
    }
    $doc->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $values = [];
    foreach ($doc->xpath('//m:si') ?: [] as $item) {
        $parts = [];
        $item->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        foreach ($item->xpath('.//m:t') ?: [] as $textNode) {
            $parts[] = (string)$textNode;
        }
        $values[] = implode('', $parts);
    }
    return $values;
}

function xlsx_sheet_path(ZipArchive $zip, string $requestedSheet): string
{
    $workbookXml = $zip->getFromName('xl/workbook.xml');
    $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($workbookXml === false || $relsXml === false) {
        throw new RuntimeException('El XLSX no contiene workbook.xml o sus relaciones.');
    }

    $workbook = simplexml_load_string($workbookXml);
    $rels = simplexml_load_string($relsXml);
    if ($workbook === false || $rels === false) {
        throw new RuntimeException('No se pudo leer la estructura interna del XLSX.');
    }

    $workbook->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $relationshipNs = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    $rels->registerXPathNamespace('p', 'http://schemas.openxmlformats.org/package/2006/relationships');

    $targets = [];
    foreach ($rels->xpath('//p:Relationship') ?: [] as $relationship) {
        $targets[(string)$relationship['Id']] = (string)$relationship['Target'];
    }

    $requestedNormalized = normalize_key($requestedSheet);
    $available = [];
    foreach ($workbook->xpath('//m:sheets/m:sheet') ?: [] as $sheet) {
        $name = (string)$sheet['name'];
        $available[] = $name;
        $attributes = $sheet->attributes($relationshipNs);
        $relationshipId = (string)($attributes['id'] ?? '');
        if (normalize_key($name) !== $requestedNormalized) {
            continue;
        }
        $target = $targets[$relationshipId] ?? '';
        if ($target === '') {
            break;
        }
        $target = ltrim(str_replace('\\', '/', $target), '/');
        if (!str_starts_with($target, 'xl/')) {
            $target = 'xl/' . $target;
        }
        return $target;
    }

    throw new RuntimeException(
        'No se encontro la hoja "' . $requestedSheet . '". Hojas disponibles: ' . implode(', ', $available)
    );
}

/** @return Generator<int,array<int,string>> */
function read_xlsx_rows(string $path, string $sheetName): Generator
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('La extension PHP zip no esta habilitada; no se puede leer XLSX.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('No se pudo abrir el archivo XLSX.');
    }

    try {
        $sharedStrings = xlsx_shared_strings($zip);
        $sheetPath = xlsx_sheet_path($zip, $sheetName);
        $sheetXml = $zip->getFromName($sheetPath);
        if ($sheetXml === false) {
            throw new RuntimeException('No se pudo leer la hoja interna: ' . $sheetPath);
        }
        $sheet = simplexml_load_string($sheetXml);
        if ($sheet === false) {
            throw new RuntimeException('La hoja XLSX no contiene XML valido.');
        }
        $sheet->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        foreach ($sheet->xpath('//m:sheetData/m:row') ?: [] as $rowNode) {
            $row = [];
            $rowNode->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            foreach ($rowNode->xpath('./m:c') ?: [] as $cell) {
                $reference = (string)$cell['r'];
                $index = column_index_from_reference($reference);
                $type = (string)$cell['t'];
                $value = '';

                if ($type === 'inlineStr') {
                    $cell->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                    $parts = [];
                    foreach ($cell->xpath('.//m:is//m:t') ?: [] as $node) {
                        $parts[] = (string)$node;
                    }
                    $value = implode('', $parts);
                } else {
                    $raw = isset($cell->v) ? (string)$cell->v : '';
                    if ($type === 's' && $raw !== '') {
                        $value = $sharedStrings[(int)$raw] ?? '';
                    } elseif ($type === 'b') {
                        $value = $raw === '1' ? 'TRUE' : 'FALSE';
                    } else {
                        $value = $raw;
                    }
                }
                $row[$index] = clean_text($value);
            }
            if ($row !== []) {
                $max = max(array_keys($row));
                yield array_replace(array_fill(0, $max + 1, ''), $row);
            }
        }
    } finally {
        $zip->close();
    }
}

/** @return Generator<int,array<int,string>> */
function read_csv_rows(string $path): Generator
{
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('No se pudo abrir el CSV.');
    }
    try {
        $firstLine = fgets($handle);
        if ($firstLine === false) {
            return;
        }
        $firstLine = preg_replace('/^\xEF\xBB\xBF/', '', $firstLine) ?? $firstLine;
        $delimiterCounts = [
            ';' => substr_count($firstLine, ';'),
            ',' => substr_count($firstLine, ','),
            "\t" => substr_count($firstLine, "\t"),
        ];
        arsort($delimiterCounts);
        $delimiter = (string)array_key_first($delimiterCounts);
        yield array_map('clean_text', str_getcsv($firstLine, $delimiter));
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            yield array_map('clean_text', $row);
        }
    } finally {
        fclose($handle);
    }
}

/** @param array<int,string> $row */
function header_score(array $row): int
{
    $wanted = ['RANGO', 'POSICION', 'NOMBRE', 'APELLIDO', 'UBICACION', 'TIPO POLICIA'];
    $keys = array_map('normalize_key', $row);
    $score = 0;
    foreach ($wanted as $header) {
        if (in_array($header, $keys, true)) {
            $score++;
        }
    }
    return $score;
}

/** @param array<int,string> $headers @return array<string,int> */
function build_header_map(array $headers): array
{
    $map = [];
    foreach ($headers as $index => $header) {
        $key = normalize_key($header);
        if ($key !== '') {
            $map[$key] = $index;
        }
    }
    return $map;
}

/** @param array<int,string> $row @param array<string,int> $map @param array<int,string> $names */
function row_value(array $row, array $map, array $names): string
{
    foreach ($names as $name) {
        $key = normalize_key($name);
        if (array_key_exists($key, $map)) {
            return clean_text($row[$map[$key]] ?? '');
        }
    }
    return '';
}

$extension = strtolower(pathinfo($archivo, PATHINFO_EXTENSION));
try {
    $rows = match ($extension) {
        'xlsx' => read_xlsx_rows($archivo, $hoja),
        'csv', 'txt' => read_csv_rows($archivo),
        default => throw new RuntimeException('Formato no soportado. Use XLSX o CSV.'),
    };

    $headerMap = null;
    $headerRowNumber = 0;
    $data = [];
    $physicalRow = 0;

    foreach ($rows as $row) {
        $physicalRow++;
        if ($headerMap === null) {
            if ($physicalRow <= 30 && header_score($row) >= 5) {
                $headerMap = build_header_map($row);
                $headerRowNumber = $physicalRow;
            }
            continue;
        }

        $rank = row_value($row, $headerMap, ['Rango']);
        $position = row_value($row, $headerMap, ['Posicion', 'Posición', 'Placa']);
        $firstName = row_value($row, $headerMap, ['Nombre', 'Nombres']);
        $lastName = row_value($row, $headerMap, ['Apellido', 'Apellidos']);
        $location = row_value($row, $headerMap, ['Ubicacion', 'Ubicación', 'Asignacion', 'Asignación']);
        $policeType = row_value($row, $headerMap, ['Tipo Policia', 'Tipo Policía', 'Tipo de Policia', 'Tipo de Policía']);
        $fullName = clean_text($firstName . ' ' . $lastName);

        if ($fullName === '' && $position === '' && $location === '') {
            continue;
        }
        if ($fullName === '') {
            continue;
        }

        $data[] = [
            'row_number' => $physicalRow,
            'rank_text' => $rank,
            'position_number' => $position,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'full_name' => $fullName,
            'location_original' => $location,
            'location_normalized' => normalize_key($location),
            'police_type_original' => $policeType,
            'raw_data_json' => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    if ($headerMap === null) {
        throw new RuntimeException('No se encontraron las columnas Rango, Posicion, Nombre, Apellido, Ubicacion y Tipo Policia.');
    }

    $pdo->beginTransaction();
    $sourceStmt = $pdo->prepare(
        "INSERT INTO workforce_sources
        (source_key,document_name,document_date,sheet_name,uploaded_file_name,total_rows,source_status,notes)
        VALUES (:source_key,'PIE DE FUERZA',:document_date,:sheet_name,:file_name,0,'cargado',:notes)
        ON DUPLICATE KEY UPDATE document_date=VALUES(document_date),sheet_name=VALUES(sheet_name),uploaded_file_name=VALUES(uploaded_file_name),source_status='cargado',notes=VALUES(notes),updated_at=NOW()"
    );
    $sourceStmt->execute([
        'source_key' => $sourceKey,
        'document_date' => $fecha !== '' ? $fecha : null,
        'sheet_name' => $extension === 'xlsx' ? $hoja : 'CSV exportado de ' . $hoja,
        'file_name' => basename($archivo),
        'notes' => 'Importacion privada. La fuente no modifica organizational_units.',
    ]);

    $selectSource = $pdo->prepare('SELECT id FROM workforce_sources WHERE source_key=:source_key LIMIT 1');
    $selectSource->execute(['source_key' => $sourceKey]);
    $sourceId = (int)($selectSource->fetch()['id'] ?? 0);
    if ($sourceId <= 0) {
        throw new RuntimeException('No se pudo obtener el identificador de la fuente.');
    }

    if ($reemplazar) {
        $delete = $pdo->prepare('DELETE FROM workforce_personnel_staging WHERE source_id=:source_id');
        $delete->execute(['source_id' => $sourceId]);
    }

    $insert = $pdo->prepare(
        "INSERT INTO workforce_personnel_staging
        (source_id,row_number,rank_text,position_number,first_name,last_name,full_name,location_original,location_normalized,police_type_original,raw_data_json,import_status,import_notes)
        VALUES (:source_id,:row_number,:rank_text,:position_number,:first_name,:last_name,:full_name,:location_original,:location_normalized,:police_type_original,:raw_data_json,'importado',:import_notes)
        ON DUPLICATE KEY UPDATE rank_text=VALUES(rank_text),position_number=VALUES(position_number),first_name=VALUES(first_name),last_name=VALUES(last_name),full_name=VALUES(full_name),location_original=VALUES(location_original),location_normalized=VALUES(location_normalized),police_type_original=VALUES(police_type_original),raw_data_json=VALUES(raw_data_json),import_status='importado',import_notes=VALUES(import_notes),updated_at=NOW()"
    );

    $imported = 0;
    foreach ($data as $record) {
        $insert->execute([
            'source_id' => $sourceId,
            'row_number' => $record['row_number'],
            'rank_text' => $record['rank_text'] ?: null,
            'position_number' => $record['position_number'] ?: null,
            'first_name' => $record['first_name'] ?: null,
            'last_name' => $record['last_name'] ?: null,
            'full_name' => $record['full_name'],
            'location_original' => $record['location_original'] ?: null,
            'location_normalized' => $record['location_normalized'] ?: null,
            'police_type_original' => $record['police_type_original'] ?: null,
            'raw_data_json' => $record['raw_data_json'],
            'import_notes' => 'Fila importada desde la hoja fuente; pendiente de match contra estructura vigente.',
        ]);
        $imported++;
    }

    $updateSource = $pdo->prepare("UPDATE workforce_sources SET total_rows=:total,source_status='cargado',updated_at=NOW() WHERE id=:id");
    $updateSource->execute(['total' => $imported, 'id' => $sourceId]);
    $pdo->commit();

    echo "Fuente: {$sourceKey}\n";
    echo "Hoja/CSV: {$hoja}\n";
    echo "Fila de encabezados: {$headerRowNumber}\n";
    echo "Personas importadas: {$imported}\n";
    echo "La estructura organizational_units no fue modificada.\n";
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Error: ' . $exception->getMessage() . "\n");
    exit(1);
}
