#!/usr/bin/env bash
set -euo pipefail

MYSQL="/c/xampp/mysql/bin/mysql.exe"
DB="estructura_zonas_test"
USER="root"

if [ "${1:-}" = "" ] || [ "${2:-}" = "" ]; then
  echo "Uso: bash scripts/decidir_relacion_moi.sh REVIEW_ID aprobar|rechazar 'nota opcional'"
  echo "Ejemplo: bash scripts/decidir_relacion_moi.sh 15 aprobar 'Relacion validada por equipo tecnico'"
  exit 1
fi

REVIEW_ID="$1"
DECISION="$2"
NOTA="${3:-Decision de revision de relacion}"

case "$DECISION" in
  aprobar) ESTADO="aprobado" ;;
  rechazar) ESTADO="rechazado" ;;
  *) echo "Decision no valida: $DECISION"; exit 1 ;;
esac

cd "$(dirname "$0")/.."

if [ ! -f "$MYSQL" ]; then
  echo "No se encontro MySQL en $MYSQL"
  exit 1
fi

"$MYSQL" -u "$USER" "$DB" <<SQL
UPDATE moi_unit_relationship_review
SET decision_status = '$ESTADO',
    review_reason = '$NOTA',
    reviewed_by = 'bash',
    reviewed_at = NOW()
WHERE id = $REVIEW_ID;

SELECT id, child_name, parent_name, relationship_type, confidence_level, source_rule, decision_status, review_reason
FROM vw_moi_revision_relaciones
WHERE id = $REVIEW_ID;
SQL
