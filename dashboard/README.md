# Dashboard de Pie de Fuerza

Módulo local para consultar el personal contra la estructura institucional vigente.

La interfaz está diseñada para que una persona sin conocimientos técnicos pueda distinguir claramente:

- La **unidad funcional** a la que pertenece el funcionario.
- La **zona territorial** donde presta servicio, cuando aplica.
- La **dependencia o sección interna** registrada.
- El estado de la ubicación: completa, unidad confirmada o pendiente.

## Sin inicio de sesión

Este módulo no incluye login, usuarios ni roles. Se abre directamente porque posteriormente será integrado dentro de un sistema institucional completo, que administrará la autenticación y los permisos.

Mientras se utiliza de forma independiente debe mantenerse en `localhost` o dentro de una intranet controlada. No debe exponerse directamente a internet.

## Requisitos

- PHP 8.x.
- MySQL o MariaDB.
- PDO MySQL habilitado.
- Base de datos local con las tablas y vistas del proyecto.

## Instalación rápida en XAMPP

1. Copiar la carpeta `dashboard` dentro del proyecto ubicado en `htdocs`.
2. Copiar el archivo de configuración:

```bash
cp dashboard/config.example.php dashboard/config.php
```

3. Editar `dashboard/config.php` con los datos de la base local.
4. Ejecutar las migraciones y vistas del proyecto, incluyendo:

```text
database/dashboard_moi_views.sql
database/pie_fuerza_20260626.sql
```

5. Abrir en el navegador:

```text
http://localhost/estructura-zonas/dashboard/
```

## Navegación principal

- **Inicio:** resumen general y buscador principal.
- **Personal:** búsqueda por nombre, posición, rango, unidad, zona o dependencia.
- **Direcciones:** navegación por direcciones nacionales.
- **Zonas policiales:** personal directo y referencias territoriales.
- **Servicios policiales:** consulta de servicios especializados.
- **Estructura:** unidades vigentes y sus dependencias.
- **Reportes:** descargas CSV y consultas preparadas.

## Archivos principales

```text
dashboard/
├── index.php
├── pie_fuerza.php
├── persona_detalle.php
├── unidades.php
├── unidad_detalle.php
├── reportes.php
├── includes/
│   ├── bootstrap.php
│   └── layout.php
├── assets/
│   ├── css/dashboard.css
│   └── js/dashboard.js
├── config.example.php
└── config.php
```

`config.php` debe permanecer únicamente en la computadora local. La plantilla pública es `config.example.php`.

## Integración futura

La conexión, las consultas y la presentación están separadas para facilitar la migración. Cuando el módulo se integre al sistema completo, la aplicación principal podrá envolver estas pantallas con su propia sesión, control de acceso, menú y permisos.

## Importante

No colocar datos reales, listados privados ni credenciales en GitHub. Los archivos originales del pie de fuerza deben mantenerse fuera del repositorio.
