-- Carga controlada DINSEC 04AGO2025 - 1 Zona Policial Bocas del Toro
-- Fuente: COMPOSICION OFICIALES DINSEC POR ZONAS Y SERVICIOS POLICIALES, paginas 3 y 4.
-- No modifica RRHH. Solo carga catalogo de sectores y referencia de personal en la base de trabajo.

CREATE TABLE IF NOT EXISTS dinsec_document_sources (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  document_name VARCHAR(180) NOT NULL,
  document_date DATE NULL,
  uploaded_file_name VARCHAR(220) NULL,
  notes VARCHAR(255) NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_dinsec_source (document_name, document_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO dinsec_document_sources
(document_name, document_date, uploaded_file_name, notes)
VALUES
('COMPOSICION OFICIALES DINSEC POR ZONAS Y SERVICIOS POLICIALES','2025-08-04','COMPOSICIÓN OFICIALES 04AGO25 DINSEC.pdf','Documento de referencia para validacion por zona');

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
(1, '1 Zona Policial - Bocas del Toro', 'A', 'Area A', 'CHANGUINOLA', NULL, 'NO DEFINIDO', 'Organigrama DINSEC pag. 3'),
(1, '1 Zona Policial - Bocas del Toro', 'B', 'Area B', 'ISLA COLON', NULL, 'NO DEFINIDO', 'Organigrama DINSEC pag. 3'),
(1, '1 Zona Policial - Bocas del Toro', 'C', 'Area C', 'ALMIRANTE', NULL, 'NO DEFINIDO', 'Organigrama DINSEC pag. 3'),
(1, '1 Zona Policial - Bocas del Toro', 'D', 'Area D', 'CHIRIQUI GRANDE', NULL, 'NO DEFINIDO', 'Organigrama DINSEC pag. 3'),
(1, '1 Zona Policial - Bocas del Toro', NULL, NULL, '1A ZONA POLICIAL', 'JEFATURA / ZONA', 'NO DEFINIDO', 'Asignacion de oficiales pag. 4'),
(1, '1 Zona Policial - Bocas del Toro', NULL, NULL, 'ADMINISTRATIVO', 'ADMINISTRATIVO', 'NO DEFINIDO', 'Asignacion de oficiales pag. 4'),
(1, '1 Zona Policial - Bocas del Toro', NULL, NULL, 'CARCEL DE DEBORAH', 'CENTRO PENITENCIARIO', 'NO DEFINIDO', 'Asignacion de oficiales pag. 4'),
(1, '1 Zona Policial - Bocas del Toro', NULL, NULL, 'SERVICIOS GENERALES', 'SERVICIOS GENERALES', 'NO DEFINIDO', 'Asignacion de oficiales pag. 4'),
(1, '1 Zona Policial - Bocas del Toro', NULL, NULL, 'RECLUTAMIENTO', 'RECLUTAMIENTO', 'NO DEFINIDO', 'Organigrama DINSEC pag. 3'),
(1, '1 Zona Policial - Bocas del Toro', NULL, NULL, 'P1', 'P1', 'NO DEFINIDO', 'Organigrama DINSEC pag. 3'),
(1, '1 Zona Policial - Bocas del Toro', NULL, NULL, 'P3', 'P3', 'NO DEFINIDO', 'Organigrama DINSEC pag. 3'),
(1, '1 Zona Policial - Bocas del Toro', NULL, NULL, 'P4', 'P4', 'NO DEFINIDO', 'Organigrama DINSEC pag. 3'),
(1, '1 Zona Policial - Bocas del Toro', NULL, NULL, 'JEFATURA ADMINISTRATIVA', 'J. ADM.', 'NO DEFINIDO', 'Organigrama DINSEC pag. 3'),
(1, '1 Zona Policial - Bocas del Toro', NULL, NULL, 'SUPERVISOR NOCTURNO', 'SUP. NOCT.', 'NO DEFINIDO', 'Organigrama DINSEC pag. 3');

CREATE TABLE IF NOT EXISTS dinsec_personnel_reference (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source_id BIGINT UNSIGNED NOT NULL,
  page_number INT NULL,
  row_number INT NULL,
  zone_label VARCHAR(180) NULL,
  zone_unit_id BIGINT UNSIGNED NULL,
  area_code VARCHAR(10) NULL,
  area_name VARCHAR(120) NULL,
  location_sector VARCHAR(180) NULL,
  direction_label VARCHAR(220) NULL,
  direction_unit_id BIGINT UNSIGNED NULL,
  service_label VARCHAR(180) NULL,
  op_status ENUM('OP','NO OP','OA','NO DEFINIDO') NOT NULL DEFAULT 'NO DEFINIDO',
  rank_text VARCHAR(80) NULL,
  position_number VARCHAR(50) NULL,
  full_name VARCHAR(180) NOT NULL,
  assignment_text VARCHAR(220) NULL,
  observation_text VARCHAR(220) NULL,
  raw_text TEXT NULL,
  matched_employee_id BIGINT UNSIGNED NULL,
  review_status ENUM('pendiente','validado','ignorado') NOT NULL DEFAULT 'pendiente',
  review_notes VARCHAR(255) NULL,
  created_by VARCHAR(100) NULL DEFAULT 'script_zona_01',
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_dinsec_source (source_id),
  INDEX idx_dinsec_zone (zone_unit_id),
  INDEX idx_dinsec_area (area_code, area_name),
  INDEX idx_dinsec_direction (direction_unit_id),
  INDEX idx_dinsec_position (position_number),
  INDEX idx_dinsec_name (full_name),
  INDEX idx_dinsec_review (review_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TEMPORARY TABLE tmp_dinsec_z01 (
  row_number INT,
  rank_text VARCHAR(80),
  position_number VARCHAR(50),
  full_name VARCHAR(180),
  assignment_text VARCHAR(220)
) ENGINE=MEMORY;

INSERT INTO tmp_dinsec_z01 (row_number, rank_text, position_number, full_name, assignment_text) VALUES
(3,'SUBCOM.','10715','JORGE GUERRA','1A ZONA POLICIAL'),
(4,'MAYOR','10682','ABDIEL ZAMBRANO','1A ZONA POLICIAL'),
(5,'MAYOR','11341','DAGOBERTO SANCHEZ','ALMIRANTE'),
(6,'CAPITAN','10034','JESUS SALAZAR','PANAMA'),
(7,'TENIENTE','14872','JOSE ANGEL ALMENGOR','JEFE DE AREA A'),
(8,'TENIENTE','16569','DOMINGO MELENDEZ','CHIRIQUI GRANDE'),
(9,'TENIENTE','15813','ROLANDO DIAZ','CHIRIQUI GRANDE'),
(10,'TENIENTE','47470','JAIME JIMENEZ VALDES','CHIRIQUI GRANDE'),
(11,'TENIENTE','15944','ANSELMO GUERRA','CARCEL DE DEBORA'),
(12,'TENIENTE','15830','SAMUEL VILLAGRA','CARCEL DE DEBORAH'),
(13,'TENIENTE','15033','GUSTAVO RODRIGUEZ','ADMINISTRATIVO'),
(14,'TENIENTE','15232','CARLOS SALAS','CHIRIQUI GRANDE'),
(15,'TENIENTE','15490','ROGER CUBILLO','CHANGUINOLA'),
(16,'TENIENTE','16735','ALEXIS IVAN RODRIGUEZ C.','CARCEL DE DEBORAH'),
(17,'TENIENTE','16464','RAUL GOMEZ','CHIRIQUI GRANDE'),
(18,'TENIENTE','16776','JOSE SAMUDIO','ALMIRANTE'),
(19,'TENIENTE','11541','RODRIGO ALIE CORTES SOLIS','CARCEL DE DEBORAH'),
(20,'TENIENTE','15961','ELMER NAVARRO','CHANGUINOLA'),
(21,'TENIENTE','16233','JACINTO AGUILA','CARCEL DE DEBORAH'),
(22,'TENIENTE','16363','VERISIMO CEPEDA','ADMINISTRATIVO'),
(23,'TENIENTE','16765','ERIKA RUIZ','ADMINISTRATIVO'),
(24,'TENIENTE','48949','JAVIER CABALLERO','ADMINISTRATIVO'),
(25,'TENIENTE','16964','HELIODORO G GOMEZ','ADMINISTRATIVO'),
(26,'TENIENTE','15520','DENNIS THOMAS','CARCEL DE DEBORAH'),
(27,'TENIENTE','17004','JOSE HERNANDEZ','ALMIRANTE'),
(28,'TENIENTE','16346','ROLANDO CASTILLO','ADMINISTRATIVO'),
(29,'TENIENTE','16772','ALEXIS SALDAÑA','CHIRIQUI GRANDE'),
(30,'TENIENTE','17057','ABDIEL WILLIAMS','ADMINISTRATIVO'),
(31,'TENIENTE','16968','CARLOS GARCIA','1A ZONA POLICIAL'),
(32,'TENIENTE','16557','EYNAR MARTINEZ','CHIRIQUI GRANDE'),
(33,'TENIENTE','18260','DANIEL ABREGO','CHANGUINOLA'),
(34,'TENIENTE','16303','JOSE BEKER','1A ZONA POLICIAL'),
(35,'TENIENTE','18242','SILVERIO RODRIGUEZ','CHANGUINOLA'),
(36,'TENIENTE','15032','OSCAR IRVING ESPINOZA TROTMAN','CHANGUINOLA'),
(37,'TENIENTE','28567','YARIBETH VERGARA','SERVICIOS GENERALES'),
(38,'TENIENTE','15769','REMBERTO ROSE','CHANGUINOLA'),
(39,'TENIENTE','18537','ORLANDO ANTONIO PITTI LINARTE','CHANGUINOLA'),
(40,'TENIENTE','47566','ROBERTO CASTILLO','ADMINISTRATIVO'),
(41,'TENIENTE','18450','WILLIAMS JOHNSTON','CARCEL DE DEBORAH'),
(42,'TENIENTE','47809','ARIEL RODRIGUEZ','ALMIRANTE'),
(43,'TENIENTE','18520','ENRIQUE PARDO','CHIRIQUI GRANDE'),
(44,'TENIENTE','18452','ARCELIO JUAREZ V.','CARCEL DE DEBORAH'),
(45,'TENIENTE','18586','ENRIQUE SALAZAR MUNUNIMO','CHIRIQUI GRANDE'),
(46,'TENIENTE','18082','EDGAR E. GANTES G.','CHANGUINOLA'),
(47,'SUBTENIENTE','29301','RODRIGO ISAAC QUINTERO GONZALEZ','CHANGUINOLA'),
(48,'SUBTENIENTE','47920','ROBERTO ADAMES','CHANGUINOLA'),
(49,'SUBTENIENTE','17666','ERIC SANTIAGO','ADMINISTRATIVO'),
(50,'SUBTENIENTE','18756','FRANKLIN ALFREDO GRANGER HOUDSON','CHANGUINOLA'),
(51,'SUBTENIENTE','17714','SERGIO TAYLOR VICENTE','ALMIRANTE'),
(52,'SUBTENIENTE','18609','GERMAN TAYLOR','CHANGUINOLA'),
(53,'SUBTENIENTE','18507','HECTOR NUÑEZ','CHIRIQUI GRANDE'),
(54,'SUBTENIENTE','17682','KERUVINIO TAYLOR','CHANGUINOLA'),
(55,'SUBTENIENTE','18519','GERONIMO PALACIO','CARCEL DE DEBORAH'),
(56,'SUBTENIENTE','18487','ERNESTO MOLINA CASTILLO','ADMINISTRATIVO'),
(57,'SUBTENIENTE','17772','MANUEL NEGRETE','CHANGUINOLA'),
(58,'SUBTENIENTE','18298','ROGELIO PALACIO','ALMIRANTE'),
(59,'SUBTENIENTE','18613','FLORENTINO TROTMAN','CARCEL DE DEBORAH'),
(60,'SUBTENIENTE','17833','ABEL CEDEÑO SANTANA','CHANGUINOLA'),
(61,'SUBTENIENTE','18748','JUAN GONZALEZ','CHANGUINOLA'),
(62,'SUBTENIENTE','20252','GABRIEL MACHUCA','CHIRIQUI GRANDE'),
(63,'SUBTENIENTE','20948','GILBERTO GONZALEZ BARRIA','ADMINISTRATIVO'),
(64,'SUBTENIENTE','20262','JOSE MANUEL BATISTA Q.','ADMINISTRATIVO'),
(65,'SUBTENIENTE','20318','CARLOS ARIEL MESA','ADMINISTRATIVO'),
(66,'SUBTENIENTE','20785','GIL GARCIA','ADMINISTRATIVO'),
(67,'SUBTENIENTE','20332','CARLOS MORALES','CHANGUINOLA'),
(68,'SUBTENIENTE','20984','FERNANDO MUÑOZ B.','CHIRIQUI GRANDE'),
(69,'SUBTENIENTE','19318','JOSE L. DE GRACIA','ALMIRANTE'),
(70,'SUBTENIENTE','16391','DANIEL HOOKER','CHANGUINOLA'),
(71,'SUBTENIENTE','18522','DUNIA PATTERSON','CARCEL DE DEBORAH');

INSERT INTO dinsec_personnel_reference
(source_id, page_number, row_number, zone_label, zone_unit_id, area_code, area_name, location_sector, service_label, op_status, rank_text, position_number, full_name, assignment_text, raw_text, review_status, review_notes, created_by)
SELECT
  src.id,
  4,
  t.row_number,
  '1 Zona Policial - Bocas del Toro',
  zcab.id,
  CASE
    WHEN t.assignment_text IN ('CHANGUINOLA','JEFE DE AREA A') THEN 'A'
    WHEN t.assignment_text = 'ISLA COLON' THEN 'B'
    WHEN t.assignment_text = 'ALMIRANTE' THEN 'C'
    WHEN t.assignment_text = 'CHIRIQUI GRANDE' THEN 'D'
    ELSE NULL
  END,
  CASE
    WHEN t.assignment_text IN ('CHANGUINOLA','JEFE DE AREA A') THEN 'Area A'
    WHEN t.assignment_text = 'ISLA COLON' THEN 'Area B'
    WHEN t.assignment_text = 'ALMIRANTE' THEN 'Area C'
    WHEN t.assignment_text = 'CHIRIQUI GRANDE' THEN 'Area D'
    ELSE NULL
  END,
  CASE
    WHEN t.assignment_text = 'JEFE DE AREA A' THEN 'CHANGUINOLA'
    WHEN t.assignment_text IN ('CHANGUINOLA','ALMIRANTE','CHIRIQUI GRANDE','ISLA COLON') THEN t.assignment_text
    WHEN t.assignment_text = 'CARCEL DE DEBORA' THEN 'CARCEL DE DEBORAH'
    ELSE t.assignment_text
  END,
  CASE
    WHEN t.assignment_text IN ('ADMINISTRATIVO','CARCEL DE DEBORA','CARCEL DE DEBORAH','SERVICIOS GENERALES','1A ZONA POLICIAL','PANAMA') THEN t.assignment_text
    ELSE NULL
  END,
  'NO DEFINIDO',
  t.rank_text,
  t.position_number,
  t.full_name,
  t.assignment_text,
  CONCAT(t.row_number,' ',t.rank_text,' ',t.position_number,' ',t.full_name,' ',t.assignment_text),
  'pendiente',
  'Carga inicial DINSEC Zona 1 desde pagina 4. Validar filas 1-2 manualmente si aparecen en imagen.',
  'script_zona_01'
FROM tmp_dinsec_z01 t
JOIN dinsec_document_sources src
  ON src.document_name='COMPOSICION OFICIALES DINSEC POR ZONAS Y SERVICIOS POLICIALES'
 AND src.document_date='2025-08-04'
LEFT JOIN organizational_units zcab
  ON BINARY zcab.legacy_table=BINARY 'MOI_CABECERA_ZONA'
 AND CAST(zcab.legacy_id AS UNSIGNED)=1
WHERE NOT EXISTS (
  SELECT 1
  FROM dinsec_personnel_reference d
  WHERE d.source_id=src.id
    AND d.position_number=t.position_number
    AND d.zone_label='1 Zona Policial - Bocas del Toro'
);

DROP TEMPORARY TABLE IF EXISTS tmp_dinsec_z01;

SELECT 'Zona 1 - sectores catalogo' AS concepto, COUNT(*) AS total
FROM moi_area_sector_catalog
WHERE zone_number=1
UNION ALL
SELECT 'Zona 1 - personal DINSEC referencia' AS concepto, COUNT(*) AS total
FROM dinsec_personnel_reference
WHERE zone_label='1 Zona Policial - Bocas del Toro';
