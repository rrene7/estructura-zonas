# Estructura de SRHN_LOCAL.mdb

Repositorio para documentar la estructura de la base Microsoft Access `SRHN_LOCAL.mdb`, a partir del reporte generado con el Documentador de Access el 5 de julio de 2026.

## Archivos

- `docs/resumen-estructura.md`: resumen ejecutivo de tablas, volumen y observaciones.
- `docs/modelo-ubicaciones-dependencias.md`: modelo propuesto para zonas, areas, direcciones, dependencias, oficinas, departamentos y ubicaciones fisicas.
- `docs/mapeo-access-ubicaciones-dependencias.md`: mapeo de tablas Access relacionadas con ubicaciones y dependencias.
- `docs/adaptacion-moi-65-16.md`: adaptacion del modelo al Manual de Organizacion Institucional MOI 65.16.
- `docs/plan-implementacion-moi-65-16.md`: plan por fases para adaptar Access al modelo MOI.
- `docs/checklist-ejecucion-moi-65-16.md`: checklist operativo para ejecutar la migracion.
- `docs/pie-fuerza-match-estructura.md`: carga del PIE DE FUERZA 26-6-2026 y asignacion exclusiva contra unidades vigentes existentes.
- `database/estructura_ubicaciones_dependencias.sql`: estructura normalizada propuesta en MySQL.
- `database/adaptacion_moi_65_16.sql`: ampliacion del modelo para clasificacion MOI, relaciones de mando, sedes fisicas y nomenclatura institucional.
- `database/staging_ubicaciones_access.sql`: tablas puente para importar la estructura original de Access.
- `database/clasificacion_moi_desde_staging.sql`: mesa de clasificacion previa y reglas automaticas iniciales.
- `database/migracion_final_moi_desde_clasificacion.sql`: migracion final de registros revisados al modelo normalizado.
- `database/dashboard_moi_views.sql`: vistas SQL para alimentar el dashboard.
- `database/pie_fuerza_20260626.sql`: staging, coincidencias, estados y vistas del PIE DE FUERZA.
- `scripts/importar_pie_fuerza.php`: importador XLSX/CSV de la hoja PIE DE FUERZA 26-6-2026.
- `scripts/matchear_pie_fuerza.php`: asigna personas solamente a unidades vigentes ya existentes.
- `scripts/cargar_pie_fuerza_20260626.sh`: ejecuta instalacion, importacion y match.
- `dashboard/index.php`: dashboard local en PHP para ver avance de la estructura.
- `dashboard/pie_fuerza.php`: resumen, filtros, personal y asignaciones del pie de fuerza.
- `dashboard/pie_fuerza_revision.php`: revision manual contra unidades vigentes.
- `dashboard/config.example.php`: configuracion ejemplo de conexion local.
- `dashboard/README.md`: instrucciones del dashboard.
- `database/migracion_ubicaciones_desde_staging.sql`: borrador de migracion inicial desde staging.
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

Flujo recomendado:

```text
Access -> staging -> clasificacion MOI -> revision manual -> migracion final -> vistas dashboard -> dashboard
```

## PIE DE FUERZA 26-6-2026

El pie de fuerza se trata como fuente de personas, no como fuente estructural.

```text
XLSX/CSV privado -> staging de personal -> match con organizational_units vigentes -> revision
```

Este flujo no crea zonas, direcciones, areas, dependencias, servicios o unidades. Cuando solo se confirma una zona o direccion, la persona queda asignada parcialmente y el nivel inferior permanece pendiente.

Carga local:

```bash
bash scripts/cargar_pie_fuerza_20260626.sh
```

## Dashboard

El dashboard permite ver el avance de la estructura por total de unidades, alcance territorial, tipo de unidad, sedes, pendientes de revision, alertas y listado tipo arbol.

El modulo del pie de fuerza esta disponible en:

```text
http://localhost/estructura-zonas/dashboard/pie_fuerza.php
```

No se sube el manual completo al repositorio. Solo se suben criterios de modelado, campos y scripts necesarios para adaptar la base de datos.

## Nota de seguridad

No subir el archivo `.mdb` original, el manual completo, datos reales, archivos XLSX/CSV del personal ni credenciales. Este repositorio debe contener solo estructura, documentacion tecnica y scripts de migracion.
