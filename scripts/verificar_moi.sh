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

echo "1) Verificando base de datos..."
"$MYSQL" -u "$USER" -e "SHOW DATABASES LIKE '$DB';"

echo ""
echo "2) Verificando tablas..."
"$MYSQL" -u "$USER" "$DB" -e "SHOW TABLES;"

echo ""
echo "3) Conteo de tablas creadas..."
"$MYSQL" -u "$USER" "$DB" -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$DB';"

echo ""
echo "4) Verificando vistas del dashboard..."
"$MYSQL" -u "$USER" "$DB" -e "SHOW FULL TABLES WHERE Table_type = 'VIEW';"

echo ""
echo "Si aqui aparecen tablas y vistas, la base esta creada. Si no aparecen datos en el dashboard, falta importar CSV a data/access_csv."
