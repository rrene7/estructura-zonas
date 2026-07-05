-- Clasificacion previa de registros heredados Access segun el modelo MOI.
-- Este script no migra datos finales; crea una mesa de revision.

DROP TABLE IF EXISTS stg_unit_classification;
CREATE TABLE stg_unit_classification (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_table VARCHAR(100) NOT NULL,
    source_id VARCHAR(100) NULL,
    source_name VARCHAR(255) NULL,
    suggested_unit_type VARCHAR(100) NULL,
    suggested_scope ENUM('nacional','regional','zonal','area','local','especializado','no_definido') NOT NULL DEFAULT 'no_definido',
    suggested_command_structure ENUM('mando_directo','linea_funcional','no_definido') NOT NULL DEFAULT 'no_definido',
    suggested_command_relationship ENUM('operacional','tactico','administrativo','funcional','no_definido') NOT NULL DEFAULT 'no_definido',
    suggested_facility_type VARCHAR(100) NULL,
    confidence_level ENUM('alto','medio','bajo') NOT NULL DEFAULT 'bajo',
    requires_review BOOLEAN NOT NULL DEFAULT TRUE,
    review_notes VARCHAR(255) NULL,
    reviewed_by VARCHAR(100) NULL,
    reviewed_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_stg_class_source (source_table, source_id),
    INDEX idx_stg_class_type (suggested_unit_type),
    INDEX idx_stg_class_review (requires_review, confidence_level)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 1. Base desde TABCUAR
INSERT INTO stg_unit_classification
(source_table, source_id, source_name, suggested_unit_type, suggested_scope, suggested_command_structure, suggested_command_relationship, suggested_facility_type, confidence_level, requires_review, review_notes)
SELECT
    'TABCUAR',
    CAST(codigocuar AS CHAR),
    descricuar,
    CASE
        WHEN UPPER(descricuar) LIKE '%REGION%' THEN 'region_policial'
        WHEN UPPER(descricuar) LIKE '%ZONA%' THEN 'zona_policial'
        WHEN UPPER(descricuar) LIKE '%AREA%' THEN 'area_policial'
        WHEN UPPER(descricuar) LIKE '%SERVICIO%' THEN 'servicio_policial'
        WHEN UPPER(descricuar) LIKE '%DIRECCION%' THEN 'direccion_nacional'
        WHEN UPPER(descricuar) LIKE '%DEPARTAMENTO%' THEN 'departamento'
        WHEN UPPER(descricuar) LIKE '%SECCION%' THEN 'seccion'
        WHEN UPPER(descricuar) LIKE '%OFICINA%' THEN 'oficina'
        WHEN UPPER(descricuar) LIKE '%SUBESTACION%' THEN 'subestacion_policial'
        WHEN UPPER(descricuar) LIKE '%ESTACION%' THEN 'estacion_policial'
        WHEN UPPER(descricuar) LIKE '%PUESTO%' THEN 'puesto_policial'
        WHEN UPPER(descricuar) LIKE '%DESTACAMENTO%' THEN 'destacamento'
        ELSE 'dependencia'
    END,
    CASE
        WHEN UPPER(descricuar) LIKE '%REGION%' THEN 'regional'
        WHEN UPPER(descricuar) LIKE '%ZONA%' THEN 'zonal'
        WHEN UPPER(descricuar) LIKE '%AREA%' THEN 'area'
        WHEN UPPER(descricuar) LIKE '%DIRECCION%' THEN 'nacional'
        WHEN UPPER(descricuar) LIKE '%SERVICIO%' THEN 'especializado'
        ELSE 'local'
    END,
    CASE
        WHEN UPPER(descricuar) LIKE '%DIRECCION%' THEN 'linea_funcional'
        WHEN UPPER(descricuar) LIKE '%SERVICIO%' THEN 'linea_funcional'
        WHEN UPPER(descricuar) LIKE '%REGION%' THEN 'mando_directo'
        WHEN UPPER(descricuar) LIKE '%ZONA%' THEN 'mando_directo'
        WHEN UPPER(descricuar) LIKE '%AREA%' THEN 'mando_directo'
        ELSE 'no_definido'
    END,
    CASE
        WHEN UPPER(descricuar) LIKE '%DIRECCION%' THEN 'funcional'
        WHEN UPPER(descricuar) LIKE '%SERVICIO%' THEN 'funcional'
        WHEN UPPER(descricuar) LIKE '%REGION%' THEN 'operacional'
        WHEN UPPER(descricuar) LIKE '%ZONA%' THEN 'operacional'
        WHEN UPPER(descricuar) LIKE '%AREA%' THEN 'tactico'
        ELSE 'no_definido'
    END,
    CASE
        WHEN UPPER(descricuar) LIKE '%SUBESTACION%' THEN 'subestacion_policial'
        WHEN UPPER(descricuar) LIKE '%ESTACION%' THEN 'estacion_policial'
        WHEN UPPER(descricuar) LIKE '%PUESTO%' THEN 'puesto_policial'
        WHEN UPPER(descricuar) LIKE '%DESTACAMENTO%' THEN 'destacamento'
        ELSE NULL
    END,
    CASE
        WHEN UPPER(descricuar) LIKE '%REGION%'
          OR UPPER(descricuar) LIKE '%ZONA%'
          OR UPPER(descricuar) LIKE '%AREA%'
          OR UPPER(descricuar) LIKE '%SERVICIO%'
          OR UPPER(descricuar) LIKE '%DIRECCION%'
          OR UPPER(descricuar) LIKE '%SUBESTACION%'
          OR UPPER(descricuar) LIKE '%ESTACION%'
          OR UPPER(descricuar) LIKE '%PUESTO%'
          OR UPPER(descricuar) LIKE '%DESTACAMENTO%'
        THEN 'medio'
        ELSE 'bajo'
    END,
    TRUE,
    'Clasificacion automatica inicial. Requiere validacion institucional.'
FROM stg_tabcuar
WHERE descricuar IS NOT NULL;

-- 2. Base desde BDFUERZA para comparar unidades resumidas
INSERT INTO stg_unit_classification
(source_table, source_id, source_name, suggested_unit_type, suggested_scope, suggested_command_structure, suggested_command_relationship, confidence_level, requires_review, review_notes)
SELECT
    'BDFUERZA',
    CAST(codcuar AS CHAR),
    descrip,
    CASE
        WHEN UPPER(descrip) LIKE '%REGION%' THEN 'region_policial'
        WHEN UPPER(descrip) LIKE '%ZONA%' THEN 'zona_policial'
        WHEN UPPER(descrip) LIKE '%AREA%' THEN 'area_policial'
        ELSE 'dependencia'
    END,
    CASE
        WHEN UPPER(descrip) LIKE '%REGION%' THEN 'regional'
        WHEN UPPER(descrip) LIKE '%ZONA%' THEN 'zonal'
        WHEN UPPER(descrip) LIKE '%AREA%' THEN 'area'
        ELSE 'local'
    END,
    CASE
        WHEN UPPER(descrip) LIKE '%REGION%' OR UPPER(descrip) LIKE '%ZONA%' OR UPPER(descrip) LIKE '%AREA%' THEN 'mando_directo'
        ELSE 'no_definido'
    END,
    CASE
        WHEN UPPER(descrip) LIKE '%AREA%' THEN 'tactico'
        WHEN UPPER(descrip) LIKE '%REGION%' OR UPPER(descrip) LIKE '%ZONA%' THEN 'operacional'
        ELSE 'no_definido'
    END,
    'bajo',
    TRUE,
    'BDFUERZA debe compararse con TABCUAR antes de migrar.'
FROM stg_bdfuerza
WHERE descrip IS NOT NULL;

-- 3. Detectar registros DOTA con cuartel no clasificado
SELECT d.cuartel, COUNT(*) AS total
FROM stg_dota d
LEFT JOIN stg_unit_classification c
  ON c.source_table = 'TABCUAR'
 AND c.source_id = CAST(d.cuartel AS CHAR)
WHERE c.id IS NULL
GROUP BY d.cuartel
ORDER BY total DESC;

-- 4. Pendientes de revision
SELECT source_table, source_id, source_name, suggested_unit_type, suggested_scope, confidence_level, review_notes
FROM stg_unit_classification
WHERE requires_review = TRUE
ORDER BY confidence_level, source_table, source_name;

-- 5. Conteo por clasificacion sugerida
SELECT suggested_unit_type, suggested_scope, COUNT(*) AS total
FROM stg_unit_classification
GROUP BY suggested_unit_type, suggested_scope
ORDER BY suggested_unit_type, suggested_scope;
