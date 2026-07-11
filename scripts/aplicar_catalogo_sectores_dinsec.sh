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

echo "Aplicando catalogo DINSEC de areas/sectores en base: $DB_NAME"
"$MYSQL_BIN" -u root "$DB_NAME" < "$ROOT_DIR/database/catalogo_sectores_dinsec.sql"

echo "Limpiando duplicados del catalogo y reforzando indice unico..."
php "$ROOT_DIR/scripts/limpiar_catalogo_sectores.php"

echo "Listo. Resumen:"
"$MYSQL_BIN" -u root "$DB_NAME" -e "SELECT zone_number, zone_label, COALESCE(area_code,'SERV') area, COUNT(*) total FROM moi_area_sector_catalog GROUP BY zone_number, zone_label, COALESCE(area_code,'SERV') ORDER BY zone_number, area;"
