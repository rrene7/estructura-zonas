-- Catalogo DINSEC 04AGO2025 - 2 Zona Policial Cocle
-- No contiene datos personales. Solo estructura territorial/servicios de referencia.

CREATE TABLE IF NOT EXISTS moi_area_sector_catalog (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source_name VARCHAR(180) NOT NULL DEFAULT 'DINSEC 04AGO2025',
  zone_number INT NOT NULL,
  zone_label VARCHAR(180) NOT NULL,
  area_code VARCHAR(10) NULL,
  area_name VARCHAR(120) NULL,
  sector_name VARCHAR(180) NOT NULL,
  service_label VARCHAR(180) NULL,
  op_status ENUM('OP','NO OP','OA','NO DEFINIDO') NOT NULL DEFAULT 'NO DEFINIDO',
  notes VARCHAR(255) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sector (zone_number, area_code, sector_name, service_label),
  INDEX idx_sector_zone (zone_number),
  INDEX idx_sector_area (area_code),
  INDEX idx_sector_name (sector_name),
  INDEX idx_sector_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO moi_area_sector_catalog
(zone_number, zone_label, area_code, area_name, sector_name, service_label, op_status, notes)
VALUES
(2, '2 Zona Policial - Cocle', 'A', 'Area A', 'PENONOME', NULL, 'NO DEFINIDO', 'DINSEC pag. 5-6'),
(2, '2 Zona Policial - Cocle', 'A', 'Area A', 'COCLESITO', NULL, 'NO DEFINIDO', 'DINSEC pag. 6'),
(2, '2 Zona Policial - Cocle', 'A', 'Area A', 'LA PINTADA', NULL, 'NO DEFINIDO', 'DINSEC pag. 6'),
(2, '2 Zona Policial - Cocle', 'A', 'Area A', 'EL COPE', NULL, 'NO DEFINIDO', 'DINSEC pag. 6'),
(2, '2 Zona Policial - Cocle', 'A', 'Area A', 'TOABRE', NULL, 'NO DEFINIDO', 'DINSEC pag. 6'),
(2, '2 Zona Policial - Cocle', 'A', 'Area A', 'CAIMITO', NULL, 'NO DEFINIDO', 'DINSEC pag. 6'),
(2, '2 Zona Policial - Cocle', 'B', 'Area B', 'AGUADULCE', NULL, 'NO DEFINIDO', 'DINSEC pag. 5-6'),
(2, '2 Zona Policial - Cocle', 'B', 'Area B', 'EL ROBLE', NULL, 'NO DEFINIDO', 'DINSEC pag. 6'),
(2, '2 Zona Policial - Cocle', 'C', 'Area C', 'ANTON', NULL, 'NO DEFINIDO', 'DINSEC pag. 5-6'),
(2, '2 Zona Policial - Cocle', 'C', 'Area C', 'RIO HATO', NULL, 'NO DEFINIDO', 'DINSEC pag. 6'),
(2, '2 Zona Policial - Cocle', 'C', 'Area C', 'SANTA CLARA', NULL, 'NO DEFINIDO', 'DINSEC pag. 6'),
(2, '2 Zona Policial - Cocle', 'D', 'Area D', 'NATA', NULL, 'NO DEFINIDO', 'DINSEC pag. 5-6'),
(2, '2 Zona Policial - Cocle', 'D', 'Area D', 'OLA', NULL, 'NO DEFINIDO', 'DINSEC pag. 6'),
(2, '2 Zona Policial - Cocle', NULL, NULL, 'DEPTO. DE RR.HH', 'RR.HH', 'NO DEFINIDO', 'Servicio/Departamento DINSEC'),
(2, '2 Zona Policial - Cocle', NULL, NULL, 'DEPTO. DE SERV. GENERALES', 'SERVICIOS GENERALES', 'NO DEFINIDO', 'Servicio/Departamento DINSEC'),
(2, '2 Zona Policial - Cocle', NULL, NULL, 'DEPTO. DE INFRAESTRUCTURA', 'INFRAESTRUCTURA', 'NO DEFINIDO', 'Servicio/Departamento DINSEC'),
(2, '2 Zona Policial - Cocle', NULL, NULL, 'DEPTO. DE COMBUSTIBLE', 'COMBUSTIBLE', 'NO DEFINIDO', 'Servicio/Departamento DINSEC'),
(2, '2 Zona Policial - Cocle', NULL, NULL, 'DEPTO. DE TRANSPORTE', 'TRANSPORTE', 'NO DEFINIDO', 'Servicio/Departamento DINSEC'),
(2, '2 Zona Policial - Cocle', NULL, NULL, 'DEPTO. DE TELEMATICA', 'TELEMATICA', 'NO DEFINIDO', 'Servicio/Departamento DINSEC'),
(2, '2 Zona Policial - Cocle', NULL, NULL, 'SERV. INTEGRA. PART. CIUDAD', 'INTEGRACION Y PARTICIPACION CIUDADANA', 'NO DEFINIDO', 'Servicio DINSEC'),
(2, '2 Zona Policial - Cocle', NULL, NULL, 'S.P. DE V. DOMESTICA Y GENERO', 'VIOLENCIA DOMESTICA Y GENERO', 'NO DEFINIDO', 'Servicio DINSEC')
ON DUPLICATE KEY UPDATE
  area_name=VALUES(area_name),
  service_label=VALUES(service_label),
  op_status=VALUES(op_status),
  notes=VALUES(notes),
  active=1,
  updated_at=NOW();
