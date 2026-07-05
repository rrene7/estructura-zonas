-- Catalogo de zonas policiales vigentes usadas como cabeceras de zona.
-- Fuente: validacion funcional indicada por el equipo usuario.
-- No modifica el legacy; crea catalogo vigente y genera candidatos de enlace.

CREATE TABLE IF NOT EXISTS moi_zonas_cabecera_vigentes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    zone_number INT NOT NULL UNIQUE,
    zone_label VARCHAR(150) NOT NULL,
    zone_name VARCHAR(150) NOT NULL,
    normalized_name VARCHAR(150) NOT NULL,
    is_cabecera BOOLEAN NOT NULL DEFAULT TRUE,
    lifecycle_status ENUM('vigente','no_vigente') NOT NULL DEFAULT 'vigente',
    valid_from DATE NULL,
    valid_to DATE NULL,
    notes VARCHAR(255) NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_zonas_cabecera_status (lifecycle_status),
    INDEX idx_zonas_cabecera_name (normalized_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO moi_zonas_cabecera_vigentes
(zone_number, zone_label, zone_name, normalized_name, is_cabecera, lifecycle_status, valid_from, notes)
VALUES
(1,  '1 Zona Policial - Bocas del Toro',       'Bocas del Toro',       'BOCAS DEL TORO',       TRUE, 'vigente', '2026-01-14', 'Cabecera de zona vigente'),
(2,  '2 Zona Policial - Cocle',                'Cocle',                'COCLE',                TRUE, 'vigente', '2026-01-14', 'Cabecera de zona vigente'),
(3,  '3 Zona Policial - Colon',                'Colon',                'COLON',                TRUE, 'vigente', '2026-01-14', 'Cabecera de zona vigente'),
(4,  '4 Zona Policial - Chiriqui',             'Chiriqui',             'CHIRIQUI',             TRUE, 'vigente', '2026-01-14', 'Cabecera de zona vigente'),
(6,  '6 Zona Policial - Herrera',              'Herrera',              'HERRERA',              TRUE, 'vigente', '2026-01-14', 'Cabecera de zona vigente'),
(7,  '7 Zona Policial - Los Santos',           'Los Santos',           'LOS SANTOS',           TRUE, 'vigente', '2026-01-14', 'Cabecera de zona vigente'),
(8,  '8 Zona Policial - Oeste',                'Oeste',                'OESTE',                TRUE, 'vigente', '2026-01-14', 'Cabecera de zona vigente'),
(9,  '9 Zona Policial - Veraguas',             'Veraguas',             'VERAGUAS',             TRUE, 'vigente', '2026-01-14', 'Cabecera de zona vigente'),
(10, '10 Zona Policial - Panama Oeste',        'Panama Oeste',         'PANAMA OESTE',         TRUE, 'vigente', '2026-01-14', 'Cabecera de zona vigente'),
(11, '11 Zona Policial - San Miguelito',       'San Miguelito',        'SAN MIGUELITO',        TRUE, 'vigente', '2026-01-14', 'Cabecera de zona vigente'),
(12, '12 Zona Policial - Canal',               'Canal',                'CANAL',                TRUE, 'vigente', '2026-01-14', 'Cabecera de zona vigente'),
(13, '13 Zona Policial - Arraijan',            'Arraijan',             'ARRAIJAN',             TRUE, 'vigente', '2026-01-14', 'Cabecera de zona vigente'),
(14, '14 Zona Policial - Norte',               'Norte',                'NORTE',                TRUE, 'vigente', '2026-01-14', 'Cabecera de zona vigente'),
(15, '15 Zona Policial - Don Bosco',           'Don Bosco',            'DON BOSCO',            TRUE, 'vigente', '2026-01-14', 'Cabecera de zona vigente'),
(16, '16 Zona Policial - Pacora',              'Pacora',               'PACORA',               TRUE, 'vigente', '2026-01-14', 'Cabecera de zona vigente'),
(18, '18 Zona Policial - Comarcal Occidente',  'Comarcal Occidente',   'COMARCAL OCCIDENTE',   TRUE, 'vigente', '2026-01-14', 'Cabecera de zona vigente'),
(19, '19 Zona Policial - Chame',               'Chame',                'CHAME',                TRUE, 'vigente', '2026-01-14', 'Cabecera de zona vigente'),
(21, '21 Zona Policial - San Francisco',       'San Francisco',        'SAN FRANCISCO',        TRUE, 'vigente', '2026-01-14', 'Cabecera de zona vigente')
ON DUPLICATE KEY UPDATE
    zone_label = VALUES(zone_label),
    zone_name = VALUES(zone_name),
    normalized_name = VALUES(normalized_name),
    is_cabecera = TRUE,
    lifecycle_status = 'vigente',
    valid_from = VALUES(valid_from),
    valid_to = NULL,
    notes = VALUES(notes),
    updated_at = NOW();

CREATE TABLE IF NOT EXISTS moi_zona_cabecera_unit_match (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    zona_cabecera_id BIGINT UNSIGNED NOT NULL,
    organizational_unit_id BIGINT UNSIGNED NULL,
    match_status ENUM('pendiente','aprobado','rechazado') NOT NULL DEFAULT 'pendiente',
    confidence_level ENUM('alto','medio','bajo') NOT NULL DEFAULT 'medio',
    match_reason VARCHAR(255) NULL,
    reviewed_by VARCHAR(100) NULL,
    reviewed_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_zona_unit_match (zona_cabecera_id, organizational_unit_id),
    INDEX idx_zona_match_status (match_status),
    FOREIGN KEY (zona_cabecera_id) REFERENCES moi_zonas_cabecera_vigentes(id),
    FOREIGN KEY (organizational_unit_id) REFERENCES organizational_units(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO moi_zona_cabecera_unit_match
(zona_cabecera_id, organizational_unit_id, match_status, confidence_level, match_reason)
SELECT
    z.id,
    ou.id,
    'pendiente',
    CASE
        WHEN UPPER(ou.name) LIKE CONCAT('%', z.zone_number, '%ZONA%')
          OR UPPER(ou.name) LIKE CONCAT('%', z.normalized_name, '%') THEN 'alto'
        ELSE 'medio'
    END,
    'Candidato a cabecera de zona vigente por numero o nombre.'
FROM moi_zonas_cabecera_vigentes z
JOIN organizational_units ou
JOIN unit_types ut ON ut.id = ou.unit_type_id
WHERE ut.name = 'zona_policial'
  AND ou.lifecycle_status = 'vigente'
  AND (
       UPPER(ou.name) LIKE CONCAT('%', z.normalized_name, '%')
       OR UPPER(ou.name) LIKE CONCAT('%', z.zone_number, '%ZONA%')
       OR UPPER(ou.name) LIKE CONCAT('%ZONA%', z.zone_number, '%')
  );

CREATE OR REPLACE VIEW vw_moi_zonas_cabecera_vigentes AS
SELECT
    z.zone_number,
    z.zone_label,
    z.zone_name,
    z.is_cabecera,
    z.lifecycle_status,
    z.valid_from,
    z.valid_to,
    m.match_status,
    m.confidence_level,
    ou.id AS organizational_unit_id,
    ou.code AS unit_code,
    ou.name AS unit_name,
    ou.legacy_table,
    ou.legacy_id
FROM moi_zonas_cabecera_vigentes z
LEFT JOIN moi_zona_cabecera_unit_match m ON m.zona_cabecera_id = z.id
LEFT JOIN organizational_units ou ON ou.id = m.organizational_unit_id
ORDER BY z.zone_number, m.confidence_level DESC, ou.name;

CREATE OR REPLACE VIEW vw_moi_zonas_cabecera_sin_match AS
SELECT z.*
FROM moi_zonas_cabecera_vigentes z
LEFT JOIN moi_zona_cabecera_unit_match m
  ON m.zona_cabecera_id = z.id
 AND m.match_status IN ('pendiente','aprobado')
WHERE m.id IS NULL
ORDER BY z.zone_number;

CREATE OR REPLACE VIEW vw_moi_zonas_no_cabecera_candidatas AS
SELECT
    ou.id,
    ou.code,
    ou.name,
    ou.legacy_table,
    ou.legacy_id,
    ou.lifecycle_status,
    'Zona policial no incluida en catalogo vigente de cabeceras' AS review_reason
FROM organizational_units ou
JOIN unit_types ut ON ut.id = ou.unit_type_id AND ut.name = 'zona_policial'
LEFT JOIN moi_zona_cabecera_unit_match m
  ON m.organizational_unit_id = ou.id
 AND m.match_status IN ('pendiente','aprobado')
WHERE ou.lifecycle_status = 'vigente'
  AND m.id IS NULL
ORDER BY ou.name;
