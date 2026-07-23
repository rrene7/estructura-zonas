-- Resolver y autorizar la unidad superior oficial de las zonas policiales.
-- Se usa el registro activo y vigente llamado Dirección Nacional de Operaciones Policiales,
-- aunque su tipo o procedencia histórica sea diferente.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

INSERT INTO structure_default_parents (
    group_key,
    parent_unit_id,
    description
)
SELECT
    'zonas',
    candidate.id,
    'Dirección Nacional de Operaciones Policiales vigente'
FROM organizational_units candidate
WHERE candidate.status = 'active'
  AND candidate.lifecycle_status = 'vigente'
  AND (
        candidate.name COLLATE utf8mb4_unicode_ci = 'Dirección Nacional de Operaciones Policiales'
        OR candidate.name COLLATE utf8mb4_unicode_ci = 'Direccion Nacional de Operaciones Policiales'
        OR candidate.name COLLATE utf8mb4_unicode_ci LIKE '%Dirección%Operaciones Policiales%'
        OR candidate.name COLLATE utf8mb4_unicode_ci LIKE '%Direccion%Operaciones Policiales%'
        OR candidate.name COLLATE utf8mb4_unicode_ci LIKE '%OPERACIONES POLICIALES%'
  )
ORDER BY
    CASE candidate.structure_source
        WHEN 'accion_posterior' THEN 1
        WHEN 'moi_65_16' THEN 2
        ELSE 3
    END,
    CASE WHEN COALESCE(candidate.legacy_frozen, 0) = 0 THEN 1 ELSE 2 END,
    CASE
        WHEN candidate.name COLLATE utf8mb4_unicode_ci = 'Dirección Nacional de Operaciones Policiales' THEN 1
        WHEN candidate.name COLLATE utf8mb4_unicode_ci = 'Direccion Nacional de Operaciones Policiales' THEN 2
        ELSE 3
    END,
    candidate.id
LIMIT 1
ON DUPLICATE KEY UPDATE
    parent_unit_id = VALUES(parent_unit_id),
    description = VALUES(description),
    updated_at = CURRENT_TIMESTAMP;

INSERT INTO structure_unit_type_rules (
    parent_type_id,
    child_type_id,
    is_allowed,
    notes
)
SELECT
    parent_unit.unit_type_id,
    child_type.id,
    1,
    'Las zonas policiales dependen de la Dirección Nacional de Operaciones Policiales'
FROM structure_default_parents parent_config
JOIN organizational_units parent_unit
  ON parent_unit.id = parent_config.parent_unit_id
JOIN unit_types child_type
  ON child_type.name IN ('zona_policial', 'region_policial')
WHERE parent_config.group_key = 'zonas'
ON DUPLICATE KEY UPDATE
    is_allowed = 1,
    notes = VALUES(notes),
    updated_at = CURRENT_TIMESTAMP;

SELECT
    parent_unit.id AS unidad_superior_id,
    parent_unit.name AS unidad_superior,
    parent_type.name AS tipo_superior,
    parent_unit.system_code AS codigo_interno,
    child_type.name AS tipo_permitido,
    rule_row.is_allowed AS permitido
FROM structure_default_parents parent_config
JOIN organizational_units parent_unit
  ON parent_unit.id = parent_config.parent_unit_id
JOIN unit_types parent_type
  ON parent_type.id = parent_unit.unit_type_id
JOIN structure_unit_type_rules rule_row
  ON rule_row.parent_type_id = parent_unit.unit_type_id
JOIN unit_types child_type
  ON child_type.id = rule_row.child_type_id
WHERE parent_config.group_key = 'zonas'
  AND child_type.name IN ('zona_policial', 'region_policial')
ORDER BY child_type.name;
