-- Vistas SQL para dashboard MOI 65.16.
-- Enfoque temporal: el tablero principal muestra la estructura vigente.
-- Ejecutar despues de database/versionado_estructura_moi.sql.

CREATE OR REPLACE VIEW vw_moi_resumen_general AS
SELECT
    (SELECT COUNT(*) FROM organizational_units WHERE lifecycle_status = 'vigente' AND (valid_to IS NULL OR valid_to >= CURRENT_DATE)) AS total_unidades,
    (SELECT COUNT(*) FROM organizational_units WHERE lifecycle_status = 'vigente' AND territorial_scope = 'nacional' AND (valid_to IS NULL OR valid_to >= CURRENT_DATE)) AS unidades_nacionales,
    (SELECT COUNT(*) FROM organizational_units WHERE lifecycle_status = 'vigente' AND territorial_scope = 'regional' AND (valid_to IS NULL OR valid_to >= CURRENT_DATE)) AS unidades_regionales,
    (SELECT COUNT(*) FROM organizational_units WHERE lifecycle_status = 'vigente' AND territorial_scope = 'zonal' AND (valid_to IS NULL OR valid_to >= CURRENT_DATE)) AS unidades_zonales,
    (SELECT COUNT(*) FROM organizational_units WHERE lifecycle_status = 'vigente' AND territorial_scope = 'area' AND (valid_to IS NULL OR valid_to >= CURRENT_DATE)) AS unidades_area,
    (SELECT COUNT(*) FROM organizational_units WHERE lifecycle_status = 'vigente' AND territorial_scope = 'local' AND (valid_to IS NULL OR valid_to >= CURRENT_DATE)) AS unidades_locales,
    (SELECT COUNT(*) FROM organizational_units WHERE lifecycle_status = 'vigente' AND facility_type_id IS NOT NULL AND (valid_to IS NULL OR valid_to >= CURRENT_DATE)) AS sedes_detectadas,
    (SELECT COUNT(*) FROM organizational_unit_relationships WHERE status = 'active' AND (valid_to IS NULL OR valid_to >= CURRENT_DATE)) AS relaciones_registradas,
    (SELECT COUNT(*) FROM organizational_units WHERE lifecycle_status <> 'vigente' OR (valid_to IS NOT NULL AND valid_to < CURRENT_DATE)) AS unidades_no_vigentes,
    (SELECT COUNT(*) FROM stg_unit_classification WHERE requires_review = TRUE) AS pendientes_revision,
    (SELECT COUNT(*) FROM stg_unit_classification WHERE requires_review = FALSE) AS aprobadas_revision;

CREATE OR REPLACE VIEW vw_moi_unidades_por_tipo AS
SELECT
    ut.name AS tipo_unidad,
    COUNT(ou.id) AS total
FROM unit_types ut
LEFT JOIN organizational_units ou
  ON ou.unit_type_id = ut.id
 AND ou.lifecycle_status = 'vigente'
 AND (ou.valid_to IS NULL OR ou.valid_to >= CURRENT_DATE)
GROUP BY ut.name
ORDER BY total DESC, ut.name;

CREATE OR REPLACE VIEW vw_moi_unidades_por_alcance AS
SELECT
    territorial_scope AS alcance,
    COUNT(*) AS total
FROM organizational_units
WHERE lifecycle_status = 'vigente'
  AND (valid_to IS NULL OR valid_to >= CURRENT_DATE)
GROUP BY territorial_scope
ORDER BY alcance;

CREATE OR REPLACE VIEW vw_moi_pendientes_revision AS
SELECT
    id,
    source_table,
    source_id,
    source_name,
    suggested_unit_type,
    suggested_scope,
    suggested_command_structure,
    suggested_command_relationship,
    confidence_level,
    review_notes
FROM stg_unit_classification
WHERE requires_review = TRUE
ORDER BY confidence_level, source_table, source_name;

CREATE OR REPLACE VIEW vw_moi_aprobadas_revision AS
SELECT
    id,
    source_table,
    source_id,
    source_name,
    suggested_unit_type,
    suggested_scope,
    suggested_command_structure,
    suggested_command_relationship,
    reviewed_by,
    reviewed_at
FROM stg_unit_classification
WHERE requires_review = FALSE
ORDER BY reviewed_at DESC, source_table, source_name;

CREATE OR REPLACE VIEW vw_moi_arbol_unidades AS
SELECT
    ou.id,
    ou.parent_id,
    parent.name AS unidad_superior,
    ou.code,
    ou.moi_code,
    ou.name,
    ut.name AS tipo_unidad,
    ou.territorial_scope,
    ou.command_structure,
    ou.command_relationship,
    ou.level,
    ou.moi_level,
    ou.valid_from,
    ou.valid_to,
    ou.lifecycle_status,
    ou.structure_source,
    ou.legacy_table,
    ou.legacy_id,
    ou.status
FROM organizational_units ou
LEFT JOIN organizational_units parent ON parent.id = ou.parent_id
LEFT JOIN unit_types ut ON ut.id = ou.unit_type_id
WHERE ou.lifecycle_status = 'vigente'
  AND (ou.valid_to IS NULL OR ou.valid_to >= CURRENT_DATE)
ORDER BY ou.moi_level, parent.name, ou.name;

CREATE OR REPLACE VIEW vw_moi_sedes AS
SELECT
    ou.id AS unidad_id,
    ou.name AS unidad,
    ft.name AS tipo_sede,
    l.address,
    l.reference,
    l.latitude,
    l.longitude,
    ul.is_main
FROM organizational_units ou
JOIN facility_types ft ON ft.id = ou.facility_type_id
LEFT JOIN unit_locations ul ON ul.organizational_unit_id = ou.id
LEFT JOIN locations l ON l.id = ul.location_id
WHERE ou.lifecycle_status = 'vigente'
  AND (ou.valid_to IS NULL OR ou.valid_to >= CURRENT_DATE)
ORDER BY ou.name;

CREATE OR REPLACE VIEW vw_moi_sedes_sin_ubicacion AS
SELECT
    ou.id,
    ou.code,
    ou.name,
    ft.name AS tipo_sede
FROM organizational_units ou
JOIN facility_types ft ON ft.id = ou.facility_type_id
LEFT JOIN unit_locations ul ON ul.organizational_unit_id = ou.id
WHERE ul.id IS NULL
  AND ou.lifecycle_status = 'vigente'
  AND (ou.valid_to IS NULL OR ou.valid_to >= CURRENT_DATE)
ORDER BY ou.name;

CREATE OR REPLACE VIEW vw_moi_unidades_sin_relacion_superior AS
SELECT
    ou.id,
    ou.code,
    ou.name,
    ut.name AS tipo_unidad,
    ou.territorial_scope,
    ou.legacy_table,
    ou.legacy_id
FROM organizational_units ou
LEFT JOIN unit_types ut ON ut.id = ou.unit_type_id
LEFT JOIN organizational_unit_relationships r
  ON r.source_unit_id = ou.id
 AND r.relationship_type = 'jerarquica'
 AND r.status = 'active'
 AND (r.valid_to IS NULL OR r.valid_to >= CURRENT_DATE)
WHERE ou.parent_id IS NULL
  AND r.id IS NULL
  AND ou.lifecycle_status = 'vigente'
  AND (ou.valid_to IS NULL OR ou.valid_to >= CURRENT_DATE)
ORDER BY ou.name;

CREATE OR REPLACE VIEW vw_moi_duplicados_nombre AS
SELECT
    name,
    COUNT(*) AS total
FROM organizational_units
WHERE lifecycle_status = 'vigente'
  AND (valid_to IS NULL OR valid_to >= CURRENT_DATE)
GROUP BY name
HAVING COUNT(*) > 1
ORDER BY total DESC, name;

CREATE OR REPLACE VIEW vw_moi_relaciones AS
SELECT
    r.id,
    origen.name AS unidad_origen,
    destino.name AS unidad_destino,
    r.relationship_type,
    r.status,
    r.valid_from,
    r.valid_to,
    r.notes
FROM organizational_unit_relationships r
JOIN organizational_units origen ON origen.id = r.source_unit_id
JOIN organizational_units destino ON destino.id = r.target_unit_id
WHERE r.status = 'active'
  AND (r.valid_to IS NULL OR r.valid_to >= CURRENT_DATE)
ORDER BY r.relationship_type, destino.name, origen.name;

CREATE OR REPLACE VIEW vw_moi_unidades_no_vigentes_dashboard AS
SELECT
    ou.id,
    ou.code,
    ou.name,
    ut.name AS tipo_unidad,
    ou.territorial_scope,
    ou.valid_from,
    ou.valid_to,
    ou.lifecycle_status,
    reemplazo.name AS unidad_reemplazo,
    ou.lifecycle_notes,
    ou.legacy_table,
    ou.legacy_id
FROM organizational_units ou
LEFT JOIN unit_types ut ON ut.id = ou.unit_type_id
LEFT JOIN organizational_units reemplazo ON reemplazo.id = ou.replacement_unit_id
WHERE ou.lifecycle_status <> 'vigente'
   OR (ou.valid_to IS NOT NULL AND ou.valid_to < CURRENT_DATE)
ORDER BY ou.valid_to DESC, ou.name;
