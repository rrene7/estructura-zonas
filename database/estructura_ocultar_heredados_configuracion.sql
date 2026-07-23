-- Ocultar registros heredados y variantes antiguas del módulo de configuración.
--
-- Los registros no se eliminan ni pierden sus relaciones o códigos internos.
-- Solamente dejan de aparecer en vw_structure_admin_units, utilizada por
-- dashboard/estructura_admin.php para la administración de la estructura vigente.
--
-- Ejecutar después de database/estructura_codigos_internos_y_padre_zonas.sql.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE OR REPLACE VIEW vw_structure_admin_units AS
SELECT
    unit.id,
    unit.parent_id,
    unit.unit_type_id,
    unit_type.name AS unit_type_name,
    unit_type.description AS unit_type_description,
    unit.system_code,
    unit.system_code AS code,
    unit.code AS institutional_code,
    unit.moi_code,
    unit.name,
    unit.short_name,
    unit.level,
    unit.moi_level,
    unit.is_operational,
    unit.is_administrative,
    unit.command_structure,
    unit.command_relationship,
    unit.territorial_scope,
    unit.functional_axis,
    unit.is_decision_center,
    unit.is_operational_executor,
    unit.facility_type_id,
    unit.normative_version_id,
    unit.verified_at,
    unit.valid_from,
    unit.valid_to,
    unit.lifecycle_status,
    unit.structure_source,
    unit.legacy_frozen,
    unit.replacement_unit_id,
    unit.lifecycle_notes,
    unit.status,
    unit.legacy_table,
    unit.legacy_id,
    unit.created_at,
    unit.updated_at,
    parent.name AS parent_name,
    parent.system_code AS parent_code,
    parent.code AS parent_institutional_code,
    0 AS is_protected,
    CASE
        WHEN unit.status = 'active' AND unit.lifecycle_status = 'vigente' THEN 'Activa'
        WHEN unit.lifecycle_status = 'suprimida' THEN 'Suprimida'
        WHEN unit.lifecycle_status = 'fusionada' THEN 'Fusionada'
        WHEN unit.lifecycle_status = 'renombrada' THEN 'Renombrada'
        WHEN unit.lifecycle_status = 'pendiente_validacion' THEN 'Pendiente de validación'
        ELSE 'Inactiva'
    END AS status_label,
    (
        SELECT COUNT(*)
        FROM organizational_units child
        WHERE child.parent_id = unit.id
          AND UPPER(TRIM(COALESCE(child.legacy_table, ''))) <> 'TABCUAR'
          AND COALESCE(child.structure_source, '') <> 'legacy'
    ) AS child_count,
    (
        SELECT COUNT(*)
        FROM organizational_units child
        WHERE child.parent_id = unit.id
          AND child.status = 'active'
          AND child.lifecycle_status = 'vigente'
          AND UPPER(TRIM(COALESCE(child.legacy_table, ''))) <> 'TABCUAR'
          AND COALESCE(child.structure_source, '') <> 'legacy'
    ) AS active_child_count,
    (
        SELECT COUNT(*)
        FROM workforce_unit_matches match_row
        WHERE match_row.matched_unit_id = unit.id
    ) AS workforce_count
FROM organizational_units unit
JOIN unit_types unit_type
  ON unit_type.id = unit.unit_type_id
LEFT JOIN organizational_units parent
  ON parent.id = unit.parent_id
WHERE UPPER(TRIM(COALESCE(unit.legacy_table, ''))) <> 'TABCUAR'
  AND COALESCE(unit.structure_source, '') <> 'legacy';

SELECT
    COUNT(*) AS unidades_visibles_configuracion,
    SUM(unit_type_name IN ('zona_policial', 'region_policial')) AS zonas_visibles,
    SUM(unit_type_name IN ('direccion_nacional', 'subdireccion_nacional', 'directorio_general')) AS direcciones_visibles,
    SUM(unit_type_name IN ('servicio_policial', 'servicio_zonal')) AS servicios_visibles
FROM vw_structure_admin_units;

SELECT
    COUNT(*) AS registros_heredados_ocultos
FROM organizational_units unit
WHERE UPPER(TRIM(COALESCE(unit.legacy_table, ''))) = 'TABCUAR'
   OR COALESCE(unit.structure_source, '') = 'legacy';
