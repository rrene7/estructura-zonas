# Flujo de revision de vigencia MOI

Este flujo permite decidir que unidades quedan vigentes y cuales pasan a no vigentes, sin borrar el legado historico.

## 1. Actualizar repositorio

```bash
cd /c/xampp/htdocs/estructura-zonas
git pull origin main
```

## 2. Preparar mesa de revision

```bash
bash scripts/preparar_revision_vigencia.sh
```

Esto crea la tabla `moi_unit_vigencia_review` y las vistas de apoyo.

## 3. Ver pendientes

```bash
/c/xampp/mysql/bin/mysql.exe -u root estructura_zonas_test -e "SELECT id, code, name, unit_type, proposed_lifecycle_status, review_reason FROM vw_moi_revision_pendiente LIMIT 50;"
```

## 4. Tomar decision por unidad

Marcar como vigente:

```bash
bash scripts/decidir_vigencia_unidad.sh 10 vigente
```

Marcar como suprimida:

```bash
bash scripts/decidir_vigencia_unidad.sh 10 suprimida 2026-01-14 "No aparece en la nueva estructura MOI"
```

Marcar como fusionada:

```bash
bash scripts/decidir_vigencia_unidad.sh 10 fusionada 2026-01-14 "Se integra a otra unidad"
```

Marcar como renombrada:

```bash
bash scripts/decidir_vigencia_unidad.sh 10 renombrada 2026-01-14 "Cambio de denominacion"
```

## 5. Aplicar decisiones aprobadas

```bash
bash scripts/aplicar_decisiones_vigencia.sh
```

## 6. Refrescar dashboard

Abrir o refrescar:

```text
http://localhost/estructura-zonas/dashboard/
```

Usar `Ctrl + F5` para forzar recarga.

## Principio

No se borra ni se cambia el legacy. Las unidades no vigentes quedan disponibles para consultas historicas, pero no deben usarse para acciones nuevas.
