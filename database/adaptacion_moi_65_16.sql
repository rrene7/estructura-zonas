-- Adaptacion del modelo de ubicaciones/dependencias al MOI 65.16.
-- No contiene datos del manual ni datos reales. Solo estructura para clasificacion institucional.

-- 1. Catalogo de tipos de sede fisica
CREATE TABLE IF NOT EXISTS facility_types (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(150) NOT NULL,
    description VARCHAR(255) NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Catalogo de patrones de nomenclatura institucional
CREATE TABLE IF NOT EXISTS nomenclature_patterns (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    family VARCHAR(100) NOT NULL,
    prefix VARCHAR(20) NOT NULL,
    description VARCHAR(255) NULL,
    example_format VARCHAR(100) NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    INDEX idx_nomenclature_family (family),
    INDEX idx_nomenclature_prefix (prefix)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Relaciones entre unidades organizacionales
CREATE TABLE IF NOT EXISTS organizational_unit_relationships (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_unit_id BIGINT UNSIGNED NOT NULL,
    target_unit_id BIGINT UNSIGNED NOT NULL,
    relationship_type ENUM('jerarquica','funcional','operacional','tactica','administrativa','ubicacion_fisica','apoyo_tecnico') NOT NULL,
    valid_from DATE NULL,
    valid_to DATE NULL,
    status ENUM('active','inactive','historical') NOT NULL DEFAULT 'active',
    notes VARCHAR(255) NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (source_unit_id) REFERENCES organizational_units(id),
    FOREIGN KEY (target_unit_id) REFERENCES organizational_units(id),
    INDEX idx_org_rel_source (source_unit_id),
    INDEX idx_org_rel_target (target_unit_id),
    INDEX idx_org_rel_type (relationship_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Registro de versiones normativas usadas para clasificar la estructura
CREATE TABLE IF NOT EXISTS organizational_normative_versions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL,
    title VARCHAR(200) NOT NULL,
    effective_date DATE NULL,
    approval_date DATE NULL,
    review_date DATE NULL,
    status ENUM('draft','active','verified','historical','repealed') NOT NULL DEFAULT 'active',
    notes VARCHAR(255) NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    UNIQUE KEY uq_normative_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Ampliacion de organizational_units
-- Ejecutar una sola vez. Si alguna columna ya existe, omitir manualmente esa linea.
ALTER TABLE organizational_units
    ADD COLUMN moi_code VARCHAR(50) NULL AFTER code,
    ADD COLUMN moi_level INT NULL AFTER level,
    ADD COLUMN command_structure ENUM('mando_directo','linea_funcional','no_definido') NOT NULL DEFAULT 'no_definido' AFTER is_administrative,
    ADD COLUMN command_relationship ENUM('operacional','tactico','administrativo','funcional','no_definido') NOT NULL DEFAULT 'no_definido' AFTER command_structure,
    ADD COLUMN territorial_scope ENUM('nacional','regional','zonal','area','local','especializado','no_definido') NOT NULL DEFAULT 'no_definido' AFTER command_relationship,
    ADD COLUMN functional_axis VARCHAR(100) NULL AFTER territorial_scope,
    ADD COLUMN is_decision_center BOOLEAN NOT NULL DEFAULT FALSE AFTER functional_axis,
    ADD COLUMN is_operational_executor BOOLEAN NOT NULL DEFAULT FALSE AFTER is_decision_center,
    ADD COLUMN facility_type_id BIGINT UNSIGNED NULL AFTER is_operational_executor,
    ADD COLUMN normative_version_id BIGINT UNSIGNED NULL AFTER facility_type_id,
    ADD COLUMN verified_at DATE NULL AFTER normative_version_id,
    ADD INDEX idx_org_units_moi_code (moi_code),
    ADD INDEX idx_org_units_command_structure (command_structure),
    ADD INDEX idx_org_units_scope (territorial_scope),
    ADD INDEX idx_org_units_facility_type (facility_type_id),
    ADD INDEX idx_org_units_normative_version (normative_version_id);

-- 6. Llaves foraneas adicionales
ALTER TABLE organizational_units
    ADD CONSTRAINT fk_org_units_facility_type
        FOREIGN KEY (facility_type_id) REFERENCES facility_types(id),
    ADD CONSTRAINT fk_org_units_normative_version
        FOREIGN KEY (normative_version_id) REFERENCES organizational_normative_versions(id);

-- 7. Semillas generales de tipos de sede fisica
INSERT IGNORE INTO facility_types (code, name, description, created_at, updated_at) VALUES
('sede_region', 'Sede de Region', 'Sede administrativa de region', NOW(), NOW()),
('estacion_policial', 'Estacion Policial', 'Sede operativa principal de zona', NOW(), NOW()),
('subestacion_policial', 'Subestacion Policial', 'Sede operativa de area', NOW(), NOW()),
('destacamento', 'Destacamento', 'Sede de servicio o grupo operativo', NOW(), NOW()),
('puesto_policial', 'Puesto Policial', 'Instalacion operativa local', NOW(), NOW()),
('oficina_administrativa', 'Oficina Administrativa', 'Oficina interna o administrativa', NOW(), NOW());

-- 8. Semillas generales para tipos de unidad MOI
INSERT IGNORE INTO unit_types (name, description, created_at, updated_at) VALUES
('directorio_general', 'Nivel de directorio general', NOW(), NOW()),
('directorio_personal', 'Componente personal del directorio', NOW(), NOW()),
('directorio_coordinacion', 'Componente de coordinacion del directorio', NOW(), NOW()),
('directorio_especial', 'Componente especial del directorio', NOW(), NOW()),
('subdireccion_nacional', 'Subdireccion nacional', NOW(), NOW()),
('region_policial', 'Region policial', NOW(), NOW()),
('zona_policial', 'Zona policial', NOW(), NOW()),
('area_policial', 'Area policial', NOW(), NOW()),
('servicio_policial', 'Servicio policial', NOW(), NOW()),
('sede_region', 'Sede de region', NOW(), NOW()),
('estacion_policial', 'Estacion policial', NOW(), NOW()),
('subestacion_policial', 'Subestacion policial', NOW(), NOW()),
('destacamento', 'Destacamento', NOW(), NOW()),
('puesto_policial', 'Puesto policial', NOW(), NOW());

-- 9. Semillas generales de nomenclatura, sin cargar listas operativas
INSERT IGNORE INTO nomenclature_patterns (family, prefix, description, example_format, status, created_at, updated_at) VALUES
('directorio', 'D', 'Familia de codigos del directorio', 'D-n', 'active', NOW(), NOW()),
('policia_funcional', 'P', 'Familia de codigos de secciones o unidades funcionales', 'Pn-n', 'active', NOW(), NOW()),
('region_policial', 'RP', 'Familia de codigos de regiones policiales', 'RP-n', 'active', NOW(), NOW()),
('zona_policial', 'ZP', 'Familia de codigos de zonas policiales', 'ZP-n', 'active', NOW(), NOW()),
('servicio_policial', 'SP', 'Familia de codigos de servicios policiales', 'SP-n', 'active', NOW(), NOW());

-- 10. Version normativa de referencia
INSERT IGNORE INTO organizational_normative_versions
(code, title, effective_date, approval_date, review_date, status, notes, created_at, updated_at)
VALUES
('MOI-65.16', 'Manual de Organizacion Institucional', '2026-01-14', '2026-01-12', '2027-06-01', 'active', 'Referencia para clasificacion interna de estructura institucional', NOW(), NOW());

-- 11. Consultas de control
-- Unidades sin clasificacion MOI
-- SELECT id, code, name, legacy_table, legacy_id FROM organizational_units WHERE moi_code IS NULL OR territorial_scope = 'no_definido';

-- Unidades con parent_id pero sin relacion jerarquica explicita
-- SELECT ou.id, ou.name FROM organizational_units ou
-- LEFT JOIN organizational_unit_relationships r ON r.source_unit_id = ou.id AND r.relationship_type = 'jerarquica'
-- WHERE ou.parent_id IS NOT NULL AND r.id IS NULL;

-- Unidades fisicas mezcladas con unidades organizacionales
-- SELECT id, name, unit_type_id, facility_type_id FROM organizational_units WHERE facility_type_id IS NOT NULL;
