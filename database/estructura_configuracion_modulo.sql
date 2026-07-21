-- Módulo de configuración del sistema
-- Complementa database/estructura_admin_db.sql.
-- Toda modificación de reglas jerárquicas se valida y audita en MySQL.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS structure_configuration_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_type VARCHAR(40) NOT NULL,
    parent_type_id BIGINT UNSIGNED DEFAULT NULL,
    child_type_id BIGINT UNSIGNED DEFAULT NULL,
    old_is_allowed TINYINT(1) DEFAULT NULL,
    new_is_allowed TINYINT(1) DEFAULT NULL,
    notes VARCHAR(255) DEFAULT NULL,
    created_by VARCHAR(100) NOT NULL DEFAULT 'administrador_local',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_structure_configuration_event_date (created_at),
    KEY idx_structure_configuration_event_parent (parent_type_id),
    KEY idx_structure_configuration_event_child (child_type_id),
    CONSTRAINT fk_structure_configuration_event_parent
        FOREIGN KEY (parent_type_id) REFERENCES unit_types (id),
    CONSTRAINT fk_structure_configuration_event_child
        FOREIGN KEY (child_type_id) REFERENCES unit_types (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE OR REPLACE VIEW vw_structure_type_rules AS
SELECT
    rule_row.id,
    rule_row.parent_type_id,
    parent_type.name AS parent_type_name,
    parent_type.description AS parent_type_description,
    rule_row.child_type_id,
    child_type.name AS child_type_name,
    child_type.description AS child_type_description,
    rule_row.is_allowed,
    CASE WHEN rule_row.is_allowed = 1 THEN 'Permitida' ELSE 'Bloqueada' END AS status_label,
    rule_row.notes,
    rule_row.created_at,
    rule_row.updated_at
FROM structure_unit_type_rules rule_row
JOIN unit_types parent_type ON parent_type.id = rule_row.parent_type_id
JOIN unit_types child_type ON child_type.id = rule_row.child_type_id;

CREATE OR REPLACE VIEW vw_structure_configuration_history AS
SELECT
    event_row.created_at AS event_at,
    'estructura' AS category,
    event_row.event_type AS action_name,
    unit_row.name AS target_name,
    event_row.notes,
    event_row.created_by
FROM organizational_unit_lifecycle_events event_row
JOIN organizational_units unit_row
    ON unit_row.id = event_row.organizational_unit_id
UNION ALL
SELECT
    config_event.created_at AS event_at,
    'regla_jerarquia' AS category,
    config_event.event_type AS action_name,
    CONCAT(parent_type.name, ' → ', child_type.name) AS target_name,
    config_event.notes,
    config_event.created_by
FROM structure_configuration_events config_event
LEFT JOIN unit_types parent_type ON parent_type.id = config_event.parent_type_id
LEFT JOIN unit_types child_type ON child_type.id = config_event.child_type_id;

DELIMITER $$

DROP PROCEDURE IF EXISTS sp_structure_set_type_rule$$
CREATE PROCEDURE sp_structure_set_type_rule(
    IN p_parent_type_id BIGINT UNSIGNED,
    IN p_child_type_id BIGINT UNSIGNED,
    IN p_is_allowed TINYINT,
    IN p_notes VARCHAR(255),
    IN p_actor VARCHAR(100)
)
BEGIN
    DECLARE v_parent_exists INT DEFAULT 0;
    DECLARE v_child_exists INT DEFAULT 0;
    DECLARE v_rule_exists INT DEFAULT 0;
    DECLARE v_old_is_allowed TINYINT DEFAULT NULL;
    DECLARE v_new_is_allowed TINYINT DEFAULT 1;
    DECLARE v_actor VARCHAR(100) DEFAULT 'administrador_local';
    DECLARE v_notes VARCHAR(255) DEFAULT NULL;
    DECLARE v_event_type VARCHAR(40) DEFAULT 'regla_actualizada';

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    SET v_actor = COALESCE(NULLIF(TRIM(p_actor), ''), 'administrador_local');
    SET v_notes = NULLIF(TRIM(p_notes), '');
    SET v_new_is_allowed = CASE WHEN p_is_allowed = 1 THEN 1 ELSE 0 END;

    IF p_parent_type_id IS NULL OR p_parent_type_id = 0
       OR p_child_type_id IS NULL OR p_child_type_id = 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Seleccione el tipo superior y el tipo subordinado.';
    END IF;

    IF p_parent_type_id = p_child_type_id THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Un tipo de unidad no puede autorizarse como subordinado de sí mismo.';
    END IF;

    START TRANSACTION;

    SELECT COUNT(*) INTO v_parent_exists
    FROM unit_types
    WHERE id = p_parent_type_id;

    SELECT COUNT(*) INTO v_child_exists
    FROM unit_types
    WHERE id = p_child_type_id;

    IF v_parent_exists = 0 OR v_child_exists = 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Uno de los tipos de unidad seleccionados no existe.';
    END IF;

    SELECT COUNT(*), MAX(is_allowed)
      INTO v_rule_exists, v_old_is_allowed
    FROM structure_unit_type_rules
    WHERE parent_type_id = p_parent_type_id
      AND child_type_id = p_child_type_id
    FOR UPDATE;

    INSERT INTO structure_unit_type_rules (
        parent_type_id,
        child_type_id,
        is_allowed,
        notes,
        created_at,
        updated_at
    ) VALUES (
        p_parent_type_id,
        p_child_type_id,
        v_new_is_allowed,
        v_notes,
        CURRENT_TIMESTAMP,
        CURRENT_TIMESTAMP
    )
    ON DUPLICATE KEY UPDATE
        is_allowed = VALUES(is_allowed),
        notes = COALESCE(VALUES(notes), notes),
        updated_at = CURRENT_TIMESTAMP;

    SET v_event_type = CASE
        WHEN v_rule_exists = 0 THEN 'regla_creada'
        WHEN v_old_is_allowed = 0 AND v_new_is_allowed = 1 THEN 'regla_activada'
        WHEN v_old_is_allowed = 1 AND v_new_is_allowed = 0 THEN 'regla_bloqueada'
        ELSE 'regla_actualizada'
    END;

    INSERT INTO structure_configuration_events (
        event_type,
        parent_type_id,
        child_type_id,
        old_is_allowed,
        new_is_allowed,
        notes,
        created_by
    ) VALUES (
        v_event_type,
        p_parent_type_id,
        p_child_type_id,
        v_old_is_allowed,
        v_new_is_allowed,
        LEFT(COALESCE(v_notes, 'Regla jerárquica actualizada desde configuración.'), 255),
        v_actor
    );

    COMMIT;

    SELECT
        1 AS ok,
        v_event_type AS event_type,
        'La regla jerárquica fue guardada correctamente.' AS message;
END$$

DELIMITER ;

SELECT
    'estructura_configuracion_modulo' AS component,
    COUNT(*) AS total_rules
FROM structure_unit_type_rules;
