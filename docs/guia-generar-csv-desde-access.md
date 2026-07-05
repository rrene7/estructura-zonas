# Generar CSV desde la base Access

Los CSV no vienen en el repositorio. Deben generarse localmente desde la base Access original, por ejemplo `SRHN_LOCAL.mdb`.

## 1. Actualizar repositorio

```bash
cd /c/xampp/htdocs/estructura-zonas
git pull origin main
```

## 2. Buscar la base Access en el usuario

```bash
bash scripts/buscar_access_db.sh
```

Tambien puede buscar en una carpeta especifica:

```bash
bash scripts/buscar_access_db.sh /c/Users/rrriverap/Downloads
bash scripts/buscar_access_db.sh /c/Users/rrriverap/Documents
bash scripts/buscar_access_db.sh /c
```

Copie la ruta del archivo `.mdb` o `.accdb` encontrado.

## 3. Exportar CSV desde Bash

Ejemplo:

```bash
bash scripts/exportar_csv_desde_access.sh "/c/Users/rrriverap/Downloads/SRHN_LOCAL.mdb"
```

El script crea los CSV en:

```text
data/access_csv/
```

Archivos esperados:

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

## 4. Cargar CSV a MySQL

```bash
bash scripts/importar_csv_access.sh
```

## 5. Verificar

```bash
bash scripts/verificar_moi.sh
```

## Si falla la exportacion automatica

Puede faltar el proveedor de Access en Windows. En ese caso abrir Microsoft Access, seleccionar cada tabla y usar exportacion a archivo de texto CSV con encabezados.

No subir los CSV al repositorio publico.
