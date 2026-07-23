-- Configuración sencilla de la estructura organizacional
-- Complementa database/estructura_admin_db.sql.
-- Permite crear zonas, direcciones y servicios principales y refuerza la desactivación segura.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

DELIMITER $$

DROP PROCEDURE IF EXISTS sp_structure_create_root_unit$$
CREATE PROCEDURE sp_structure_create_root_unit(
    IN p_parent_id BIGINT UNSIGNED,
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
    DECLARE v_parent_level INT DEFAULT 0;
    DECLARE v_parent_moi_level INT DEFAULT 0;
    DECLARE v_parent_status VARCHAR(20) DEFAULT NULL;
    DECLARE v_parent_lifecycle VARCHAR(40) DEFAULT NULL;
    DECLARE v_type_name VARCHAR(100) DEFAULT NULL;
    DECLARE v_rule_count INT DEFAULT 0;
    DECLARE v_rule_allowed INT DEFAULT 0;
    DECLARE v_duplicate_count INT DEFAULT 0;
    DECLARE v_new_unit_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE v_actor VARCHAR(100) DEFAULT 'administrador_local';
    DECLARE v_notes VARCHAR(255) DEFAULT NULL;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    SET v_parent_id = NULLIF(p_parent_id, 0);
    SET v_actor = COALESCE(NULLIF(TRIM(p_actor), ''), 'administrador_local');
    SET v_notes = NULLIF(TRIM(p_notes), '');

    IF NULLIF(TRIM(p_name), '') IS NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Escriba el nombre de la zona, dirección o servicio.';
    END IF;

    START TRANSACTION;

    SELECT name
      INTO v_type_name
    FROM unit_types
    WHERE id = p_unit_type_id
    LIMIT 1;

    IF v_type_name IS NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'El tipo seleccionado no existe.';
    END IF;

    IF v_type_name NOT IN (
        'zona_policial',
        'region_policial',
        'direccion_nacional',
        'subdireccion_nacional',
        'directorio_general',
        'servicio_policial'
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'En este nivel solo se pueden crear zonas, direcciones o servicios principales.';
    END IF;

    IF v_parent_id IS NOT NULL THEN
        SELECT
            unit_type_id,
            COALESCE(level, 0),
            COALESCE(moi_level, 0),
            status,
            lifecycle_status
          INTO
            v_parent_type_id,
            v_parent_level,
            v_parent_moi_level,
            v_parent_status,
            v_parent_lifecycle
        FROM organizational_units
        WHERE id = v_parent_id
        LIMIT 1
        FOR UPDATE;

        IF v_parent_type_id IS NULL THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'La unidad superior seleccionada no existe.';
        END IF;

        IF v_parent_status <> 'active' OR v_parent_lifecycle <> 'vigente' THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'La unidad superior debe estar activa y vigente.';
        END IF;

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
                    SET MESSAGE_TEXT = 'Ese tipo no está permitido debajo de la unidad superior seleccionada.';
            END IF;
        END IF;
    END IF;

    SELECT COUNT(*) INTO v_duplicate_count
    FROM organizational_units duplicate_row
    WHERE duplicate_row.parent_id <=> v_parent_id
      AND duplicate_row.status = 'active'
      AND duplicate_row.lifecycle_status = 'vigente'
      AND (
            UPPER(TRIM(duplicate_row.name)) = UPPER(TRIM(p_name))
            OR (
                NULLIF(TRIM(p_code), '') IS NOT NULL
                AND UPPER(TRIM(COALESCE(duplicate_row.code, ''))) = UPPER(TRIM(p_code))
            )
      );

    IF v_duplicate_count > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Ya existe una unidad activa con el mismo nombre o código en ese nivel.';
    END IF;

    INSERT INTO organizational_units (
        parent_id,
        unit_type_id,
        code,
        moi_code,
        name,
        short_name,
        level,
        moi_level,
        is_operational,
        is_administrative,
        command_structure,
        command_relationship,
        territorial_scope,
        valid_from,
        lifecycle_status,
        structure_source,
        legacy_frozen,
        lifecycle_notes,
        status,
        created_at,
        updated_at
    ) VALUES (
        v_parent_id,
        p_unit_type_id,
        NULLIF(TRIM(p_code), ''),
        NULLIF(TRIM(p_moi_code), ''),
        TRIM(p_name),
        NULLIF(TRIM(p_short_name), ''),
        CASE WHEN v_parent_id IS NULL THEN 1 ELSE v_parent_level + 1 END,
        CASE WHEN v_parent_id IS NULL THEN 1 ELSE v_parent_moi_level + 1 END,
        CASE WHEN v_type_name IN ('zona_policial', 'region_policial', 'servicio_policial') THEN 1 ELSE 0 END,
        CASE WHEN v_type_name IN ('direccion_nacional', 'subdireccion_nacional', 'directorio_general') THEN 1 ELSE 0 END,
        'no_definido',
        'no_definido',
        'no_definido',
        CURDATE(),
        'vigente',
        'accion_posterior',
        0,
        COALESCE(v_notes, 'Creada desde la configuración sencilla de estructura.'),
        'active',
        CURRENT_TIMESTAMP,
        CURRENT_TIMESTAMP
    );

    SET v_new_unit_id = LAST_INSERT_ID();

    INSERT INTO organizational_unit_lifecycle_events (
        organizational_unit_id,
        event_type,
        effective_from,
        notes,
        created_by
    ) VALUES (
        v_new_unit_id,
        'creacion',
        CURDATE(),
        LEFT(CONCAT(
            'Unidad principal creada desde configuración.',
            COALESCE(CONCAT(' Motivo: ', v_notes), '')
        ), 255),
        v_actor
    );

    COMMIT;

    SELECT
        1 AS ok,
        v_new_unit_id AS unit_id,
        'La unidad principal fue creada correctamente.' AS message;
END$$

DROP PROCEDURE IF EXISTS sp_structure_deactivate_unit$$
CREATE PROCEDURE sp_structure_deactivate_unit(
    IN p_unit_id BIGINT UNSIGNED,
    IN p_notes VARCHAR(255),
    IN p_actor VARCHAR(100)
)
BEGIN
    DECLARE v_unit_name VARCHAR(200) DEFAULT NULL;
    DECLARE v_status VARCHAR(20) DEFAULT NULL;
    DECLARE v_lifecycle VARCHAR(40) DEFAULT NULL;
    DECLARE v_legacy_table VARCHAR(100) DEFAULT NULL;
    DECLARE v_source VARCHAR(40) DEFAULT NULL;
    DECLARE v_active_children INT DEFAULT 0;
    DECLARE v_workforce_total INT DEFAULT 0;
    DECLARE v_assignments_total INT DEFAULT 0;
    DECLARE v_positions_total INT DEFAULT 0;
    DECLARE v_actor VARCHAR(100) DEFAULT 'administrador_local';
    DECLARE v_notes VARCHAR(255) DEFAULT NULL;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    SET v_actor = COALESCE(NULLIF(TRIM(p_actor), ''), 'administrador_local');
    SET v_notes = NULLIF(TRIM(p_notes), '');

    IF v_notes IS NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Escriba el motivo de la desactivación.';
    END IF;

    START TRANSACTION;

    SELECT name, status, lifecycle_status, legacy_table, structure_source
      INTO v_unit_name, v_status, v_lifecycle, v_legacy_table, v_source
    FROM organizational_units
    WHERE id = p_unit_id
    LIMIT 1
    FOR UPDATE;

    IF v_unit_name IS NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'La unidad seleccionada no existe.';
    END IF;

    IF UPPER(TRIM(COALESCE(v_legacy_table, ''))) = 'TABCUAR'
       OR v_source = 'legacy' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Este registro heredado está protegido y no puede desactivarse.';
    END IF;

    IF v_status <> 'active' OR v_lifecycle <> 'vigente' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'La unidad ya se encuentra inactiva.';
    END IF;

    SELECT COUNT(*) INTO v_active_children
    FROM organizational_units child
    WHERE child.parent_id = p_unit_id
      AND child.status = 'active'
      AND child.lifecycle_status = 'vigente';

    SELECT COUNT(*) INTO v_workforce_total
    FROM workforce_unit_matches match_row
    WHERE match_row.matched_unit_id = p_unit_id;

    SELECT COUNT(*) INTO v_assignments_total
    FROM unit_assignments assignment_row
    WHERE assignment_row.organizational_unit_id = p_unit_id
      AND assignment_row.status = 'active'
      AND (assignment_row.end_date IS NULL OR assignment_row.end_date >= CURDATE());

    SELECT COUNT(*) INTO v_positions_total
    FROM positions position_row
    WHERE position_row.organizational_unit_id = p_unit_id;

    IF v_active_children > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Primero debe mover o desactivar las dependencias que están dentro de esta unidad.';
    END IF;

    IF v_workforce_total > 0 OR v_assignments_total > 0 OR v_positions_total > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'La unidad todavía tiene personal, asignaciones o posiciones relacionadas. Debe reubicarlas antes de desactivarla.';
    END IF;

    UPDATE organizational_units
    SET
        status = 'inactive',
        lifecycle_status = 'suprimida',
        valid_to = CURDATE(),
        lifecycle_notes = v_notes,
        updated_at = CURRENT_TIMESTAMP
    WHERE id = p_unit_id;

    INSERT INTO organizational_unit_lifecycle_events (
        organizational_unit_id,
        event_type,
        effective_from,
        notes,
        created_by
    ) VALUES (
        p_unit_id,
        'supresion',
        CURDATE(),
        LEFT(CONCAT('Unidad desactivada. Motivo: ', v_notes), 255),
        v_actor
    );

    COMMIT;

    SELECT
        1 AS ok,
        p_unit_id AS unit_id,
        'La unidad fue desactivada sin eliminar su historial.' AS message;
END$$

DELIMITER ;

SELECT
    'estructura_configuracion_simple' AS component,
    COUNT(*) AS root_unit_types
FROM unit_types
WHERE name IN (
    'zona_policial',
    'region_policial',
    'direccion_nacional',
    'subdireccion_nacional',
    'directorio_general',
    'servicio_policial'
);
