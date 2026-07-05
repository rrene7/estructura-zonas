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

echo "Aplicando catalogo de direcciones cabecera vigentes..."
"$MYSQL" -u "$USER" "$DB" < database/direcciones_cabecera_vigentes.sql

echo "Resumen de cabeceras con candidatos:"
"$MYSQL" -u "$USER" "$DB" -e "SELECT direction_number, direction_label, match_status, confidence_level, unit_name, legacy_id FROM vw_moi_direcciones_cabecera_vigentes ORDER BY direction_number, confidence_level DESC;"

echo "Cabeceras sin candidato encontrado:"
"$MYSQL" -u "$USER" "$DB" -e "SELECT direction_number, direction_label FROM vw_moi_direcciones_cabecera_sin_match;"

echo "Direcciones vigentes no incluidas como cabecera:"
"$MYSQL" -u "$USER" "$DB" -e "SELECT id, code, name, legacy_id FROM vw_moi_direcciones_no_cabecera_candidatas LIMIT 50;"

echo "Catalogo de direcciones aplicado. Revise candidatos antes de suprimir o fusionar otras direcciones."
