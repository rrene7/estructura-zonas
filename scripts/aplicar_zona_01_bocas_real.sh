#!/usr/bin/env bash
set -euo pipefail

DB_NAME="${DB_NAME:-estructura_zonas_test}"
MYSQL_BIN="${MYSQL_BIN:-/c/xampp/mysql/bin/mysql.exe}"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

if [ ! -x "$MYSQL_BIN" ]; then
  echo "No encuentro mysql en: $MYSQL_BIN"
  echo "Ajusta MYSQL_BIN o usa Git Bash desde XAMPP."
  exit 1
fi

echo "1/4 Cargando referencia DINSEC Zona 1..."
"$MYSQL_BIN" -u root "$DB_NAME" < "$ROOT_DIR/database/dinsec_zona_01_bocas.sql"

echo "2/4 Aplicando estructura real y vinculo de personal Zona 1..."
"$MYSQL_BIN" -u root "$DB_NAME" < "$ROOT_DIR/database/aplicar_zona_01_bocas_real.sql"

echo "3/4 Marcando como validado el personal DINSEC ya vinculado..."
"$MYSQL_BIN" -u root "$DB_NAME" < "$ROOT_DIR/database/validar_dinsec_zona_01_bocas.sql"

echo "4/4 Validacion Zona 1"
"$MYSQL_BIN" -u root "$DB_NAME" -e "
SELECT 'SECTORES' bloque, ou.legacy_id, ou.name, parent.name AS superior
FROM organizational_units ou
LEFT JOIN organizational_units parent ON parent.id=ou.parent_id
WHERE BINARY ou.legacy_table=BINARY 'DINSEC_SECTOR' AND ou.legacy_id LIKE 'Z01-%'
ORDER BY ou.legacy_id;

SELECT 'PERSONAL_ASIGNADO' bloque, l.assignment_scope, COALESCE(su.name,a.name,z.name) unidad_asignada, l.location_sector, COUNT(*) total
FROM dinsec_personnel_unit_links l
LEFT JOIN organizational_units su ON su.id=l.assignment_unit_id
LEFT JOIN organizational_units a ON a.id=l.area_unit_id
LEFT JOIN organizational_units z ON z.id=l.zone_unit_id
JOIN dinsec_personnel_reference d ON d.id=l.dinsec_personnel_reference_id
WHERE d.zone_label='1 Zona Policial - Bocas del Toro'
GROUP BY l.assignment_scope, unidad_asignada, l.location_sector
ORDER BY l.assignment_scope, unidad_asignada, l.location_sector;

SELECT 'DINSEC_ESTADO' bloque, d.review_status, COUNT(*) total
FROM dinsec_personnel_reference d
WHERE d.zone_label='1 Zona Policial - Bocas del Toro'
GROUP BY d.review_status;

SELECT 'SIN_VINCULO' bloque, COUNT(*) total
FROM dinsec_personnel_reference d
LEFT JOIN dinsec_personnel_unit_links l ON l.dinsec_personnel_reference_id=d.id
WHERE d.zone_label='1 Zona Policial - Bocas del Toro' AND l.id IS NULL;
"