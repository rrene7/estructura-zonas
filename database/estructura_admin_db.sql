-- Administrador de estructura institucional
-- La lógica de validación, jerarquía, auditoría y movimientos se concentra en MySQL.
-- Ejecutar indicando la base de datos destino:
-- mysql -u root estructura_zonas_test < database/estructura_admin_db.sql

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS structure_unit_type_rules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    parent_type_id BIGINT UNSIGNED NOT NULL,
    child_type_id BIGINT UNSIGNED NOT NULL,
    is_allowed TINYINT(1) NOT NULL DEFAULT 1,
    notes VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_structure_type_rule (parent_type_id, child_type_id),
    KEY idx_structure_type_rule_parent (parent_type_id, is_allowed),
    KEY idx_structure_type_rule_child (child_type_id, is_allowed),
    CONSTRAINT fk_structure_type_rule_parent
        FOREIGN KEY (parent_type_id) REFERENCES unit_types (id),
    CONSTRAINT fk_structure_type_rule_child
        FOREIGN KEY (child_type_id) REFERENCES unit_types (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reglas iniciales. Cuando un tipo superior tiene reglas configuradas,
-- solo podrá recibir los tipos subordinados autorizados aquí.
INSERT INTO structure_unit_type_rules
    (parent_type_id, child_type_id, is_allowed, notes)
SELECT
    parent_type.id,
    child_type.id,
    1,
    'Regla inicial del administrador de estructura'
FROM unit_types parent_type
JOIN unit_types child_type
WHERE
       (parent_type.name = 'institucion' AND child_type.name IN (
            'directorio_general', 'direccion_nacional', 'subdireccion_nacional',
            'zona_policial', 'region_policial', 'servicio_policial'
       ))
    OR (parent_type.name IN ('directorio_general', 'direccion_nacional', 'subdireccion_nacional') AND child_type.name IN (
            'directorio_personal', 'directorio_coordinacion', 'directorio_especial',
            'departamento', 'division', 'oficina', 'seccion', 'dependencia',
            'servicio_policial', 'area', 'area_policial'
       ))
    OR (parent_type.name IN ('zona_policial', 'region_policial') AND child_type.name IN (
            'area', 'area_policial', 'estacion', 'estacion_policial', 'cuartel',
            'servicio_zonal', 'servicio_policial', 'sector_policial', 'dependencia'
       ))
    OR (parent_type.name IN ('area', 'area_policial') AND child_type.name IN (
            'estacion', 'estacion_policial', 'subestacion', 'subestacion_policial',
            'puesto', 'puesto_policial', 'destacamento', 'sector_policial',
            'servicio_zonal', 'seccion', 'dependencia', 'grupo_operativo'
       ))
    OR (parent_type.name IN ('departamento', 'division', 'oficina', 'dependencia') AND child_type.name IN (
            'departamento', 'division', 'oficina', 'seccion', 'dependencia',
            'grupo_operativo', 'puesto'
       ))
    OR (parent_type.name IN ('cuartel', 'estacion', 'estacion_policial', 'subestacion', 'subestacion_policial') AND child_type.name IN (
            'subestacion', 'subestacion_policial', 'puesto', 'puesto_policial',
            'destacamento', 'seccion', 'dependencia', 'grupo_operativo'
       ))
    OR (parent_type.name IN ('servicio_policial', 'servicio_zonal') AND child_type.name IN (
            'area', 'area_policial', 'grupo_operativo', 'departamento',
            'division', 'seccion', 'oficina', 'dependencia',
            'estacion_policial', 'puesto_policial'
       ))
ON DUPLICATE KEY UPDATE
    is_allowed = VALUES(is_allowed),
    notes = VALUES(notes),
    updated_at = CURRENT_TIMESTAMP;

CREATE OR REPLACE VIEW vw_structure_admin_units AS
SELECT
    unit.id,
    unit.parent_id,
    unit.unit_type_id,
    unit_type.name AS unit_type_name,
    unit_type.description AS unit_type_description,
    unit.code,
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
    parent.code AS parent_code,
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
JOIN unit_types unit_type ON unit_type.id = unit.unit_type_id
LEFT JOIN organizational_units parent ON parent.id = unit.parent_id;

CREATE OR REPLACE VIEW vw_structure_admin_impact AS
SELECT
    unit.id AS unit_id,
    (
        SELECT COUNT(*)
        FROM workforce_unit_matches match_row
        WHERE match_row.matched_unit_id = unit.id
    ) AS workforce_total,
    (
        SELECT COUNT(*)
        FROM unit_assignments assignment_row
        WHERE assignment_row.organizational_unit_id = unit.id
    ) AS assignments_total,
    (
        SELECT COUNT(*)
        FROM positions position_row
        WHERE position_row.organizational_unit_id = unit.id
    ) AS positions_total,
    (
        SELECT COUNT(*)
        FROM structure_action_routing action_row
        WHERE action_row.old_unit_id = unit.id
           OR action_row.new_unit_id = unit.id
    ) AS actions_total,
    (
        SELECT COUNT(*)
        FROM organizational_units child
        WHERE child.parent_id = unit.id
    ) AS children_total,
    (
        SELECT COUNT(*)
        FROM organizational_units child
        WHERE child.parent_id = unit.id
          AND child.status = 'active'
          AND child.lifecycle_status = 'vigente'
    ) AS active_children_total
FROM organizational_units unit;

CREATE OR REPLACE VIEW vw_structure_admin_history AS
SELECT
    event_row.id,
    event_row.organizational_unit_id,
    event_row.event_type,
    event_row.effective_from,
    event_row.effective_to,
    event_row.replacement_unit_id,
    replacement.name AS replacement_name,
    event_row.source_document,
    event_row.notes,
    event_row.created_by,
    event_row.created_at
FROM organizational_unit_lifecycle_events event_row
LEFT JOIN organizational_units replacement
    ON replacement.id = event_row.replacement_unit_id;

DELIMITER $$

DROP PROCEDURE IF EXISTS sp_structure_get_allowed_child_types$$
CREATE PROCEDURE sp_structure_get_allowed_child_types(IN p_parent_id BIGINT UNSIGNED)
BEGIN
    DECLARE v_parent_type_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE v_parent_status VARCHAR(20) DEFAULT NULL;
    DECLARE v_parent_lifecycle VARCHAR(40) DEFAULT NULL;
    DECLARE v_rule_count INT DEFAULT 0;

    SELECT unit_type_id, status, lifecycle_status
      INTO v_parent_type_id, v_parent_status, v_parent_lifecycle
    FROM organizational_units
    WHERE id = p_parent_id
    LIMIT 1;

    IF v_parent_type_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La unidad superior seleccionada no existe.';
    END IF;

    IF v_parent_status <> 'active' OR v_parent_lifecycle <> 'vigente' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La unidad superior debe estar activa y vigente.';
    END IF;

    SELECT COUNT(*) INTO v_rule_count
    FROM structure_unit_type_rules
    WHERE parent_type_id = v_parent_type_id
      AND is_allowed = 1;

    SELECT
        unit_type.id,
        unit_type.name,
        unit_type.description
    FROM unit_types unit_type
    WHERE v_rule_count = 0
       OR EXISTS (
            SELECT 1
            FROM structure_unit_type_rules rule_row
            WHERE rule_row.parent_type_id = v_parent_type_id
              AND rule_row.child_type_id = unit_type.id
              AND rule_row.is_allowed = 1
       )
    ORDER BY unit_type.name;
END$$

DROP PROCEDURE IF EXISTS sp_structure_get_valid_parents$$
CREATE PROCEDURE sp_structure_get_valid_parents(IN p_unit_id BIGINT UNSIGNED)
BEGIN
    DECLARE v_unit_type_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE v_depth INT DEFAULT 0;
    DECLARE v_rows INT DEFAULT 1;

    SELECT unit_type_id INTO v_unit_type_id
    FROM organizational_units
    WHERE id = p_unit_id
    LIMIT 1;

    IF v_unit_type_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La unidad seleccionada no existe.';
    END IF;

    DROP TEMPORARY TABLE IF EXISTS tmp_structure_descendants;
    CREATE TEMPORARY TABLE tmp_structure_descendants (
        id BIGINT UNSIGNED NOT NULL,
        depth INT NOT NULL,
        PRIMARY KEY (id)
    ) ENGINE=MEMORY;

    INSERT INTO tmp_structure_descendants (id, depth)
    VALUES (p_unit_id, 0);

    WHILE v_rows > 0 DO
        INSERT IGNORE INTO tmp_structure_descendants (id, depth)
        SELECT child.id, v_depth + 1
        FROM organizational_units child
        JOIN tmp_structure_descendants parent_row
          ON parent_row.id = child.parent_id
         AND parent_row.depth = v_depth;

        SET v_rows = ROW_COUNT();
        SET v_depth = v_depth + 1;
    END WHILE;

    SELECT candidate.*
    FROM vw_structure_admin_units candidate
    WHERE candidate.status = 'active'
      AND candidate.lifecycle_status = 'vigente'
      AND candidate.is_protected = 0
      AND NOT EXISTS (
            SELECT 1
            FROM tmp_structure_descendants descendant
            WHERE descendant.id = candidate.id
      )
      AND (
            NOT EXISTS (
                SELECT 1
                FROM structure_unit_type_rules rule_check
                WHERE rule_check.parent_type_id = candidate.unit_type_id
                  AND rule_check.is_allowed = 1
            )
            OR EXISTS (
                SELECT 1
                FROM structure_unit_type_rules rule_match
                WHERE rule_match.parent_type_id = candidate.unit_type_id
                  AND rule_match.child_type_id = v_unit_type_id
                  AND rule_match.is_allowed = 1
            )
      )
    ORDER BY COALESCE(candidate.level, 99), candidate.name, candidate.id;

    DROP TEMPORARY TABLE IF EXISTS tmp_structure_descendants;
END$$

DROP PROCEDURE IF EXISTS sp_structure_create_unit$$
CREATE PROCEDURE sp_structure_create_unit(
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
    DECLARE v_parent_type_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE v_parent_level INT DEFAULT 0;
    DECLARE v_parent_moi_level INT DEFAULT 0;
    DECLARE v_parent_status VARCHAR(20) DEFAULT NULL;
    DECLARE v_parent_lifecycle VARCHAR(40) DEFAULT NULL;
    DECLARE v_parent_legacy_table VARCHAR(100) DEFAULT NULL;
    DECLARE v_parent_source VARCHAR(40) DEFAULT NULL;
    DECLARE v_type_exists INT DEFAULT 0;
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

    SET v_actor = COALESCE(NULLIF(TRIM(p_actor), ''), 'administrador_local');
    SET v_notes = NULLIF(TRIM(p_notes), '');

    IF p_parent_id IS NULL OR p_parent_id = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Seleccione una unidad superior.';
    END IF;

    IF NULLIF(TRIM(p_name), '') IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El nombre oficial es obligatorio.';
    END IF;

    START TRANSACTION;

    SELECT
        unit_type_id,
        COALESCE(level, 0),
        COALESCE(moi_level, 0),
        status,
        lifecycle_status,
        legacy_table,
        structure_source
      INTO
        v_parent_type_id,
        v_parent_level,
        v_parent_moi_level,
        v_parent_status,
        v_parent_lifecycle,
        v_parent_legacy_table,
        v_parent_source
    FROM organizational_units
    WHERE id = p_parent_id
    LIMIT 1
    FOR UPDATE;

    IF v_parent_type_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La unidad superior seleccionada no existe.';
    END IF;

    IF v_parent_status <> 'active' OR v_parent_lifecycle <> 'vigente' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La unidad superior debe estar activa y vigente.';
    END IF;

    IF UPPER(TRIM(COALESCE(v_parent_legacy_table, ''))) = 'TABCUAR'
       OR v_parent_source = 'legacy' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'No se pueden crear dependencias bajo un registro heredado protegido.';
    END IF;

    SELECT COUNT(*) INTO v_type_exists
    FROM unit_types
    WHERE id = p_unit_type_id;

    IF v_type_exists = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El tipo de unidad seleccionado no existe.';
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
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ese tipo de unidad no está permitido debajo de la unidad superior seleccionada.';
        END IF;
    END IF;

    SELECT COUNT(*) INTO v_duplicate_count
    FROM organizational_units duplicate_row
    WHERE duplicate_row.parent_id = p_parent_id
      AND duplicate_row.status = 'active'
      AND duplicate_row.lifecycle_status = 'vigente'
      AND (
            UPPER(TRIM(duplicate_row.name)) = UPPER(TRIM(p_name))
            OR (
                NULLIF(TRIM(p_code), '') IS NOT NULL
                AND UPPER(TRIM(COALESCE(duplicate_row.code, ''))) = UPPER(TRIM(p_code))
            )
            OR (
                NULLIF(TRIM(p_moi_code), '') IS NOT NULL
                AND UPPER(TRIM(COALESCE(duplicate_row.moi_code, ''))) = UPPER(TRIM(p_moi_code))
            )
      );

    IF v_duplicate_count > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ya existe una unidad activa con el mismo nombre o código dentro de esa unidad superior.';
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
        p_parent_id,
        p_unit_type_id,
        NULLIF(TRIM(p_code), ''),
        NULLIF(TRIM(p_moi_code), ''),
        TRIM(p_name),
        NULLIF(TRIM(p_short_name), ''),
        v_parent_level + 1,
        v_parent_moi_level + 1,
        0,
        1,
        'no_definido',
        'no_definido',
        'no_definido',
        CURDATE(),
        'vigente',
        'accion_posterior',
        0,
        COALESCE(v_notes, 'Creada desde el administrador de estructura.'),
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
        LEFT(CONCAT('Unidad creada. ', COALESCE(CONCAT('Motivo: ', v_notes), '')), 255),
        v_actor
    );

    COMMIT;

    SELECT
        1 AS ok,
        v_new_unit_id AS unit_id,
        'La unidad fue creada correctamente.' AS message;
END$$

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
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El nombre oficial es obligatorio.';
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
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La unidad seleccionada no existe.';
    END IF;

    IF UPPER(TRIM(COALESCE(v_legacy_table, ''))) = 'TABCUAR'
       OR v_source = 'legacy' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Este registro heredado está protegido. Edite la unidad institucional vigente equivalente.';
    END IF;

    IF v_status <> 'active' OR v_lifecycle <> 'vigente' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Para editar esta unidad primero debe reactivarla.';
    END IF;

    SELECT COUNT(*) INTO v_type_exists
    FROM unit_types
    WHERE id = p_unit_type_id;

    IF v_type_exists = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El tipo de unidad seleccionado no existe.';
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
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El tipo seleccionado no está permitido dentro de la unidad superior actual.';
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
                NULLIF(TRIM(p_code), '') IS NOT NULL
                AND UPPER(TRIM(COALESCE(duplicate_row.code, ''))) = UPPER(TRIM(p_code))
            )
            OR (
                NULLIF(TRIM(p_moi_code), '') IS NOT NULL
                AND UPPER(TRIM(COALESCE(duplicate_row.moi_code, ''))) = UPPER(TRIM(p_moi_code))
            )
      );

    IF v_duplicate_count > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ya existe otra unidad activa con el mismo nombre o código en ese nivel.';
    END IF;

    UPDATE organizational_units
    SET
        unit_type_id = p_unit_type_id,
        name = TRIM(p_name),
        short_name = NULLIF(TRIM(p_short_name), ''),
        code = NULLIF(TRIM(p_code), ''),
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

DROP PROCEDURE IF EXISTS sp_structure_move_unit$$
CREATE PROCEDURE sp_structure_move_unit(
    IN p_unit_id BIGINT UNSIGNED,
    IN p_new_parent_id BIGINT UNSIGNED,
    IN p_notes VARCHAR(255),
    IN p_actor VARCHAR(100)
)
BEGIN
    DECLARE v_old_parent_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE v_unit_type_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE v_unit_name VARCHAR(200) DEFAULT NULL;
    DECLARE v_status VARCHAR(20) DEFAULT NULL;
    DECLARE v_lifecycle VARCHAR(40) DEFAULT NULL;
    DECLARE v_legacy_table VARCHAR(100) DEFAULT NULL;
    DECLARE v_source VARCHAR(40) DEFAULT NULL;
    DECLARE v_new_parent_type_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE v_new_parent_name VARCHAR(200) DEFAULT NULL;
    DECLARE v_new_parent_level INT DEFAULT 0;
    DECLARE v_new_parent_moi_level INT DEFAULT 0;
    DECLARE v_new_parent_status VARCHAR(20) DEFAULT NULL;
    DECLARE v_new_parent_lifecycle VARCHAR(40) DEFAULT NULL;
    DECLARE v_new_parent_legacy_table VARCHAR(100) DEFAULT NULL;
    DECLARE v_new_parent_source VARCHAR(40) DEFAULT NULL;
    DECLARE v_old_parent_name VARCHAR(200) DEFAULT NULL;
    DECLARE v_rule_count INT DEFAULT 0;
    DECLARE v_rule_allowed INT DEFAULT 0;
    DECLARE v_cycle_count INT DEFAULT 0;
    DECLARE v_duplicate_count INT DEFAULT 0;
    DECLARE v_depth INT DEFAULT 0;
    DECLARE v_rows INT DEFAULT 1;
    DECLARE v_actor VARCHAR(100) DEFAULT 'administrador_local';
    DECLARE v_notes VARCHAR(255) DEFAULT NULL;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        DROP TEMPORARY TABLE IF EXISTS tmp_structure_subtree;
        RESIGNAL;
    END;

    SET v_actor = COALESCE(NULLIF(TRIM(p_actor), ''), 'administrador_local');
    SET v_notes = NULLIF(TRIM(p_notes), '');

    IF p_new_parent_id IS NULL OR p_new_parent_id = 0 OR p_new_parent_id = p_unit_id THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Seleccione una unidad superior válida.';
    END IF;

    IF v_notes IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Indique el motivo del movimiento.';
    END IF;

    START TRANSACTION;

    SELECT
        parent_id,
        unit_type_id,
        name,
        status,
        lifecycle_status,
        legacy_table,
        structure_source
      INTO
        v_old_parent_id,
        v_unit_type_id,
        v_unit_name,
        v_status,
        v_lifecycle,
        v_legacy_table,
        v_source
    FROM organizational_units
    WHERE id = p_unit_id
    LIMIT 1
    FOR UPDATE;

    IF v_unit_name IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La unidad que desea mover no existe.';
    END IF;

    IF UPPER(TRIM(COALESCE(v_legacy_table, ''))) = 'TABCUAR'
       OR v_source = 'legacy' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Este registro heredado está protegido y no puede moverse.';
    END IF;

    IF v_status <> 'active' OR v_lifecycle <> 'vigente' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Solo se pueden mover unidades activas y vigentes.';
    END IF;

    SELECT
        unit_type_id,
        name,
        COALESCE(level, 0),
        COALESCE(moi_level, 0),
        status,
        lifecycle_status,
        legacy_table,
        structure_source
      INTO
        v_new_parent_type_id,
        v_new_parent_name,
        v_new_parent_level,
        v_new_parent_moi_level,
        v_new_parent_status,
        v_new_parent_lifecycle,
        v_new_parent_legacy_table,
        v_new_parent_source
    FROM organizational_units
    WHERE id = p_new_parent_id
    LIMIT 1
    FOR UPDATE;

    IF v_new_parent_name IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La nueva unidad superior no existe.';
    END IF;

    IF v_new_parent_status <> 'active' OR v_new_parent_lifecycle <> 'vigente' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La nueva unidad superior debe estar activa y vigente.';
    END IF;

    IF UPPER(TRIM(COALESCE(v_new_parent_legacy_table, ''))) = 'TABCUAR'
       OR v_new_parent_source = 'legacy' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La nueva unidad superior no puede ser un registro heredado protegido.';
    END IF;

    DROP TEMPORARY TABLE IF EXISTS tmp_structure_subtree;
    CREATE TEMPORARY TABLE tmp_structure_subtree (
        id BIGINT UNSIGNED NOT NULL,
        depth INT NOT NULL,
        PRIMARY KEY (id)
    ) ENGINE=MEMORY;

    INSERT INTO tmp_structure_subtree (id, depth)
    VALUES (p_unit_id, 0);

    WHILE v_rows > 0 DO
        INSERT IGNORE INTO tmp_structure_subtree (id, depth)
        SELECT child.id, v_depth + 1
        FROM organizational_units child
        JOIN tmp_structure_subtree parent_row
          ON parent_row.id = child.parent_id
         AND parent_row.depth = v_depth;

        SET v_rows = ROW_COUNT();
        SET v_depth = v_depth + 1;
    END WHILE;

    SELECT COUNT(*) INTO v_cycle_count
    FROM tmp_structure_subtree
    WHERE id = p_new_parent_id;

    IF v_cycle_count > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'No puede mover una unidad dentro de una de sus propias dependencias.';
    END IF;

    SELECT COUNT(*) INTO v_rule_count
    FROM structure_unit_type_rules
    WHERE parent_type_id = v_new_parent_type_id
      AND is_allowed = 1;

    IF v_rule_count > 0 THEN
        SELECT COUNT(*) INTO v_rule_allowed
        FROM structure_unit_type_rules
        WHERE parent_type_id = v_new_parent_type_id
          AND child_type_id = v_unit_type_id
          AND is_allowed = 1;

        IF v_rule_allowed = 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El tipo de esta unidad no está permitido debajo del nuevo nivel seleccionado.';
        END IF;
    END IF;

    SELECT COUNT(*) INTO v_duplicate_count
    FROM organizational_units duplicate_row
    JOIN organizational_units source_row ON source_row.id = p_unit_id
    WHERE duplicate_row.id <> p_unit_id
      AND duplicate_row.parent_id = p_new_parent_id
      AND duplicate_row.status = 'active'
      AND duplicate_row.lifecycle_status = 'vigente'
      AND (
            UPPER(TRIM(duplicate_row.name)) = UPPER(TRIM(source_row.name))
            OR (
                NULLIF(TRIM(source_row.code), '') IS NOT NULL
                AND UPPER(TRIM(COALESCE(duplicate_row.code, ''))) = UPPER(TRIM(source_row.code))
            )
      );

    IF v_duplicate_count > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ya existe una unidad equivalente dentro de la nueva unidad superior.';
    END IF;

    IF v_old_parent_id IS NOT NULL THEN
        SELECT name INTO v_old_parent_name
        FROM organizational_units
        WHERE id = v_old_parent_id
        LIMIT 1;
    END IF;

    UPDATE organizational_units unit_row
    JOIN tmp_structure_subtree subtree ON subtree.id = unit_row.id
    SET
        unit_row.parent_id = CASE
            WHEN subtree.depth = 0 THEN p_new_parent_id
            ELSE unit_row.parent_id
        END,
        unit_row.level = v_new_parent_level + 1 + subtree.depth,
        unit_row.moi_level = v_new_parent_moi_level + 1 + subtree.depth,
        unit_row.updated_at = CURRENT_TIMESTAMP;

    INSERT INTO organizational_unit_lifecycle_events (
        organizational_unit_id,
        event_type,
        effective_from,
        notes,
        created_by
    ) VALUES (
        p_unit_id,
        'actualizacion',
        CURDATE(),
        LEFT(CONCAT(
            'Unidad movida desde ', COALESCE(v_old_parent_name, 'nivel principal'),
            ' hacia ', v_new_parent_name,
            '. Motivo: ', v_notes
        ), 255),
        v_actor
    );

    COMMIT;
    DROP TEMPORARY TABLE IF EXISTS tmp_structure_subtree;

    SELECT
        1 AS ok,
        p_unit_id AS unit_id,
        'La unidad y sus dependencias fueron movidas correctamente.' AS message;
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
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Indique el motivo de la desactivación.';
    END IF;

    START TRANSACTION;

    SELECT name, status, lifecycle_status, legacy_table, structure_source
      INTO v_unit_name, v_status, v_lifecycle, v_legacy_table, v_source
    FROM organizational_units
    WHERE id = p_unit_id
    LIMIT 1
    FOR UPDATE;

    IF v_unit_name IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La unidad seleccionada no existe.';
    END IF;

    IF UPPER(TRIM(COALESCE(v_legacy_table, ''))) = 'TABCUAR'
       OR v_source = 'legacy' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Este registro heredado está protegido y no puede desactivarse.';
    END IF;

    IF v_status <> 'active' OR v_lifecycle <> 'vigente' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La unidad ya se encuentra inactiva o fuera de vigencia.';
    END IF;

    SELECT COUNT(*) INTO v_active_children
    FROM organizational_units child
    WHERE child.parent_id = p_unit_id
      AND child.status = 'active'
      AND child.lifecycle_status = 'vigente';

    IF v_active_children > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Primero debe mover o desactivar las unidades subordinadas vigentes.';
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
        'La unidad fue desactivada y su historial se conservó.' AS message;
END$$

DROP PROCEDURE IF EXISTS sp_structure_reactivate_unit$$
CREATE PROCEDURE sp_structure_reactivate_unit(
    IN p_unit_id BIGINT UNSIGNED,
    IN p_notes VARCHAR(255),
    IN p_actor VARCHAR(100)
)
BEGIN
    DECLARE v_unit_name VARCHAR(200) DEFAULT NULL;
    DECLARE v_parent_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE v_status VARCHAR(20) DEFAULT NULL;
    DECLARE v_legacy_table VARCHAR(100) DEFAULT NULL;
    DECLARE v_source VARCHAR(40) DEFAULT NULL;
    DECLARE v_parent_status VARCHAR(20) DEFAULT NULL;
    DECLARE v_parent_lifecycle VARCHAR(40) DEFAULT NULL;
    DECLARE v_actor VARCHAR(100) DEFAULT 'administrador_local';
    DECLARE v_notes VARCHAR(255) DEFAULT NULL;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    SET v_actor = COALESCE(NULLIF(TRIM(p_actor), ''), 'administrador_local');
    SET v_notes = NULLIF(TRIM(p_notes), '');

    START TRANSACTION;

    SELECT name, parent_id, status, legacy_table, structure_source
      INTO v_unit_name, v_parent_id, v_status, v_legacy_table, v_source
    FROM organizational_units
    WHERE id = p_unit_id
    LIMIT 1
    FOR UPDATE;

    IF v_unit_name IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La unidad seleccionada no existe.';
    END IF;

    IF UPPER(TRIM(COALESCE(v_legacy_table, ''))) = 'TABCUAR'
       OR v_source = 'legacy' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Este registro heredado está protegido y no puede reactivarse desde esta pantalla.';
    END IF;

    IF v_status = 'active' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La unidad ya se encuentra activa.';
    END IF;

    IF v_parent_id IS NOT NULL THEN
        SELECT status, lifecycle_status
          INTO v_parent_status, v_parent_lifecycle
        FROM organizational_units
        WHERE id = v_parent_id
        LIMIT 1;

        IF v_parent_status <> 'active' OR v_parent_lifecycle <> 'vigente' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La unidad superior debe estar activa y vigente antes de reactivar esta unidad.';
        END IF;
    END IF;

    UPDATE organizational_units
    SET
        status = 'active',
        lifecycle_status = 'vigente',
        valid_to = NULL,
        lifecycle_notes = COALESCE(v_notes, 'Unidad reactivada desde el administrador.'),
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
        'reactivacion',
        CURDATE(),
        LEFT(CONCAT(
            'Unidad reactivada.',
            COALESCE(CONCAT(' Motivo: ', v_notes), '')
        ), 255),
        v_actor
    );

    COMMIT;

    SELECT
        1 AS ok,
        p_unit_id AS unit_id,
        'La unidad fue reactivada correctamente.' AS message;
END$$

DELIMITER ;

-- Verificación rápida del componente instalado.
SELECT
    'estructura_admin_db' AS component,
    COUNT(*) AS configured_rules
FROM structure_unit_type_rules
WHERE is_allowed = 1;
