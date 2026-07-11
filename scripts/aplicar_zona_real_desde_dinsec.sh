#!/usr/bin/env bash
set -euo pipefail

ZONA="${1:-}"
if [ -z "$ZONA" ]; then
  echo "Uso: bash scripts/aplicar_zona_real_desde_dinsec.sh 2"
  exit 1
fi

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
php "$ROOT_DIR/scripts/aplicar_zona_real_desde_dinsec.php" --zona="$ZONA"
