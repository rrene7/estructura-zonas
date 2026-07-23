-- Código interno uniforme para todas las unidades y corrección del padre automático de zonas.
--
-- Objetivos:
-- 1. Conservar organizational_units.code como código institucional/histórico.
-- 2. Asignar organizational_units.system_code a TODAS las unidades actuales y futuras.
-- 3. Mantener los códigos internos permanentes, únicos e irreutilizables.
-- 4. Configurar la Dirección Nacional de Operaciones Policiales vigente como padre de nuevas zonas.
--
-- Ejecutar después de:
--   database/estructura_admin_db.sql
--   database/estructura_configuracion_simple.sql
--   database/estructura_codigos_secuenciales.sql
--   database/estructura_nombres_y_padres_automaticos.sql

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE organizational_units
    ADD COLUMN IF NOT EXISTS system_code VARCHAR(50) NULL AFTER moi_code;

CREATE TABLE IF NOT EXISTS structure_system_code_registry (
    system_code VARCHAR(50) NOT NULL,
    organizational_unit_id BIGINT UNSIGNED NOT NULL,
    unit_type_id BIGINT UNSIGNED NOT NULL,
    prefix VARCHAR(8) NOT NULL,
    assigned_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (system_code),
    UNIQUE KEY uq_structure_system_code_unit (organizational_unit_id),
    KEY idx_structure_system_code_type (unit_type_id),
    KEY idx_structure_system_code_prefix (prefix),
    CONSTRAINT fk_structure_system_code_unit
        FOREIGN KEY (organizational_unit_id) REFERENCES organizational_units (id),
    CONSTRAINT fk_structure_system_code_type
        FOREIGN KEY (unit_type_id) REFERENCES unit_types (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Asegurar que todos los tipos tengan prefijo.
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
        WHEN unit_type.name = 'oficina' THEN 'OFI'
        WHEN unit_type.name IN ('estacion', 'estacion_policial', 'subestacion', 'subestacion_policial') THEN 'EST'
        WHEN unit_type.name IN ('puesto', 'puesto_policial', 'destacamento', 'cuartel') THEN 'PUE'
        WHEN unit_type.name = 'grupo_operativo' THEN 'GRU'
        ELSE 'UNI'
    END,
    6,
    CONCAT('Código interno automático para ', REPLACE(unit_type.name, '_', ' '))
FROM unit_types unit_type
ON DUPLICATE KEY UPDATE
    prefix = VALUES(prefix),
    number_width = VALUES(number_width),
    description = VALUES(description),
    updated_at = CURRENT_TIMESTAMP;

-- Retirar los triggers antiguos que utilizaban el campo histórico code.
DROP TRIGGER IF EXISTS trg_structure_code_before_insert;
DROP TRIGGER IF EXISTS trg_structure_code_after_insert;
DROP TRIGGER IF EXISTS trg_structure_code_before_update;
DROP TRIGGER IF EXISTS trg_structure_code_after_update;
DROP TRIGGER IF EXISTS trg_structure_system_code_before_insert;
DROP TRIGGER IF EXISTS trg_structure_system_code_after_insert;
DROP TRIGGER IF EXISTS trg_structure_identifiers_before_update;
DROP TRIGGER IF EXISTS trg_structure_system_code_after_update;

-- Registrar códigos internos que ya pudieran existir por una ejecución anterior.
INSERT IGNORE INTO structure_system_code_registry (
    system_code,
    organizational_unit_id,
    unit_type_id,
    prefix
)
SELECT
    TRIM(unit_row.system_code),
    unit_row.id,
    unit_row.unit_type_id,
    COALESCE(prefix_row.prefix, SUBSTRING_INDEX(TRIM(unit_row.system_code), '-', 1), 'UNI')
FROM organizational_units unit_row
LEFT JOIN structure_code_prefixes prefix_row
  ON prefix_row.unit_type_id = unit_row.unit_type_id
WHERE NULLIF(TRIM(unit_row.system_code), '') IS NOT NULL;

-- Sincronizar las secuencias exclusivamente con los códigos internos.
INSERT INTO structure_code_sequences (prefix, last_number)
SELECT DISTINCT prefix, 0
FROM structure_code_prefixes
ON DUPLICATE KEY UPDATE prefix = VALUES(prefix);

UPDATE structure_code_sequences sequence_row
LEFT JOIN (
    SELECT
        registry_row.prefix,
        MAX(
            CASE
                WHEN registry_row.system_code REGEXP CONCAT('^', registry_row.prefix, '-[0-9]+$')
                THEN CAST(SUBSTRING_INDEX(registry_row.system_code, '-', -1) AS UNSIGNED)
                ELSE 0
            END
        ) AS maximum_number
    FROM structure_system_code_registry registry_row
    GROUP BY registry_row.prefix
) maximum_row
  ON maximum_row.prefix = sequence_row.prefix
SET sequence_row.last_number = COALESCE(maximum_row.maximum_number, 0);

DELIMITER $$

DROP PROCEDURE IF EXISTS sp_structure_assign_missing_system_codes$$
CREATE PROCEDURE sp_structure_assign_missing_system_codes()
BEGIN
    DECLARE v_done INT DEFAULT 0;
    DECLARE v_unit_id BIGINT UNSIGNED DEFAULT 0;
    DECLARE v_unit_type_id BIGINT UNSIGNED DEFAULT 0;
    DECLARE v_prefix VARCHAR(8) DEFAULT 'UNI';
    DECLARE v_width INT DEFAULT 6;
    DECLARE v_next_number BIGINT UNSIGNED DEFAULT 0;
    DECLARE v_candidate VARCHAR(50) DEFAULT NULL;
    DECLARE v_exists INT DEFAULT 1;

    DECLARE unit_cursor CURSOR FOR
        SELECT id, unit_type_id
        FROM organizational_units
        WHERE NULLIF(TRIM(system_code), '') IS NULL
        ORDER BY id;

    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = 1;

    OPEN unit_cursor;

    assignment_loop: LOOP
        FETCH unit_cursor INTO v_unit_id, v_unit_type_id;
        IF v_done = 1 THEN
            LEAVE assignment_loop;
        END IF;

        SELECT
            COALESCE(MAX(prefix), 'UNI'),
            COALESCE(MAX(number_width), 6)
          INTO v_prefix, v_width
        FROM structure_code_prefixes
        WHERE unit_type_id = v_unit_type_id;

        INSERT INTO structure_code_sequences (prefix, last_number)
        VALUES (v_prefix, 0)
        ON DUPLICATE KEY UPDATE prefix = VALUES(prefix);

        SET v_exists = 1;
        WHILE v_exists > 0 DO
            UPDATE structure_code_sequences
            SET last_number = last_number + 1
            WHERE prefix = v_prefix;

            SELECT last_number
              INTO v_next_number
            FROM structure_code_sequences
            WHERE prefix = v_prefix;

            SET v_candidate = CONCAT(v_prefix, '-', LPAD(v_next_number, v_width, '0'));

            SELECT COUNT(*)
              INTO v_exists
            FROM structure_system_code_registry
            WHERE system_code = v_candidate;
        END WHILE;

        UPDATE organizational_units
        SET system_code = v_candidate
        WHERE id = v_unit_id;

        INSERT INTO structure_system_code_registry (
            system_code,
            organizational_unit_id,
            unit_type_id,
            prefix
        ) VALUES (
            v_candidate,
            v_unit_id,
            v_unit_type_id,
            v_prefix
        );
    END LOOP;

    CLOSE unit_cursor;
END$$

DELIMITER ;

CALL sp_structure_assign_missing_system_codes();

-- Índice único en la tabla principal, agregado de forma compatible y rerunnable.
SET @system_code_index_exists := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'organizational_units'
      AND INDEX_NAME = 'uq_organizational_units_system_code'
);
SET @system_code_index_sql := IF(
    @system_code_index_exists = 0,
    'ALTER TABLE organizational_units ADD UNIQUE KEY uq_organizational_units_system_code (system_code)',
    'SELECT 1'
);
PREPARE system_code_index_statement FROM @system_code_index_sql;
EXECUTE system_code_index_statement;
DEALLOCATE PREPARE system_code_index_statement;

-- Mostrar el código interno en el administrador, conservando el histórico por separado.
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
    CASE
        WHEN UPPER(TRIM(COALESCE(unit.legacy_table, ''))) = 'TABCUAR'
          OR unit.structure_source = 'legacy'
        THEN 1 ELSE 0
    END AS is_protected,
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
    ) AS child_count,
    (
        SELECT COUNT(*)
        FROM organizational_units child
        WHERE child.parent_id = unit.id
          AND child.status = 'active'
          AND child.lifecycle_status = 'vigente'
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
  ON parent.id = unit.parent_id;

CREATE OR REPLACE VIEW vw_structure_code_previews AS
SELECT
    prefix_row.unit_type_id,
    prefix_row.prefix,
    prefix_row.number_width,
    CONCAT(
        prefix_row.prefix,
        '-',
        LPAD(COALESCE(sequence_row.last_number, 0) + 1, prefix_row.number_width, '0')
    ) AS next_code
FROM structure_code_prefixes prefix_row
LEFT JOIN structure_code_sequences sequence_row
  ON sequence_row.prefix = prefix_row.prefix;

-- Corregir la unidad superior automática de zonas sin excluir el registro institucional existente.
INSERT INTO structure_default_parents (group_key, parent_unit_id, description)
SELECT
    'zonas',
    candidate.id,
    'Dirección Nacional de Operaciones Policiales vigente'
FROM organizational_units candidate
JOIN unit_types candidate_type
  ON candidate_type.id = candidate.unit_type_id
WHERE candidate.status = 'active'
  AND candidate.lifecycle_status = 'vigente'
  AND (
        candidate.name COLLATE utf8mb4_unicode_ci = 'Dirección Nacional de Operaciones Policiales'
        OR candidate.name COLLATE utf8mb4_unicode_ci = 'Direccion Nacional de Operaciones Policiales'
        OR candidate.name COLLATE utf8mb4_unicode_ci LIKE '%Dirección%Operaciones Policiales%'
        OR candidate.name COLLATE utf8mb4_unicode_ci LIKE '%Direccion%Operaciones Policiales%'
        OR candidate.name COLLATE utf8mb4_unicode_ci LIKE '%OPERACIONES POLICIALES%'
  )
  AND candidate_type.name IN (
        'direccion_nacional',
        'subdireccion_nacional',
        'directorio_general',
        'dependencia'
  )
ORDER BY
    CASE candidate.structure_source
        WHEN 'accion_posterior' THEN 1
        WHEN 'moi_65_16' THEN 2
        ELSE 3
    END,
    CASE WHEN COALESCE(candidate.legacy_frozen, 0) = 0 THEN 1 ELSE 2 END,
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

DELIMITER $$

-- Edición segura: el código histórico y el código interno no se modifican.
DROP PROCEDURE IF EXISTS sp_structure_update_unit$$
CREATE PROCEDURE sp_structure_update_unit(
    IN p_unit_id BIGINT UNSIGNED,
    IN p_unit_type_id BIGINT UNSIGNED,
    IN p_name VARCHAR(200),
    IN p_short_name VARCHAR(100),
    IN p_code VARCHAR(50),
    IN p_moi_code VARCHAR(50),
    IN p_notes VARCHAR(255),
    IN p_actor VARCHAR(100)
)
BEGIN
    DECLARE v_parent_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE v_parent_type_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE v_old_name VARCHAR(200) DEFAULT NULL;
    DECLARE v_status VARCHAR(20) DEFAULT NULL;
    DECLARE v_lifecycle VARCHAR(40) DEFAULT NULL;
    DECLARE v_legacy_table VARCHAR(100) DEFAULT NULL;
    DECLARE v_source VARCHAR(40) DEFAULT NULL;
    DECLARE v_type_exists INT DEFAULT 0;
    DECLARE v_rule_count INT DEFAULT 0;
    DECLARE v_rule_allowed INT DEFAULT 0;
    DECLARE v_duplicate_count INT DEFAULT 0;
    DECLARE v_event_type VARCHAR(30) DEFAULT 'actualizacion';
    DECLARE v_event_notes VARCHAR(255) DEFAULT NULL;
    DECLARE v_actor VARCHAR(100) DEFAULT 'administrador_local';
    DECLARE v_notes VARCHAR(255) DEFAULT NULL;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    SET v_actor = COALESCE(NULLIF(TRIM(p_actor), ''), 'administrador_local');
    SET v_notes = NULLIF(TRIM(p_notes), '');

    IF NULLIF(TRIM(p_name), '') IS NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'El nombre oficial es obligatorio.';
    END IF;

    START TRANSACTION;

    SELECT
        parent_id,
        name,
        status,
        lifecycle_status,
        legacy_table,
        structure_source
      INTO
        v_parent_id,
        v_old_name,
        v_status,
        v_lifecycle,
        v_legacy_table,
        v_source
    FROM organizational_units
    WHERE id = p_unit_id
    LIMIT 1
    FOR UPDATE;

    IF v_old_name IS NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'La unidad seleccionada no existe.';
    END IF;

    IF UPPER(TRIM(COALESCE(v_legacy_table, ''))) = 'TABCUAR'
       OR v_source = 'legacy' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Este registro heredado está protegido.';
    END IF;

    IF v_status <> 'active' OR v_lifecycle <> 'vigente' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Para editar esta unidad primero debe reactivarla.';
    END IF;

    SELECT COUNT(*) INTO v_type_exists
    FROM unit_types
    WHERE id = p_unit_type_id;

    IF v_type_exists = 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'El tipo de unidad seleccionado no existe.';
    END IF;

    IF v_parent_id IS NOT NULL THEN
        SELECT unit_type_id INTO v_parent_type_id
        FROM organizational_units
        WHERE id = v_parent_id
        LIMIT 1;

        SELECT COUNT(*) INTO v_rule_count
        FROM structure_unit_type_rules
        WHERE parent_type_id = v_parent_type_id
          AND is_allowed = 1;

        IF v_rule_count > 0 THEN
            SELECT COUNT(*) INTO v_rule_allowed
            FROM structure_unit_type_rules
            WHERE parent_type_id = v_parent_type_id
              AND child_type_id = p_unit_type_id
              AND is_allowed = 1;

            IF v_rule_allowed = 0 THEN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'El tipo seleccionado no está permitido dentro de la unidad superior actual.';
            END IF;
        END IF;
    END IF;

    SELECT COUNT(*) INTO v_duplicate_count
    FROM organizational_units duplicate_row
    WHERE duplicate_row.id <> p_unit_id
      AND duplicate_row.parent_id <=> v_parent_id
      AND duplicate_row.status = 'active'
      AND duplicate_row.lifecycle_status = 'vigente'
      AND (
            UPPER(TRIM(duplicate_row.name)) = UPPER(TRIM(p_name))
            OR (
                NULLIF(TRIM(p_moi_code), '') IS NOT NULL
                AND UPPER(TRIM(COALESCE(duplicate_row.moi_code, ''))) = UPPER(TRIM(p_moi_code))
            )
      );

    IF v_duplicate_count > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Ya existe otra unidad activa con el mismo nombre o código MOI en ese nivel.';
    END IF;

    UPDATE organizational_units
    SET
        unit_type_id = p_unit_type_id,
        name = TRIM(p_name),
        short_name = NULLIF(TRIM(p_short_name), ''),
        moi_code = NULLIF(TRIM(p_moi_code), ''),
        lifecycle_notes = COALESCE(v_notes, lifecycle_notes),
        updated_at = CURRENT_TIMESTAMP
    WHERE id = p_unit_id;

    IF BINARY TRIM(v_old_name) <> BINARY TRIM(p_name) THEN
        SET v_event_type = 'renombre';
        SET v_event_notes = LEFT(CONCAT(
            'Nombre anterior: ', v_old_name,
            '. Nombre nuevo: ', TRIM(p_name),
            '.',
            COALESCE(CONCAT(' Motivo: ', v_notes), '')
        ), 255);
    ELSE
        SET v_event_type = 'actualizacion';
        SET v_event_notes = LEFT(CONCAT(
            'Datos institucionales actualizados.',
            COALESCE(CONCAT(' Motivo: ', v_notes), '')
        ), 255);
    END IF;

    INSERT INTO organizational_unit_lifecycle_events (
        organizational_unit_id,
        event_type,
        effective_from,
        notes,
        created_by
    ) VALUES (
        p_unit_id,
        v_event_type,
        CURDATE(),
        v_event_notes,
        v_actor
    );

    COMMIT;

    SELECT
        1 AS ok,
        p_unit_id AS unit_id,
        'La unidad fue actualizada correctamente.' AS message;
END$$

CREATE TRIGGER trg_structure_system_code_before_insert
BEFORE INSERT ON organizational_units
FOR EACH ROW
BEGIN
    DECLARE v_prefix VARCHAR(8) DEFAULT 'UNI';
    DECLARE v_width INT DEFAULT 6;
    DECLARE v_next_number BIGINT UNSIGNED DEFAULT 0;
    DECLARE v_candidate VARCHAR(50) DEFAULT NULL;
    DECLARE v_exists INT DEFAULT 1;

    IF NULLIF(TRIM(NEW.system_code), '') IS NULL THEN
        SELECT
            COALESCE(MAX(prefix), 'UNI'),
            COALESCE(MAX(number_width), 6)
          INTO v_prefix, v_width
        FROM structure_code_prefixes
        WHERE unit_type_id = NEW.unit_type_id;

        INSERT INTO structure_code_sequences (prefix, last_number)
        VALUES (v_prefix, 0)
        ON DUPLICATE KEY UPDATE prefix = VALUES(prefix);

        WHILE v_exists > 0 DO
            UPDATE structure_code_sequences
            SET last_number = last_number + 1
            WHERE prefix = v_prefix;

            SELECT last_number
              INTO v_next_number
            FROM structure_code_sequences
            WHERE prefix = v_prefix;

            SET v_candidate = CONCAT(v_prefix, '-', LPAD(v_next_number, v_width, '0'));

            SELECT COUNT(*)
              INTO v_exists
            FROM structure_system_code_registry
            WHERE system_code = v_candidate;
        END WHILE;

        SET NEW.system_code = v_candidate;
    END IF;
END$$

CREATE TRIGGER trg_structure_system_code_after_insert
AFTER INSERT ON organizational_units
FOR EACH ROW
BEGIN
    INSERT INTO structure_system_code_registry (
        system_code,
        organizational_unit_id,
        unit_type_id,
        prefix
    ) VALUES (
        NEW.system_code,
        NEW.id,
        NEW.unit_type_id,
        SUBSTRING_INDEX(NEW.system_code, '-', 1)
    );
END$$

CREATE TRIGGER trg_structure_identifiers_before_update
BEFORE UPDATE ON organizational_units
FOR EACH ROW
BEGIN
    -- Ambos identificadores son permanentes. El histórico puede estar vacío y debe mantenerse así.
    SET NEW.code = OLD.code;
    SET NEW.system_code = OLD.system_code;
END$$

CREATE TRIGGER trg_structure_system_code_after_update
AFTER UPDATE ON organizational_units
FOR EACH ROW
BEGIN
    INSERT INTO structure_system_code_registry (
        system_code,
        organizational_unit_id,
        unit_type_id,
        prefix
    ) VALUES (
        NEW.system_code,
        NEW.id,
        NEW.unit_type_id,
        SUBSTRING_INDEX(NEW.system_code, '-', 1)
    )
    ON DUPLICATE KEY UPDATE
        unit_type_id = VALUES(unit_type_id),
        prefix = VALUES(prefix);
END$$

DELIMITER ;

SELECT
    COUNT(*) AS total_unidades,
    SUM(NULLIF(TRIM(system_code), '') IS NOT NULL) AS con_codigo_interno,
    SUM(NULLIF(TRIM(system_code), '') IS NULL) AS sin_codigo_interno,
    COUNT(DISTINCT system_code) AS codigos_internos_distintos
FROM organizational_units;

SELECT
    parent_config.group_key,
    parent_config.parent_unit_id,
    parent_unit.name AS parent_name,
    parent_unit.system_code AS parent_system_code
FROM structure_default_parents parent_config
LEFT JOIN organizational_units parent_unit
  ON parent_unit.id = parent_config.parent_unit_id
WHERE parent_config.group_key = 'zonas';

SELECT
    unit_type.name AS unit_type,
    COUNT(*) AS total,
    MIN(unit_row.system_code) AS first_system_code,
    MAX(unit_row.system_code) AS last_system_code
FROM organizational_units unit_row
JOIN unit_types unit_type
  ON unit_type.id = unit_row.unit_type_id
GROUP BY unit_type.name
ORDER BY unit_type.name;
