# Guia para crear la base de datos local

## Opcion 1: desde phpMyAdmin

1. Abrir XAMPP.
2. Iniciar Apache y MySQL.
3. Abrir en el navegador:

```text
http://localhost/phpmyadmin
```

4. Entrar a la pestana SQL.
5. Ejecutar:

```sql
CREATE DATABASE IF NOT EXISTS estructura_zonas_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

6. Seleccionar la base `estructura_zonas_test`.
7. Ejecutar los scripts del proyecto en este orden:

```text
database/staging_ubicaciones_access.sql
database/estructura_ubicaciones_dependencias.sql
database/adaptacion_moi_65_16.sql
database/clasificacion_moi_desde_staging.sql
database/migracion_final_moi_desde_clasificacion.sql
database/dashboard_moi_views.sql
```

## Opcion 2: desde Git Bash o CMD

Entrar al proyecto:

```bash
cd /c/xampp/htdocs/estructura-zonas
```

Ejecutar:

```bash
/c/xampp/mysql/bin/mysql.exe -u root < database/crear_base_datos.sql
```

Luego ejecutar los scripts en orden, indicando la base:

```bash
/c/xampp/mysql/bin/mysql.exe -u root estructura_zonas_test < database/staging_ubicaciones_access.sql
/c/xampp/mysql/bin/mysql.exe -u root estructura_zonas_test < database/estructura_ubicaciones_dependencias.sql
/c/xampp/mysql/bin/mysql.exe -u root estructura_zonas_test < database/adaptacion_moi_65_16.sql
/c/xampp/mysql/bin/mysql.exe -u root estructura_zonas_test < database/clasificacion_moi_desde_staging.sql
/c/xampp/mysql/bin/mysql.exe -u root estructura_zonas_test < database/migracion_final_moi_desde_clasificacion.sql
/c/xampp/mysql/bin/mysql.exe -u root estructura_zonas_test < database/dashboard_moi_views.sql
```

## Configuracion del dashboard

Copiar el archivo de ejemplo:

```bash
cp dashboard/config.example.php dashboard/config.php
```

Editar:

```bash
notepad dashboard/config.php
```

Debe quedar asi para XAMPP local:

```php
'db_host' => '127.0.0.1',
'db_port' => '3306',
'db_name' => 'estructura_zonas_test',
'db_user' => 'root',
'db_pass' => '',
```

Abrir:

```text
http://localhost/estructura-zonas/dashboard/
```
