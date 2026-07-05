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

MODO="${1:-alto}"

if [ "$MODO" = "alto" ]; then
  WHERE_SQL="confidence_level = 'alto'"
elif [ "$MODO" = "todo" ]; then
  WHERE_SQL="1 = 1"
else
  echo "Uso: bash scripts/aprobar_absorcion_cabeceras.sh alto|todo"
  exit 1
fi

echo "Aprobando absorciones pendientes modo: $MODO"
"$MYSQL" -u "$USER" "$DB" -e "UPDATE moi_cabecera_absorption_review SET match_status = 'aprobado', reviewed_by = 'bash', reviewed_at = NOW(), updated_at = NOW() WHERE match_status = 'pendiente' AND $WHERE_SQL;"

echo "Resumen:"
"$MYSQL" -u "$USER" "$DB" -e "SELECT * FROM vw_moi_absorcion_cabeceras_resumen;"
