# Plan de implementacion MOI 65.16

Este plan explica como adaptar la estructura extraida de Access al modelo institucional basado en el MOI 65.16, sin subir el manual completo ni datos reales al repositorio.

## Objetivo

Convertir la estructura heredada de Access en una estructura institucional normalizada que permita manejar:

- regiones policiales;
- zonas policiales;
- areas policiales;
- servicios policiales;
- direcciones nacionales;
- departamentos;
- secciones;
- oficinas;
- dependencias;
- unidades;
- sedes fisicas;
- relaciones de mando;
- asignacion de funcionarios o posiciones a unidades.

## Principio central

No todo lo que aparece como cuartel, lugar o dependencia en Access debe convertirse directamente en una zona o una oficina.

Primero se debe clasificar cada registro en tres dimensiones:

1. **Que es:** region, zona, area, direccion, servicio, departamento, oficina, dependencia, unidad o sede fisica.
2. **Donde esta:** provincia, distrito, corregimiento, direccion fisica o sede.
3. **De quien depende:** relacion jerarquica, funcional, operacional, tactica o administrativa.

## Fase 1: Cargar estructura heredada en staging

Usar el archivo:

```text
/database/staging_ubicaciones_access.sql
```

Tablas principales de staging:

```text
stg_tabcuar
stg_bdfuerza
stg_tablugar
stg_tabdir
stg_dir
stg_dota
stg_polplani
stg_vacantes
stg_cargos
stg_tabran
stg_tabstatus
```

Resultado esperado: tener los datos originales de Access cargados en MySQL, sin modificar el modelo final.

## Fase 2: Crear modelo normalizado

Ejecutar primero:

```text
/database/estructura_ubicaciones_dependencias.sql
```

Luego ejecutar:

```text
/database/adaptacion_moi_65_16.sql
```

Esto crea o amplia las tablas necesarias:

```text
unit_types
organizational_units
territorial_divisions
locations
unit_locations
positions
unit_assignments
facility_types
nomenclature_patterns
organizational_unit_relationships
organizational_normative_versions
```

## Fase 3: Clasificar registros Access

Crear una tabla puente llamada `stg_unit_classification`.

Esta tabla permite revisar cada registro de `TABCUAR`, `BDFUERZA`, `POLPLANI` o `DOTA` antes de enviarlo al modelo final.

Campos recomendados:

| Campo | Uso |
|---|---|
| `source_table` | Tabla Access de origen |
| `source_id` | Codigo heredado |
| `source_name` | Nombre heredado |
| `suggested_unit_type` | Clasificacion sugerida |
| `suggested_scope` | Alcance: nacional, regional, zonal, area, local o especializado |
| `suggested_command_structure` | Mando directo o linea funcional |
| `confidence_level` | alto, medio o bajo |
| `requires_review` | Si necesita validacion manual |
| `review_notes` | Observaciones |

## Fase 4: Reglas automaticas de clasificacion

### 4.1 Regiones

Clasificar como `region_policial` cuando el nombre o codigo corresponda a una agrupacion regional de zonas.

### 4.2 Zonas policiales

Clasificar como `zona_policial` cuando el registro represente una jurisdiccion territorial principal.

### 4.3 Areas policiales

Clasificar como `area_policial` cuando el registro represente subdivision territorial dentro de una zona.

### 4.4 Servicios policiales

Clasificar como `servicio_policial` cuando la unidad sea especializada o preste servicio transversal a zonas o regiones.

### 4.5 Direcciones nacionales

Clasificar como `direccion_nacional` cuando el registro corresponda a una direccion institucional de alcance nacional.

### 4.6 Departamentos, secciones y oficinas

Clasificar segun dependencia funcional dentro de una direccion, zona, region o servicio.

### 4.7 Sedes fisicas

Clasificar como sede fisica cuando el registro represente una instalacion y no una unidad de mando.

Ejemplos de tipos de sede:

```text
sede_region
estacion_policial
subestacion_policial
destacamento
puesto_policial
oficina_administrativa
```

## Fase 5: Separar unidad y sede

Una unidad organizacional debe ir en:

```text
organizational_units
```

Una ubicacion fisica debe ir en:

```text
locations
```

La relacion entre ambas debe ir en:

```text
unit_locations
```

Ejemplo conceptual:

```text
Unidad: Zona Policial X
Sede: Estacion Policial X
Relacion: sede principal
```

## Fase 6: Crear relaciones de mando

No usar solamente `parent_id`.

Usar tambien:

```text
organizational_unit_relationships
```

Tipos de relacion:

```text
jerarquica
funcional
operacional
tactica
administrativa
ubicacion_fisica
apoyo_tecnico
```

Esto permite representar casos donde una unidad depende administrativamente de una direccion nacional, pero opera fisicamente dentro de una zona.

## Fase 7: Migrar datos al modelo final

Orden recomendado:

1. `territorial_divisions` desde `TABDIR`.
2. `locations` desde `TABLUGAR` y `DIR`.
3. `organizational_units` desde `TABCUAR`, `BDFUERZA` y clasificacion manual.
4. `unit_locations` para unir sedes con unidades.
5. `positions` desde `POLPLANI`, `VACANTES` y `CARGOS`.
6. `unit_assignments` desde `DOTA`.
7. `organizational_unit_relationships` para mando, funcion, operacion y administracion.

## Fase 8: Validaciones

Antes de dar por buena la migracion, validar:

- unidades sin clasificacion MOI;
- unidades sin relacion jerarquica;
- sedes fisicas mezcladas como unidades;
- registros Access que no cruzan con ninguna unidad;
- funcionarios en `DOTA` con cuartel inexistente en `TABCUAR`;
- posiciones de `POLPLANI` sin unidad asociada;
- duplicados por nombre parecido;
- unidades activas sin vigencia normativa.

## Fase 9: Revision manual obligatoria

La clasificacion automatica no debe aprobarse sola.

Se debe revisar manualmente todo registro con:

```text
confidence_level = medio
confidence_level = bajo
requires_review = 1
```

## Fase 10: Resultado final esperado

Al terminar, el sistema debe permitir consultar:

- organigrama institucional;
- regiones y zonas;
- areas dentro de una zona;
- servicios policiales;
- direcciones nacionales;
- departamentos, secciones y oficinas;
- sedes fisicas;
- funcionarios asignados a una unidad;
- posiciones asociadas a una dependencia;
- relaciones de mando directo y linea funcional;
- cambios historicos de estructura.

## Regla de oro

Primero clasificar, luego migrar.

No se debe insertar directamente todo `TABCUAR` como dependencia final, porque el MOI obliga a distinguir unidad, sede, jurisdiccion, funcion y relacion de mando.
