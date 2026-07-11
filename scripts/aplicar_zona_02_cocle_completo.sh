#!/usr/bin/env bash
set -euo pipefail

DB_NAME="${DB_NAME:-estructura_zonas_test}"
MYSQL_BIN="${MYSQL_BIN:-/c/xampp/mysql/bin/mysql.exe}"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CSV_PATH="${1:-$ROOT_DIR/local/private/dinsec_zona_02_cocle.csv}"

if [ ! -x "$MYSQL_BIN" ]; then
  echo "No encuentro mysql en: $MYSQL_BIN"
  echo "Ajusta MYSQL_BIN o usa Git Bash desde XAMPP."
  exit 1
fi

if [ ! -f "$CSV_PATH" ]; then
  echo "No encuentro el CSV privado de Cocle en: $CSV_PATH"
  echo "Cree la carpeta local/private y coloque ahi: dinsec_zona_02_cocle.csv"
  echo "Este archivo contiene personal y NO debe subirse a GitHub."
  exit 1
fi

echo "1/4 Aplicando catalogo real DINSEC Zona 2 - Cocle..."
"$MYSQL_BIN" -u root "$DB_NAME" < "$ROOT_DIR/database/dinsec_zona_02_cocle_catalogo.sql"

echo "2/4 Importando personal DINSEC Zona 2 desde CSV privado..."
php "$ROOT_DIR/scripts/importar_dinsec_personal_csv.php" --zona=2 --archivo="$CSV_PATH"

echo "3/4 Aplicando estructura real y vinculo de personal Zona 2..."
php "$ROOT_DIR/scripts/aplicar_zona_real_desde_dinsec.php" --zona=2

echo "4/4 Validacion Zona 2"
"$MYSQL_BIN" -u root "$DB_NAME" -e "
SELECT 'CATALOGO' bloque, COALESCE(area_code,'SERV') area, sector_name, service_label
FROM moi_area_sector_catalog
WHERE active=1 AND zone_number=2
ORDER BY COALESCE(area_code,'Z'), sector_name;

SELECT 'PERSONAL' bloque, COUNT(*) total
FROM dinsec_personnel_reference
WHERE zone_label LIKE '%2 Zona Policial - Cocle%';

SELECT 'VINCULADO' bloque, l.assignment_scope, COALESCE(a.name,z.name) unidad_area_zona, l.location_sector, COUNT(*) total
FROM dinsec_personnel_unit_links l
LEFT JOIN organizational_units a ON a.id=l.area_unit_id
LEFT JOIN organizational_units z ON z.id=l.zone_unit_id
JOIN dinsec_personnel_reference d ON d.id=l.dinsec_personnel_reference_id
WHERE d.zone_label LIKE '%2 Zona Policial - Cocle%'
GROUP BY l.assignment_scope, unidad_area_zona, l.location_sector
ORDER BY l.assignment_scope, unidad_area_zona, l.location_sector;

SELECT 'SIN_VINCULO' bloque, COUNT(*) total
FROM dinsec_personnel_reference d
LEFT JOIN dinsec_personnel_unit_links l ON l.dinsec_personnel_reference_id=d.id AND l.status='active'
WHERE d.zone_label LIKE '%2 Zona Policial - Cocle%' AND l.id IS NULL;
"

echo "Listo. Abra: http://localhost/estructura-zonas/dashboard/detalle_zona_personal.php?zona_id=2"
