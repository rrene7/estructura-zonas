-- Padres automáticos y nombres guiados para zonas, direcciones y servicios principales.
-- Ejecutar después de estructura_codigos_internos_y_padre_zonas.sql.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- La raíz institucional se resuelve por tipo, nombre y posición jerárquica.
INSERT INTO structure_default_parents (
    group_key,
    parent_unit_id,
    description
)
SELECT
    'direcciones',
    root_unit.id,
    'Raíz institucional vigente para nuevas direcciones nacionales'
FROM organizational_units root_unit
JOIN unit_types root_type
  ON root_type.id = root_unit.unit_type_id
WHERE root_unit.status = 'active'
  AND root_unit.lifecycle_status = 'vigente'
ORDER BY
    CASE
        WHEN root_type.name = 'institucion' THEN 1
        WHEN root_unit.name COLLATE utf8mb4_unicode_ci IN (
            'Policía Nacional',
            'Policía Nacional de Panamá',
            'Policia Nacional',
            'Policia Nacional de Panama'
        ) THEN 2
        WHEN root_unit.name COLLATE utf8mb4_unicode_ci LIKE '%Policía Nacional%' THEN 3
        WHEN root_unit.name COLLATE utf8mb4_unicode_ci LIKE '%Policia Nacional%' THEN 4
        WHEN root_unit.parent_id IS NULL THEN 5
        ELSE 6
    END,
    CASE WHEN root_unit.parent_id IS NULL THEN 1 ELSE 2 END,
    COALESCE(root_unit.level, 999),
    root_unit.id
LIMIT 1
ON DUPLICATE KEY UPDATE
    parent_unit_id = VALUES(parent_unit_id),
    description = VALUES(description),
    updated_at = CURRENT_TIMESTAMP;

INSERT INTO structure_default_parents (
    group_key,
    parent_unit_id,
    description
)
SELECT
    'servicios',
    root_unit.id,
    'Raíz institucional vigente para nuevos servicios policiales'
FROM organizational_units root_unit
JOIN unit_types root_type
  ON root_type.id = root_unit.unit_type_id
WHERE root_unit.status = 'active'
  AND root_unit.lifecycle_status = 'vigente'
ORDER BY
    CASE
        WHEN root_type.name = 'institucion' THEN 1
        WHEN root_unit.name COLLATE utf8mb4_unicode_ci IN (
            'Policía Nacional',
            'Policía Nacional de Panamá',
            'Policia Nacional',
            'Policia Nacional de Panama'
        ) THEN 2
        WHEN root_unit.name COLLATE utf8mb4_unicode_ci LIKE '%Policía Nacional%' THEN 3
        WHEN root_unit.name COLLATE utf8mb4_unicode_ci LIKE '%Policia Nacional%' THEN 4
        WHEN root_unit.parent_id IS NULL THEN 5
        ELSE 6
    END,
    CASE WHEN root_unit.parent_id IS NULL THEN 1 ELSE 2 END,
    COALESCE(root_unit.level, 999),
    root_unit.id
LIMIT 1
ON DUPLICATE KEY UPDATE
    parent_unit_id = VALUES(parent_unit_id),
    description = VALUES(description),
    updated_at = CURRENT_TIMESTAMP;

-- Mantener la Dirección Nacional de Operaciones Policiales como padre oficial de las zonas.
INSERT INTO structure_default_parents (
    group_key,
    parent_unit_id,
    description
)
SELECT
    'zonas',
    candidate.id,
    'Dirección Nacional de Operaciones Policiales vigente'
FROM organizational_units candidate
WHERE candidate.status = 'active'
  AND candidate.lifecycle_status = 'vigente'
  AND (
        candidate.name COLLATE utf8mb4_unicode_ci = 'Dirección Nacional de Operaciones Policiales'
        OR candidate.name COLLATE utf8mb4_unicode_ci = 'Direccion Nacional de Operaciones Policiales'
        OR candidate.name COLLATE utf8mb4_unicode_ci LIKE '%Dirección%Operaciones Policiales%'
        OR candidate.name COLLATE utf8mb4_unicode_ci LIKE '%Direccion%Operaciones Policiales%'
        OR candidate.name COLLATE utf8mb4_unicode_ci LIKE '%OPERACIONES POLICIALES%'
  )
ORDER BY
    CASE
        WHEN candidate.name COLLATE utf8mb4_unicode_ci = 'Dirección Nacional de Operaciones Policiales' THEN 1
        WHEN candidate.name COLLATE utf8mb4_unicode_ci = 'Direccion Nacional de Operaciones Policiales' THEN 2
        ELSE 3
    END,
    CASE candidate.structure_source
        WHEN 'accion_posterior' THEN 1
        WHEN 'moi_65_16' THEN 2
        ELSE 3
    END,
    candidate.id
LIMIT 1
ON DUPLICATE KEY UPDATE
    parent_unit_id = VALUES(parent_unit_id),
    description = VALUES(description),
    updated_at = CURRENT_TIMESTAMP;

-- Autorizar cada tipo principal debajo de su padre automático.
INSERT INTO structure_unit_type_rules (
    parent_type_id,
    child_type_id,
    is_allowed,
    notes
)
SELECT
    parent_unit.unit_type_id,
    child_type.id,
    1,
    CONCAT('Relación automática para el grupo ', parent_config.group_key)
FROM structure_default_parents parent_config
JOIN organizational_units parent_unit
  ON parent_unit.id = parent_config.parent_unit_id
JOIN unit_types child_type
  ON (
        parent_config.group_key = 'zonas'
        AND child_type.name = 'zona_policial'
     )
     OR (
        parent_config.group_key = 'direcciones'
        AND child_type.name = 'direccion_nacional'
     )
     OR (
        parent_config.group_key = 'servicios'
        AND child_type.name = 'servicio_policial'
     )
WHERE parent_config.group_key IN ('zonas', 'direcciones', 'servicios')
ON DUPLICATE KEY UPDATE
    is_allowed = 1,
    notes = VALUES(notes),
    updated_at = CURRENT_TIMESTAMP;

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
    DECLARE v_name VARCHAR(200) DEFAULT NULL;

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
        WHEN v_type_name = 'zona_policial' THEN 'zonas'
        WHEN v_type_name = 'direccion_nacional' THEN 'direcciones'
        WHEN v_type_name = 'servicio_policial' THEN 'servicios'
        ELSE NULL
    END;

    IF v_group_key IS NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'En esta pantalla solo se crean zonas, direcciones nacionales o servicios policiales principales.';
    END IF;

    SET v_name = NULLIF(TRIM(p_name), '');
    IF v_name IS NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Escriba el nombre o la descripción de la unidad.';
    END IF;

    IF v_type_name = 'direccion_nacional'
       AND v_name COLLATE utf8mb4_unicode_ci NOT LIKE 'Dirección Nacional%' THEN
        SET v_name = CONCAT('Dirección Nacional de ', v_name);
    END IF;

    IF v_type_name = 'servicio_policial'
       AND v_name COLLATE utf8mb4_unicode_ci NOT LIKE 'Servicio%' THEN
        SET v_name = CONCAT('Servicio Policial - ', v_name);
    END IF;

    IF v_type_name = 'zona_policial'
       AND v_name NOT REGEXP '^[0-9]+[[:space:]]+Zona[[:space:]]+Policial' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'El nombre de la zona debe comenzar con su número y el texto Zona Policial.';
    END IF;

    SELECT parent_unit_id
      INTO v_parent_id
    FROM structure_default_parents
    WHERE group_key = v_group_key
    LIMIT 1;

    IF v_parent_id IS NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'No está configurada la unidad superior oficial para este tipo de unidad.';
    END IF;

    CALL sp_structure_create_root_unit(
        v_parent_id,
        p_unit_type_id,
        v_name,
        '',
        '',
        '',
        p_notes,
        p_actor
    );
END$$

DELIMITER ;

SELECT
    parent_config.group_key AS grupo,
    parent_unit.id AS unidad_superior_id,
    parent_unit.name AS unidad_superior,
    parent_type.name AS tipo_superior,
    parent_unit.system_code AS codigo_interno
FROM structure_default_parents parent_config
LEFT JOIN organizational_units parent_unit
  ON parent_unit.id = parent_config.parent_unit_id
LEFT JOIN unit_types parent_type
  ON parent_type.id = parent_unit.unit_type_id
WHERE parent_config.group_key IN ('zonas', 'direcciones', 'servicios')
ORDER BY parent_config.group_key;
