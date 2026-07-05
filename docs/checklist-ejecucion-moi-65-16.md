# Checklist de ejecucion MOI 65.16

## Orden de ejecucion

1. Crear tablas staging.
2. Cargar datos exportados desde Access.
3. Crear modelo base de ubicaciones y dependencias.
4. Aplicar ampliacion MOI.
5. Crear clasificacion previa.
6. Revisar los registros pendientes.
7. Marcar como revisados los registros aprobados.
8. Ejecutar migracion final.
9. Ejecutar consultas de control.
10. Revisar duplicados y registros sin clasificacion.

## Archivos SQL en orden

```text
database/staging_ubicaciones_access.sql
database/estructura_ubicaciones_dependencias.sql
database/adaptacion_moi_65_16.sql
database/clasificacion_moi_desde_staging.sql
database/migracion_final_moi_desde_clasificacion.sql
```

## Reglas de aprobacion

Antes de migrar una unidad al modelo final, debe estar revisada:

```sql
UPDATE stg_unit_classification
SET requires_review = FALSE,
    reviewed_by = 'equipo_tecnico',
    reviewed_at = NOW()
WHERE id = 1;
```

Tambien se puede ajustar la clasificacion:

```sql
UPDATE stg_unit_classification
SET suggested_unit_type = 'zona_policial',
    suggested_scope = 'zonal',
    suggested_command_structure = 'mando_directo',
    suggested_command_relationship = 'operacional',
    requires_review = FALSE,
    reviewed_by = 'equipo_tecnico',
    reviewed_at = NOW()
WHERE id = 1;
```

## Controles minimos

Despues de migrar, validar:

- unidades sin clasificacion;
- unidades sin version normativa;
- unidades sin relacion superior;
- sedes sin ubicacion;
- nombres duplicados;
- registros Access sin cruce con el modelo final.

## Resultado esperado

El sistema debe poder mostrar la estructura por:

- alcance nacional;
- region;
- zona;
- area;
- servicio;
- direccion;
- departamento;
- seccion;
- oficina;
- sede fisica;
- relacion de mando.
