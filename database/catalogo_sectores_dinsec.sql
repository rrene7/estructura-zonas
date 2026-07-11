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

INSERT IGNORE INTO moi_area_sector_catalog
(zone_number, zone_label, area_code, area_name, sector_name, service_label, op_status, notes)
VALUES
-- 2da Zona Policial - Cocle. El documento muestra Area A Penonome, Coclesito, La Pintada, El Cope, Toabre y Caimito; Area B Aguadulce y El Roble; Area C Anton, Rio Hato y Santa Clara; Area D Nata y Ola.
(2, '2 Zona Policial - Cocle', 'A', 'Area A', 'PENONOME', NULL, 'NO DEFINIDO', 'Catalogo base DINSEC'),
(2, '2 Zona Policial - Cocle', 'A', 'Area A', 'COCLESITO', NULL, 'NO DEFINIDO', 'Catalogo base DINSEC'),
(2, '2 Zona Policial - Cocle', 'A', 'Area A', 'LA PINTADA', NULL, 'NO DEFINIDO', 'Catalogo base DINSEC'),
(2, '2 Zona Policial - Cocle', 'A', 'Area A', 'EL COPE', NULL, 'NO DEFINIDO', 'Catalogo base DINSEC'),
(2, '2 Zona Policial - Cocle', 'A', 'Area A', 'TOABRE', NULL, 'NO DEFINIDO', 'Catalogo base DINSEC'),
(2, '2 Zona Policial - Cocle', 'A', 'Area A', 'CAIMITO', NULL, 'NO DEFINIDO', 'Catalogo base DINSEC'),
(2, '2 Zona Policial - Cocle', 'B', 'Area B', 'AGUADULCE', NULL, 'NO DEFINIDO', 'Catalogo base DINSEC'),
(2, '2 Zona Policial - Cocle', 'B', 'Area B', 'EL ROBLE', NULL, 'NO DEFINIDO', 'Catalogo base DINSEC'),
(2, '2 Zona Policial - Cocle', 'C', 'Area C', 'ANTON', NULL, 'NO DEFINIDO', 'Catalogo base DINSEC'),
(2, '2 Zona Policial - Cocle', 'C', 'Area C', 'RIO HATO', NULL, 'NO DEFINIDO', 'Catalogo base DINSEC'),
(2, '2 Zona Policial - Cocle', 'C', 'Area C', 'SANTA CLARA', NULL, 'NO DEFINIDO', 'Catalogo base DINSEC'),
(2, '2 Zona Policial - Cocle', 'D', 'Area D', 'NATA', NULL, 'NO DEFINIDO', 'Catalogo base DINSEC'),
(2, '2 Zona Policial - Cocle', 'D', 'Area D', 'OLA', NULL, 'NO DEFINIDO', 'Catalogo base DINSEC'),

-- 3ra Zona Policial - Colon. El documento muestra areas A-J y un cuadro con total OP/NO OP por area/servicio.
(3, '3 Zona Policial - Colon', 'A', 'Area A', 'COLON CENTRO', NULL, 'NO DEFINIDO', 'Catalogo base DINSEC'),
(3, '3 Zona Policial - Colon', 'A', 'Area A', 'COLON', NULL, 'NO DEFINIDO', 'Alias usado en listado'),
(3, '3 Zona Policial - Colon', 'B', 'Area B', 'SABANITAS', NULL, 'NO DEFINIDO', 'Catalogo base DINSEC'),
(3, '3 Zona Policial - Colon', 'C', 'Area C', 'BUENA VISTA', NULL, 'NO DEFINIDO', 'Catalogo base DINSEC'),
(3, '3 Zona Policial - Colon', 'D', 'Area D', 'COSTA ARRIBA', NULL, 'NO DEFINIDO', 'Catalogo base DINSEC'),
(3, '3 Zona Policial - Colon', 'E', 'Area E', 'LOS LAGOS - LA FERIA', NULL, 'NO DEFINIDO', 'Catalogo base DINSEC'),
(3, '3 Zona Policial - Colon', 'E', 'Area E', 'LA FERIA', NULL, 'NO DEFINIDO', 'Alias usado en organigrama'),
(3, '3 Zona Policial - Colon', 'E', 'Area E', 'LOS LAGOS', NULL, 'NO DEFINIDO', 'Alias usado en listado'),
(3, '3 Zona Policial - Colon', 'F', 'Area F', 'BATERIA 35', NULL, 'NO DEFINIDO', 'Catalogo base DINSEC'),
(3, '3 Zona Policial - Colon', 'G', 'Area G', 'VILLA DEL CARIBE', NULL, 'NO DEFINIDO', 'Catalogo base DINSEC'),
(3, '3 Zona Policial - Colon', 'H', 'Area H', 'CATIVA', NULL, 'NO DEFINIDO', 'Catalogo base DINSEC'),
(3, '3 Zona Policial - Colon', 'I', 'Area I', 'ALTOS DE LOS LAGOS', NULL, 'NO DEFINIDO', 'Catalogo base DINSEC'),
(3, '3 Zona Policial - Colon', 'J', 'Area J', 'MARGARITA', NULL, 'NO DEFINIDO', 'Catalogo base DINSEC'),
(3, '3 Zona Policial - Colon', NULL, NULL, 'SPM-LINCES COLON', 'LINCES', 'OP', 'Servicio dentro de zona'),
(3, '3 Zona Policial - Colon', NULL, NULL, 'SERVICIO DE NINEZ Y ADOLESCENCIA', 'NINEZ Y ADOLESCENCIA', 'OP', 'Servicio dentro de zona'),
(3, '3 Zona Policial - Colon', NULL, NULL, 'SERVICIO DE TURISMO', 'TURISMO', 'OP', 'Servicio dentro de zona'),
(3, '3 Zona Policial - Colon', NULL, NULL, 'SEGURIDAD CIUDADANA', 'SEGURIDAD CIUDADANA', 'NO DEFINIDO', 'Servicio dentro de zona'),
(3, '3 Zona Policial - Colon', NULL, NULL, 'ADMINISTRATIVOS', 'ADMINISTRATIVOS', 'NO DEFINIDO', 'Servicio dentro de zona');
