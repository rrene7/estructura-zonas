#!/usr/bin/env bash
set -euo pipefail

MYSQL="/c/xampp/mysql/bin/mysql.exe"
DB="estructura_zonas_test"
USER="root"

if [ "${1:-}" = "" ] || [ "${2:-}" = "" ] || [ "${3:-}" = "" ]; then
  echo "Uso: bash scripts/marcar_unidad_no_vigente.sh TABCUAR CODIGO YYYY-MM-DD 'nota opcional'"
  echo "Ejemplo: bash scripts/marcar_unidad_no_vigente.sh TABCUAR 12345 2026-01-14 'Unidad no vigente en nueva estructura'"
  exit 1
fi

LEGACY_TABLE="$1"
LEGACY_ID="$2"
FECHA="$3"
NOTA="${4:-Cambio de vigencia estructural}"

cd "$(dirname "$0")/.."

if [ ! -f "$MYSQL" ]; then
  echo "No se encontro MySQL en $MYSQL"
  exit 1
fi

"$MYSQL" -u "$USER" "$DB" <<SQL
START TRANSACTION;

INSERT INTO organizational_unit_lifecycle_events
(organizational_unit_id, event_type, effective_from, source_document, notes, created_by)
SELECT id, 'supresion', '$FECHA', 'MOI-65.16', '$NOTA', 'bash'
FROM organizational_units
WHERE legacy_table = '$LEGACY_TABLE'
  AND legacy_id = '$LEGACY_ID';

UPDATE organizational_units
SET lifecycle_status = 'suprimida',
    status = 'inactive',
    valid_to = '$FECHA',
    lifecycle_notes = '$NOTA',
    updated_at = NOW()
WHERE legacy_table = '$LEGACY_TABLE'
  AND legacy_id = '$LEGACY_ID';

COMMIT;

SELECT id, code, name, lifecycle_status, valid_from, valid_to, lifecycle_notes
FROM organizational_units
WHERE legacy_table = '$LEGACY_TABLE'
  AND legacy_id = '$LEGACY_ID';
SQL
