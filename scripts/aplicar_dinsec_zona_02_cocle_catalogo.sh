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

echo "Aplicando catalogo DINSEC Zona 2 - Cocle en base: $DB_NAME"
"$MYSQL_BIN" -u root "$DB_NAME" < "$ROOT_DIR/database/dinsec_zona_02_cocle_catalogo.sql"

echo "Resumen Zona 2:"
"$MYSQL_BIN" -u root "$DB_NAME" -e "SELECT zone_number, zone_label, COALESCE(area_code,'SERV') area, sector_name, service_label FROM moi_area_sector_catalog WHERE zone_number=2 AND active=1 ORDER BY COALESCE(area_code,'Z'), sector_name;"