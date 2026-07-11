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

echo "Aplicando DINSEC Zona 1 - Bocas del Toro en base: $DB_NAME"
"$MYSQL_BIN" -u root "$DB_NAME" < "$ROOT_DIR/database/dinsec_zona_01_bocas.sql"

echo "Validacion rapida Zona 1:"
"$MYSQL_BIN" -u root "$DB_NAME" -e "SELECT zone_number, zone_label, COALESCE(area_code,'SERV') area, sector_name, service_label FROM moi_area_sector_catalog WHERE zone_number=1 ORDER BY COALESCE(area_code,'Z'), sector_name; SELECT area_code, location_sector, COUNT(*) total FROM dinsec_personnel_reference WHERE zone_label='1 Zona Policial - Bocas del Toro' GROUP BY area_code, location_sector ORDER BY area_code, location_sector;"
