-- Versionado temporal para estructura MOI.
-- Principio: el dato heredado no se modifica. La nueva estructura rige desde su fecha efectiva.

ALTER TABLE organizational_units
    ADD COLUMN IF NOT EXISTS valid_from DATE NULL AFTER verified_at,
    ADD COLUMN IF NOT EXISTS valid_to DATE NULL AFTER valid_from,
    ADD COLUMN IF NOT EXISTS lifecycle_status ENUM('vigente','suprimida','fusionada','renombrada','pendiente_validacion') NOT NULL DEFAULT 'vigente' AFTER valid_to,
    ADD COLUMN IF NOT EXISTS structure_source ENUM('legacy','moi_65_16','accion_posterior') NOT NULL DEFAULT 'moi_65_16' AFTER lifecycle_status,
    ADD COLUMN IF NOT EXISTS legacy_frozen BOOLEAN NOT NULL DEFAULT TRUE AFTER structure_source,
    ADD COLUMN IF NOT EXISTS replacement_unit_id BIGINT UNSIGNED NULL AFTER legacy_frozen,
    ADD COLUMN IF NOT EXISTS lifecycle_notes VARCHAR(255) NULL AFTER replacement_unit_id,
    ADD INDEX IF NOT EXISTS idx_org_units_vigencia (valid_from, valid_to),
    ADD INDEX IF NOT EXISTS idx_org_units_lifecycle (lifecycle_status);

CREATE TABLE IF NOT EXISTS organizational_unit_lifecycle_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organizational_unit_id BIGINT UNSIGNED NOT NULL,
    event_type ENUM('creacion','actualizacion','supresion','fusion','renombre','reactivacion') NOT NULL,
    effective_from DATE NOT NULL,
    effective_to DATE NULL,
    replacement_unit_id BIGINT UNSIGNED NULL,
    source_document VARCHAR(150) NULL,
    notes VARCHAR(255) NULL,
    created_by VARCHAR(100) NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (organizational_unit_id) REFERENCES organizational_units(id),
    FOREIGN KEY (replacement_unit_id) REFERENCES organizational_units(id),
    INDEX idx_lifecycle_unit (organizational_unit_id),
    INDEX idx_lifecycle_dates (effective_from, effective_to),
    INDEX idx_lifecycle_type (event_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS structure_action_routing (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    action_type ENUM('estado','traslado','vacaciones','nombramiento','asignacion','otro') NOT NULL,
    person_legacy_id VARCHAR(100) NULL,
    old_unit_id BIGINT UNSIGNED NULL,
    new_unit_id BIGINT UNSIGNED NULL,
    effective_from DATE NOT NULL,
    effective_to DATE NULL,
    source_table VARCHAR(100) NULL,
    source_id VARCHAR(100) NULL,
    notes VARCHAR(255) NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (old_unit_id) REFERENCES organizational_units(id),
    FOREIGN KEY (new_unit_id) REFERENCES organizational_units(id),
    INDEX idx_action_person (person_legacy_id),
    INDEX idx_action_dates (effective_from, effective_to),
    INDEX idx_action_units (old_unit_id, new_unit_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE organizational_units
SET valid_from = COALESCE(valid_from, '2026-01-14'),
    lifecycle_status = COALESCE(lifecycle_status, 'vigente'),
    structure_source = COALESCE(structure_source, 'moi_65_16'),
    legacy_frozen = TRUE
WHERE normative_version_id IS NOT NULL;

CREATE OR REPLACE VIEW vw_moi_unidades_vigentes AS
SELECT *
FROM organizational_units
WHERE lifecycle_status = 'vigente'
  AND (valid_from IS NULL OR valid_from <= CURRENT_DATE)
  AND (valid_to IS NULL OR valid_to >= CURRENT_DATE);

CREATE OR REPLACE VIEW vw_moi_unidades_no_vigentes AS
SELECT *
FROM organizational_units
WHERE lifecycle_status <> 'vigente'
   OR (valid_to IS NOT NULL AND valid_to < CURRENT_DATE);

CREATE OR REPLACE VIEW vw_moi_acciones_posteriores AS
SELECT
    r.id,
    r.action_type,
    r.person_legacy_id,
    old_unit.name AS unidad_anterior,
    new_unit.name AS unidad_nueva,
    r.effective_from,
    r.effective_to,
    r.source_table,
    r.source_id,
    r.notes
FROM structure_action_routing r
LEFT JOIN organizational_units old_unit ON old_unit.id = r.old_unit_id
LEFT JOIN organizational_units new_unit ON new_unit.id = r.new_unit_id;
