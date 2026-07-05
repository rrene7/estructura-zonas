-- Aplica absorciones aprobadas de cabeceras MOI.
-- No borra legacy. Marca unidad heredada como fusionada y apunta replacement_unit_id a la cabecera legitima.

START TRANSACTION;

INSERT INTO organizational_unit_lifecycle_events
(organizational_unit_id, event_type, effective_from, effective_to, replacement_unit_id, source_document, notes, created_by)
SELECT
    r.absorbed_unit_id,
    'fusion',
    CURRENT_DATE,
    CURRENT_DATE,
    r.absorber_unit_id,
    'MOI-65.16',
    r.review_reason,
    COALESCE(r.reviewed_by, 'sistema')
FROM moi_cabecera_absorption_review r
WHERE r.match_status = 'aprobado'
  AND NOT EXISTS (
      SELECT 1
      FROM organizational_unit_lifecycle_events e
      WHERE e.organizational_unit_id = r.absorbed_unit_id
        AND e.event_type = 'fusion'
        AND e.replacement_unit_id = r.absorber_unit_id
  );

UPDATE organizational_units absorbed
JOIN moi_cabecera_absorption_review r ON r.absorbed_unit_id = absorbed.id
SET absorbed.lifecycle_status = 'fusionada',
    absorbed.status = 'inactive',
    absorbed.valid_to = CURRENT_DATE,
    absorbed.replacement_unit_id = r.absorber_unit_id,
    absorbed.lifecycle_notes = r.review_reason,
    absorbed.updated_at = NOW(),
    r.match_status = 'aplicado',
    r.applied_at = NOW(),
    r.updated_at = NOW()
WHERE r.match_status = 'aprobado';

COMMIT;

SELECT * FROM vw_moi_absorcion_cabeceras_resumen;
