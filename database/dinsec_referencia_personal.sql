-- Referencia de personal DINSEC desde documento PDF.
-- Paso 1: guardar lo que dice el documento sin modificar RRHH ni legacy.

CREATE TABLE IF NOT EXISTS dinsec_document_sources (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    document_name VARCHAR(180) NOT NULL,
    document_date DATE NULL,
    uploaded_file_name VARCHAR(220) NULL,
    notes VARCHAR(255) NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_dinsec_source (document_name, document_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO dinsec_document_sources
(document_name, document_date, uploaded_file_name, notes)
VALUES
('COMPOSICION OFICIALES DINSEC POR ZONAS Y SERVICIOS POLICIALES', '2025-08-04', 'COMPOSICIÓN OFICIALES 04AGO25 DINSEC.pdf', 'Documento de referencia para validacion de oficiales, zonas, areas, servicios y OP/NO OP.');

CREATE TABLE IF NOT EXISTS dinsec_personnel_reference (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_id BIGINT UNSIGNED NOT NULL,
    page_number INT NULL,
    row_number INT NULL,
    zone_label VARCHAR(180) NULL,
    zone_unit_id BIGINT UNSIGNED NULL,
    area_code VARCHAR(10) NULL,
    area_name VARCHAR(120) NULL,
    location_sector VARCHAR(180) NULL,
    direction_label VARCHAR(220) NULL,
    direction_unit_id BIGINT UNSIGNED NULL,
    service_label VARCHAR(180) NULL,
    op_status ENUM('OP','NO OP','OA','NO DEFINIDO') NOT NULL DEFAULT 'NO DEFINIDO',
    rank_text VARCHAR(80) NULL,
    position_number VARCHAR(50) NULL,
    full_name VARCHAR(180) NOT NULL,
    assignment_text VARCHAR(220) NULL,
    observation_text VARCHAR(220) NULL,
    raw_text TEXT NULL,
    matched_employee_id BIGINT UNSIGNED NULL,
    review_status ENUM('pendiente','validado','ignorado') NOT NULL DEFAULT 'pendiente',
    review_notes VARCHAR(255) NULL,
    created_by VARCHAR(100) NULL DEFAULT 'dashboard',
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_dinsec_source (source_id),
    INDEX idx_dinsec_zone (zone_unit_id),
    INDEX idx_dinsec_area (area_code, area_name),
    INDEX idx_dinsec_direction (direction_unit_id),
    INDEX idx_dinsec_position (position_number),
    INDEX idx_dinsec_name (full_name),
    INDEX idx_dinsec_review (review_status),
    CONSTRAINT fk_dinsec_reference_source FOREIGN KEY (source_id) REFERENCES dinsec_document_sources(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE OR REPLACE VIEW vw_dinsec_personal_resumen AS
SELECT
    COALESCE(zone_label, 'SIN ZONA') AS zona,
    COALESCE(area_code, '') AS area,
    COALESCE(area_name, '') AS area_nombre,
    COALESCE(location_sector, '') AS ubicacion_sector,
    op_status,
    review_status,
    COUNT(*) AS total
FROM dinsec_personnel_reference
GROUP BY zone_label, area_code, area_name, location_sector, op_status, review_status
ORDER BY zona, area, ubicacion_sector, op_status, review_status;

CREATE OR REPLACE VIEW vw_dinsec_personal_detalle AS
SELECT
    r.id,
    s.document_name,
    s.document_date,
    r.page_number,
    r.row_number,
    r.zone_label,
    r.area_code,
    r.area_name,
    r.location_sector,
    r.direction_label,
    r.service_label,
    r.op_status,
    r.rank_text,
    r.position_number,
    r.full_name,
    r.assignment_text,
    r.observation_text,
    r.review_status,
    r.review_notes,
    r.created_at
FROM dinsec_personnel_reference r
JOIN dinsec_document_sources s ON s.id = r.source_id;
