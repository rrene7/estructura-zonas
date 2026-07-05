# Mapeo Access -> ubicaciones y dependencias

Documento para identificar en `SRHN_LOCAL.mdb` las tablas y campos que sirven para construir ubicaciones, direcciones, zonas, areas, dependencias, oficinas, departamentos, cuarteles y posiciones.

No contiene datos reales; solo estructura y reglas de mapeo.

## Tablas revisadas

| Tabla Access | Registros | Paginas | Columnas | Papel probable |
|---|---:|---:|---:|---|
| `TABLUGAR` | 5,416 | 319-323 | 9 | Catalogo de lugares |
| `TABDIR` | 541 | 293-298 | 14 | Catalogo geografico: provincia, distrito y corregimiento |
| `TABCUAR` | 3,739 | 273-281 | 18 | Catalogo principal de cuarteles, unidades o dependencias |
| `BDFUERZA` | 75 | 33-35 | 11 | Estructura resumida de fuerza por cuartel/unidad |
| `DIR` | 30,072 | 68-73 | 23 | Direcciones locales asociadas a personas o registros |
| `POLPLANI` | 21,267 | 254-260 | 26 | Planilla, area, entidad y posiciones |
| `DOTA` | 50,229 | 86-127 | 87 | Dotacion o asignacion del funcionario; contiene `CUARTEL` |
| `VACANTES` | 4,617 | 366-381 | 42 | Vacantes y posiciones presupuestarias |
| `CARGOS` | 415 | 57-58 | 5 | Catalogo de cargos |
| `TABRAN` | 69 | 331-336 | 11 | Catalogo de rangos |
| `TABSTATUS` | 29 | 340-342 | 10 | Catalogo de estados |

## Mapeo normalizado recomendado

| Modelo nuevo | Fuente Access | Campos clave | Observacion |
|---|---|---|---|
| `territorial_divisions` | `TABDIR` | `CODPROV`, `DESPROV`, `CODDIST`, `DESDIST`, `CODCORR`, `DESCORR` | Separar provincia, distrito y corregimiento. |
| `locations` | `DIR`, `TABLUGAR` | `DLOCAL`, `DLUGAR`, `DCODP`, `DCODD`, `DCODC`, `DESLUG` | `DIR` contiene direccion local y codigos geograficos; `TABLUGAR` sirve como catalogo de lugar. |
| `organizational_units` | `TABCUAR`, `BDFUERZA` | `CODIGOCUAR`, `DESCRICUAR`, `PLANICUAR`, `SECTOR`, `SEDE`, `CLASI`, `CODCUAR`, `DESCRIP` | Tabla central para zonas, unidades, cuarteles, dependencias y oficinas. |
| `unit_types` | Regla de negocio | zona, direccion, area, departamento, oficina, dependencia, cuartel, estacion, subestacion, puesto | No aparece como catalogo separado; se propone normalizarlo. |
| `positions` | `POLPLANI`, `VACANTES`, `CARGOS` | `POSICION`, `AREA`, `ENTIDAD`, `EPCARGO`, `EPPOSAN`, `EPPLAN`, `CODIGO`, `ROT` | Separar cargo, posicion y estado de vacante. |
| `unit_assignments` | `DOTA`, `POLPLANI`, `ACCIONES` | `NEMP`, `CUARTEL`, `POSICIPN`, `POSICIMI`, `FECTRAS`, `ESTADO` | Historial de asignacion del funcionario a unidad o dependencia. |
| `ranks` | `TABRAN` | `CODIGORAN`, `DESCRIPRAN`, `RANGOCORTO`, `SALARIO` | Catalogo de rangos. |
| `status_catalog` | `TABSTATUS` | `CODIGO`, `DESCRIPCION`, `DESCRIPCION_CORTA` | Catalogo de estados. |

## Relaciones probables por validar

- `DIR.DCODP`, `DIR.DCODD`, `DIR.DCODC` probablemente apuntan a `TABDIR.CODPROV`, `TABDIR.CODDIST`, `TABDIR.CODCORR`.
- `DOTA.CUARTEL` probablemente apunta a `TABCUAR.CODIGOCUAR`, porque ambos campos manejan identificador tipo replicacion/UUID.
- `TABCUAR.DESCRICUAR` y `BDFUERZA.DESCRIP` parecen representar nombres de cuarteles/unidades. Hay que comparar valores para evitar duplicados.
- `POLPLANI.AREA`, `POLPLANI.ENTIDAD` y `POLPLANI.POSICION` deben mapearse a estructura presupuestaria y posiciones.
- `VACANTES` no muestra una llave directa de cuartel en la estructura extraida. Puede requerir union por posicion, planilla, cargo o una tabla intermedia.
- Los campos `CEDUSER_BIT`, `CEDSUPER_BIT`, `CODERROR`, `TOPER`, `FECHA_BIT`, `HORA_BIT`, `TIEMPOREG` se repiten como auditoria heredada.

## Campos exactos por tabla

### `TABLUGAR`

Campos: `CODIGO`, `DESLUG`, `CEDUSER_BIT`, `CEDSUPER_BIT`, `CODERROR`, `TOPER`, `FECHA_BIT`, `HORA_BIT`, `TIEMPOREG`.

Uso: catalogo de lugares. `CODIGO` seria el codigo heredado y `DESLUG` el nombre del lugar.

### `TABDIR`

Campos: `CODPROV`, `DESPROV`, `CODDIST`, `DESDIST`, `CODCORR`, `DESCORR`, `VIGENTE`, `CEDUSER_BIT`, `CEDSUPER_BIT`, `CODERROR`, `TOPER`, `FECHA_BIT`, `HORA_BIT`, `TIEMPOREG`.

Uso: catalogo geografico. Permite crear provincias, distritos y corregimientos.

### `TABCUAR`

Campos: `CODIGOCUAR`, `DESCRICUAR`, `PLANICUAR`, `VIGENTE`, `SECTOR`, `SEDE`, `CLASI`, `ORDSAL`, `USUARIO`, `FECHADIA`, `HORA`, `CEDUSER_BIT`, `CEDSUPER_BIT`, `CODERROR`, `TOPER`, `FECHA_BIT`, `HORA_BIT`, `TIEMPOREG`.

Uso: catalogo principal de cuarteles, unidades, dependencias o sedes. `CODIGOCUAR` debe conservarse como `legacy_id`.

### `BDFUERZA`

Campos: `CODCUAR`, `DESCRIP`, `ORDSAL`, `VERORD`, `CEDUSER_BIT`, `CEDSUPER_BIT`, `CODERROR`, `TOPER`, `FECHA_BIT`, `HORA_BIT`, `TIEMPOREG`.

Uso: lista resumida de unidades o fuerza. Validar contra `TABCUAR` para detectar equivalencias.

### `DIR`

Campos: `DNEMP`, `DLOCAL`, `DLUGAR`, `DTEL`, `DCED`, `ESTADO`, `FECHADIA`, `USUARIO`, `DTEL1`, `DTEL2`, `DNOMBRE`, `DAPELLIDO`, `DCODP`, `DCODD`, `DCODC`, `HORA`, `CEDUSER_BIT`, `CEDSUPER_BIT`, `CODERROR`, `TOPER`, `FECHA_BIT`, `HORA_BIT`, `TIEMPOREG`.

Uso: direcciones locales. `DCODP`, `DCODD`, `DCODC` deben cruzarse con `TABDIR`.

### `POLPLANI`

Campos: `AREA`, `ENTIDAD`, `POSICION`, `CED_PRV`, `CED_TOMO`, `CED_ASIEN`, `PLANILLA`, `NOMBRES`, `APE_PATER`, `APE_MATER`, `APE_CASADA`, `MONTO_SFIJ`, `MONTO_ANTI`, `MONTO_ZONA`, `MONTO_JEFA`, `MONTO_SSOB`, `MONTO_SPRF`, `MONTO_GAST`, `ESTADO_PRI`, `ESTADO_SEC`, `FINI_LAB`, `TIB`, `CONDICION`, `SEGURO_SOC`, `CLAVE_RENT`, `DEPENDIENT`.

Uso: planilla y posiciones. Los campos importantes para estructura son `AREA`, `ENTIDAD`, `POSICION`, `PLANILLA` y `DEPENDIENT`.

### `DOTA`

Campos principales para ubicaciones/dependencias: `RANGO`, `PLACA`, `PLANILLA`, `NEMP`, `NOMBRE`, `APELLIDO`, `SEXO`, `CUARTEL`, `CEDULA`, `POSICIPN`, `POSICIMI`, `FECING`, `FECTRAS`, `ADM`, `ESTADO`, `DEPLANTA`, `USUARIO`, `FECHADIA`, `TIPOPOL`, `CODGRUPO`.

Campos completos: `RANGO`, `PLACA`, `PLANILLA`, `NEMP`, `NOMBRE`, `APELLIDO`, `SEXO`, `CUARTEL`, `CEDULA`, `SSOCIAL`, `POSICIPN`, `POSICIMI`, `SALARIO`, `SOBSUEL`, `SOBRSPTU`, `SALPRE`, `FECNAC`, `FECING`, `FECASCEN`, `TIPOSANG`, `FECVAC`, `FECTRAS`, `FECROT`, `ADM`, `ESTADO`, `PART`, `DEPLANTA`, `DISCONT`, `GOLPISTA`, `MESACUM`, `DIACUM`, `FECNATURA`, `TS`, `FECVACUM`, `TOMAMES`, `TOMADIA`, `FECREING`, `USUARIO`, `FECHADIA`, `IDIOMAS`, `IDIOMA2`, `IDIOMA3`, `ESTATURA`, `RELIGION`, `FECSTA`, `CANTSTA`, `HORA`, `USAFECHA`, `USAESTAT`, `FINIVIE`, `FINIPP`, `FALTOPP`, `FRINIPP`, `TIPOPOL`, `TR`, `TC`, `TURNOE`, `MARCA`, `TIPO`, `FECHASUP`, `ESTASUP`, `CARRADMI`, `FECARADM`, `FECSTA1`, `FECSTA2`, `CANSTA1`, `CANSTA2`, `ESTADO1`, `ESTADO2`, `PIN`, `CODGRUPO`, `AGREGAR`, `GUARDAR`, `MODIFICAR`, `ELIMINAR`, `ANULAR`, `IMPRIMIR`, `VERGLOBAL`, `ACCTODAS`, `CEDUSER_BIT`, `CEDSUPER_BIT`, `CODERROR`, `TOPER`, `FECHA_BIT`, `HORA_BIT`, `TIEMPOREG`, `PERPEND`.

Uso: asignacion del funcionario a unidad. `CUARTEL` es el campo clave para cruzar contra `TABCUAR`.

### `VACANTES`

Campos: `EPLLAVE`, `EPPARSU`, `EPSUVIG`, `EPSUEL1`, `EPMES1`, `EPSUEL2`, `EPMES2`, `EPPARSB`, `EPSBSUE`, `EPCARGO`, `EPGRETA`, `EPPOSAN`, `EPSTAT`, `EPFLAG`, `EPPLAN`, `EPNMBRE`, `EPAPE`, `EPNOSS`, `EPPROV`, `EPSEXO`, `EPBRUTO`, `EPCLIR`, `EPVIR`, `EPEXOSE`, `EPCEDLA`, `EPSLDACM`, `EPQNCACM`, `EPSTAVAC`, `EPQNCVAC`, `EPMONVAC`, `EPMONACU`, `EPTIPGO`, `EPMONPAG`, `EPMONACP`, `EPQNCD`, `EPCONT`, `EPSUEL3`, `EPMES3`, `EPSUEL4`, `EPMES4`, `EPPARTG`, `EPSBGAS`.

Uso: vacantes y posiciones presupuestarias. Validar `EPCARGO`, `EPPLAN`, `EPPOSAN` y `EPLLAVE`.

### `CARGOS`

Campos: `CODIGO`, `COD`, `ROT`, `FLG_LEY_ES`, `FLG_CAR_AD`.

Uso: catalogo de cargos.

### `TABRAN`

Campos: `CODIGORAN`, `DESCRIPRAN`, `RANGOCORTO`, `SALARIO`, `CEDUSER_BIT`, `CEDSUPER_BIT`, `CODERROR`, `TOPER`, `FECHA_BIT`, `HORA_BIT`, `TIEMPOREG`.

Uso: catalogo de rangos.

### `TABSTATUS`

Campos: `CODIGO`, `DESCRIPCION`, `DESCRIPCION_CORTA`, `CEDUSER_BIT`, `CEDSUPER_BIT`, `CODERROR`, `TOPER`, `FECHA_BIT`, `HORA_BIT`, `TIEMPOREG`.

Uso: catalogo de estados.

## Orden de trabajo sugerido

1. Importar las tablas Access a tablas `staging`.
2. Crear `territorial_divisions` desde `TABDIR`.
3. Crear `organizational_units` desde `TABCUAR` y validar con `BDFUERZA`.
4. Crear `locations` desde `DIR` y `TABLUGAR`.
5. Crear `positions` desde `POLPLANI`, `VACANTES` y `CARGOS`.
6. Crear `unit_assignments` desde `DOTA` y luego enriquecer con historico de `ACCIONES`.
