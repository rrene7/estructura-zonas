-- Migracion final desde stg_unit_classification hacia modelo normalizado.
-- Ejecutar solo despues de revisar manualmente la clasificacion.

START TRANSACTION;

INSERT INTO organizational_units
(parent_id, unit_type_id, code, moi_code, name, short_name, level, moi_level,
 is_operational, is_administrative, command_structure, command_relationship,
 territorial_scope, functional_axis, is_decision_center, is_operational_executor,
 facility_type_id, normative_version_id, verified_at, status, legacy_table, legacy_id,
 created_at, updated_at)
SELECT
    NULL,
    ut.id,
    c.source_id,
    c.source_id,
    c.source_name,
    LEFT(c.source_name, 100),
    CASE
        WHEN c.suggested_scope = 'nacional' THEN 1
        WHEN c.suggested_scope = 'regional' THEN 2
        WHEN c.suggested_scope = 'zonal' THEN 3
        WHEN c.suggested_scope = 'area' THEN 4
        WHEN c.suggested_scope = 'local' THEN 5
        ELSE NULL
    END,
    CASE
        WHEN c.suggested_scope = 'nacional' THEN 1
        WHEN c.suggested_scope = 'regional' THEN 2
        WHEN c.suggested_scope = 'zonal' THEN 3
        WHEN c.suggested_scope = 'area' THEN 4
        WHEN c.suggested_scope = 'local' THEN 5
        ELSE NULL
    END,
    CASE WHEN c.suggested_command_structure = 'mando_directo' THEN TRUE ELSE FALSE END,
    TRUE,
    c.suggested_command_structure,
    c.suggested_command_relationship,
    c.suggested_scope,
    NULL,
    CASE WHEN c.suggested_scope IN ('nacional','regional','zonal') THEN TRUE ELSE FALSE END,
    CASE WHEN c.suggested_scope IN ('zonal','area','local','especializado') THEN TRUE ELSE FALSE END,
    ft.id,
    nv.id,
    COALESCE(DATE(c.reviewed_at), CURRENT_DATE),
    'active',
    c.source_table,
    c.source_id,
    NOW(), NOW()
FROM stg_unit_classification c
JOIN unit_types ut ON ut.name = c.suggested_unit_type
LEFT JOIN facility_types ft ON ft.code = c.suggested_facility_type
LEFT JOIN organizational_normative_versions nv ON nv.code = 'MOI-65.16'
LEFT JOIN organizational_units existing
  ON existing.legacy_table = c.source_table
 AND existing.legacy_id = c.source_id
WHERE c.requires_review = FALSE
  AND c.source_name IS NOT NULL
  AND existing.id IS NULL;

INSERT INTO locations
(address, reference, status, legacy_table, legacy_id, created_at, updated_at)
SELECT
    c.source_name,
    c.source_name,
    'active',
    c.source_table,
    c.source_id,
    NOW(), NOW()
FROM stg_unit_classification c
LEFT JOIN locations l
  ON l.legacy_table = c.source_table
 AND l.legacy_id = c.source_id
WHERE c.requires_review = FALSE
  AND c.suggested_facility_type IS NOT NULL
  AND l.id IS NULL;

INSERT INTO unit_locations
(organizational_unit_id, location_id, is_main, valid_from, valid_to, created_at, updated_at)
SELECT
    ou.id,
    l.id,
    TRUE,
    CURRENT_DATE,
    NULL,
    NOW(), NOW()
FROM stg_unit_classification c
JOIN organizational_units ou
  ON ou.legacy_table = c.source_table
 AND ou.legacy_id = c.source_id
JOIN locations l
  ON l.legacy_table = c.source_table
 AND l.legacy_id = c.source_id
LEFT JOIN unit_locations ul
  ON ul.organizational_unit_id = ou.id
 AND ul.location_id = l.id
WHERE c.requires_review = FALSE
  AND c.suggested_facility_type IS NOT NULL
  AND ul.id IS NULL;

INSERT INTO organizational_unit_relationships
(source_unit_id, target_unit_id, relationship_type, valid_from, valid_to, status, notes, created_at, updated_at)
SELECT
    child.id,
    parent.id,
    'jerarquica',
    CURRENT_DATE,
    NULL,
    'active',
    'Relacion creada desde parent_id',
    NOW(), NOW()
FROM organizational_units child
JOIN organizational_units parent ON parent.id = child.parent_id
LEFT JOIN organizational_unit_relationships r
  ON r.source_unit_id = child.id
 AND r.target_unit_id = parent.id
 AND r.relationship_type = 'jerarquica'
WHERE child.parent_id IS NOT NULL
  AND r.id IS NULL;

COMMIT;

-- Control posterior
-- SELECT COUNT(*) FROM organizational_units WHERE normative_version_id IS NOT NULL;
-- SELECT territorial_scope, COUNT(*) FROM organizational_units GROUP BY territorial_scope;
-- SELECT legacy_table, COUNT(*) FROM organizational_units GROUP BY legacy_table;
