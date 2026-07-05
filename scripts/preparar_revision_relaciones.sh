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

echo "Aplicando versionado base..."
"$MYSQL" -u "$USER" "$DB" < database/versionado_estructura_moi.sql

echo "Preparando revision de relaciones jerarquicas..."
"$MYSQL" -u "$USER" "$DB" < database/revision_relaciones_moi.sql

echo "Resumen de relaciones sugeridas:"
"$MYSQL" -u "$USER" "$DB" -e "SELECT * FROM vw_moi_revision_relaciones_resumen;"

echo "Primeras relaciones pendientes:"
"$MYSQL" -u "$USER" "$DB" -e "SELECT id, child_name, child_type, parent_name, parent_type, confidence_level, source_rule FROM vw_moi_revision_relaciones_pendientes LIMIT 30;"

echo "Revision de relaciones preparada."
