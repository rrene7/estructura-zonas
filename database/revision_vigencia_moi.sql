-- Mesa de revision de vigencia MOI.
-- Este script NO cambia la estructura final.
-- Prepara una mesa tecnica para decidir si cada unidad queda vigente, suprimida, fusionada o renombrada.

CREATE TABLE IF NOT EXISTS moi_unit_vigencia_review (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organizational_unit_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(50) NULL,
    name VARCHAR(200) NOT NULL,
    unit_type VARCHAR(100) NULL,
    territorial_scope VARCHAR(50) NULL,
    current_lifecycle_status VARCHAR(50) NULL,
    proposed_lifecycle_status ENUM('vigente','suprimida','fusionada','renombrada','pendiente_validacion') NOT NULL DEFAULT 'pendiente_validacion',
    proposed_valid_from DATE NULL,
    proposed_valid_to DATE NULL,
    replacement_unit_id BIGINT UNSIGNED NULL,
    review_reason VARCHAR(255) NULL,
    decision_status ENUM('pendiente','aprobado','rechazado') NOT NULL DEFAULT 'pendiente',
    reviewed_by VARCHAR(100) NULL,
    reviewed_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_review_unit (organizational_unit_id),
    INDEX idx_review_decision (decision_status),
    INDEX idx_review_proposed_status (proposed_lifecycle_status),
    INDEX idx_review_scope (territorial_scope),
    FOREIGN KEY (organizational_unit_id) REFERENCES organizational_units(id),
    FOREIGN KEY (replacement_unit_id) REFERENCES organizational_units(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO moi_unit_vigencia_review
(organizational_unit_id, code, name, unit_type, territorial_scope, current_lifecycle_status,
 proposed_lifecycle_status, proposed_valid_from, review_reason)
SELECT
    ou.id,
    ou.code,
    ou.name,
    ut.name,
    ou.territorial_scope,
    ou.lifecycle_status,
    'pendiente_validacion',
    ou.valid_from,
    'Revision inicial de vigencia contra nueva estructura MOI.'
FROM organizational_units ou
LEFT JOIN unit_types ut ON ut.id = ou.unit_type_id
LEFT JOIN moi_unit_vigencia_review r ON r.organizational_unit_id = ou.id
WHERE r.id IS NULL;

-- Marcar como posibles duplicados los nombres repetidos.
UPDATE moi_unit_vigencia_review r
JOIN (
    SELECT name, COUNT(*) AS total
    FROM organizational_units
    WHERE lifecycle_status = 'vigente'
    GROUP BY name
    HAVING COUNT(*) > 1
) d ON d.name = r.name
SET r.proposed_lifecycle_status = 'pendiente_validacion',
    r.review_reason = 'Posible duplicado por nombre. Revisar si corresponde fusion, renombre o supresion.'
WHERE r.decision_status = 'pendiente';

-- Mantener direcciones, regiones y zonas como vigentes sugeridas para revision inicial.
UPDATE moi_unit_vigencia_review
SET proposed_lifecycle_status = 'vigente',
    review_reason = 'Unidad de nivel superior sugerida como vigente en revision inicial.'
WHERE decision_status = 'pendiente'
  AND unit_type IN ('direccion_nacional','region_policial','zona_policial');

CREATE OR REPLACE VIEW vw_moi_revision_vigencia AS
SELECT
    r.id,
    r.organizational_unit_id,
    r.code,
    r.name,
    r.unit_type,
    r.territorial_scope,
    r.current_lifecycle_status,
    r.proposed_lifecycle_status,
    r.proposed_valid_from,
    r.proposed_valid_to,
    replacement.name AS replacement_unit,
    r.review_reason,
    r.decision_status,
    r.reviewed_by,
    r.reviewed_at
FROM moi_unit_vigencia_review r
LEFT JOIN organizational_units replacement ON replacement.id = r.replacement_unit_id;

CREATE OR REPLACE VIEW vw_moi_revision_vigencia_resumen AS
SELECT
    proposed_lifecycle_status,
    decision_status,
    COUNT(*) AS total
FROM moi_unit_vigencia_review
GROUP BY proposed_lifecycle_status, decision_status
ORDER BY proposed_lifecycle_status, decision_status;

CREATE OR REPLACE VIEW vw_moi_revision_duplicados_nombre AS
SELECT
    r.name,
    COUNT(*) AS total,
    GROUP_CONCAT(r.code ORDER BY r.code SEPARATOR ', ') AS codigos,
    GROUP_CONCAT(r.organizational_unit_id ORDER BY r.organizational_unit_id SEPARATOR ', ') AS unidad_ids
FROM moi_unit_vigencia_review r
GROUP BY r.name
HAVING COUNT(*) > 1
ORDER BY total DESC, r.name;

CREATE OR REPLACE VIEW vw_moi_revision_pendiente AS
SELECT *
FROM vw_moi_revision_vigencia
WHERE decision_status = 'pendiente'
ORDER BY proposed_lifecycle_status, unit_type, name;
