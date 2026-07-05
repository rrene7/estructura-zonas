# Estructura de SRHN_LOCAL.mdb

Repositorio para documentar la estructura de la base Microsoft Access `SRHN_LOCAL.mdb`, a partir del reporte generado con el Documentador de Access el 5 de julio de 2026.

## Archivos

- `docs/resumen-estructura.md`: resumen ejecutivo de tablas, volumen y observaciones.
- `docs/modelo-ubicaciones-dependencias.md`: modelo propuesto para zonas, areas, direcciones, dependencias, oficinas, departamentos y ubicaciones fisicas.
- `docs/mapeo-access-ubicaciones-dependencias.md`: mapeo de tablas Access relacionadas con ubicaciones y dependencias.
- `database/estructura_ubicaciones_dependencias.sql`: estructura normalizada propuesta en MySQL.
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

## Nota de seguridad

No subir el archivo `.mdb` original ni datos reales. Este repositorio debe contener solo estructura, documentacion y scripts de migracion.
