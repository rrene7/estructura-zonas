#!/usr/bin/env bash
set -euo pipefail

MYSQL="/c/xampp/mysql/bin/mysql.exe"
PHP="/c/xampp/php/php.exe"
DB="estructura_zonas_test"
USER="root"

cd "$(dirname "$0")/.."

if [ ! -f "$PHP" ]; then
  echo "No se encontro PHP en $PHP"
  exit 1
fi

if [ ! -f "$MYSQL" ]; then
  echo "No se encontro MySQL en $MYSQL"
  exit 1
fi

mkdir -p data/access_csv

echo "Carpeta esperada para CSV: data/access_csv"
echo "Archivos esperados: TABCUAR.csv, BDFUERZA.csv, TABLUGAR.csv, TABDIR.csv, DIR.csv, DOTA.csv, POLPLANI.csv, VACANTES.csv, CARGOS.csv, TABRAN.csv, TABSTATUS.csv"

echo "Importando CSV hacia tablas staging..."
"$PHP" scripts/importar_csv_access.php

echo "Ejecutando clasificacion MOI..."
"$MYSQL" -u "$USER" "$DB" < database/clasificacion_moi_desde_staging.sql

echo "Ejecutando migracion final MOI..."
"$MYSQL" -u "$USER" "$DB" < database/migracion_final_moi_desde_clasificacion.sql

echo "Creando vistas del dashboard..."
"$MYSQL" -u "$USER" "$DB" < database/dashboard_moi_views.sql

echo "Proceso completado."
echo "Abrir: http://localhost/estructura-zonas/dashboard/"
