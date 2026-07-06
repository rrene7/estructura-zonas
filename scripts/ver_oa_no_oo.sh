#!/usr/bin/env bash
set -euo pipefail

MYSQL="/c/xampp/mysql/bin/mysql.exe"
DB="estructura_zonas_test"
USER="root"

cd "$(dirname "$0")/.."

if [ ! -f "$MYSQL" ]; then
  echo "No se encontro MySQL en $MYSQL"
  exit 1
fi

echo "Creando vista OA / NO / OO..."
"$MYSQL" -u "$USER" "$DB" < database/vista_clasificacion_oa_no_oo.sql

echo "Resumen:"
"$MYSQL" -u "$USER" "$DB" -e "SELECT * FROM vw_moi_oa_no_oo_resumen;"

echo "Primeros 50 registros:"
"$MYSQL" -u "$USER" "$DB" -e "SELECT clasificacion_oa_no_oo, descripcion_clasificacion, name, unit_type, territorial_scope, legacy_table, legacy_id FROM vw_moi_oa_no_oo WHERE lifecycle_status = 'vigente' ORDER BY clasificacion_oa_no_oo, name LIMIT 50;"
