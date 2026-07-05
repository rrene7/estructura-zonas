-- Modelo inicial para ubicaciones, zonas, areas, dependencias, oficinas y departamentos

CREATE TABLE unit_types (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    description VARCHAR(255) NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
);

CREATE TABLE organizational_units (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    parent_id BIGINT UNSIGNED NULL,
    unit_type_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(50) NULL,
    name VARCHAR(200) NOT NULL,
    short_name VARCHAR(100) NULL,
    level INT NULL,
    is_operational BOOLEAN NOT NULL DEFAULT FALSE,
    is_administrative BOOLEAN NOT NULL DEFAULT TRUE,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    legacy_table VARCHAR(100) NULL,
    legacy_id VARCHAR(100) NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (parent_id) REFERENCES organizational_units(id),
    FOREIGN KEY (unit_type_id) REFERENCES unit_types(id),
    INDEX idx_org_units_parent (parent_id),
    INDEX idx_org_units_type (unit_type_id),
    INDEX idx_org_units_code (code),
    INDEX idx_org_units_legacy (legacy_table, legacy_id)
);

CREATE TABLE territorial_divisions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    parent_id BIGINT UNSIGNED NULL,
    type ENUM('provincia','distrito','corregimiento') NOT NULL,
    name VARCHAR(150) NOT NULL,
    code VARCHAR(50) NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (parent_id) REFERENCES territorial_divisions(id),
    INDEX idx_territorial_parent (parent_id),
    INDEX idx_territorial_type (type)
);

CREATE TABLE locations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    province_id BIGINT UNSIGNED NULL,
    district_id BIGINT UNSIGNED NULL,
    corregimiento_id BIGINT UNSIGNED NULL,
    address VARCHAR(255) NULL,
    reference VARCHAR(255) NULL,
    latitude DECIMAL(10,7) NULL,
    longitude DECIMAL(10,7) NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    legacy_table VARCHAR(100) NULL,
    legacy_id VARCHAR(100) NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (province_id) REFERENCES territorial_divisions(id),
    FOREIGN KEY (district_id) REFERENCES territorial_divisions(id),
    FOREIGN KEY (corregimiento_id) REFERENCES territorial_divisions(id),
    INDEX idx_locations_province (province_id),
    INDEX idx_locations_district (district_id),
    INDEX idx_locations_corregimiento (corregimiento_id),
    INDEX idx_locations_legacy (legacy_table, legacy_id)
);

CREATE TABLE unit_locations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organizational_unit_id BIGINT UNSIGNED NOT NULL,
    location_id BIGINT UNSIGNED NOT NULL,
    is_main BOOLEAN NOT NULL DEFAULT FALSE,
    valid_from DATE NULL,
    valid_to DATE NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (organizational_unit_id) REFERENCES organizational_units(id),
    FOREIGN KEY (location_id) REFERENCES locations(id),
    INDEX idx_unit_locations_unit (organizational_unit_id),
    INDEX idx_unit_locations_location (location_id)
);

CREATE TABLE positions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    position_code VARCHAR(50) NULL,
    title VARCHAR(200) NOT NULL,
    organizational_unit_id BIGINT UNSIGNED NOT NULL,
    rank_id BIGINT UNSIGNED NULL,
    status ENUM('active','inactive','vacant') NOT NULL DEFAULT 'active',
    legacy_table VARCHAR(100) NULL,
    legacy_id VARCHAR(100) NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (organizational_unit_id) REFERENCES organizational_units(id),
    INDEX idx_positions_code (position_code),
    INDEX idx_positions_unit (organizational_unit_id),
    INDEX idx_positions_legacy (legacy_table, legacy_id)
);

CREATE TABLE unit_assignments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    person_id BIGINT UNSIGNED NOT NULL,
    position_id BIGINT UNSIGNED NULL,
    organizational_unit_id BIGINT UNSIGNED NOT NULL,
    assignment_type VARCHAR(50) NULL,
    start_date DATE NOT NULL,
    end_date DATE NULL,
    source_action_id BIGINT UNSIGNED NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    legacy_table VARCHAR(100) NULL,
    legacy_id VARCHAR(100) NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (position_id) REFERENCES positions(id),
    FOREIGN KEY (organizational_unit_id) REFERENCES organizational_units(id),
    INDEX idx_unit_assignments_person (person_id),
    INDEX idx_unit_assignments_position (position_id),
    INDEX idx_unit_assignments_unit (organizational_unit_id),
    INDEX idx_unit_assignments_dates (start_date, end_date)
);

INSERT INTO unit_types (name, description) VALUES
('institucion', 'Institucion principal'),
('direccion_nacional', 'Direccion nacional'),
('zona_policial', 'Zona policial'),
('area', 'Area regional, operativa o administrativa'),
('departamento', 'Departamento'),
('division', 'Division'),
('seccion', 'Seccion'),
('oficina', 'Oficina'),
('dependencia', 'Dependencia'),
('cuartel', 'Cuartel'),
('estacion', 'Estacion'),
('subestacion', 'Subestacion'),
('puesto', 'Puesto');
