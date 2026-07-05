# Modelo propuesto: ubicaciones, zonas, áreas, dependencias, oficinas y departamentos

Este documento define una estructura normalizada para manejar la jerarquía organizacional y territorial del sistema.

> Fuente de análisis: reporte de estructura de `SRHN_LOCAL.mdb` generado desde Microsoft Access. En el reporte aparecen tablas relacionadas con ubicaciones y estructura institucional, entre ellas `TABLUGAR`, `TABDIR`, `DIR`, `TABCUAR`, `BDFUERZA`, `POLNAL`, `POLPLANI`, `DOTA` y `VACANTES`.

## Objetivo

Crear una estructura limpia para registrar:

- Zonas policiales.
- Áreas o regiones.
- Direcciones nacionales.
- Departamentos.
- Oficinas.
- Dependencias.
- Unidades o cuarteles.
- Ubicaciones físicas.
- Relación entre funcionarios, posiciones y dependencias.

## Jerarquía recomendada

```text
Institución
└── Dirección / Zona / Área
    └── Departamento / División / Sección
        └── Oficina / Dependencia / Unidad
            └── Ubicación física
```

Ejemplo:

```text
Policía Nacional
└── Dirección Nacional de Telemática
    └── Departamento de Soporte Técnico
        └── Oficina de Redes
            └── Ancón / Sede principal
```

Otro ejemplo:

```text
Policía Nacional
└── 10ma Zona Policial
    └── Subzona / Área Operativa
        └── Estación / Subestación / Puesto Policial
            └── Dirección física del puesto
```

## Tablas principales recomendadas

### 1. `organizational_units`

Tabla central para manejar direcciones, zonas, departamentos, oficinas, dependencias y unidades.

| Campo | Tipo sugerido | Descripción |
|---|---|---|
| `id` | BIGINT | Identificador interno |
| `parent_id` | BIGINT NULL | Unidad superior |
| `unit_type_id` | BIGINT | Tipo de unidad: zona, dirección, departamento, oficina, etc. |
| `code` | VARCHAR(50) | Código institucional o código heredado |
| `name` | VARCHAR(200) | Nombre de la unidad |
| `short_name` | VARCHAR(100) | Nombre corto o sigla |
| `level` | INT | Nivel jerárquico |
| `is_operational` | BOOLEAN | Indica si es unidad operativa |
| `is_administrative` | BOOLEAN | Indica si es unidad administrativa |
| `status` | ENUM | active / inactive |
| `legacy_table` | VARCHAR(100) | Tabla original Access |
| `legacy_id` | VARCHAR(100) | Código original Access |
| `created_at` | TIMESTAMP | Fecha de creación |
| `updated_at` | TIMESTAMP | Fecha de actualización |

### 2. `unit_types`

Catálogo de tipos de unidades.

| Campo | Tipo sugerido | Descripción |
|---|---|---|
| `id` | BIGINT | Identificador |
| `name` | VARCHAR(100) | Nombre del tipo |
| `description` | VARCHAR(255) | Descripción |

Tipos sugeridos:

| Tipo | Uso |
|---|---|
| `institucion` | Policía Nacional |
| `direccion_nacional` | Dirección Nacional |
| `zona_policial` | Zona Policial |
| `area` | Área regional u operativa |
| `departamento` | Departamento interno |
| `division` | División |
| `seccion` | Sección |
| `oficina` | Oficina |
| `dependencia` | Dependencia administrativa u operativa |
| `cuartel` | Cuartel |
| `estacion` | Estación policial |
| `subestacion` | Subestación |
| `puesto` | Puesto policial |

### 3. `locations`

Tabla para direcciones físicas.

| Campo | Tipo sugerido | Descripción |
|---|---|---|
| `id` | BIGINT | Identificador |
| `province_id` | BIGINT NULL | Provincia |
| `district_id` | BIGINT NULL | Distrito |
| `corregimiento_id` | BIGINT NULL | Corregimiento |
| `address` | VARCHAR(255) | Dirección física |
| `reference` | VARCHAR(255) | Punto de referencia |
| `latitude` | DECIMAL(10,7) NULL | Latitud |
| `longitude` | DECIMAL(10,7) NULL | Longitud |
| `status` | ENUM | active / inactive |

### 4. `unit_locations`

Relación entre una unidad organizacional y su ubicación física.

| Campo | Tipo sugerido | Descripción |
|---|---|---|
| `id` | BIGINT | Identificador |
| `organizational_unit_id` | BIGINT | Unidad |
| `location_id` | BIGINT | Ubicación |
| `is_main` | BOOLEAN | Indica sede principal |
| `valid_from` | DATE NULL | Desde cuándo aplica |
| `valid_to` | DATE NULL | Hasta cuándo aplica |

### 5. `territorial_divisions`

Catálogo geográfico de Panamá.

| Campo | Tipo sugerido | Descripción |
|---|---|---|
| `id` | BIGINT | Identificador |
| `parent_id` | BIGINT NULL | División superior |
| `type` | ENUM | provincia / distrito / corregimiento |
| `name` | VARCHAR(150) | Nombre |
| `code` | VARCHAR(50) | Código geográfico, si existe |

### 6. `positions`

Tabla para cargos o posiciones asociadas a una unidad.

| Campo | Tipo sugerido | Descripción |
|---|---|---|
| `id` | BIGINT | Identificador |
| `position_code` | VARCHAR(50) | Código de posición |
| `title` | VARCHAR(200) | Cargo |
| `organizational_unit_id` | BIGINT | Dependencia actual |
| `rank_id` | BIGINT NULL | Rango asociado, si aplica |
| `status` | ENUM | active / inactive / vacant |

### 7. `employee_assignments`

Historial de asignación de funcionario a zona, dependencia, oficina o posición.

| Campo | Tipo sugerido | Descripción |
|---|---|---|
| `id` | BIGINT | Identificador |
| `employee_id` | BIGINT | Funcionario |
| `position_id` | BIGINT NULL | Posición |
| `organizational_unit_id` | BIGINT | Unidad donde labora |
| `assignment_type` | VARCHAR(50) | permanente / temporal / comisión / traslado |
| `start_date` | DATE | Fecha inicio |
| `end_date` | DATE NULL | Fecha fin |
| `source_action_id` | BIGINT NULL | Acción de personal que originó el movimiento |
| `status` | ENUM | active / inactive |

## Mapeo inicial de tablas Access a nuevo modelo

| Tabla Access | Posible uso en nuevo modelo |
|---|---|
| `TABLUGAR` | Catálogo de lugares o ubicaciones |
| `TABDIR` | Catálogo de direcciones o unidades superiores |
| `DIR` | Direcciones/domicilios o información asociada a funcionario/unidad |
| `TABCUAR` | Catálogo de cuarteles/unidades |
| `BDFUERZA` | Estructura de fuerza por zona/dependencia |
| `POLNAL` | Datos principales del funcionario |
| `POLPLANI` | Posición/planilla/dependencia laboral |
| `DOTA` | Dotación/asignación institucional |
| `VACANTES` | Posiciones vacantes por dependencia |
| `CARGOS` | Catálogo de cargos |
| `TABRAN` | Catálogo de rangos |
| `TABSTATUS` | Catálogo de estados |

## Reglas recomendadas

1. No repetir nombres de zonas, direcciones o dependencias en muchas tablas.
2. Usar una sola tabla jerárquica: `organizational_units`.
3. Guardar códigos antiguos en `legacy_id` para poder rastrear la migración.
4. Separar ubicación física de estructura administrativa.
5. Una dependencia puede cambiar de dirección física sin perder su historial.
6. Un funcionario debe tener historial de asignaciones, no solo ubicación actual.
7. Las tablas viejas deben quedar como respaldo/histórico hasta validar la migración.

## Ejemplo de carga inicial

```text
organizational_units

1 | NULL | institucion | PN | Policía Nacional
2 | 1    | direccion_nacional | DINTE | Dirección Nacional de Telemática
3 | 2    | departamento | SOPTEC | Departamento de Soporte Técnico
4 | 3    | oficina | REDES | Oficina de Redes
5 | 1    | zona_policial | ZP10 | 10ma Zona Policial
6 | 5    | dependencia | EST-CHORRERA | Estación de Policía de La Chorrera
```

## Próximo paso

Validar en el reporte de Access los campos exactos de estas tablas:

- `TABLUGAR`
- `TABDIR`
- `TABCUAR`
- `BDFUERZA`
- `POLPLANI`
- `DOTA`
- `VACANTES`

Luego generar un script SQL definitivo para MySQL o migraciones Laravel.
