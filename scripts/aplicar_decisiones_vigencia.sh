#!/usr/bin/env bash
set -euo pipefail

MYSQL="/c/xampp/mysql/bin/mysql.exe"
DB="estructura_zonas_test"
USER="root"

cd "$(dirname "$0")/.."

if [ ! -f "$MYSQL" ]; then
  echo "No se encontro MySQL en $MYSQL"
  exit 1
fi

echo "Aplicando decisiones aprobadas de vigencia..."
"$MYSQL" -u "$USER" "$DB" <<'SQL'
START TRANSACTION;

INSERT INTO organizational_unit_lifecycle_events
(organizational_unit_id, event_type, effective_from, effective_to, replacement_unit_id, source_document, notes, created_by)
SELECT
    r.organizational_unit_id,
    CASE
        WHEN r.proposed_lifecycle_status = 'vigente' THEN 'actualizacion'
        WHEN r.proposed_lifecycle_status = 'suprimida' THEN 'supresion'
        WHEN r.proposed_lifecycle_status = 'fusionada' THEN 'fusion'
        WHEN r.proposed_lifecycle_status = 'renombrada' THEN 'renombre'
        ELSE 'actualizacion'
    END,
    COALESCE(r.proposed_valid_from, CURRENT_DATE),
    r.proposed_valid_to,
    r.replacement_unit_id,
    'MOI-65.16',
    r.review_reason,
    COALESCE(r.reviewed_by, 'bash')
FROM moi_unit_vigencia_review r
WHERE r.decision_status = 'aprobado'
  AND NOT EXISTS (
      SELECT 1
      FROM organizational_unit_lifecycle_events e
      WHERE e.organizational_unit_id = r.organizational_unit_id
        AND e.event_type = CASE
            WHEN r.proposed_lifecycle_status = 'vigente' THEN 'actualizacion'
            WHEN r.proposed_lifecycle_status = 'suprimida' THEN 'supresion'
            WHEN r.proposed_lifecycle_status = 'fusionada' THEN 'fusion'
            WHEN r.proposed_lifecycle_status = 'renombrada' THEN 'renombre'
            ELSE 'actualizacion'
        END
        AND e.effective_from = COALESCE(r.proposed_valid_from, CURRENT_DATE)
  );

UPDATE organizational_units ou
JOIN moi_unit_vigencia_review r ON r.organizational_unit_id = ou.id
SET ou.lifecycle_status = r.proposed_lifecycle_status,
    ou.valid_from = COALESCE(r.proposed_valid_from, ou.valid_from),
    ou.valid_to = r.proposed_valid_to,
    ou.replacement_unit_id = r.replacement_unit_id,
    ou.lifecycle_notes = r.review_reason,
    ou.status = CASE WHEN r.proposed_lifecycle_status = 'vigente' THEN 'active' ELSE 'inactive' END,
    ou.updated_at = NOW()
WHERE r.decision_status = 'aprobado';

COMMIT;
SQL

echo "Reconstruyendo vistas..."
"$MYSQL" -u "$USER" "$DB" < database/dashboard_moi_views.sql

echo "Resumen:"
"$MYSQL" -u "$USER" "$DB" -e "SELECT total_unidades, unidades_no_vigentes, pendientes_revision, aprobadas_revision FROM vw_moi_resumen_general;"

echo "Decisiones aplicadas. Refresque el dashboard con Ctrl+F5."
