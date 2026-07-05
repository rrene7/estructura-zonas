-- Catalogo de direcciones vigentes usadas como cabeceras de direccion.
-- Fuente: validacion funcional indicada por el equipo usuario.
-- No modifica el legacy; crea catalogo vigente y genera candidatos de enlace.

CREATE TABLE IF NOT EXISTS moi_direcciones_cabecera_vigentes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    direction_number INT NOT NULL UNIQUE,
    direction_label VARCHAR(220) NOT NULL,
    direction_name VARCHAR(220) NOT NULL,
    normalized_name VARCHAR(220) NOT NULL,
    is_cabecera BOOLEAN NOT NULL DEFAULT TRUE,
    lifecycle_status ENUM('vigente','no_vigente') NOT NULL DEFAULT 'vigente',
    valid_from DATE NULL,
    valid_to DATE NULL,
    notes VARCHAR(255) NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_direcciones_cabecera_status (lifecycle_status),
    INDEX idx_direcciones_cabecera_name (normalized_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO moi_direcciones_cabecera_vigentes
(direction_number, direction_label, direction_name, normalized_name, is_cabecera, lifecycle_status, valid_from, notes)
VALUES
(1,  'Direccion General',                                           'Direccion General',                                           'DIRECCION GENERAL',                                           TRUE, 'vigente', '2026-01-14', 'Cabecera de direccion vigente'),
(2,  'Direccion Nacional de Inteligencia Policial',                 'Direccion Nacional de Inteligencia Policial',                 'DIRECCION NACIONAL DE INTELIGENCIA POLICIAL',                 TRUE, 'vigente', '2026-01-14', 'Cabecera de direccion vigente'),
(3,  'Direccion Nacional de Fuerzas Especiales',                    'Direccion Nacional de Fuerzas Especiales',                    'DIRECCION NACIONAL DE FUERZAS ESPECIALES',                    TRUE, 'vigente', '2026-01-14', 'Cabecera de direccion vigente'),
(4,  'Direccion Nacional de Docencia',                              'Direccion Nacional de Docencia',                              'DIRECCION NACIONAL DE DOCENCIA',                              TRUE, 'vigente', '2026-01-14', 'Cabecera de direccion vigente'),
(5,  'Direccion Nacional de Recursos Humanos',                      'Direccion Nacional de Recursos Humanos',                      'DIRECCION NACIONAL DE RECURSOS HUMANOS',                      TRUE, 'vigente', '2026-01-14', 'Cabecera de direccion vigente'),
(6,  'Direccion Nacional Antidrogas',                               'Direccion Nacional Antidrogas',                               'DIRECCION NACIONAL ANTIDROGAS',                               TRUE, 'vigente', '2026-01-14', 'Cabecera de direccion vigente'),
(7,  'Direccion Nacional de Operaciones Policiales',                'Direccion Nacional de Operaciones Policiales',                'DIRECCION NACIONAL DE OPERACIONES POLICIALES',                TRUE, 'vigente', '2026-01-14', 'Cabecera de direccion vigente'),
(8,  'Direccion Nacional de Servicios Generales',                   'Direccion Nacional de Servicios Generales',                   'DIRECCION NACIONAL DE SERVICIOS GENERALES',                   TRUE, 'vigente', '2026-01-14', 'Cabecera de direccion vigente'),
(9,  'Direccion Nacional de Telematica',                            'Direccion Nacional de Telematica',                            'DIRECCION NACIONAL DE TELEMATICA',                            TRUE, 'vigente', '2026-01-14', 'Cabecera de direccion vigente'),
(10, 'Direccion Nacional de Comunicacion Estrategica',              'Direccion Nacional de Comunicacion Estrategica',              'DIRECCION NACIONAL DE COMUNICACION ESTRATEGICA',              TRUE, 'vigente', '2026-01-14', 'Cabecera de direccion vigente'),
(11, 'Direccion de Responsabilidad Profesional',                    'Direccion de Responsabilidad Profesional',                    'DIRECCION DE RESPONSABILIDAD PROFESIONAL',                    TRUE, 'vigente', '2026-01-14', 'Cabecera de direccion vigente'),
(12, 'Direccion Nacional de Planificacion Estrategica Institucional','Direccion Nacional de Planificacion Estrategica Institucional','DIRECCION NACIONAL DE PLANIFICACION ESTRATEGICA INSTITUCIONAL',TRUE, 'vigente', '2026-01-14', 'Cabecera de direccion vigente'),
(13, 'Direccion Nacional de Armamentos y Equipos de Seguridad',     'Direccion Nacional de Armamentos y Equipos de Seguridad',     'DIRECCION NACIONAL DE ARMAMENTOS Y EQUIPOS DE SEGURIDAD',     TRUE, 'vigente', '2026-01-14', 'Cabecera de direccion vigente'),
(14, 'Direccion Nacional de Asesoria Legal',                        'Direccion Nacional de Asesoria Legal',                        'DIRECCION NACIONAL DE ASESORIA LEGAL',                        TRUE, 'vigente', '2026-01-14', 'Cabecera de direccion vigente'),
(15, 'Direccion Nacional de Infraestructura y Mantenimiento',       'Direccion Nacional de Infraestructura y Mantenimiento',       'DIRECCION NACIONAL DE INFRAESTRUCTURA Y MANTENIMIENTO',       TRUE, 'vigente', '2026-01-14', 'Cabecera de direccion vigente'),
(16, 'Direccion Nacional de Ingenieria y Servicios Policiales',     'Direccion Nacional de Ingenieria y Servicios Policiales',     'DIRECCION NACIONAL DE INGENIERIA Y SERVICIOS POLICIALES',     TRUE, 'vigente', '2026-01-14', 'Cabecera de direccion vigente'),
(17, 'Direccion Nacional de Investigacion Judicial',                'Direccion Nacional de Investigacion Judicial',                'DIRECCION NACIONAL DE INVESTIGACION JUDICIAL',                TRUE, 'vigente', '2026-01-14', 'Cabecera de direccion vigente'),
(18, 'Direccion Nacional de Operaciones de Transito',               'Direccion Nacional de Operaciones de Transito',               'DIRECCION NACIONAL DE OPERACIONES DE TRANSITO',               TRUE, 'vigente', '2026-01-14', 'Cabecera de direccion vigente'),
(19, 'Direccion de Transporte y Mantenimiento',                     'Direccion de Transporte y Mantenimiento',                     'DIRECCION DE TRANSPORTE Y MANTENIMIENTO',                     TRUE, 'vigente', '2026-01-14', 'Cabecera de direccion vigente')
ON DUPLICATE KEY UPDATE
    direction_label = VALUES(direction_label),
    direction_name = VALUES(direction_name),
    normalized_name = VALUES(normalized_name),
    is_cabecera = TRUE,
    lifecycle_status = 'vigente',
    valid_from = VALUES(valid_from),
    valid_to = NULL,
    notes = VALUES(notes),
    updated_at = NOW();

CREATE TABLE IF NOT EXISTS moi_direccion_cabecera_unit_match (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    direccion_cabecera_id BIGINT UNSIGNED NOT NULL,
    organizational_unit_id BIGINT UNSIGNED NULL,
    match_status ENUM('pendiente','aprobado','rechazado') NOT NULL DEFAULT 'pendiente',
    confidence_level ENUM('alto','medio','bajo') NOT NULL DEFAULT 'medio',
    match_reason VARCHAR(255) NULL,
    reviewed_by VARCHAR(100) NULL,
    reviewed_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_direccion_unit_match (direccion_cabecera_id, organizational_unit_id),
    INDEX idx_direccion_match_status (match_status),
    FOREIGN KEY (direccion_cabecera_id) REFERENCES moi_direcciones_cabecera_vigentes(id),
    FOREIGN KEY (organizational_unit_id) REFERENCES organizational_units(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO moi_direccion_cabecera_unit_match
(direccion_cabecera_id, organizational_unit_id, match_status, confidence_level, match_reason)
SELECT
    d.id,
    ou.id,
    'pendiente',
    CASE
        WHEN UPPER(ou.name) = d.normalized_name THEN 'alto'
        WHEN UPPER(ou.name) LIKE CONCAT('%', d.normalized_name, '%') THEN 'alto'
        ELSE 'medio'
    END,
    'Candidato a cabecera de direccion vigente por coincidencia de nombre.'
FROM moi_direcciones_cabecera_vigentes d
JOIN organizational_units ou
JOIN unit_types ut ON ut.id = ou.unit_type_id
WHERE ou.lifecycle_status = 'vigente'
  AND ut.name IN ('direccion_nacional','directorio_general','directorio_personal','directorio_coordinacion','directorio_especial','dependencia')
  AND (
       UPPER(ou.name) = d.normalized_name
       OR UPPER(ou.name) LIKE CONCAT('%', d.normalized_name, '%')
       OR d.normalized_name LIKE CONCAT('%', UPPER(ou.name), '%')
  );

CREATE OR REPLACE VIEW vw_moi_direcciones_cabecera_vigentes AS
SELECT
    d.direction_number,
    d.direction_label,
    d.direction_name,
    d.is_cabecera,
    d.lifecycle_status,
    d.valid_from,
    d.valid_to,
    m.match_status,
    m.confidence_level,
    ou.id AS organizational_unit_id,
    ou.code AS unit_code,
    ou.name AS unit_name,
    ou.legacy_table,
    ou.legacy_id
FROM moi_direcciones_cabecera_vigentes d
LEFT JOIN moi_direccion_cabecera_unit_match m ON m.direccion_cabecera_id = d.id
LEFT JOIN organizational_units ou ON ou.id = m.organizational_unit_id
ORDER BY d.direction_number, m.confidence_level DESC, ou.name;

CREATE OR REPLACE VIEW vw_moi_direcciones_cabecera_sin_match AS
SELECT d.*
FROM moi_direcciones_cabecera_vigentes d
LEFT JOIN moi_direccion_cabecera_unit_match m
  ON m.direccion_cabecera_id = d.id
 AND m.match_status IN ('pendiente','aprobado')
WHERE m.id IS NULL
ORDER BY d.direction_number;

CREATE OR REPLACE VIEW vw_moi_direcciones_no_cabecera_candidatas AS
SELECT
    ou.id,
    ou.code,
    ou.name,
    ou.legacy_table,
    ou.legacy_id,
    ou.lifecycle_status,
    'Direccion no incluida en catalogo vigente de cabeceras' AS review_reason
FROM organizational_units ou
JOIN unit_types ut ON ut.id = ou.unit_type_id
LEFT JOIN moi_direccion_cabecera_unit_match m
  ON m.organizational_unit_id = ou.id
 AND m.match_status IN ('pendiente','aprobado')
WHERE ou.lifecycle_status = 'vigente'
  AND ut.name IN ('direccion_nacional','directorio_general','directorio_personal','directorio_coordinacion','directorio_especial')
  AND m.id IS NULL
ORDER BY ou.name;
