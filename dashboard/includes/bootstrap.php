<?php
declare(strict_types=1);

$configPath = dirname(__DIR__) . '/config.php';
if (!is_file($configPath)) {
    http_response_code(500);
    echo '<!doctype html><html lang="es"><meta charset="utf-8"><title>Configuración requerida</title>';
    echo '<body style="font-family:Arial,sans-serif;padding:32px;background:#f5f7fb;color:#172033">';
    echo '<h1>Falta la configuración local</h1>';
    echo '<p>Copie <code>dashboard/config.example.php</code> como <code>dashboard/config.php</code> y complete los datos de la base local.</p>';
    echo '</body></html>';
    exit;
}

$config = require $configPath;

try {
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        $config['db_host'],
        $config['db_port'],
        $config['db_name'],
        $config['charset'] ?? 'utf8mb4'
    );

    $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
} catch (Throwable $error) {
    http_response_code(500);
    echo '<!doctype html><html lang="es"><meta charset="utf-8"><title>Error de conexión</title>';
    echo '<body style="font-family:Arial,sans-serif;padding:32px;background:#f5f7fb;color:#172033">';
    echo '<h1>No fue posible abrir el dashboard</h1>';
    echo '<p>Revise que MySQL esté iniciado y que <code>dashboard/config.php</code> tenga los datos correctos.</p>';
    echo '<p style="color:#64748b">Detalle técnico: ' . htmlspecialchars($error->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>';
    echo '</body></html>';
    exit;
}

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function expand_repeated_named_parameters(string $sql, array $params): array
{
    foreach ($params as $name => $value) {
        if (!is_string($name) || $name === '') {
            continue;
        }

        $pattern = '/:' . preg_quote($name, '/') . '\b/';
        if (preg_match_all($pattern, $sql, $matches, PREG_OFFSET_CAPTURE) < 2) {
            continue;
        }

        $occurrences = $matches[0];
        for ($index = count($occurrences) - 1; $index >= 1; $index--) {
            $replacementName = $name . '__' . ($index + 1);
            $offset = (int)$occurrences[$index][1];
            $length = strlen((string)$occurrences[$index][0]);
            $sql = substr_replace($sql, ':' . $replacementName, $offset, $length);
            $params[$replacementName] = $value;
        }
    }

    return [$sql, $params];
}

function rows(PDO $pdo, string $sql, array $params = []): array
{
    [$sql, $params] = expand_repeated_named_parameters($sql, $params);
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return $statement->fetchAll();
}

function one(PDO $pdo, string $sql, array $params = []): array
{
    $result = rows($pdo, $sql, $params);
    return $result[0] ?? [];
}

function table_exists(PDO $pdo, string $table): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) AS total
         FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name = :table'
    );
    $statement->execute(['table' => $table]);
    return (int)($statement->fetch()['total'] ?? 0) > 0;
}

function column_exists(PDO $pdo, string $table, string $column): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) AS total
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = :table
           AND column_name = :column'
    );
    $statement->execute(['table' => $table, 'column' => $column]);
    return (int)($statement->fetch()['total'] ?? 0) > 0;
}

function workforce_is_available(PDO $pdo): bool
{
    return table_exists($pdo, 'workforce_sources')
        && table_exists($pdo, 'workforce_personnel_staging')
        && table_exists($pdo, 'workforce_unit_matches')
        && table_exists($pdo, 'vw_workforce_match_detail')
        && table_exists($pdo, 'vw_workforce_summary');
}

function current_workforce_source(PDO $pdo, int $requestedId = 0): array
{
    if (!workforce_is_available($pdo)) {
        return [];
    }

    if ($requestedId > 0) {
        return one($pdo, 'SELECT * FROM workforce_sources WHERE id = :id LIMIT 1', ['id' => $requestedId]);
    }

    return one(
        $pdo,
        'SELECT * FROM workforce_sources
         ORDER BY document_date DESC, id DESC
         LIMIT 1'
    );
}

function format_number(mixed $value): string
{
    return number_format((int)$value, 0, ',', '.');
}

function assignment_label(?string $status): string
{
    return match ($status) {
        'asignado_completo' => 'Ubicación completa',
        'asignado_parcial' => 'Unidad confirmada',
        'pendiente_revision' => 'Requiere revisión',
        'sin_coincidencia' => 'Sin ubicación confirmada',
        default => 'Sin información',
    };
}

function assignment_help(?string $status): string
{
    return match ($status) {
        'asignado_completo' => 'La unidad y el nivel organizacional están identificados.',
        'asignado_parcial' => 'La unidad principal está confirmada y el detalle interno se conserva por separado.',
        'pendiente_revision' => 'La ubicación necesita una validación manual.',
        'sin_coincidencia' => 'No se encontró una unidad vigente equivalente.',
        default => 'No hay información de clasificación disponible.',
    };
}

function assignment_class(?string $status): string
{
    return match ($status) {
        'asignado_completo' => 'success',
        'asignado_parcial' => 'info',
        'pendiente_revision' => 'warning',
        'sin_coincidencia' => 'danger',
        default => 'neutral',
    };
}

function review_label(?string $status): string
{
    return match ($status) {
        'aprobado' => 'Validado',
        'automatico' => 'Clasificación automática',
        'pendiente' => 'Pendiente de validar',
        'rechazado' => 'Rechazado',
        default => 'Sin revisión',
    };
}

function level_label(?string $level): string
{
    return match ($level) {
        'direccion' => 'Dirección',
        'zona' => 'Zona policial',
        'area' => 'Área',
        'servicio' => 'Servicio policial',
        'dependencia' => 'Dependencia',
        'unidad' => 'Unidad',
        'otro' => 'Otro nivel',
        'ninguno', null, '' => 'Sin nivel',
        default => ucfirst(str_replace('_', ' ', $level)),
    };
}

function query_url(string $path, array $values): string
{
    $clean = array_filter(
        $values,
        static fn (mixed $value): bool => $value !== '' && $value !== null && $value !== 0 && $value !== '0'
    );
    return $path . ($clean ? '?' . http_build_query($clean) : '');
}
