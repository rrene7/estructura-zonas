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

echo "Aplicando relaciones jerarquicas aprobadas..."
"$MYSQL" -u "$USER" "$DB" <<'SQL'
START TRANSACTION;

INSERT INTO organizational_unit_relationships
(source_unit_id, target_unit_id, relationship_type, valid_from, valid_to, status, notes, created_at, updated_at)
SELECT
    r.child_unit_id,
    r.parent_unit_id,
    r.relationship_type,
    CURRENT_DATE,
    NULL,
    'active',
    r.review_reason,
    NOW(), NOW()
FROM moi_unit_relationship_review r
LEFT JOIN organizational_unit_relationships existing
  ON existing.source_unit_id = r.child_unit_id
 AND existing.target_unit_id = r.parent_unit_id
 AND existing.relationship_type = r.relationship_type
 AND existing.status = 'active'
WHERE r.decision_status = 'aprobado'
  AND r.parent_unit_id IS NOT NULL
  AND existing.id IS NULL;

UPDATE organizational_units child
JOIN moi_unit_relationship_review r ON r.child_unit_id = child.id
SET child.parent_id = r.parent_unit_id,
    child.updated_at = NOW()
WHERE r.decision_status = 'aprobado'
  AND r.parent_unit_id IS NOT NULL
  AND r.relationship_type = 'jerarquica';

COMMIT;
SQL

echo "Reconstruyendo vistas del dashboard..."
"$MYSQL" -u "$USER" "$DB" < database/dashboard_moi_views.sql

echo "Resumen de relaciones:"
"$MYSQL" -u "$USER" "$DB" -e "SELECT COUNT(*) AS relaciones_activas FROM organizational_unit_relationships WHERE status = 'active';"
"$MYSQL" -u "$USER" "$DB" -e "SELECT COUNT(*) AS unidades_sin_superior FROM vw_moi_unidades_sin_relacion_superior;"

echo "Relaciones aplicadas. Refresque el dashboard con Ctrl+F5."
