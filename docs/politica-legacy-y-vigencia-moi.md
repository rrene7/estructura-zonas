# Politica de legacy y vigencia MOI

## Principio rector

La informacion historica heredada desde Access no se modifica. Los campos `legacy_table` y `legacy_id` quedan como referencia permanente al dato de origen.

La nueva estructura MOI rige para hechos administrativos posteriores a la fecha efectiva definida para la version normativa.

## Que cambia

A partir de la nueva estructura, las acciones futuras deben apuntar a la unidad vigente:

- estados;
- traslados;
- vacaciones;
- nombramientos;
- asignaciones;
- otros movimientos administrativos.

## Que no cambia

No se reescribe el pasado. Las acciones antiguas conservan la estructura y codigos con los que fueron generadas originalmente.

## Manejo de unidades que ya no existen

Una unidad que ya no existe no debe borrarse fisicamente. Debe marcarse como no vigente mediante:

- `lifecycle_status = 'suprimida'`;
- `status = 'inactive'`;
- `valid_to = fecha de cierre`;
- registro en `organizational_unit_lifecycle_events`.

Esto permite consultar historicos sin perder trazabilidad.

## Tablas agregadas

`organizational_unit_lifecycle_events` guarda eventos de ciclo de vida de unidades.

`structure_action_routing` registra acciones posteriores que deben regir bajo la nueva estructura.

## Flujo recomendado

1. Cargar legacy desde Access.
2. Clasificar segun MOI.
3. Marcar estructura vigente desde la fecha efectiva.
4. Marcar unidades no vigentes sin borrarlas.
5. Enviar toda accion nueva contra unidades vigentes.
6. Mantener reportes historicos separados de reportes de estructura actual.
