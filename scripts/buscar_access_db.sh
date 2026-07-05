#!/usr/bin/env bash
set -euo pipefail

BASE_DIR="/c/Users/rrriverap"

if [ "${1:-}" != "" ]; then
  BASE_DIR="$1"
fi

echo "Buscando archivos Access en: $BASE_DIR"
echo "Esto puede tardar unos minutos."
echo ""

find "$BASE_DIR" -type f \( -iname "*.mdb" -o -iname "*.accdb" \) 2>/dev/null | sort

echo ""
echo "Si aparece SRHN_LOCAL.mdb o una base similar, copie la ruta y use:"
echo "bash scripts/exportar_csv_desde_access.sh \"/ruta/al/archivo.mdb\""
