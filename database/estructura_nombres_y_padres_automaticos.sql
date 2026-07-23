-- Padres automáticos, nombres guiados y números de zona irrepetibles.
-- Ejecutar después de:
--   database/estructura_admin_db.sql
--   database/estructura_configuracion_simple.sql
--   database/estructura_codigos_secuenciales.sql

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS structure_default_parents (
    group_key VARCHAR(30) NOT NULL,
    parent_unit_id BIGINT UNSIGNED DEFAULT NULL,
    description VARCHAR(180) DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (group_key),
    KEY idx_structure_default_parent_unit (parent_unit_id),
    CONSTRAINT fk_structure_default_parent_unit
        FOREIGN KEY (parent_unit_id) REFERENCES organizational_units (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La zona nueva depende de la Dirección Nacional de Operaciones Policiales vigente,
-- excluyendo variantes heredadas y registros protegidos.
INSERT INTO structure_default_parents (group_key, parent_unit_id, description)
SELECT
    'zonas',
    candidate.id,
    'Unidad superior automática para nuevas zonas policiales'
FROM organizational_units candidate
JOIN unit_types candidate_type
  ON candidate_type.id = candidate.unit_type_id
WHERE candidate.status = 'active'
  AND candidate.lifecycle_status = 'vigente'
  AND COALESCE(candidate.legacy_frozen, 0) = 0
  AND candidate.structure_source <> 'legacy'
  AND UPPER(TRIM(COALESCE(candidate.legacy_table, ''))) <> 'TABCUAR'
  AND (
        candidate.name COLLATE utf8mb4_unicode_ci = 'Dirección Nacional de Operaciones Policiales'
        OR candidate.name COLLATE utf8mb4_unicode_ci = 'Direccion Nacional de Operaciones Policiales'
        OR (
            candidate.name COLLATE utf8mb4_unicode_ci LIKE '%Operaciones Policiales%'
            AND candidate_type.name IN ('direccion_nacional', 'subdireccion_nacional', 'directorio_general')
        )
  )
ORDER BY
    CASE
        WHEN candidate.name COLLATE utf8mb4_unicode_ci = 'Dirección Nacional de Operaciones Policiales' THEN 1
        WHEN candidate.name COLLATE utf8mb4_unicode_ci = 'Direccion Nacional de Operaciones Policiales' THEN 2
        ELSE 3
    END,
    candidate.id
LIMIT 1
ON DUPLICATE KEY UPDATE
    parent_unit_id = VALUES(parent_unit_id),
    description = VALUES(description),
    updated_at = CURRENT_TIMESTAMP;

-- Direcciones y servicios principales dependen de la raíz institucional vigente.
INSERT INTO structure_default_parents (group_key, parent_unit_id, description)
SELECT
    group_source.group_key,
    root_unit.id,
    group_source.description
FROM (
    SELECT 'direcciones' AS group_key, 'Unidad superior automática para nuevas direcciones' AS description
    UNION ALL
    SELECT 'servicios', 'Unidad superior automática para nuevos servicios principales'
) group_source
JOIN organizational_units root_unit
  ON root_unit.status = 'active'
 AND root_unit.lifecycle_status = 'vigente'
 AND root_unit.parent_id IS NULL
 AND COALESCE(root_unit.legacy_frozen, 0) = 0
 AND root_unit.structure_source <> 'legacy'
WHERE root_unit.name COLLATE utf8mb4_unicode_ci LIKE '%Policía Nacional%'
   OR root_unit.name COLLATE utf8mb4_unicode_ci LIKE '%Policia Nacional%'
ORDER BY root_unit.id
LIMIT 2
ON DUPLICATE KEY UPDATE
    parent_unit_id = VALUES(parent_unit_id),
    description = VALUES(description),
    updated_at = CURRENT_TIMESTAMP;

CREATE TABLE IF NOT EXISTS structure_zone_number_registry (
    zone_number SMALLINT UNSIGNED NOT NULL,
    organizational_unit_id BIGINT UNSIGNED NOT NULL,
    registered_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (zone_number),
    UNIQUE KEY uq_structure_zone_number_unit (organizational_unit_id),
    CONSTRAINT fk_structure_zone_number_unit
        FOREIGN KEY (organizational_unit_id) REFERENCES organizational_units (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reservar primero los números oficiales de las cabeceras MOI.
INSERT IGNORE INTO structure_zone_number_registry (zone_number, organizational_unit_id)
SELECT
    CAST(unit_row.legacy_id AS UNSIGNED),
    unit_row.id
FROM organizational_units unit_row
WHERE BINARY unit_row.legacy_table = BINARY 'MOI_CABECERA_ZONA'
  AND unit_row.legacy_id REGEXP '^[0-9]+$'
  AND CAST(unit_row.legacy_id AS UNSIGNED) > 0;

-- Reservar también números reconocibles en otros registros de tipo zona.
INSERT IGNORE INTO structure_zone_number_registry (zone_number, organizational_unit_id)
SELECT
    CAST(SUBSTRING_INDEX(TRIM(unit_row.name), ' ', 1) AS UNSIGNED),
    unit_row.id
FROM organizational_units unit_row
JOIN unit_types unit_type
  ON unit_type.id = unit_row.unit_type_id
WHERE unit_type.name IN ('zona_policial', 'region_policial')
  AND TRIM(unit_row.name) REGEXP '^[0-9]+'
  AND CAST(SUBSTRING_INDEX(TRIM(unit_row.name), ' ', 1) AS UNSIGNED) > 0;

CREATE OR REPLACE VIEW vw_structure_code_previews AS
SELECT
    prefix_row.unit_type_id,
    prefix_row.prefix,
    prefix_row.number_width,
    CONCAT(
        prefix_row.prefix,
        '-',
        LPAD(
            GREATEST(
                COALESCE(sequence_row.last_number, 0),
                COALESCE(MAX(
                    CASE
                        WHEN registry_row.code REGEXP CONCAT('^', prefix_row.prefix, '-[0-9]+$')
                        THEN CAST(SUBSTRING_INDEX(registry_row.code, '-', -1) AS UNSIGNED)
                        ELSE 0
                    END
                ), 0)
            ) + 1,
            prefix_row.number_width,
            '0'
        )
    ) AS next_code
FROM structure_code_prefixes prefix_row
LEFT JOIN structure_code_sequences sequence_row
  ON sequence_row.prefix = prefix_row.prefix
LEFT JOIN structure_code_registry registry_row
  ON registry_row.prefix = prefix_row.prefix
GROUP BY
    prefix_row.unit_type_id,
    prefix_row.prefix,
    prefix_row.number_width,
    sequence_row.last_number;

DELIMITER $$

DROP PROCEDURE IF EXISTS sp_structure_create_principal_unit$$
CREATE PROCEDURE sp_structure_create_principal_unit(
    IN p_unit_type_id BIGINT UNSIGNED,
    IN p_name VARCHAR(200),
    IN p_notes VARCHAR(255),
    IN p_actor VARCHAR(100)
)
BEGIN
    DECLARE v_type_name VARCHAR(100) DEFAULT NULL;
    DECLARE v_group_key VARCHAR(30) DEFAULT NULL;
    DECLARE v_parent_id BIGINT UNSIGNED DEFAULT NULL;

    SELECT name
      INTO v_type_name
    FROM unit_types
    WHERE id = p_unit_type_id
    LIMIT 1;

    IF v_type_name IS NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'El tipo seleccionado no existe.';
    END IF;

    SET v_group_key = CASE
        WHEN v_type_name IN ('zona_policial', 'region_policial') THEN 'zonas'
        WHEN v_type_name IN ('direccion_nacional', 'subdireccion_nacional', 'directorio_general') THEN 'direcciones'
        WHEN v_type_name IN ('servicio_policial') THEN 'servicios'
        ELSE NULL
    END;

    IF v_group_key IS NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Ese tipo no corresponde a una unidad principal.';
    END IF;

    SELECT parent_unit_id
      INTO v_parent_id
    FROM structure_default_parents
    WHERE group_key = v_group_key
    LIMIT 1;

    IF v_group_key = 'zonas' AND v_parent_id IS NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'No está configurada la unidad superior oficial para las zonas.';
    END IF;

    CALL sp_structure_create_root_unit(
        COALESCE(v_parent_id, 0),
        p_unit_type_id,
        p_name,
        '',
        '',
        '',
        p_notes,
        p_actor
    );
END$$

DROP TRIGGER IF EXISTS trg_structure_zone_number_before_insert$$
CREATE TRIGGER trg_structure_zone_number_before_insert
BEFORE INSERT ON organizational_units
FOR EACH ROW
BEGIN
    DECLARE v_type_name VARCHAR(100) DEFAULT NULL;
    DECLARE v_zone_number INT DEFAULT 0;
    DECLARE v_used INT DEFAULT 0;

    SELECT MAX(name)
      INTO v_type_name
    FROM unit_types
    WHERE id = NEW.unit_type_id;

    IF NEW.structure_source = 'accion_posterior'
       AND COALESCE(NEW.legacy_frozen, 0) = 0
       AND v_type_name IN ('zona_policial', 'region_policial') THEN

        SET v_zone_number = CAST(SUBSTRING_INDEX(TRIM(NEW.name), ' ', 1) AS UNSIGNED);

        IF v_zone_number <= 0 THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'El nombre de la zona debe comenzar con un número válido.';
        END IF;

        SELECT COUNT(*)
          INTO v_used
        FROM structure_zone_number_registry
        WHERE zone_number = v_zone_number;

        IF v_used > 0 THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Ese número de zona ya fue utilizado y no puede reutilizarse.';
        END IF;
    END IF;
END$$

DROP TRIGGER IF EXISTS trg_structure_zone_number_after_insert$$
CREATE TRIGGER trg_structure_zone_number_after_insert
AFTER INSERT ON organizational_units
FOR EACH ROW
BEGIN
    DECLARE v_type_name VARCHAR(100) DEFAULT NULL;
    DECLARE v_zone_number INT DEFAULT 0;

    SELECT MAX(name)
      INTO v_type_name
    FROM unit_types
    WHERE id = NEW.unit_type_id;

    IF v_type_name IN ('zona_policial', 'region_policial')
       AND TRIM(NEW.name) REGEXP '^[0-9]+' THEN
        SET v_zone_number = CAST(SUBSTRING_INDEX(TRIM(NEW.name), ' ', 1) AS UNSIGNED);

        IF v_zone_number > 0 THEN
            INSERT INTO structure_zone_number_registry (
                zone_number,
                organizational_unit_id
            ) VALUES (
                v_zone_number,
                NEW.id
            );
        END IF;
    END IF;
END$$

DROP TRIGGER IF EXISTS trg_structure_zone_number_before_update$$
CREATE TRIGGER trg_structure_zone_number_before_update
BEFORE UPDATE ON organizational_units
FOR EACH ROW
BEGIN
    DECLARE v_old_type_name VARCHAR(100) DEFAULT NULL;
    DECLARE v_old_number INT DEFAULT 0;
    DECLARE v_new_number INT DEFAULT 0;

    SELECT MAX(name)
      INTO v_old_type_name
    FROM unit_types
    WHERE id = OLD.unit_type_id;

    IF v_old_type_name IN ('zona_policial', 'region_policial')
       AND TRIM(OLD.name) REGEXP '^[0-9]+' THEN
        SET v_old_number = CAST(SUBSTRING_INDEX(TRIM(OLD.name), ' ', 1) AS UNSIGNED);
        SET v_new_number = CAST(SUBSTRING_INDEX(TRIM(NEW.name), ' ', 1) AS UNSIGNED);

        IF v_new_number <> v_old_number THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'El número institucional de una zona no puede cambiarse ni reutilizarse.';
        END IF;
    END IF;
END$$

DELIMITER ;

SELECT
    parent_config.group_key,
    parent_config.parent_unit_id,
    parent_unit.name AS parent_name
FROM structure_default_parents parent_config
LEFT JOIN organizational_units parent_unit
  ON parent_unit.id = parent_config.parent_unit_id
ORDER BY parent_config.group_key;
