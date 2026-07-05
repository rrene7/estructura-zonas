# Adaptacion del modelo al Manual de Organizacion Institucional MOI 65.16

Este documento adapta el modelo de zonas, ubicaciones y dependencias del repositorio a la logica organizacional del Manual de Organizacion Institucional MOI 65.16.

## Criterio de seguridad

El manual usado como referencia es de uso institucional restringido. Por esa razon, este repositorio no debe contener el PDF original ni transcripciones extensas del manual. La adaptacion aqui publicada solo contiene criterios de modelado, campos de sistema y reglas generales necesarias para normalizar la base de datos.

## Cambios principales que requiere el modelo

El modelo anterior manejaba principalmente:

- ubicaciones fisicas;
- zonas;
- areas;
- dependencias;
- oficinas;
- departamentos;
- cuarteles;
- posiciones.

Con el MOI, el modelo debe separar cuatro conceptos que antes estaban mezclados:

1. **Unidad organizacional:** direccion, region, zona, area, servicio, dependencia, oficina, departamento, seccion o unidad.
2. **Sede fisica:** sede de region, estacion, subestacion, destacamento, puesto u otra instalacion.
3. **Relacion de mando:** mando directo, linea funcional, control operacional, control tactico o control administrativo.
4. **Nomenclatura institucional:** codigos como region policial, zona policial, servicio policial, directorio y secciones funcionales.

## Jerarquia base recomendada

```text
Institucion
|-- Directorio General
|   |-- Directorio General Personal
|   |-- Directorio General de Coordinacion
|   |-- Directorio General Especial
|
|-- Direcciones Nacionales
|   |-- Departamentos
|   |-- Secciones
|   |-- Unidades
|
|-- Regiones Policiales
|   |-- Zonas Policiales
|       |-- Areas Policiales
|           |-- Subestaciones / Puestos
|
|-- Servicios Policiales
|   |-- Destacamentos
|   |-- Secciones
|   |-- Unidades
```

## Ajuste al catalogo `unit_types`

Se deben mantener y ampliar los tipos de unidad para cubrir la estructura institucional:

| Tipo | Uso |
|---|---|
| `institucion` | Entidad principal |
| `directorio_general` | Nivel superior de apoyo al mando |
| `directorio_personal` | Componente personal del directorio |
| `directorio_coordinacion` | Componente de coordinacion del directorio |
| `directorio_especial` | Componente especial del directorio |
| `direccion_nacional` | Direccion nacional |
| `subdireccion_nacional` | Subdireccion nacional |
| `region_policial` | Agrupacion administrativa de zonas |
| `zona_policial` | Division operativa territorial |
| `area_policial` | Subdivision territorial dentro de una zona |
| `servicio_policial` | Servicio especializado u operativo |
| `departamento` | Departamento interno |
| `division` | Division funcional |
| `seccion` | Seccion funcional |
| `oficina` | Oficina administrativa u operativa |
| `dependencia` | Dependencia institucional |
| `unidad` | Unidad operativa o administrativa |
| `sede_region` | Instalacion sede de region |
| `estacion_policial` | Instalacion sede de zona |
| `subestacion_policial` | Instalacion de area |
| `destacamento` | Instalacion de servicio o grupo |
| `puesto_policial` | Instalacion operativa menor |

## Campos nuevos necesarios en `organizational_units`

| Campo | Proposito |
|---|---|
| `moi_code` | Codigo normalizado segun nomenclatura institucional |
| `moi_level` | Nivel jerarquico funcional |
| `command_structure` | `mando_directo` o `linea_funcional` |
| `command_relationship` | `operacional`, `tactico`, `administrativo`, `funcional` |
| `territorial_scope` | `nacional`, `regional`, `zonal`, `area`, `local`, `especializado` |
| `functional_axis` | Eje funcional: personal, inteligencia, operaciones, sostenimiento, planes, telematica, comunicaciones u otro |
| `is_decision_center` | Indica si la unidad toma decisiones administrativas o estrategicas |
| `is_operational_executor` | Indica si ejecuta operaciones o tramites operativos |
| `facility_type_id` | Tipo de sede fisica vinculada, si aplica |
| `moi_version` | Version normativa usada para clasificar la unidad |
| `verified_at` | Fecha de verificacion de la clasificacion |

## Reglas de negocio a implementar

### 1. Separar unidad de sede

Una zona policial no debe ser lo mismo que su estacion fisica. La zona es unidad organizacional; la estacion es sede fisica.

Ejemplo conceptual:

```text
Zona Policial Panama Oeste -> unidad organizacional
Estacion Policial Panama Oeste -> sede fisica principal de esa zona
```

### 2. Permitir relaciones de mando multiples

Una unidad puede depender organicamente de una direccion nacional, pero estar fisicamente ubicada en una zona o prestar servicio dentro de una region. Para esto no basta el campo `parent_id`; se requiere una tabla de relaciones organizacionales.

Tabla sugerida: `organizational_unit_relationships`.

Relaciones sugeridas:

- `jerarquica`
- `funcional`
- `operacional`
- `administrativa`
- `ubicacion_fisica`
- `apoyo_tecnico`

### 3. Clasificar por alcance territorial

Toda unidad debe tener un alcance:

- nacional;
- regional;
- zonal;
- area;
- local;
- especializado.

Esto permitira diferenciar entre una Direccion Nacional, una Region Policial, una Zona Policial, un Area Policial y un Servicio Policial.

### 4. Manejar nomenclaturas institucionales

Agregar una tabla `nomenclature_patterns` para registrar codigos institucionales sin quemarlos en el codigo fuente.

Ejemplos de familias de codigos:

- Directorio;
- Policia / secciones funcionales;
- Region Policial;
- Zona Policial;
- Servicio Policial.

### 5. Controlar vigencia y version normativa

El MOI tiene vigencia y politica de revision. Por eso cada unidad clasificada debe guardar:

- documento normativo fuente;
- version o codificacion;
- fecha de vigencia;
- fecha de verificacion;
- estado: vigente, pendiente de validar, derogada o historica.

## Impacto sobre tablas Access

| Tabla Access | Nuevo uso recomendado |
|---|---|
| `TABCUAR` | Base inicial para `organizational_units` y posibles sedes |
| `BDFUERZA` | Validacion o agrupacion resumida de fuerza/unidad |
| `TABDIR` | `territorial_divisions` |
| `TABLUGAR` | `locations` o catalogo auxiliar de lugar |
| `DIR` | Direcciones fisicas asociadas a personas o registros |
| `DOTA` | Asignacion actual de persona a unidad/sede |
| `POLPLANI` | Posiciones, areas presupuestarias y entidades |
| `VACANTES` | Posiciones disponibles o presupuestarias |

## Prioridad de adaptacion

1. Crear catalogo de tipos MOI.
2. Agregar campos MOI a `organizational_units`.
3. Crear tabla de relaciones organizacionales.
4. Crear tabla de patrones de nomenclatura.
5. Separar sedes fisicas de unidades organizacionales.
6. Crear scripts de validacion para detectar registros Access que no se puedan clasificar.
7. Cargar primero regiones, zonas, areas, servicios y direcciones nacionales.
8. Luego clasificar departamentos, secciones, oficinas, dependencias y unidades.

## Pendientes de validacion interna

- Confirmar lista oficial vigente de regiones y zonas.
- Confirmar si cada `TABCUAR` representa zona, area, estacion, subestacion, puesto, servicio o dependencia.
- Confirmar la relacion real entre `DOTA.CUARTEL` y `TABCUAR.CODIGOCUAR`.
- Confirmar como `POLPLANI.AREA` y `POLPLANI.ENTIDAD` se relacionan con la nueva estructura.
- Confirmar que unidades con presencia fisica en una zona mantengan su dependencia funcional de la Direccion Nacional correspondiente.
