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

echo "Aplicando catalogo de zonas cabecera vigentes..."
"$MYSQL" -u "$USER" "$DB" < database/zonas_cabecera_vigentes.sql

echo "Resumen de cabeceras con candidatos:"
"$MYSQL" -u "$USER" "$DB" -e "SELECT zone_number, zone_label, match_status, confidence_level, unit_name, legacy_id FROM vw_moi_zonas_cabecera_vigentes ORDER BY zone_number, confidence_level DESC;"

echo "Cabeceras sin candidato encontrado:"
"$MYSQL" -u "$USER" "$DB" -e "SELECT zone_number, zone_label FROM vw_moi_zonas_cabecera_sin_match;"

echo "Zonas vigentes no incluidas como cabecera:"
"$MYSQL" -u "$USER" "$DB" -e "SELECT id, code, name, legacy_id FROM vw_moi_zonas_no_cabecera_candidatas LIMIT 50;"

echo "Catalogo aplicado. Revise candidatos antes de suprimir o fusionar otras zonas."
