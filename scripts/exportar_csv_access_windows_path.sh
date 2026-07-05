#!/usr/bin/env bash
set -euo pipefail

if [ "${1:-}" = "" ]; then
  echo "Uso desde Git Bash:"
  echo "bash scripts/exportar_csv_access_windows_path.sh 'C:\\Ruta\\SRHN_LOCAL.mdb'"
  exit 1
fi

ACCESS_PATH_WINDOWS="$1"
OUTPUT_DIR="data/access_csv"

cd "$(dirname "$0")/.."
mkdir -p "$OUTPUT_DIR"

OUTPUT_DIR_WINDOWS="$(cygpath -w "$OUTPUT_DIR")"
SCRIPT_WINDOWS="$(cygpath -w "scripts/exportar_access_csv.ps1")"

echo "Ruta Access: $ACCESS_PATH_WINDOWS"
echo "Salida CSV: $OUTPUT_DIR_WINDOWS"

echo "Verificando ruta Windows..."
powershell.exe -NoProfile -ExecutionPolicy Bypass -Command "if (Test-Path -LiteralPath '$ACCESS_PATH_WINDOWS') { Write-Host 'Archivo encontrado' } else { Write-Host 'Archivo no encontrado'; exit 2 }"

echo "Exportando tablas Access a CSV..."
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "$SCRIPT_WINDOWS" -AccessPath "$ACCESS_PATH_WINDOWS" -OutputDir "$OUTPUT_DIR_WINDOWS"

echo ""
echo "Contenido generado:"
ls -lh "$OUTPUT_DIR" || true

echo ""
echo "CSV generados en: $OUTPUT_DIR"
echo "Ahora ejecute: bash scripts/importar_csv_access.sh"
