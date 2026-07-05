# Flujo de relaciones jerarquicas MOI

Este flujo conecta la estructura vigente sin modificar el legacy historico.

## 1. Actualizar repositorio

```bash
cd /c/xampp/htdocs/estructura-zonas
git pull origin main
```

## 2. Preparar revision de relaciones

```bash
bash scripts/preparar_revision_relaciones.sh
```

Esto crea una mesa de revision con relaciones sugeridas.

Reglas iniciales:

- unidades superiores hacia la raiz institucional `POLICIA NACIONAL`;
- areas hacia zonas por prefijo ordinal del nombre;
- unidades sin candidato automatico quedan pendientes para revision manual.

## 3. Ver relaciones pendientes

```bash
/c/xampp/mysql/bin/mysql.exe -u root estructura_zonas_test -e "SELECT id, child_name, child_type, parent_name, parent_type, confidence_level, source_rule FROM vw_moi_revision_relaciones_pendientes LIMIT 50;"
```

## 4. Aprobar o rechazar una relacion

Aprobar:

```bash
bash scripts/decidir_relacion_moi.sh 15 aprobar "Relacion validada"
```

Rechazar:

```bash
bash scripts/decidir_relacion_moi.sh 15 rechazar "No corresponde al organigrama vigente"
```

## 5. Aplicar relaciones aprobadas

```bash
bash scripts/aplicar_relaciones_moi.sh
```

## 6. Refrescar dashboard

```text
http://localhost/estructura-zonas/dashboard/
```

Usar `Ctrl + F5`.

## Principio

Las relaciones nuevas pertenecen a la estructura vigente. El pasado heredado permanece intacto mediante `legacy_table` y `legacy_id`.
