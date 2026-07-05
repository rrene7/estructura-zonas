# Guia completa desde Git Bash

## 1. Actualizar repositorio

```bash
cd /c/xampp/htdocs/estructura-zonas
git pull origin main
```

## 2. Crear base y estructura inicial

```bash
bash scripts/setup_moi_xampp.sh
```

Este comando crea la base local, las tablas staging, el modelo normalizado, la adaptacion MOI y el archivo local del dashboard.

## 3. Colocar los CSV exportados desde Access

Crear carpeta:

```bash
mkdir -p data/access_csv
```

Colocar alli estos archivos:

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

## 4. Importar CSV y reconstruir dashboard

```bash
bash scripts/importar_csv_access.sh
```

Este comando importa los CSV, ejecuta la clasificacion MOI, ejecuta la migracion final y crea las vistas del dashboard.

## 5. Abrir dashboard

```text
http://localhost/estructura-zonas/dashboard/
```

## 6. Consultas utiles desde Bash

Resumen general:

```bash
/c/xampp/mysql/bin/mysql.exe -u root estructura_zonas_test -e "SELECT * FROM vw_moi_resumen_general;"
```

Conteo por tipo:

```bash
/c/xampp/mysql/bin/mysql.exe -u root estructura_zonas_test -e "SELECT * FROM vw_moi_unidades_por_tipo;"
```

Pendientes de revision:

```bash
/c/xampp/mysql/bin/mysql.exe -u root estructura_zonas_test -e "SELECT id, source_table, source_name, suggested_unit_type, confidence_level FROM vw_moi_pendientes_revision LIMIT 20;"
```

## 7. Aprobar registros revisados

Aprobar un registro:

```bash
/c/xampp/mysql/bin/mysql.exe -u root estructura_zonas_test -e "UPDATE stg_unit_classification SET requires_review = FALSE, reviewed_by = 'equipo_tecnico', reviewed_at = NOW() WHERE id = 1;"
```

Luego reconstruir dashboard:

```bash
bash scripts/importar_csv_access.sh
```

## Nota

Mantener local la carpeta `data/access_csv` y el archivo `dashboard/config.php`.
