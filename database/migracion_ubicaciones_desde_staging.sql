-- Borrador de migracion desde staging Access hacia modelo normalizado.
-- Requiere haber creado antes `database/estructura_ubicaciones_dependencias.sql`.
-- Revisar y ajustar antes de ejecutar en produccion.

-- 1. Provincias desde TABDIR
INSERT INTO territorial_divisions (parent_id, type, name, code, created_at, updated_at)
SELECT DISTINCT NULL, 'provincia', desprov, CAST(codprov AS CHAR), NOW(), NOW()
FROM stg_tabdir
WHERE codprov IS NOT NULL AND desprov IS NOT NULL;

-- 2. Distritos desde TABDIR
INSERT INTO territorial_divisions (parent_id, type, name, code, created_at, updated_at)
SELECT DISTINCT p.id, 'distrito', d.desdist, CONCAT(CAST(d.codprov AS CHAR), '-', CAST(d.coddist AS CHAR)), NOW(), NOW()
FROM stg_tabdir d
JOIN territorial_divisions p
  ON p.type = 'provincia'
 AND p.code = CAST(d.codprov AS CHAR)
WHERE d.coddist IS NOT NULL AND d.desdist IS NOT NULL;

-- 3. Corregimientos desde TABDIR
INSERT INTO territorial_divisions (parent_id, type, name, code, created_at, updated_at)
SELECT DISTINCT dist.id, 'corregimiento', d.descorr,
       CONCAT(CAST(d.codprov AS CHAR), '-', CAST(d.coddist AS CHAR), '-', CAST(d.codcorr AS CHAR)),
       NOW(), NOW()
FROM stg_tabdir d
JOIN territorial_divisions dist
  ON dist.type = 'distrito'
 AND dist.code = CONCAT(CAST(d.codprov AS CHAR), '-', CAST(d.coddist AS CHAR))
WHERE d.codcorr IS NOT NULL AND d.descorr IS NOT NULL;

-- 4. Unidades organizacionales desde TABCUAR
-- Se cargan inicialmente como tipo `cuartel`. Luego se pueden reclasificar a zona, direccion, area, departamento, oficina o dependencia.
INSERT INTO organizational_units
(parent_id, unit_type_id, code, name, short_name, level, is_operational, is_administrative, status, legacy_table, legacy_id, created_at, updated_at)
SELECT NULL, ut.id, CAST(c.codigocuar AS CHAR), c.descricuar, c.descricuar, 1, TRUE, TRUE,
       CASE WHEN c.vigente = 0 THEN 'inactive' ELSE 'active' END,
       'TABCUAR', CAST(c.codigocuar AS CHAR), NOW(), NOW()
FROM stg_tabcuar c
JOIN unit_types ut ON ut.name = 'cuartel'
WHERE c.codigocuar IS NOT NULL AND c.descricuar IS NOT NULL;

-- 5. Lugares desde TABLUGAR como ubicaciones genericas
INSERT INTO locations (address, reference, status, legacy_table, legacy_id, created_at, updated_at)
SELECT l.deslug, l.deslug, 'active', 'TABLUGAR', CAST(l.codigo AS CHAR), NOW(), NOW()
FROM stg_tablugar l
WHERE l.codigo IS NOT NULL AND l.deslug IS NOT NULL;

-- 6. Direcciones locales desde DIR con codigos geograficos
INSERT INTO locations (province_id, district_id, corregimiento_id, address, reference, status, legacy_table, legacy_id, created_at, updated_at)
SELECT p.id, dist.id, corr.id, d.dlocal, d.dlugar, 'active', 'DIR', CAST(d.dnemp AS CHAR), NOW(), NOW()
FROM stg_dir d
LEFT JOIN territorial_divisions p
  ON p.type = 'provincia'
 AND p.code = CAST(d.dcodp AS CHAR)
LEFT JOIN territorial_divisions dist
  ON dist.type = 'distrito'
 AND dist.code = CONCAT(CAST(d.dcodp AS CHAR), '-', CAST(d.dcodd AS CHAR))
LEFT JOIN territorial_divisions corr
  ON corr.type = 'corregimiento'
 AND corr.code = CONCAT(CAST(d.dcodp AS CHAR), '-', CAST(d.dcodd AS CHAR), '-', CAST(d.dcodc AS CHAR))
WHERE d.dlocal IS NOT NULL OR d.dlugar IS NOT NULL;

-- 7. Posiciones desde POLPLANI
-- Nota: esta carga deja la unidad organizacional como provisional. Hay que sustituir la union por la relacion real posicion -> dependencia.
INSERT INTO positions (position_code, title, organizational_unit_id, status, legacy_table, legacy_id, created_at, updated_at)
SELECT DISTINCT CAST(p.posicion AS CHAR), CONCAT('Posicion ', CAST(p.posicion AS CHAR)), ou.id, 'active', 'POLPLANI', CAST(p.posicion AS CHAR), NOW(), NOW()
FROM stg_polplani p
JOIN organizational_units ou ON ou.legacy_table = 'TABCUAR'
WHERE p.posicion IS NOT NULL;

-- 8. Asignaciones desde DOTA
-- Nota: depende de que employee_id/person_id exista en el sistema destino. Aqui se conserva NEMP como legacy_id.
INSERT INTO unit_assignments (person_id, position_id, organizational_unit_id, assignment_type, start_date, end_date, source_action_id, status, legacy_table, legacy_id, created_at, updated_at)
SELECT CAST(d.nemp AS UNSIGNED), NULL, ou.id, 'actual',
       COALESCE(DATE(d.fectras), DATE(d.fecing), CURRENT_DATE),
       NULL, NULL,
       CASE WHEN d.estado IS NULL THEN 'active' ELSE 'active' END,
       'DOTA', CAST(d.nemp AS CHAR), NOW(), NOW()
FROM stg_dota d
JOIN organizational_units ou
  ON ou.legacy_table = 'TABCUAR'
 AND ou.legacy_id = CAST(d.cuartel AS CHAR)
WHERE d.nemp IS NOT NULL AND d.cuartel IS NOT NULL;
