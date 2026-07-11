-- Marca como validado el personal DINSEC de Zona 1 cuando ya tiene vinculo activo.
-- No crea person_id ni toca rrhh2029. Solo cambia el estado de revision de la referencia DINSEC.

UPDATE dinsec_personnel_reference d
JOIN dinsec_personnel_unit_links l
  ON l.dinsec_personnel_reference_id=d.id
 AND l.status='active'
SET d.review_status='validado',
    d.review_notes='Aplicado y vinculado a estructura de trabajo Zona 1 desde DINSEC 04AGO2025',
    d.updated_at=NOW()
WHERE d.zone_label='1 Zona Policial - Bocas del Toro';

INSERT INTO moi_zone_apply_audit (zone_number, zone_label, action_name, affected_rows, notes)
SELECT 1, '1 Zona Policial - Bocas del Toro', 'validar_personal_dinsec_zona_01', COUNT(*), 'Referencias DINSEC marcadas como validadas por tener vinculo activo'
FROM dinsec_personnel_reference d
JOIN dinsec_personnel_unit_links l
  ON l.dinsec_personnel_reference_id=d.id
 AND l.status='active'
WHERE d.zone_label='1 Zona Policial - Bocas del Toro'
  AND d.review_status='validado';
