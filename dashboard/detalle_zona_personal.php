<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

$zoneId = max(0, (int)($_GET['zona_id'] ?? 0));
$unitId = 0;

if ($zoneId > 0 && table_exists($pdo, 'moi_zonas_cabecera_vigentes')) {
    $zone = one(
        $pdo,
        "SELECT zone_unit.id AS unit_id
         FROM moi_zonas_cabecera_vigentes zone_row
         LEFT JOIN organizational_units zone_unit
           ON BINARY zone_unit.legacy_table = BINARY 'MOI_CABECERA_ZONA'
          AND CAST(zone_unit.legacy_id AS UNSIGNED) = zone_row.zone_number
         WHERE zone_row.id = :zone_id
         LIMIT 1",
        ['zone_id' => $zoneId]
    );
    $unitId = (int)($zone['unit_id'] ?? 0);
}

if ($unitId > 0) {
    header('Location: estructura_admin.php?id=' . $unitId . '&grupo=zonas');
    exit;
}

header('Location: trabajo_zonas.php' . ($zoneId > 0 ? '?zona_id=' . $zoneId : ''));
exit;
