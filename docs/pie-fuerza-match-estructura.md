# PIE DE FUERZA 26-6-2026: asignación contra estructura vigente

## Objetivo

Importar las columnas `Rango`, `Posición`, `Nombre`, `Apellido`, `Ubicación` y `Tipo Policía` de la hoja **PIE DE FUERZA 26-6-2026** y vincular cada persona únicamente con unidades que ya existan en `organizational_units`.

## Regla institucional

El archivo de pie de fuerza es una fuente de personal y ubicación declarada. No es una fuente para construir la estructura.

El módulo:

- no crea zonas, direcciones, áreas, servicios o dependencias;
- no cambia `parent_id`;
- no renombra unidades;
- no modifica vigencias;
- conserva la ubicación original para auditoría;
- permite asignar una persona a una zona o dirección mientras el nivel inferior queda pendiente.

## Estados

- `asignado_completo`: se encontró una unidad vigente específica y única.
- `asignado_parcial`: se confirmó una zona, dirección o área, pero falta identificar un nivel inferior.
- `pendiente_revision`: no existe una coincidencia automática suficientemente segura.
- `sin_coincidencia`: una revisión manual confirmó que la ubicación no existe en la estructura vigente.

## Archivos privados

Colocar el archivo en:

```text
local/private/PIE_DE_FUERZA_2026-06-26.xlsx
```

La carpeta y los archivos XLSX/CSV están excluidos por `.gitignore`.

## Instalación y carga

Desde Git Bash:

```bash
cd /c/xampp/htdocs/estructura-zonas
bash scripts/cargar_pie_fuerza_20260626.sh
```

También se puede indicar otra ruta:

```bash
bash scripts/cargar_pie_fuerza_20260626.sh "/c/ruta/PIE_DE_FUERZA.xlsx"
```

El proceso ejecuta:

1. `database/pie_fuerza_20260626.sql`
2. `scripts/importar_pie_fuerza.php`
3. `scripts/matchear_pie_fuerza.php`

## Reprocesar el match

```bash
php scripts/matchear_pie_fuerza.php --source-key=PIE_FUERZA_20260626
```

Las asignaciones aprobadas manualmente se conservan. Para recalcular incluso esas asignaciones:

```bash
php scripts/matchear_pie_fuerza.php --source-key=PIE_FUERZA_20260626 --forzar=1
```

## Dashboard

Abrir:

```text
http://localhost/estructura-zonas/dashboard/pie_fuerza.php
```

El tablero muestra:

- total importado;
- asignaciones completas;
- asignaciones parciales;
- pendientes;
- personas por unidad confirmada;
- exportación CSV;
- revisión manual contra unidades vigentes.

La revisión manual solo permite seleccionar un registro existente de `organizational_units`.

## Validación de seguridad

Después de la carga se puede comprobar que el módulo no creó estructura comparando el conteo antes y después:

```sql
SELECT COUNT(*) FROM organizational_units;
```

También se puede revisar la fuente y sus resultados:

```sql
SELECT * FROM vw_workforce_summary;
SELECT * FROM vw_workforce_match_detail
ORDER BY row_number;
```
