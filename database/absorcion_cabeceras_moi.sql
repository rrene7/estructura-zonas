-- Absorcion de cabeceras MOI.
-- Principio: las 18 zonas y 19 direcciones legitimas son cabeceras canonicas.
-- Las variantes antiguas o dependencias relacionadas se absorben por la cabecera vigente.
-- No se borra ni se modifica el legacy; se marca fusion/supresion con replacement_unit_id.

-- Requiere ejecutar antes:
-- database/versionado_estructura_moi.sql
-- database/zonas_cabecera_vigentes.sql
-- database/direcciones_cabecera_vigentes.sql

-- 1. Crear unidades canonicas de zonas cabecera.
INSERT INTO organizational_units
(parent_id, unit_type_id, code, moi_code, name, short_name, level, moi_level,
 is_operational, is_administrative, command_structure, command_relationship,
 territorial_scope, functional_axis, is_decision_center, is_operational_executor,
 facility_type_id, normative_version_id, verified_at, valid_from, valid_to,
 lifecycle_status, structure_source, legacy_frozen, replacement_unit_id, lifecycle_notes,
 status, legacy_table, legacy_id, created_at, updated_at)
SELECT
    NULL,
    ut.id,
    CONCAT('ZP-', LPAD(z.zone_number, 2, '0')),
    CONCAT('ZP-', LPAD(z.zone_number, 2, '0')),
    z.zone_label,
    LEFT(z.zone_label, 100),
    3,
    3,
    TRUE,
    TRUE,
    'mando_directo',
    'operacional',
    'zonal',
    NULL,
    TRUE,
    TRUE,
    NULL,
    nv.id,
    CURRENT_DATE,
    COALESCE(z.valid_from, nv.effective_date, CURRENT_DATE),
    NULL,
    'vigente',
    'moi_65_16',
    TRUE,
    NULL,
    'Cabecera canonica de zona vigente. Absorbe variantes heredadas relacionadas.',
    'active',
    'MOI_CABECERA_ZONA',
    CAST(z.zone_number AS CHAR),
    NOW(), NOW()
FROM moi_zonas_cabecera_vigentes z
JOIN unit_types ut ON ut.name = 'zona_policial'
LEFT JOIN organizational_normative_versions nv ON nv.code = 'MOI-65.16'
WHERE z.lifecycle_status = 'vigente'
  AND NOT EXISTS (
      SELECT 1 FROM organizational_units ou
      WHERE ou.legacy_table = 'MOI_CABECERA_ZONA'
        AND ou.legacy_id = CAST(z.zone_number AS CHAR)
  );

-- 2. Crear unidades canonicas de direcciones cabecera.
INSERT INTO organizational_units
(parent_id, unit_type_id, code, moi_code, name, short_name, level, moi_level,
 is_operational, is_administrative, command_structure, command_relationship,
 territorial_scope, functional_axis, is_decision_center, is_operational_executor,
 facility_type_id, normative_version_id, verified_at, valid_from, valid_to,
 lifecycle_status, structure_source, legacy_frozen, replacement_unit_id, lifecycle_notes,
 status, legacy_table, legacy_id, created_at, updated_at)
SELECT
    NULL,
    ut.id,
    CONCAT('DN-', LPAD(d.direction_number, 2, '0')),
    CONCAT('DN-', LPAD(d.direction_number, 2, '0')),
    d.direction_label,
    LEFT(d.direction_label, 100),
    CASE WHEN d.direction_number = 1 THEN 0 ELSE 1 END,
    CASE WHEN d.direction_number = 1 THEN 0 ELSE 1 END,
    TRUE,
    TRUE,
    CASE WHEN d.direction_number = 1 THEN 'mando_directo' ELSE 'linea_funcional' END,
    CASE WHEN d.direction_number = 1 THEN 'operacional' ELSE 'funcional' END,
    'nacional',
    NULL,
    TRUE,
    FALSE,
    NULL,
    nv.id,
    CURRENT_DATE,
    COALESCE(d.valid_from, nv.effective_date, CURRENT_DATE),
    NULL,
    'vigente',
    'moi_65_16',
    TRUE,
    NULL,
    'Cabecera canonica de direccion vigente. Absorbe variantes heredadas relacionadas.',
    'active',
    'MOI_CABECERA_DIRECCION',
    CAST(d.direction_number AS CHAR),
    NOW(), NOW()
FROM moi_direcciones_cabecera_vigentes d
JOIN unit_types ut ON ut.name = CASE WHEN d.direction_number = 1 THEN 'directorio_general' ELSE 'direccion_nacional' END
LEFT JOIN organizational_normative_versions nv ON nv.code = 'MOI-65.16'
WHERE d.lifecycle_status = 'vigente'
  AND NOT EXISTS (
      SELECT 1 FROM organizational_units ou
      WHERE ou.legacy_table = 'MOI_CABECERA_DIRECCION'
        AND ou.legacy_id = CAST(d.direction_number AS CHAR)
  );

-- 3. Enlaces aprobados catalogo -> unidad canonica.
UPDATE moi_zona_cabecera_unit_match
SET match_status = 'rechazado',
    match_reason = 'Reemplazado por cabecera canonica MOI.',
    reviewed_by = 'sistema',
    reviewed_at = NOW()
WHERE match_status = 'pendiente';

INSERT IGNORE INTO moi_zona_cabecera_unit_match
(zona_cabecera_id, organizational_unit_id, match_status, confidence_level, match_reason, reviewed_by, reviewed_at)
SELECT
    z.id,
    ou.id,
    'aprobado',
    'alto',
    'Cabecera canonica MOI aprobada automaticamente.',
    'sistema',
    NOW()
FROM moi_zonas_cabecera_vigentes z
JOIN organizational_units ou
  ON ou.legacy_table = 'MOI_CABECERA_ZONA'
 AND ou.legacy_id = CAST(z.zone_number AS CHAR);

UPDATE moi_direccion_cabecera_unit_match
SET match_status = 'rechazado',
    match_reason = 'Reemplazado por cabecera canonica MOI.',
    reviewed_by = 'sistema',
    reviewed_at = NOW()
WHERE match_status = 'pendiente';

INSERT IGNORE INTO moi_direccion_cabecera_unit_match
(direccion_cabecera_id, organizational_unit_id, match_status, confidence_level, match_reason, reviewed_by, reviewed_at)
SELECT
    d.id,
    ou.id,
    'aprobado',
    'alto',
    'Cabecera canonica MOI aprobada automaticamente.',
    'sistema',
    NOW()
FROM moi_direcciones_cabecera_vigentes d
JOIN organizational_units ou
  ON ou.legacy_table = 'MOI_CABECERA_DIRECCION'
 AND ou.legacy_id = CAST(d.direction_number AS CHAR);

-- 4. Mesa de absorcion.
CREATE TABLE IF NOT EXISTS moi_cabecera_absorption_review (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    absorption_type ENUM('zona','direccion') NOT NULL,
    catalog_number INT NOT NULL,
    absorber_unit_id BIGINT UNSIGNED NOT NULL,
    absorbed_unit_id BIGINT UNSIGNED NOT NULL,
    match_status ENUM('pendiente','aprobado','rechazado','aplicado') NOT NULL DEFAULT 'pendiente',
    confidence_level ENUM('alto','medio','bajo') NOT NULL DEFAULT 'medio',
    source_rule VARCHAR(120) NULL,
    review_reason VARCHAR(255) NULL,
    reviewed_by VARCHAR(100) NULL,
    reviewed_at TIMESTAMP NULL,
    applied_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_absorption_pair (absorber_unit_id, absorbed_unit_id),
    INDEX idx_absorption_type (absorption_type),
    INDEX idx_absorption_status (match_status),
    INDEX idx_absorption_absorber (absorber_unit_id),
    INDEX idx_absorption_absorbed (absorbed_unit_id),
    FOREIGN KEY (absorber_unit_id) REFERENCES organizational_units(id),
    FOREIGN KEY (absorbed_unit_id) REFERENCES organizational_units(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Candidatos de absorcion para zonas heredadas.
INSERT IGNORE INTO moi_cabecera_absorption_review
(absorption_type, catalog_number, absorber_unit_id, absorbed_unit_id, match_status, confidence_level, source_rule, review_reason)
SELECT
    'zona',
    z.zone_number,
    cab.id,
    old.id,
    'pendiente',
    CASE
        WHEN UPPER(old.name) REGEXP CONCAT('(^|[^0-9])', z.zone_number, '([^0-9]|$)') THEN 'alto'
        ELSE 'medio'
    END,
    CASE
        WHEN UPPER(old.name) REGEXP CONCAT('(^|[^0-9])', z.zone_number, '([^0-9]|$)') THEN 'zona_por_numero'
        ELSE 'zona_por_nombre'
    END,
    CONCAT('Absorber unidad heredada relacionada bajo cabecera vigente: ', z.zone_label)
FROM moi_zonas_cabecera_vigentes z
JOIN organizational_units cab
  ON cab.legacy_table = 'MOI_CABECERA_ZONA'
 AND cab.legacy_id = CAST(z.zone_number AS CHAR)
JOIN organizational_units old
JOIN unit_types ut ON ut.id = old.unit_type_id AND ut.name = 'zona_policial'
WHERE old.lifecycle_status = 'vigente'
  AND old.id <> cab.id
  AND old.legacy_table <> 'MOI_CABECERA_ZONA'
  AND (
      UPPER(old.name) REGEXP CONCAT('(^|[^0-9])', z.zone_number, '([^0-9]|$)')
      OR (z.zone_number <> 8 AND UPPER(old.name) LIKE CONCAT('%', z.normalized_name, '%'))
      OR (z.zone_number = 8 AND UPPER(old.name) LIKE '%ZONA%OESTE%' AND UPPER(old.name) NOT LIKE '%PANAMA OESTE%')
  );

-- 6. Candidatos de absorcion para direcciones heredadas.
INSERT IGNORE INTO moi_cabecera_absorption_review
(absorption_type, catalog_number, absorber_unit_id, absorbed_unit_id, match_status, confidence_level, source_rule, review_reason)
SELECT
    'direccion',
    d.direction_number,
    cab.id,
    old.id,
    'pendiente',
    CASE
        WHEN UPPER(old.name) = d.normalized_name THEN 'alto'
        WHEN UPPER(old.name) LIKE CONCAT('%', d.normalized_name, '%') THEN 'alto'
        ELSE 'medio'
    END,
    'direccion_por_nombre',
    CONCAT('Absorber unidad heredada relacionada bajo cabecera vigente: ', d.direction_label)
FROM moi_direcciones_cabecera_vigentes d
JOIN organizational_units cab
  ON cab.legacy_table = 'MOI_CABECERA_DIRECCION'
 AND cab.legacy_id = CAST(d.direction_number AS CHAR)
JOIN organizational_units old
JOIN unit_types ut ON ut.id = old.unit_type_id
WHERE old.lifecycle_status = 'vigente'
  AND old.id <> cab.id
  AND old.legacy_table <> 'MOI_CABECERA_DIRECCION'
  AND ut.name IN ('direccion_nacional','directorio_general','directorio_personal','directorio_coordinacion','directorio_especial','dependencia')
  AND (
      UPPER(old.name) = d.normalized_name
      OR UPPER(old.name) LIKE CONCAT('%', d.normalized_name, '%')
      OR d.normalized_name LIKE CONCAT('%', UPPER(old.name), '%')
  );

CREATE OR REPLACE VIEW vw_moi_absorcion_cabeceras AS
SELECT
    r.id,
    r.absorption_type,
    r.catalog_number,
    absorber.name AS cabecera_legitima,
    absorbed.id AS absorbed_unit_id,
    absorbed.code AS absorbed_code,
    absorbed.name AS unidad_a_absorber,
    ut.name AS absorbed_type,
    absorbed.legacy_table,
    absorbed.legacy_id,
    r.match_status,
    r.confidence_level,
    r.source_rule,
    r.review_reason,
    r.reviewed_by,
    r.reviewed_at,
    r.applied_at
FROM moi_cabecera_absorption_review r
JOIN organizational_units absorber ON absorber.id = r.absorber_unit_id
JOIN organizational_units absorbed ON absorbed.id = r.absorbed_unit_id
LEFT JOIN unit_types ut ON ut.id = absorbed.unit_type_id;

CREATE OR REPLACE VIEW vw_moi_absorcion_cabeceras_resumen AS
SELECT
    absorption_type,
    match_status,
    confidence_level,
    COUNT(*) AS total
FROM moi_cabecera_absorption_review
GROUP BY absorption_type, match_status, confidence_level
ORDER BY absorption_type, match_status, confidence_level;

CREATE OR REPLACE VIEW vw_moi_absorcion_cabeceras_pendientes AS
SELECT *
FROM vw_moi_absorcion_cabeceras
WHERE match_status = 'pendiente'
ORDER BY absorption_type, catalog_number, confidence_level DESC, unidad_a_absorber;
