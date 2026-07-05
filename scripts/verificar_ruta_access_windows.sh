#!/usr/bin/env bash
set -euo pipefail

if [ "${1:-}" = "" ]; then
  echo "Uso: bash scripts/verificar_ruta_access_windows.sh 'C:\\Ruta\\SRHN_LOCAL.mdb'"
  exit 1
fi

ACCESS_PATH_WINDOWS="$1"

echo "Probando con PowerShell..."
powershell.exe -NoProfile -ExecutionPolicy Bypass -Command "if (Test-Path -LiteralPath '$ACCESS_PATH_WINDOWS') { Write-Host 'OK: archivo encontrado' } else { Write-Host 'NO: archivo no encontrado'; exit 2 }"

echo ""
echo "Verificando archivo desde CMD sin abrir consola interactiva..."
cmd.exe /c "if exist \"$ACCESS_PATH_WINDOWS\" (echo OK: archivo visible desde CMD) else (echo NO: archivo no visible desde CMD)"

echo ""
echo "Verificacion terminada."
