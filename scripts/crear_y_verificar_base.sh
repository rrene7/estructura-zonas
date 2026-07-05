#!/usr/bin/env bash
set -euo pipefail

MYSQL="/c/xampp/mysql/bin/mysql.exe"
DB="estructura_zonas_test"
USER="root"

cd "$(dirname "$0")/.."

if [ ! -f "$MYSQL" ]; then
  echo "ERROR: no se encontro MySQL en $MYSQL"
  exit 1
fi

echo "Creando base $DB..."
"$MYSQL" -u "$USER" < database/crear_base_datos.sql

echo "Verificando base..."
"$MYSQL" -u "$USER" -e "SHOW DATABASES LIKE '$DB';"

echo "Base creada. Ahora puedes ejecutar:"
echo "bash scripts/setup_moi_xampp.sh"
