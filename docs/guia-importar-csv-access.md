# Guia para importar CSV exportados desde Access

Despues de crear la base de datos y ejecutar los scripts de estructura, el dashboard puede abrir, pero saldra vacio si no se cargan datos en las tablas `stg_*`.

## 1. Exportar desde Access

Exportar estas tablas como CSV:

```text
TABCUAR
BDFUERZA
TABLUGAR
TABDIR
DIR
DOTA
POLPLANI
VACANTES
CARGOS
TABRAN
TABSTATUS
```

Guardar los archivos localmente, por ejemplo en:

```text
C:\xampp\htdocs\estructura-zonas\data\access_csv\
```

Usar nombres simples:

```text
TABCUAR.csv
BDFUERZA.csv
TABLUGAR.csv
TABDIR.csv
DIR.csv
DOTA.csv
POLPLANI.csv
VACANTES.csv
CARGOS.csv
TABRAN.csv
TABSTATUS.csv
```

## 2. Importar desde phpMyAdmin

1. Abrir:

```text
http://localhost/phpmyadmin
```

2. Seleccionar la base:

```text
estructura_zonas_test
```

3. Seleccionar cada tabla staging correspondiente, por ejemplo:

```text
stg_tabcuar
```

4. Ir a **Importar**.
5. Seleccionar el CSV correspondiente, por ejemplo `TABCUAR.csv`.
6. Verificar separador: coma o punto y coma, segun como lo exporto Access.
7. Marcar que la primera fila contiene nombres de columnas, si aplica.
8. Ejecutar.

## 3. Equivalencia de archivos CSV con tablas staging

| CSV | Tabla destino |
|---|---|
| `TABCUAR.csv` | `stg_tabcuar` |
| `BDFUERZA.csv` | `stg_bdfuerza` |
| `TABLUGAR.csv` | `stg_tablugar` |
| `TABDIR.csv` | `stg_tabdir` |
| `DIR.csv` | `stg_dir` |
| `DOTA.csv` | `stg_dota` |
| `POLPLANI.csv` | `stg_polplani` |
| `VACANTES.csv` | `stg_vacantes` |
| `CARGOS.csv` | `stg_cargos` |
| `TABRAN.csv` | `stg_tabran` |
| `TABSTATUS.csv` | `stg_tabstatus` |

## 4. Despues de importar

Ejecutar nuevamente:

```bash
/c/xampp/mysql/bin/mysql.exe -u root estructura_zonas_test < database/clasificacion_moi_desde_staging.sql
```

Luego revisar la clasificacion:

```sql
SELECT suggested_unit_type, suggested_scope, COUNT(*) AS total
FROM stg_unit_classification
GROUP BY suggested_unit_type, suggested_scope
ORDER BY suggested_unit_type, suggested_scope;
```

Aprobar registros revisados:

```sql
UPDATE stg_unit_classification
SET requires_review = FALSE,
    reviewed_by = 'equipo_tecnico',
    reviewed_at = NOW()
WHERE confidence_level IN ('alto','medio');
```

Luego ejecutar:

```bash
/c/xampp/mysql/bin/mysql.exe -u root estructura_zonas_test < database/migracion_final_moi_desde_clasificacion.sql
/c/xampp/mysql/bin/mysql.exe -u root estructura_zonas_test < database/dashboard_moi_views.sql
```

## 5. Abrir dashboard

```text
http://localhost/estructura-zonas/dashboard/
```

## Nota de seguridad

Los CSV contienen informacion institucional o personal. No subirlos al repositorio publico.
