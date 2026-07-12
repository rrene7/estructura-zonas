-- Modulo de importacion y asignacion del PIE DE FUERZA 26-6-2026.
-- Regla principal: este modulo NUNCA crea ni modifica organizational_units.
-- Solo vincula personal a unidades vigentes existentes y deja lo ambiguo en revision.

CREATE TABLE IF NOT EXISTS workforce_sources (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_key VARCHAR(80) NOT NULL,
    document_name VARCHAR(180) NOT NULL,
    document_date DATE NULL,
    sheet_name VARCHAR(180) NULL,
    uploaded_file_name VARCHAR(220) NULL,
    total_rows INT NOT NULL DEFAULT 0,
    source_status ENUM('cargado','procesado','archivado') NOT NULL DEFAULT 'cargado',
    notes VARCHAR(255) NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_workforce_source_key (source_key),
    INDEX idx_workforce_source_date (document_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS workforce_personnel_staging (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_id BIGINT UNSIGNED NOT NULL,
    row_number INT NOT NULL,
    rank_text VARCHAR(100) NULL,
    position_number VARCHAR(60) NULL,
    first_name VARCHAR(140) NULL,
    last_name VARCHAR(140) NULL,
    full_name VARCHAR(240) NOT NULL,
    location_original VARCHAR(500) NULL,
    location_normalized VARCHAR(500) NULL,
    police_type_original VARCHAR(80) NULL,
    raw_data_json LONGTEXT NULL,
    import_status ENUM('importado','omitido','error') NOT NULL DEFAULT 'importado',
    import_notes VARCHAR(255) NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_workforce_source_row (source_id, row_number),
    INDEX idx_workforce_position (position_number),
    INDEX idx_workforce_name (full_name),
    INDEX idx_workforce_location (location_normalized(191)),
    CONSTRAINT fk_workforce_person_source
        FOREIGN KEY (source_id) REFERENCES workforce_sources(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS workforce_unit_matches (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    personnel_staging_id BIGINT UNSIGNED NOT NULL,
    matched_unit_id BIGINT UNSIGNED NULL,
    matched_level ENUM('zona','direccion','area','dependencia','servicio','unidad','otro','ninguno') NOT NULL DEFAULT 'ninguno',
    assignment_status ENUM('asignado_completo','asignado_parcial','pendiente_revision','sin_coincidencia') NOT NULL DEFAULT 'pendiente_revision',
    pending_level VARCHAR(120) NULL,
    match_method VARCHAR(80) NULL,
    confidence_level ENUM('alto','medio','bajo') NOT NULL DEFAULT 'bajo',
    candidate_count INT NOT NULL DEFAULT 0,
    candidate_data LONGTEXT NULL,
    review_status ENUM('automatico','pendiente','aprobado','rechazado') NOT NULL DEFAULT 'pendiente',
    review_notes VARCHAR(500) NULL,
    reviewed_by VARCHAR(120) NULL,
    reviewed_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_workforce_person_match (personnel_staging_id),
    INDEX idx_workforce_match_unit (matched_unit_id),
    INDEX idx_workforce_match_status (assignment_status, review_status),
    INDEX idx_workforce_match_level (matched_level),
    CONSTRAINT fk_workforce_match_person
        FOREIGN KEY (personnel_staging_id) REFERENCES workforce_personnel_staging(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_workforce_match_unit
        FOREIGN KEY (matched_unit_id) REFERENCES organizational_units(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE OR REPLACE VIEW vw_workforce_match_detail AS
SELECT
    p.id AS personnel_staging_id,
    p.source_id,
    s.source_key,
    s.document_name,
    s.document_date,
    s.sheet_name,
    p.row_number,
    p.rank_text,
    p.position_number,
    p.first_name,
    p.last_name,
    p.full_name,
    p.location_original,
    p.location_normalized,
    p.police_type_original,
    m.id AS match_id,
    m.matched_unit_id,
    m.matched_level,
    m.assignment_status,
    m.pending_level,
    m.match_method,
    m.confidence_level,
    m.candidate_count,
    m.review_status,
    m.review_notes,
    m.reviewed_by,
    m.reviewed_at,
    ou.name AS matched_unit_name,
    ou.code AS matched_unit_code,
    ou.legacy_table AS matched_unit_legacy_table,
    ou.legacy_id AS matched_unit_legacy_id,
    ut.name AS matched_unit_type,
    parent.id AS parent_unit_id,
    parent.name AS parent_unit_name
FROM workforce_personnel_staging p
JOIN workforce_sources s ON s.id = p.source_id
LEFT JOIN workforce_unit_matches m ON m.personnel_staging_id = p.id
LEFT JOIN organizational_units ou ON ou.id = m.matched_unit_id
LEFT JOIN unit_types ut ON ut.id = ou.unit_type_id
LEFT JOIN organizational_units parent ON parent.id = ou.parent_id;

CREATE OR REPLACE VIEW vw_workforce_summary AS
SELECT
    s.id AS source_id,
    s.source_key,
    s.document_name,
    s.document_date,
    s.sheet_name,
    COUNT(p.id) AS total_personas,
    SUM(CASE WHEN m.assignment_status = 'asignado_completo' THEN 1 ELSE 0 END) AS asignados_completos,
    SUM(CASE WHEN m.assignment_status = 'asignado_parcial' THEN 1 ELSE 0 END) AS asignados_parciales,
    SUM(CASE WHEN m.assignment_status = 'pendiente_revision' OR m.id IS NULL THEN 1 ELSE 0 END) AS pendientes_revision,
    SUM(CASE WHEN m.assignment_status = 'sin_coincidencia' THEN 1 ELSE 0 END) AS sin_coincidencia
FROM workforce_sources s
LEFT JOIN workforce_personnel_staging p ON p.source_id = s.id AND p.import_status = 'importado'
LEFT JOIN workforce_unit_matches m ON m.personnel_staging_id = p.id
GROUP BY s.id, s.source_key, s.document_name, s.document_date, s.sheet_name;
