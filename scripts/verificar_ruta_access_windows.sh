#!/usr/bin/env bash
set -euo pipefail

if [ "${1:-}" = "" ]; then
  echo "Uso: bash scripts/verificar_ruta_access_windows.sh 'Z:\\Ruta\\SRHN_LOCAL.mdb'"
  exit 1
fi

ACCESS_PATH_WINDOWS="$1"

echo "Probando con PowerShell..."
powershell.exe -NoProfile -ExecutionPolicy Bypass -Command "if (Test-Path -LiteralPath '$ACCESS_PATH_WINDOWS') { Write-Host 'OK: archivo encontrado' } else { Write-Host 'NO: archivo no encontrado' }"

echo ""
echo "Probando listado de unidad Z..."
cmd.exe /c "dir Z:\\" 2>/dev/null || true
