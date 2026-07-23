-- Autorizar que las zonas policiales dependan de la Dirección Nacional de Operaciones Policiales configurada.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

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
    parent_unit.name AS unidad_superior,
    parent_type.name AS tipo_superior,
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
