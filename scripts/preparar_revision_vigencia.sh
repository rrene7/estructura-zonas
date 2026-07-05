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

echo "Preparando mesa de revision de vigencia..."
"$MYSQL" -u "$USER" "$DB" < database/revision_vigencia_moi.sql

echo "Resumen de revision:"
"$MYSQL" -u "$USER" "$DB" -e "SELECT * FROM vw_moi_revision_vigencia_resumen;"

echo "Primeros pendientes:"
"$MYSQL" -u "$USER" "$DB" -e "SELECT id, code, name, unit_type, proposed_lifecycle_status, review_reason FROM vw_moi_revision_pendiente LIMIT 30;"

echo "Revision preparada."
