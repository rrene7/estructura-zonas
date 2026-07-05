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

echo "Aplicando versionado..."
"$MYSQL" -u "$USER" "$DB" < database/versionado_estructura_moi.sql

echo "Aplicando catalogo de zonas cabecera..."
"$MYSQL" -u "$USER" "$DB" < database/zonas_cabecera_vigentes.sql

echo "Aplicando catalogo de direcciones cabecera..."
"$MYSQL" -u "$USER" "$DB" < database/direcciones_cabecera_vigentes.sql

echo "Preparando cabeceras canonicas y absorcion..."
"$MYSQL" -u "$USER" "$DB" < database/absorcion_cabeceras_moi.sql

echo "Resumen de absorcion:"
"$MYSQL" -u "$USER" "$DB" -e "SELECT * FROM vw_moi_absorcion_cabeceras_resumen;"

echo "Primeros pendientes de absorcion:"
"$MYSQL" -u "$USER" "$DB" -e "SELECT id, absorption_type, catalog_number, cabecera_legitima, unidad_a_absorber, confidence_level, source_rule FROM vw_moi_absorcion_cabeceras_pendientes LIMIT 50;"

echo "Preparacion completada. Revise y apruebe absorciones antes de aplicar."
