#!/usr/bin/env bash
set -euo pipefail

DB_NAME="${DB_NAME:-estructura_zonas_test}"
MYSQL_BIN="${MYSQL_BIN:-/c/xampp/mysql/bin/mysql.exe}"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FILE_PATH="${1:-$ROOT_DIR/local/private/PIE_DE_FUERZA_2026-06-26.xlsx}"
SHEET_NAME="${SHEET_NAME:-PIE DE FUERZA 26-6-2026}"
SOURCE_KEY="${SOURCE_KEY:-PIE_FUERZA_20260626}"

if [ ! -x "$MYSQL_BIN" ]; then
  echo "No encuentro mysql en: $MYSQL_BIN"
  echo "Ajusta MYSQL_BIN o ejecuta desde Git Bash con XAMPP."
  exit 1
fi

if [ ! -f "$FILE_PATH" ]; then
  echo "No encuentro el archivo privado en: $FILE_PATH"
  echo "Coloque el XLSX o CSV dentro de local/private/. No debe subirse a GitHub."
  exit 1
fi

echo "1/3 Creando tablas del modulo PIE DE FUERZA..."
"$MYSQL_BIN" -u root "$DB_NAME" < "$ROOT_DIR/database/pie_fuerza_20260626.sql"

echo "2/3 Importando exclusivamente la hoja: $SHEET_NAME"
php "$ROOT_DIR/scripts/importar_pie_fuerza.php" \
  --archivo="$FILE_PATH" \
  --hoja="$SHEET_NAME" \
  --fecha="2026-06-26" \
  --source-key="$SOURCE_KEY"

echo "3/3 Haciendo match contra organizational_units vigentes..."
php "$ROOT_DIR/scripts/matchear_pie_fuerza.php" --source-key="$SOURCE_KEY"

echo "Listo. Abra: http://localhost/estructura-zonas/dashboard/pie_fuerza.php"
echo "La estructura organizational_units no fue creada, movida ni modificada."
