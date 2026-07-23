-- Códigos secuenciales e irrepetibles para nuevas unidades organizacionales
-- Los códigos existentes se conservan. Las unidades nuevas reciben un código automático.
-- El código no cambia al editar, mover, desactivar o reactivar una unidad.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS structure_code_prefixes (
    unit_type_id BIGINT UNSIGNED NOT NULL,
    prefix VARCHAR(8) NOT NULL,
    number_width TINYINT UNSIGNED NOT NULL DEFAULT 6,
    description VARCHAR(150) DEFAULT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (unit_type_id),
    KEY idx_structure_code_prefix (prefix),
    CONSTRAINT fk_structure_code_prefix_type
        FOREIGN KEY (unit_type_id) REFERENCES unit_types (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS structure_code_sequences (
    prefix VARCHAR(8) NOT NULL,
    last_number BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (prefix)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS structure_code_registry (
    code VARCHAR(50) NOT NULL,
    organizational_unit_id BIGINT UNSIGNED DEFAULT NULL,
    unit_type_id BIGINT UNSIGNED DEFAULT NULL,
    prefix VARCHAR(8) DEFAULT NULL,
    registered_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (code),
    KEY idx_structure_code_registry_unit (organizational_unit_id),
    KEY idx_structure_code_registry_type (unit_type_id),
    CONSTRAINT fk_structure_code_registry_unit
        FOREIGN KEY (organizational_unit_id) REFERENCES organizational_units (id),
    CONSTRAINT fk_structure_code_registry_type
        FOREIGN KEY (unit_type_id) REFERENCES unit_types (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Prefijos sencillos por tipo de unidad.
INSERT INTO structure_code_prefixes (unit_type_id, prefix, number_width, description)
SELECT
    unit_type.id,
    CASE
        WHEN unit_type.name IN ('zona_policial', 'region_policial') THEN 'ZON'
        WHEN unit_type.name IN ('direccion_nacional', 'subdireccion_nacional', 'directorio_general', 'directorio_personal', 'directorio_coordinacion', 'directorio_especial') THEN 'DIR'
        WHEN unit_type.name IN ('servicio_policial', 'servicio_zonal') THEN 'SER'
        WHEN unit_type.name IN ('area', 'area_policial') THEN 'ARE'
        WHEN unit_type.name IN ('departamento', 'dependencia', 'division') THEN 'DEP'
        WHEN unit_type.name IN ('seccion', 'sector_policial') THEN 'SEC'
        WHEN unit_type.name IN ('oficina') THEN 'OFI'
        WHEN unit_type.name IN ('estacion', 'estacion_policial', 'subestacion', 'subestacion_policial') THEN 'EST'
        WHEN unit_type.name IN ('puesto', 'puesto_policial', 'destacamento', 'cuartel') THEN 'PUE'
        WHEN unit_type.name IN ('grupo_operativo') THEN 'GRU'
        ELSE 'UNI'
    END,
    6,
    CONCAT('Código automático para ', REPLACE(unit_type.name, '_', ' '))
FROM unit_types unit_type
ON DUPLICATE KEY UPDATE
    prefix = VALUES(prefix),
    number_width = VALUES(number_width),
    description = VALUES(description),
    updated_at = CURRENT_TIMESTAMP;

-- Reservar todos los códigos que ya existen, incluso si la unidad está inactiva.
INSERT IGNORE INTO structure_code_registry (
    code,
    organizational_unit_id,
    unit_type_id,
    prefix
)
SELECT
    TRIM(unit_row.code),
    unit_row.id,
    unit_row.unit_type_id,
    COALESCE(prefix_row.prefix, 'LEG')
FROM organizational_units unit_row
LEFT JOIN structure_code_prefixes prefix_row
    ON prefix_row.unit_type_id = unit_row.unit_type_id
WHERE NULLIF(TRIM(unit_row.code), '') IS NOT NULL;

DELIMITER $$

DROP TRIGGER IF EXISTS trg_structure_code_before_insert$$
CREATE TRIGGER trg_structure_code_before_insert
BEFORE INSERT ON organizational_units
FOR EACH ROW
BEGIN
    DECLARE v_prefix VARCHAR(8) DEFAULT NULL;
    DECLARE v_width INT DEFAULT 6;
    DECLARE v_next BIGINT UNSIGNED DEFAULT 0;
    DECLARE v_candidate VARCHAR(50) DEFAULT NULL;
    DECLARE v_exists INT DEFAULT 1;

    -- Las unidades nuevas creadas desde configuración siempre reciben código automático.
    IF NEW.structure_source = 'accion_posterior'
       AND COALESCE(NEW.legacy_frozen, 0) = 0 THEN

        SELECT MAX(prefix), MAX(number_width)
          INTO v_prefix, v_width
        FROM structure_code_prefixes
        WHERE unit_type_id = NEW.unit_type_id;

        SET v_prefix = COALESCE(NULLIF(v_prefix, ''), 'UNI');
        SET v_width = COALESCE(NULLIF(v_width, 0), 6);

        INSERT INTO structure_code_sequences (prefix, last_number)
        VALUES (v_prefix, 0)
        ON DUPLICATE KEY UPDATE prefix = VALUES(prefix);

        WHILE v_exists > 0 DO
            UPDATE structure_code_sequences
            SET last_number = last_number + 1
            WHERE prefix = v_prefix;

            SELECT last_number
              INTO v_next
            FROM structure_code_sequences
            WHERE prefix = v_prefix;

            SET v_candidate = CONCAT(v_prefix, '-', LPAD(v_next, v_width, '0'));

            SELECT COUNT(*)
              INTO v_exists
            FROM structure_code_registry
            WHERE code = v_candidate;
        END WHILE;

        SET NEW.code = v_candidate;
    END IF;
END$$

DROP TRIGGER IF EXISTS trg_structure_code_after_insert$$
CREATE TRIGGER trg_structure_code_after_insert
AFTER INSERT ON organizational_units
FOR EACH ROW
BEGIN
    IF NULLIF(TRIM(NEW.code), '') IS NOT NULL THEN
        INSERT IGNORE INTO structure_code_registry (
            code,
            organizational_unit_id,
            unit_type_id,
            prefix
        ) VALUES (
            TRIM(NEW.code),
            NEW.id,
            NEW.unit_type_id,
            SUBSTRING_INDEX(TRIM(NEW.code), '-', 1)
        );
    END IF;
END$$

DROP TRIGGER IF EXISTS trg_structure_code_before_update$$
CREATE TRIGGER trg_structure_code_before_update
BEFORE UPDATE ON organizational_units
FOR EACH ROW
BEGIN
    -- Un código asignado no se cambia ni se reutiliza.
    IF NULLIF(TRIM(OLD.code), '') IS NOT NULL THEN
        SET NEW.code = OLD.code;
    END IF;
END$$

DROP TRIGGER IF EXISTS trg_structure_code_after_update$$
CREATE TRIGGER trg_structure_code_after_update
AFTER UPDATE ON organizational_units
FOR EACH ROW
BEGIN
    IF NULLIF(TRIM(NEW.code), '') IS NOT NULL THEN
        INSERT IGNORE INTO structure_code_registry (
            code,
            organizational_unit_id,
            unit_type_id,
            prefix
        ) VALUES (
            TRIM(NEW.code),
            NEW.id,
            NEW.unit_type_id,
            SUBSTRING_INDEX(TRIM(NEW.code), '-', 1)
        );
    END IF;
END$$

DELIMITER ;

SELECT
    prefix,
    last_number
FROM structure_code_sequences
ORDER BY prefix;
