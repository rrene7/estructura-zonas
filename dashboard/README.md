# Dashboard MOI 65.16

Dashboard local para visualizar como se va armando la estructura institucional a partir de Access y la clasificacion MOI.

## Requisitos

- PHP 8.x
- MySQL o MariaDB
- PDO MySQL habilitado
- Base de datos de prueba con las tablas y vistas del proyecto

## Instalacion rapida en XAMPP

1. Copiar la carpeta `dashboard` dentro del proyecto o dentro de `htdocs`.
2. Copiar el archivo de configuracion:

```bash
cp dashboard/config.example.php dashboard/config.php
```

3. Editar `dashboard/config.php` con los datos de la base local.

4. Ejecutar los scripts SQL en este orden:

```text
database/staging_ubicaciones_access.sql
database/estructura_ubicaciones_dependencias.sql
database/adaptacion_moi_65_16.sql
database/clasificacion_moi_desde_staging.sql
database/migracion_final_moi_desde_clasificacion.sql
database/dashboard_moi_views.sql
```

5. Abrir en el navegador:

```text
http://localhost/estructura-zonas/dashboard/
```

## Que muestra

- Total de unidades.
- Unidades nacionales, regionales, zonales, de area y locales.
- Sedes detectadas.
- Pendientes de revision.
- Unidades por tipo.
- Unidades por alcance.
- Listado tipo arbol de unidades.
- Pendientes de revision.
- Alertas de calidad.

## Flujo recomendado

```text
Access -> staging -> clasificacion MOI -> revision manual -> migracion final -> vistas dashboard -> dashboard
```

## Importante

No colocar datos reales ni credenciales reales en GitHub. El archivo `config.php` debe quedar solamente local.
