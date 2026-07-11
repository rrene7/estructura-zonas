-- Limpieza de duplicados del catalogo DINSEC Zona 2 - Cocle.
-- MySQL permite duplicados cuando service_label es NULL en una llave UNIQUE.
-- Este script deja un solo registro por zona/area/sector/servicio.

DELETE c1
FROM moi_area_sector_catalog c1
JOIN moi_area_sector_catalog c2
  ON c1.zone_number=c2.zone_number
 AND COALESCE(c1.area_code,'')=COALESCE(c2.area_code,'')
 AND UPPER(c1.sector_name COLLATE utf8mb4_unicode_ci)=UPPER(c2.sector_name COLLATE utf8mb4_unicode_ci)
 AND COALESCE(c1.service_label,'')=COALESCE(c2.service_label,'')
 AND c1.id > c2.id
WHERE c1.zone_number=2;

UPDATE moi_area_sector_catalog
SET active=1,
    zone_label='2 Zona Policial - Cocle',
    updated_at=NOW()
WHERE zone_number=2;
