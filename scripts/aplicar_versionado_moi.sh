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

echo "Aplicando versionado de estructura MOI..."
"$MYSQL" -u "$USER" "$DB" < database/versionado_estructura_moi.sql

echo "Actualizando vistas del dashboard..."
"$MYSQL" -u "$USER" "$DB" < database/dashboard_moi_views.sql

echo "Resumen vigente/no vigente:"
"$MYSQL" -u "$USER" "$DB" -e "SELECT total_unidades, unidades_no_vigentes, pendientes_revision, aprobadas_revision FROM vw_moi_resumen_general;"

echo "Versionado aplicado. Refresque el dashboard con Ctrl+F5."
