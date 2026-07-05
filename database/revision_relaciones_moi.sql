-- Revision de relaciones jerarquicas MOI.
-- Objetivo: sugerir relaciones sin alterar legacy ni aplicar cambios automaticamente.

-- Crear unidad raiz institucional para la estructura vigente, si no existe.
INSERT INTO organizational_units
(parent_id, unit_type_id, code, moi_code, name, short_name, level, moi_level,
 is_operational, is_administrative, command_structure, command_relationship,
 territorial_scope, functional_axis, is_decision_center, is_operational_executor,
 facility_type_id, normative_version_id, verified_at, valid_from, lifecycle_status,
 structure_source, legacy_frozen, status, legacy_table, legacy_id, created_at, updated_at)
SELECT
    NULL,
    ut.id,
    'PN',
    'PN',
    'POLICIA NACIONAL',
    'PN',
    0,
    0,
    TRUE,
    TRUE,
    'mando_directo',
    'operacional',
    'nacional',
    NULL,
    TRUE,
    FALSE,
    NULL,
    nv.id,
    CURRENT_DATE,
    COALESCE(nv.effective_date, CURRENT_DATE),
    'vigente',
    'moi_65_16',
    TRUE,
    'active',
    'SISTEMA',
    'ROOT',
    NOW(), NOW()
FROM unit_types ut
LEFT JOIN organizational_normative_versions nv ON nv.code = 'MOI-65.16'
WHERE ut.name = 'institucion'
  AND NOT EXISTS (
      SELECT 1 FROM organizational_units WHERE legacy_table = 'SISTEMA' AND legacy_id = 'ROOT'
  );

CREATE TABLE IF NOT EXISTS moi_unit_relationship_review (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    child_unit_id BIGINT UNSIGNED NOT NULL,
    parent_unit_id BIGINT UNSIGNED NULL,
    relationship_type ENUM('jerarquica','funcional','operacional','tactica','administrativa','ubicacion_fisica','apoyo_tecnico') NOT NULL DEFAULT 'jerarquica',
    confidence_level ENUM('alto','medio','bajo') NOT NULL DEFAULT 'bajo',
    source_rule VARCHAR(100) NULL,
    review_reason VARCHAR(255) NULL,
    decision_status ENUM('pendiente','aprobado','rechazado') NOT NULL DEFAULT 'pendiente',
    reviewed_by VARCHAR(100) NULL,
    reviewed_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_rel_review (child_unit_id, parent_unit_id, relationship_type),
    INDEX idx_rel_review_child (child_unit_id),
    INDEX idx_rel_review_parent (parent_unit_id),
    INDEX idx_rel_review_decision (decision_status),
    INDEX idx_rel_review_confidence (confidence_level),
    FOREIGN KEY (child_unit_id) REFERENCES organizational_units(id),
    FOREIGN KEY (parent_unit_id) REFERENCES organizational_units(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 1. Nivel superior contra raiz institucional.
INSERT IGNORE INTO moi_unit_relationship_review
(child_unit_id, parent_unit_id, relationship_type, confidence_level, source_rule, review_reason)
SELECT
    child.id,
    root.id,
    'jerarquica',
    'medio',
    'nivel_superior_a_raiz',
    'Unidad de nivel superior sugerida bajo raiz institucional.'
FROM organizational_units child
JOIN unit_types ut ON ut.id = child.unit_type_id
JOIN organizational_units root ON root.legacy_table = 'SISTEMA' AND root.legacy_id = 'ROOT'
LEFT JOIN organizational_unit_relationships rel
  ON rel.source_unit_id = child.id
 AND rel.relationship_type = 'jerarquica'
 AND rel.status = 'active'
WHERE child.id <> root.id
  AND child.lifecycle_status = 'vigente'
  AND ut.name IN ('direccion_nacional','region_policial','servicio_policial')
  AND rel.id IS NULL;

-- 2. Areas hacia zonas por prefijo ordinal del nombre: 10MA, 11MA, 12VA, etc.
INSERT IGNORE INTO moi_unit_relationship_review
(child_unit_id, parent_unit_id, relationship_type, confidence_level, source_rule, review_reason)
SELECT
    area.id,
    zona.id,
    'jerarquica',
    'medio',
    'area_a_zona_por_prefijo_nombre',
    'Area sugerida bajo zona por coincidencia de prefijo ordinal en el nombre.'
FROM organizational_units area
JOIN unit_types area_type ON area_type.id = area.unit_type_id AND area_type.name = 'area_policial'
JOIN organizational_units zona ON zona.lifecycle_status = 'vigente'
JOIN unit_types zona_type ON zona_type.id = zona.unit_type_id AND zona_type.name = 'zona_policial'
LEFT JOIN organizational_unit_relationships rel
  ON rel.source_unit_id = area.id
 AND rel.relationship_type = 'jerarquica'
 AND rel.status = 'active'
WHERE area.lifecycle_status = 'vigente'
  AND area.id <> zona.id
  AND REPLACE(REPLACE(UPPER(SUBSTRING_INDEX(area.name, ' ', 1)), '.', ''), ',', '') =
      REPLACE(REPLACE(UPPER(SUBSTRING_INDEX(zona.name, ' ', 1)), '.', ''), ',', '')
  AND rel.id IS NULL;

-- 3. Unidades vigentes sin candidato de superior quedan visibles para revision manual.
INSERT IGNORE INTO moi_unit_relationship_review
(child_unit_id, parent_unit_id, relationship_type, confidence_level, source_rule, review_reason)
SELECT
    ou.id,
    NULL,
    'jerarquica',
    'bajo',
    'sin_candidato_automatico',
    'No se detecto superior automatico. Requiere revision manual.'
FROM organizational_units ou
LEFT JOIN organizational_units root ON root.legacy_table = 'SISTEMA' AND root.legacy_id = 'ROOT'
LEFT JOIN organizational_unit_relationships rel
  ON rel.source_unit_id = ou.id
 AND rel.relationship_type = 'jerarquica'
 AND rel.status = 'active'
LEFT JOIN moi_unit_relationship_review rr
  ON rr.child_unit_id = ou.id
WHERE ou.lifecycle_status = 'vigente'
  AND (root.id IS NULL OR ou.id <> root.id)
  AND rel.id IS NULL
  AND rr.id IS NULL;

CREATE OR REPLACE VIEW vw_moi_revision_relaciones AS
SELECT
    r.id,
    r.child_unit_id,
    child.code AS child_code,
    child.name AS child_name,
    child_type.name AS child_type,
    child.territorial_scope AS child_scope,
    r.parent_unit_id,
    parent.code AS parent_code,
    parent.name AS parent_name,
    parent_type.name AS parent_type,
    r.relationship_type,
    r.confidence_level,
    r.source_rule,
    r.review_reason,
    r.decision_status,
    r.reviewed_by,
    r.reviewed_at
FROM moi_unit_relationship_review r
JOIN organizational_units child ON child.id = r.child_unit_id
LEFT JOIN unit_types child_type ON child_type.id = child.unit_type_id
LEFT JOIN organizational_units parent ON parent.id = r.parent_unit_id
LEFT JOIN unit_types parent_type ON parent_type.id = parent.unit_type_id;

CREATE OR REPLACE VIEW vw_moi_revision_relaciones_resumen AS
SELECT
    source_rule,
    confidence_level,
    decision_status,
    COUNT(*) AS total
FROM moi_unit_relationship_review
GROUP BY source_rule, confidence_level, decision_status
ORDER BY source_rule, confidence_level, decision_status;

CREATE OR REPLACE VIEW vw_moi_revision_relaciones_pendientes AS
SELECT *
FROM vw_moi_revision_relaciones
WHERE decision_status = 'pendiente'
ORDER BY confidence_level DESC, source_rule, child_name;
