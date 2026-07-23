<?php
declare(strict_types=1);

// Sincroniza los datos canonicos de una unidad existente sin crearla ni moverla.
// Uso:
// php scripts/sincronizar_unidad_canonica.php --id=123 --parent-id=456 \
//   --name="Nombre oficial" --short="SIGLA" --code="CODIGO" --type="servicio_policial"

$configPath = __DIR__ . '/../dashboard/config.php';
if (!is_file($configPath)) {
    fwrite(STDERR, "Falta dashboard/config.php\n");
    exit(1);
}

$options = getopt('', ['id:', 'parent-id:', 'name:', 'short:', 'code:', 'type:']);
$id = (int)($options['id'] ?? 0);
$parentId = (int)($options['parent-id'] ?? 0);
$name = trim((string)($options['name'] ?? ''));
$short = trim((string)($options['short'] ?? ''));
$code = trim((string)($options['code'] ?? ''));
$type = trim((string)($options['type'] ?? ''));

if ($id <= 0 || $parentId <= 0 || $name === '' || $short === '' || $code === '' || $type === '') {
    fwrite(STDERR, "Faltan parametros obligatorios.\n");
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

$pdo->beginTransaction();
try {
    $statement = $pdo->prepare(
        "SELECT id,parent_id,name,short_name,code,moi_code,status,lifecycle_status
         FROM organizational_units
         WHERE id=:id
         FOR UPDATE"
    );
    $statement->execute(['id'=>$id]);
    $unit = $statement->fetch();
    if (!$unit) {
        throw new RuntimeException('La unidad indicada no existe.');
    }
    if ((int)$unit['parent_id'] !== $parentId) {
        throw new RuntimeException('La unidad no depende del superior confirmado.');
    }
    if ($unit['status'] !== 'active' || $unit['lifecycle_status'] !== 'vigente') {
        throw new RuntimeException('La unidad no esta activa y vigente.');
    }

    $typeStatement = $pdo->prepare('SELECT id FROM unit_types WHERE name=:name LIMIT 1');
    $typeStatement->execute(['name'=>$type]);
    $typeRow = $typeStatement->fetch();
    if (!$typeRow) {
        throw new RuntimeException('No existe el tipo de unidad solicitado.');
    }

    $duplicate = $pdo->prepare(
        "SELECT id FROM organizational_units
         WHERE id<>:id
           AND (
                UPPER(COALESCE(short_name,''))=UPPER(:short_name)
                OR UPPER(COALESCE(code,''))=UPPER(:code)
                OR UPPER(COALESCE(moi_code,''))=UPPER(:moi_code)
           )
         LIMIT 1"
    );
    $duplicate->execute([
        'id'=>$id,
        'short_name'=>$short,
        'code'=>$code,
        'moi_code'=>$code,
    ]);
    if ($duplicate->fetch()) {
        throw new RuntimeException('Otra unidad ya utiliza la misma sigla o codigo.');
    }

    $update = $pdo->prepare(
        "UPDATE organizational_units
         SET unit_type_id=:unit_type_id,
             name=:name,
             short_name=:short_name,
             code=:code,
             moi_code=:moi_code,
             updated_at=NOW()
         WHERE id=:id
           AND parent_id=:parent_id"
    );
    $update->execute([
        'unit_type_id'=>(int)$typeRow['id'],
        'name'=>$name,
        'short_name'=>$short,
        'code'=>$code,
        'moi_code'=>$code,
        'id'=>$id,
        'parent_id'=>$parentId,
    ]);

    $pdo->commit();
    echo "Unidad sincronizada correctamente.\n";
    echo "ID: {$id}\n";
    echo "Nombre: {$name}\n";
    echo "Sigla: {$short}\n";
    echo "Codigo: {$code}\n";
    echo "Superior ID: {$parentId}\n";
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Error: ' . $exception->getMessage() . "\n");
    exit(1);
}
