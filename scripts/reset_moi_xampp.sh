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

echo "ADVERTENCIA: esto borrara la base local $DB y la creara nuevamente."
read -p "Continuar? escriba SI: " CONFIRM

if [ "$CONFIRM" != "SI" ]; then
  echo "Cancelado."
  exit 0
fi

echo "Eliminando base local anterior..."
"$MYSQL" -u "$USER" -e "DROP DATABASE IF EXISTS $DB; CREATE DATABASE $DB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

echo "Base reiniciada: $DB"
echo "Ahora ejecute: bash scripts/setup_moi_xampp.sh"
