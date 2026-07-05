# Flujo de absorcion de cabeceras MOI

## Principio

Las 18 zonas policiales y las 19 direcciones indicadas por el equipo usuario son las cabeceras legitimas vigentes.

Todo registro heredado relacionado con esas cabeceras debe ser absorbido por la cabecera legitima correspondiente.

No se borra ni se reescribe el legacy. La unidad heredada queda historica y se marca como fusionada con `replacement_unit_id` apuntando a la cabecera legitima.

## Archivos

- `database/absorcion_cabeceras_moi.sql`
- `database/aplicar_absorcion_cabeceras_moi.sql`
- `scripts/preparar_absorcion_cabeceras.sh`
- `scripts/aprobar_absorcion_cabeceras.sh`
- `scripts/aplicar_absorcion_cabeceras.sh`

## 1. Preparar candidatos de absorcion

```bash
cd /c/xampp/htdocs/estructura-zonas
git pull origin main
bash scripts/preparar_absorcion_cabeceras.sh
```

## 2. Aprobar candidatos

Aprobar solo coincidencias de alta confianza:

```bash
bash scripts/aprobar_absorcion_cabeceras.sh alto
```

Aprobar todos los candidatos pendientes:

```bash
bash scripts/aprobar_absorcion_cabeceras.sh todo
```

## 3. Aplicar absorcion

```bash
bash scripts/aplicar_absorcion_cabeceras.sh
```

## Resultado

- La cabecera legitima queda vigente.
- La unidad heredada relacionada queda `fusionada`.
- El campo `replacement_unit_id` apunta a la cabecera legitima.
- El `legacy_table` y `legacy_id` de la unidad heredada permanecen intactos.
