-- Aplicacion real controlada - Zona 1 Bocas del Toro
-- Usa la informacion DINSEC ya cargada en dinsec_personnel_reference y moi_area_sector_catalog.
-- No toca rrhh2029. Trabaja en la base configurada por el script local.

INSERT IGNORE INTO unit_types (name, description, created_at, updated_at)
VALUES
('area_policial','Area policial',NOW(),NOW()),
('sector_policial','Sector o ubicacion policial dentro de un area',NOW(),NOW()),
('servicio_zonal','Servicio o funcion dentro de zona policial',NOW(),NOW());

CREATE TABLE IF NOT EXISTS dinsec_personnel_unit_links (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  dinsec_personnel_reference_id BIGINT UNSIGNED NOT NULL,
  zone_unit_id BIGINT UNSIGNED NULL,
  area_unit_id BIGINT UNSIGNED NULL,
  sector_catalog_id BIGINT UNSIGNED NULL,
  assignment_unit_id BIGINT UNSIGNED NULL,
  assignment_scope ENUM('zona','area','sector','servicio','administrativo','pendiente') NOT NULL DEFAULT 'pendiente',
  position_number VARCHAR(50) NULL,
  full_name VARCHAR(180) NOT NULL,
  rank_text VARCHAR(80) NULL,
  assignment_text VARCHAR(220) NULL,
  location_sector VARCHAR(180) NULL,
  source_name VARCHAR(180) NOT NULL DEFAULT 'DINSEC 04AGO2025',
  status ENUM('active','inactive','review') NOT NULL DEFAULT 'active',
  notes VARCHAR(255) NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_dinsec_link (dinsec_personnel_reference_id),
  INDEX idx_link_zone (zone_unit_id),
  INDEX idx_link_area (area_unit_id),
  INDEX idx_link_sector (sector_catalog_id),
  INDEX idx_link_assignment (assignment_unit_id),
  INDEX idx_link_position (position_number),
  INDEX idx_link_scope (assignment_scope)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS moi_zone_apply_audit (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  zone_number INT NOT NULL,
  zone_label VARCHAR(180) NOT NULL,
  action_name VARCHAR(120) NOT NULL,
  affected_rows INT NOT NULL DEFAULT 0,
  notes VARCHAR(255) NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_zone_apply (zone_number, action_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 1) Asegurar cabeceras de Area A-D para Zona 1.
INSERT INTO organizational_units
(parent_id, unit_type_id, code, name, short_name, level, is_operational, is_administrative, status, legacy_table, legacy_id, created_at, updated_at)
SELECT zcab.id, ut.id, CONCAT('Z01-AREA-',c.area_code), CONCAT('Area ',c.area_code), CONCAT('Area ',c.area_code), 4, 1, 1, 'active', 'MOI_CABECERA_AREA', CONCAT('Z01-AREA-',c.area_code), NOW(), NOW()
FROM (SELECT 'A' area_code UNION SELECT 'B' UNION SELECT 'C' UNION SELECT 'D') c
JOIN unit_types ut ON ut.name='area_policial'
JOIN organizational_units zcab ON BINARY zcab.legacy_table=BINARY 'MOI_CABECERA_ZONA' AND CAST(zcab.legacy_id AS UNSIGNED)=1
WHERE NOT EXISTS (
  SELECT 1 FROM organizational_units ex
  WHERE BINARY ex.legacy_table=BINARY 'MOI_CABECERA_AREA'
    AND BINARY ex.legacy_id=BINARY CONCAT('Z01-AREA-',c.area_code)
);

UPDATE organizational_units ou
SET moi_code=COALESCE(moi_code, code),
    moi_level=4,
    command_structure='mando_directo',
    command_relationship='tactico',
    territorial_scope='area',
    is_decision_center=1,
    is_operational_executor=1,
    valid_from=COALESCE(valid_from, CURRENT_DATE),
    lifecycle_status=COALESCE(lifecycle_status,'vigente'),
    structure_source=COALESCE(structure_source,'dinsec_04ago2025'),
    legacy_frozen=1,
    lifecycle_notes=CONCAT('Cabecera real de ',name,' dentro de 1 Zona Policial - Bocas del Toro')
WHERE BINARY legacy_table=BINARY 'MOI_CABECERA_AREA'
  AND legacy_id IN ('Z01-AREA-A','Z01-AREA-B','Z01-AREA-C','Z01-AREA-D');

-- 2) Asegurar unidades de sector reales bajo cada area.
INSERT INTO organizational_units
(parent_id, unit_type_id, code, name, short_name, level, is_operational, is_administrative, status, legacy_table, legacy_id, created_at, updated_at)
SELECT area.id, ut.id,
       CONCAT('Z01-',s.area_code,'-',REPLACE(REPLACE(REPLACE(UPPER(s.sector_name),' ','-'),'Í','I'),'Á','A')),
       s.sector_name,
       s.sector_name,
       5, 1, 1, 'active', 'DINSEC_SECTOR', CONCAT('Z01-',s.area_code,'-',REPLACE(REPLACE(REPLACE(UPPER(s.sector_name),' ','-'),'Í','I'),'Á','A')), NOW(), NOW()
FROM moi_area_sector_catalog s
JOIN unit_types ut ON ut.name='sector_policial'
JOIN organizational_units area ON BINARY area.legacy_table=BINARY 'MOI_CABECERA_AREA' AND BINARY area.legacy_id=BINARY CONCAT('Z01-AREA-',s.area_code)
WHERE s.zone_number=1
  AND s.area_code IS NOT NULL
  AND s.active=1
  AND NOT EXISTS (
    SELECT 1 FROM organizational_units ex
    WHERE BINARY ex.legacy_table=BINARY 'DINSEC_SECTOR'
      AND BINARY ex.legacy_id=BINARY CONCAT('Z01-',s.area_code,'-',REPLACE(REPLACE(REPLACE(UPPER(s.sector_name),' ','-'),'Í','I'),'Á','A'))
  );

UPDATE organizational_units ou
SET moi_code=COALESCE(moi_code, code),
    moi_level=5,
    command_structure='mando_directo',
    command_relationship='tactico',
    territorial_scope='sector',
    is_decision_center=0,
    is_operational_executor=1,
    valid_from=COALESCE(valid_from, CURRENT_DATE),
    lifecycle_status=COALESCE(lifecycle_status,'vigente'),
    structure_source=COALESCE(structure_source,'dinsec_04ago2025'),
    legacy_frozen=1,
    lifecycle_notes='Sector real DINSEC dentro de Area/Zona'
WHERE BINARY legacy_table=BINARY 'DINSEC_SECTOR'
  AND legacy_id LIKE 'Z01-%';

-- 3) Relacionar sectores con areas y areas con zona.
INSERT INTO organizational_unit_relationships (source_unit_id,target_unit_id,relationship_type,valid_from,status,notes,created_at,updated_at)
SELECT area.id, zcab.id, 'jerarquica', CURRENT_DATE, 'active', 'Area cabecera pertenece a Zona 1', NOW(), NOW()
FROM organizational_units area
JOIN organizational_units zcab ON BINARY zcab.legacy_table=BINARY 'MOI_CABECERA_ZONA' AND CAST(zcab.legacy_id AS UNSIGNED)=1
WHERE BINARY area.legacy_table=BINARY 'MOI_CABECERA_AREA'
  AND area.legacy_id IN ('Z01-AREA-A','Z01-AREA-B','Z01-AREA-C','Z01-AREA-D')
  AND NOT EXISTS (
    SELECT 1 FROM organizational_unit_relationships r
    WHERE r.source_unit_id=area.id AND r.target_unit_id=zcab.id AND r.relationship_type='jerarquica' AND r.status='active'
  );

INSERT INTO organizational_unit_relationships (source_unit_id,target_unit_id,relationship_type,valid_from,status,notes,created_at,updated_at)
SELECT sector.id, area.id, 'jerarquica', CURRENT_DATE, 'active', 'Sector DINSEC pertenece al area real', NOW(), NOW()
FROM organizational_units sector
JOIN organizational_units area ON area.id=sector.parent_id
WHERE BINARY sector.legacy_table=BINARY 'DINSEC_SECTOR'
  AND sector.legacy_id LIKE 'Z01-%'
  AND NOT EXISTS (
    SELECT 1 FROM organizational_unit_relationships r
    WHERE r.source_unit_id=sector.id AND r.target_unit_id=area.id AND r.relationship_type='jerarquica' AND r.status='active'
  );

-- 4) Marcar/registrar ubicacion de unidades legacy existentes de Zona 1 cuando el nombre coincida con sector real.
INSERT INTO moi_area_letter_assignments
(organizational_unit_id, area_code, area_unit_id, location_sector, direction_unit_id, zone_unit_id, notes)
SELECT ou.id, s.area_code, area.id, s.sector_name, NULL, zcab.id,
       CONCAT('Aplicado por DINSEC Zona 1: Area ',s.area_code,' / ',s.sector_name)
FROM organizational_units ou
JOIN moi_area_sector_catalog s
  ON s.zone_number=1
 AND s.area_code IS NOT NULL
 AND s.active=1
 AND UPPER(ou.name COLLATE utf8mb4_unicode_ci) LIKE CONCAT('%', UPPER(s.sector_name COLLATE utf8mb4_unicode_ci), '%')
JOIN organizational_units area ON BINARY area.legacy_table=BINARY 'MOI_CABECERA_AREA' AND BINARY area.legacy_id=BINARY CONCAT('Z01-AREA-',s.area_code)
JOIN organizational_units zcab ON BINARY zcab.legacy_table=BINARY 'MOI_CABECERA_ZONA' AND CAST(zcab.legacy_id AS UNSIGNED)=1
WHERE BINARY ou.legacy_table NOT IN (BINARY 'MOI_CABECERA_ZONA', BINARY 'MOI_CABECERA_DIRECCION', BINARY 'MOI_CABECERA_AREA', BINARY 'DINSEC_SECTOR')
  AND (UPPER(ou.name COLLATE utf8mb4_unicode_ci) LIKE '%BOCAS%' OR UPPER(ou.name COLLATE utf8mb4_unicode_ci) LIKE '%CHANGUINOLA%' OR UPPER(ou.name COLLATE utf8mb4_unicode_ci) LIKE '%ALMIRANTE%' OR UPPER(ou.name COLLATE utf8mb4_unicode_ci) LIKE '%CHIRIQUI GRANDE%' OR UPPER(ou.name COLLATE utf8mb4_unicode_ci) LIKE '%ISLA%COLON%')
ON DUPLICATE KEY UPDATE
  area_code=VALUES(area_code),
  area_unit_id=VALUES(area_unit_id),
  location_sector=VALUES(location_sector),
  zone_unit_id=VALUES(zone_unit_id),
  notes=VALUES(notes),
  updated_at=NOW();

-- 5) Vincular el personal DINSEC a su unidad/area/sector dentro de la base de trabajo.
INSERT INTO dinsec_personnel_unit_links
(dinsec_personnel_reference_id, zone_unit_id, area_unit_id, sector_catalog_id, assignment_unit_id, assignment_scope, position_number, full_name, rank_text, assignment_text, location_sector, status, notes)
SELECT d.id,
       d.zone_unit_id,
       area.id AS area_unit_id,
       s.id AS sector_catalog_id,
       COALESCE(sectorUnit.id, area.id, d.zone_unit_id) AS assignment_unit_id,
       CASE
         WHEN d.area_code IS NOT NULL AND s.id IS NOT NULL THEN 'sector'
         WHEN d.area_code IS NOT NULL THEN 'area'
         WHEN d.service_label IS NOT NULL THEN 'servicio'
         ELSE 'zona'
       END AS assignment_scope,
       d.position_number,
       d.full_name,
       d.rank_text,
       d.assignment_text,
       d.location_sector,
       'active',
       'Vinculo aplicado desde DINSEC Zona 1. No reemplaza RRHH hasta conciliacion final.'
FROM dinsec_personnel_reference d
LEFT JOIN organizational_units area
  ON BINARY area.legacy_table=BINARY 'MOI_CABECERA_AREA'
 AND BINARY area.legacy_id=BINARY CONCAT('Z01-AREA-',d.area_code)
LEFT JOIN moi_area_sector_catalog s
  ON s.zone_number=1
 AND ((s.area_code=d.area_code) OR (s.area_code IS NULL AND d.area_code IS NULL))
 AND UPPER(s.sector_name COLLATE utf8mb4_unicode_ci)=UPPER(d.location_sector COLLATE utf8mb4_unicode_ci)
LEFT JOIN organizational_units sectorUnit
  ON BINARY sectorUnit.legacy_table=BINARY 'DINSEC_SECTOR'
 AND BINARY sectorUnit.legacy_id=BINARY CONCAT('Z01-',d.area_code,'-',REPLACE(REPLACE(REPLACE(UPPER(d.location_sector),' ','-'),'Í','I'),'Á','A'))
WHERE d.zone_label='1 Zona Policial - Bocas del Toro'
ON DUPLICATE KEY UPDATE
  zone_unit_id=VALUES(zone_unit_id),
  area_unit_id=VALUES(area_unit_id),
  sector_catalog_id=VALUES(sector_catalog_id),
  assignment_unit_id=VALUES(assignment_unit_id),
  assignment_scope=VALUES(assignment_scope),
  position_number=VALUES(position_number),
  full_name=VALUES(full_name),
  rank_text=VALUES(rank_text),
  assignment_text=VALUES(assignment_text),
  location_sector=VALUES(location_sector),
  status='active',
  notes=VALUES(notes),
  updated_at=NOW();

INSERT INTO moi_zone_apply_audit (zone_number, zone_label, action_name, affected_rows, notes)
SELECT 1, '1 Zona Policial - Bocas del Toro', 'aplicar_zona_01_bocas', COUNT(*), 'Personal DINSEC vinculado a estructura de trabajo'
FROM dinsec_personnel_unit_links l
JOIN dinsec_personnel_reference d ON d.id=l.dinsec_personnel_reference_id
WHERE d.zone_label='1 Zona Policial - Bocas del Toro';
