# PIE DE FUERZA 26-6-2026 contra estructura vigente

## Regla institucional

El archivo privado de pie de fuerza es una fuente de personas y ubicaciones heredadas. No es una fuente autorizada para crear o modificar estructura.

El proceso solamente puede:

1. importar las personas;
2. conservar exactamente la ubicación original;
3. comparar esa ubicación con unidades vigentes existentes;
4. vincular a la unidad más específica confirmada;
5. dejar pendiente cualquier nivel inferior que no pueda demostrarse;
6. enviar coincidencias ambiguas a revisión.

Nunca debe crear zonas, direcciones, áreas, servicios, dependencias, estaciones ni puestos desde el Excel.

## Archivos privados

Ubicación recomendada:

```text
local/private/PIE_DE_FUERZA_2026-06-26.xlsx
```

La carpeta y los archivos XLSX están excluidos por `.gitignore`.

## Columnas utilizadas

La hoja `PIE DE FUERZA 26-6-2026` debe contener:

- Rango
- Posición
- Nombre
- Apellido
- Ubicación
- Tipo Policía

El importador normaliza encabezados para aceptar variantes de acentos y espacios.

## Ejecución

Desde Git Bash en la raíz del proyecto:

```bash
bash scripts/cargar_pie_fuerza_20260626.sh
```

El script ejecuta:

1. `database/pie_fuerza_20260626.sql`;
2. `scripts/importar_pie_fuerza.php`;
3. `scripts/matchear_pie_fuerza.php`.

## Estados

- `asignado_completo`: la ubicación coincide con una unidad específica existente.
- `asignado_parcial`: se confirmó una zona, dirección o área; el nivel inferior sigue pendiente.
- `pendiente_revision`: no existe una coincidencia única y segura.
- `sin_coincidencia`: una revisión confirmó que la ubicación no existe en la estructura vigente.

## Revisión individual

Abrir:

```text
http://localhost/estructura-zonas/dashboard/pie_fuerza.php
```

Cada persona puede revisarse mediante `pie_fuerza_revision.php`. La pantalla valida que la unidad seleccionada esté activa y vigente antes de guardar.

## Revisión masiva por ubicación

Abrir:

```text
http://localhost/estructura-zonas/dashboard/pie_fuerza_masiva.php
```

Esta pantalla agrupa todas las personas por `location_normalized`, mostrando:

- ubicación original;
- cantidad de personas;
- completos, parciales y pendientes;
- unidad actualmente sugerida;
- excepciones individuales existentes.

Una decisión puede aplicarse a todo el grupo como:

- asignación completa;
- asignación parcial, indicando el nivel pendiente;
- sin coincidencia.

La revisión masiva solo permite escoger unidades activas y vigentes ya registradas. Los registros previamente aprobados mediante `revision_manual` se conservan como excepciones y no son sobrescritos por una decisión grupal.

Después de aprobar un grupo, este sale de la bandeja `Por revisar`, aunque conserve el estado institucional `asignado_parcial` por tener un nivel inferior pendiente.

## Reprocesamiento

El motor automático puede ejecutarse nuevamente:

```bash
php scripts/matchear_pie_fuerza.php --source=PIE_FUERZA_20260626
```

Las decisiones de revisión individual o masiva aprobadas se conservan. El reprocesamiento automático no modifica `organizational_units`.

## Seguridad

- No subir el Excel ni CSV con datos personales.
- No almacenar credenciales en el repositorio.
- Revisar coincidencias ambiguas antes de aprobarlas.
- Mantener respaldos de la base antes de una carga masiva.
- Confirmar una unidad por su nombre, código y superior jerárquico.
