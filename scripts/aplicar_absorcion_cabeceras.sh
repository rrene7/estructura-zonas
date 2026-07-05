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

echo "Aplicando absorciones aprobadas..."
"$MYSQL" -u "$USER" "$DB" < database/aplicar_absorcion_cabeceras_moi.sql

echo "Reconstruyendo vistas dashboard..."
"$MYSQL" -u "$USER" "$DB" < database/dashboard_moi_views.sql

echo "Resumen dashboard:"
"$MYSQL" -u "$USER" "$DB" -e "SELECT total_unidades, unidades_no_vigentes, pendientes_revision FROM vw_moi_resumen_general;"

echo "Absorcion aplicada. Refresque el dashboard con Ctrl+F5."
