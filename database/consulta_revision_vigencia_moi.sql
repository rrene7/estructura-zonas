-- Consultas para revision de vigencia de unidades MOI.
-- Objetivo: preparar depuracion sin alterar el historico heredado.

-- 1. Resumen por estado de ciclo de vida
SELECT
    lifecycle_status,
    COUNT(*) AS total
FROM organizational_units
GROUP BY lifecycle_status
ORDER BY lifecycle_status;

-- 2. Unidades vigentes sin relacion superior
SELECT
    id,
    code,
    name,
    territorial_scope,
    legacy_table,
    legacy_id
FROM vw_moi_unidades_sin_relacion_superior
ORDER BY territorial_scope, name
LIMIT 100;

-- 3. Posibles unidades duplicadas vigentes
SELECT
    name,
    COUNT(*) AS total
FROM organizational_units
WHERE lifecycle_status = 'vigente'
GROUP BY name
HAVING COUNT(*) > 1
ORDER BY total DESC, name
LIMIT 100;

-- 4. Unidades no vigentes
SELECT
    id,
    code,
    name,
    territorial_scope,
    lifecycle_status,
    valid_from,
    valid_to,
    legacy_table,
    legacy_id,
    lifecycle_notes
FROM vw_moi_unidades_no_vigentes_dashboard
ORDER BY valid_to DESC, name
LIMIT 100;

-- 5. Acciones posteriores registradas bajo nueva estructura
SELECT *
FROM vw_moi_acciones_posteriores
ORDER BY effective_from DESC
LIMIT 100;
