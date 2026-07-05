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

echo "Creando base de datos..."
"$MYSQL" -u "$USER" < database/crear_base_datos.sql

echo "Creando tablas staging..."
"$MYSQL" -u "$USER" "$DB" < database/staging_ubicaciones_access.sql

echo "Creando modelo base..."
"$MYSQL" -u "$USER" "$DB" < database/estructura_ubicaciones_dependencias.sql

echo "Aplicando adaptacion MOI..."
"$MYSQL" -u "$USER" "$DB" < database/adaptacion_moi_65_16.sql

echo "Aplicando versionado MOI..."
"$MYSQL" -u "$USER" "$DB" < database/versionado_estructura_moi.sql

echo "Creando config del dashboard si no existe..."
if [ ! -f dashboard/config.php ]; then
  cp dashboard/config.example.php dashboard/config.php
fi

echo "Setup inicial completado."
echo "Ahora exporta los CSV de Access a: data/access_csv/"
echo "Luego ejecuta: bash scripts/importar_csv_access.sh"
