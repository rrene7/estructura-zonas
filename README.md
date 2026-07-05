# Estructura de SRHN_LOCAL.mdb

Repositorio para documentar la estructura de la base Microsoft Access `SRHN_LOCAL.mdb`, a partir del reporte generado con el Documentador de Access el 5 de julio de 2026.

## Archivos

- `docs/resumen-estructura.md`: resumen ejecutivo de tablas, volumen y observaciones.
- `docs/modelo-ubicaciones-dependencias.md`: modelo propuesto para zonas, areas, direcciones, dependencias, oficinas, departamentos y ubicaciones fisicas.
- `docs/mapeo-access-ubicaciones-dependencias.md`: mapeo de tablas Access relacionadas con ubicaciones y dependencias.
- `docs/adaptacion-moi-65-16.md`: adaptacion del modelo al Manual de Organizacion Institucional MOI 65.16.
- `database/estructura_ubicaciones_dependencias.sql`: estructura normalizada propuesta en MySQL.
- `database/adaptacion_moi_65_16.sql`: ampliacion del modelo para clasificacion MOI, relaciones de mando, sedes fisicas y nomenclatura institucional.
- `database/staging_ubicaciones_access.sql`: tablas puente para importar la estructura original de Access.
- `database/migracion_ubicaciones_desde_staging.sql`: borrador de migracion desde staging al modelo normalizado.
- `database/consultas_access_ubicaciones.sql`: consultas para ejecutar en Access y validar zonas, direcciones, dependencias y posiciones.

## Tablas Access clave

- `TABLUGAR`: catalogo de lugares.
- `TABDIR`: provincias, distritos y corregimientos.
- `TABCUAR`: cuarteles, unidades o dependencias.
- `BDFUERZA`: estructura resumida de fuerza.
- `DIR`: direcciones locales.
- `POLPLANI`: areas, entidades y posiciones.
- `DOTA`: dotacion y asignacion a cuartel o unidad.
- `VACANTES`: vacantes y posiciones presupuestarias.

## Adaptacion MOI 65.16

La estructura ahora debe separar unidad organizacional, sede fisica, relacion de mando, alcance territorial y nomenclatura institucional.

No se sube el manual completo al repositorio. Solo se suben criterios de modelado, campos y scripts necesarios para adaptar la base de datos.

## Nota de seguridad

No subir el archivo `.mdb` original, el manual completo, ni datos reales. Este repositorio debe contener solo estructura, documentacion tecnica y scripts de migracion.
