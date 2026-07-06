-- Vista simple para clasificar unidades como OA, NO u OO.
-- OA = Operativo Administrativo
-- NO = No Operativo
-- OO = Operativo

CREATE OR REPLACE VIEW vw_moi_oa_no_oo AS
SELECT
    ou.id,
    ou.code,
    ou.name,
    ut.name AS unit_type,
    ou.territorial_scope,
    ou.command_relationship,
    ou.is_operational,
    ou.is_administrative,
    CASE
        WHEN ou.is_operational = 1 AND ou.is_administrative = 1 THEN 'OA'
        WHEN ou.is_operational = 1 AND ou.is_administrative = 0 THEN 'OO'
        WHEN ou.is_operational = 0 THEN 'NO'
        ELSE 'NO'
    END AS clasificacion_oa_no_oo,
    CASE
        WHEN ou.is_operational = 1 AND ou.is_administrative = 1 THEN 'Operativo Administrativo'
        WHEN ou.is_operational = 1 AND ou.is_administrative = 0 THEN 'Operativo'
        WHEN ou.is_operational = 0 THEN 'No Operativo'
        ELSE 'No Operativo'
    END AS descripcion_clasificacion,
    ou.parent_id,
    parent.name AS unidad_superior,
    ou.lifecycle_status,
    ou.legacy_table,
    ou.legacy_id
FROM organizational_units ou
LEFT JOIN unit_types ut ON ut.id = ou.unit_type_id
LEFT JOIN organizational_units parent ON parent.id = ou.parent_id;

CREATE OR REPLACE VIEW vw_moi_oa_no_oo_resumen AS
SELECT
    clasificacion_oa_no_oo,
    descripcion_clasificacion,
    COUNT(*) AS total
FROM vw_moi_oa_no_oo
WHERE lifecycle_status = 'vigente'
GROUP BY clasificacion_oa_no_oo, descripcion_clasificacion
ORDER BY clasificacion_oa_no_oo;
