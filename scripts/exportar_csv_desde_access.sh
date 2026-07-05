#!/usr/bin/env bash
set -euo pipefail

if [ "${1:-}" = "" ]; then
  echo "Uso: bash scripts/exportar_csv_desde_access.sh \"/c/ruta/al/archivo.mdb\""
  echo "Ejemplo: bash scripts/exportar_csv_desde_access.sh \"/c/Users/rrriverap/Downloads/SRHN_LOCAL.mdb\""
  exit 1
fi

ACCESS_PATH="$1"
OUTPUT_DIR="data/access_csv"

cd "$(dirname "$0")/.."
mkdir -p "$OUTPUT_DIR"

WIN_ACCESS_PATH="$(cygpath -w "$ACCESS_PATH")"
WIN_OUTPUT_DIR="$(cygpath -w "$OUTPUT_DIR")"

powershell.exe -NoProfile -ExecutionPolicy Bypass -File "scripts/exportar_access_csv.ps1" -AccessPath "$WIN_ACCESS_PATH" -OutputDir "$WIN_OUTPUT_DIR"

echo ""
echo "CSV generados en: $OUTPUT_DIR"
echo "Ahora puede ejecutar: bash scripts/importar_csv_access.sh"
