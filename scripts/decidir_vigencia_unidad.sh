#!/usr/bin/env bash
set -euo pipefail

MYSQL="/c/xampp/mysql/bin/mysql.exe"
DB="estructura_zonas_test"
USER="root"

if [ "${1:-}" = "" ] || [ "${2:-}" = "" ]; then
  echo "Uso: bash scripts/decidir_vigencia_unidad.sh REVIEW_ID vigente|suprimida|fusionada|renombrada FECHA_CIERRE_OPCIONAL 'nota opcional'"
  echo "Ejemplo vigente: bash scripts/decidir_vigencia_unidad.sh 10 vigente"
  echo "Ejemplo suprimida: bash scripts/decidir_vigencia_unidad.sh 10 suprimida 2026-01-14 'No aparece en nueva estructura'"
  exit 1
fi

REVIEW_ID="$1"
ESTADO="$2"
FECHA="${3:-}"
NOTA="${4:-Decision de revision de vigencia}"

case "$ESTADO" in
  vigente|suprimida|fusionada|renombrada) ;;
  *) echo "Estado no valido: $ESTADO"; exit 1 ;;
esac

cd "$(dirname "$0")/.."

if [ ! -f "$MYSQL" ]; then
  echo "No se encontro MySQL en $MYSQL"
  exit 1
fi

if [ "$ESTADO" = "vigente" ]; then
  "$MYSQL" -u "$USER" "$DB" <<SQL
UPDATE moi_unit_vigencia_review
SET proposed_lifecycle_status = 'vigente',
    proposed_valid_to = NULL,
    review_reason = '$NOTA',
    decision_status = 'aprobado',
    reviewed_by = 'bash',
    reviewed_at = NOW()
WHERE id = $REVIEW_ID;
SQL
else
  if [ "$FECHA" = "" ]; then
    echo "Para $ESTADO debe indicar fecha de cierre, ejemplo: 2026-01-14"
    exit 1
  fi
  "$MYSQL" -u "$USER" "$DB" <<SQL
UPDATE moi_unit_vigencia_review
SET proposed_lifecycle_status = '$ESTADO',
    proposed_valid_to = '$FECHA',
    review_reason = '$NOTA',
    decision_status = 'aprobado',
    reviewed_by = 'bash',
    reviewed_at = NOW()
WHERE id = $REVIEW_ID;
SQL
fi

"$MYSQL" -u "$USER" "$DB" -e "SELECT id, code, name, proposed_lifecycle_status, proposed_valid_to, decision_status, review_reason FROM vw_moi_revision_vigencia WHERE id = $REVIEW_ID;"
