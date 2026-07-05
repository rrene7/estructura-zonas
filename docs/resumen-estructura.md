# Resumen de estructura - SRHN_LOCAL.mdb

Fuente: reporte PDF del Documentador de Microsoft Access (`reporteacces.pdf`).

## Totales

- Tablas detectadas: **75**
- Páginas del reporte: **389**
- La estructura no incluye relaciones ni llaves primarias confirmadas; se debe validar con Access antes de migrar a producción.

## Tablas con mayor cantidad de registros

| Tabla | Registros | Columnas | Páginas |
|---|---:|---:|---:|
| `ACCIONES` | 2,390,626 | 38 | 1-15 |
| `EVALUAR` | 392,237 | 23 | 166-175 |
| `ESTUDIOS` | 117,258 | 21 | 154-159 |
| `FAM` | 99,442 | 18 | 178-182 |
| `TCREDI` | 95,199 | 10 | 344-346 |
| `PLADB` | 90,141 | 14 | 236-239 |
| `DOTA` | 50,229 | 87 | 86-127 |
| `ejemplo` | 47,895 | 21 | 143-148 |
| `DIR` | 30,072 | 23 | 68-73 |
| `POLNAL` | 21,778 | 56 | 240-253 |
| `POLPLANI` | 21,267 | 26 | 254-260 |
| `TRAMITES` | 21,066 | 40 | 353-363 |
| `DOLENCIA` | 18,853 | 19 | 80-85 |
| `TABCURSO` | 9,987 | 9 | 287-292 |
| `HISTURNO` | 6,960 | 9 | 192-194 |
| `TEMPOACC` | 6,345 | 5 | 347-348 |
| `TABCUAR_vieja` | 5,541 | 12 | 283-286 |
| `TABLUGAR` | 5,416 | 9 | 319-324 |
| `VACANTES` | 4,617 | 52 | 366-389 |
| `TABCUAR` | 3,739 | 18 | 273-282 |

## Observaciones iniciales

- `ACCIONES` es la tabla más grande detectada y concentra el histórico principal de acciones de personal.
- `DOTA`, `POLNAL`, `POLPLANI`, `VACANTES`, `PLADB`, `ESTUDIOS`, `FAM`, `EVALUAR` y `TRAMITES` parecen ser tablas críticas para recursos humanos, planilla, dotación, evaluaciones y trámites.
- Hay tablas duplicadas o de respaldo como `ACCIONES2`, `BDFUERZA1`, `BDFUERZA-MOLDE`, `BDFUERZADIJ`, `BDFUERZAV`, `DIRCURR`, `ESTUDIOSO` y `TABCUAR_vieja`; deben clasificarse antes de migrar.
- Varias tablas tienen campos de auditoría repetidos: `CEDUSER_BIT`, `CEDSUPER_BIT`, `CODERROR`, `TOPER`, `FECHA_BIT`, `HORA_BIT`, `TIEMPOREG`.
- Muchas claves antiguas están como `Doble` o `Id. de replicación`; en una migración a MySQL conviene revisar si corresponden a `INT`, `BIGINT`, `UUID` o catálogos relacionados.

## Inventario de tablas

| # | Tabla | Registros | Columnas | Páginas |
|---:|---|---:|---:|---:|
| 1 | `ACCIONES` | 2,390,626 | 38 | 1-15 |
| 2 | `ACCIONES_PLACAS` | 36 | 2 | 16-16 |
| 3 | `ACCIONES2` | 0 | 38 | 17-26 |
| 4 | `ACTAPOSE` | 0 | 22 | 27-32 |
| 5 | `BDFUERZA` | 75 | 11 | 33-36 |
| 6 | `BDFUERZA1` | 216 | 11 | 37-40 |
| 7 | `BDFUERZADIJ` | 156 | 11 | 41-44 |
| 8 | `BDFUERZA-MOLDE` | 0 | 11 | 45-48 |
| 9 | `BDFUERZAV` | 92 | 11 | 49-52 |
| 10 | `BITAREG` | 192 | 13 | 53-56 |
| 11 | `CARGOS` | 415 | 5 | 57-58 |
| 12 | `COMPU` | 51 | 9 | 59-61 |
| 13 | `CONECT` | 1,326 | 7 | 62-64 |
| 14 | `CONECT_viejo` | 147 | 4 | 65-66 |
| 15 | `contador_de_gafete` | 12 | 2 | 67-67 |
| 16 | `DIR` | 30,072 | 23 | 68-73 |
| 17 | `DIRCURR` | 36 | 23 | 74-79 |
| 18 | `DOLENCIA` | 18,853 | 19 | 80-85 |
| 19 | `DOTA` | 50,229 | 87 | 86-127 |
| 20 | `DOTACAND` | 36 | 58 | 128-142 |
| 21 | `ejemplo` | 47,895 | 21 | 143-148 |
| 22 | `ENFERMED` | 366 | 2 | 149-149 |
| 23 | `estado_fuerza` | 1,299 | 2 | 150-150 |
| 24 | `estado_placa` | 72 | 2 | 151-151 |
| 25 | `ESTE_PLACA` | 72 | 3 | 152-153 |
| 26 | `ESTUDIOS` | 117,258 | 21 | 154-159 |
| 27 | `ESTUDIOSO` | 24 | 21 | 160-165 |
| 28 | `EVALUAR` | 392,237 | 23 | 166-175 |
| 29 | `EXPERIENCIA` | 24 | 6 | 176-177 |
| 30 | `FAM` | 99,442 | 18 | 178-182 |
| 31 | `gafete` | 6 | 5 | 183-184 |
| 32 | `GRUPOOPC` | 768 | 9 | 185-187 |
| 33 | `GRUPOS` | 75 | 12 | 188-191 |
| 34 | `HISTURNO` | 6,960 | 9 | 192-194 |
| 35 | `IDIOMA` | 0 | 5 | 195-196 |
| 36 | `MASTER_PLACAS` | 18 | 8 | 197-199 |
| 37 | `MOVIMIENTOS_PLACAS` | 15 | 7 | 200-203 |
| 38 | `NOTATRA` | 1,086 | 21 | 204-209 |
| 39 | `OPCMENU` | 273 | 9 | 210-212 |
| 40 | `pbcatcol` | 36 | 20 | 213-218 |
| 41 | `pbcatedt` | 63 | 7 | 219-221 |
| 42 | `pbcatfmt` | 60 | 4 | 222-223 |
| 43 | `pbcattbl` | 15 | 25 | 224-230 |
| 44 | `pbcatvld` | 0 | 5 | 231-232 |
| 45 | `PERMIACC` | 195 | 9 | 233-235 |
| 46 | `PLADB` | 90,141 | 14 | 236-239 |
| 47 | `POLNAL` | 21,778 | 56 | 240-253 |
| 48 | `POLPLANI` | 21,267 | 26 | 254-260 |
| 49 | `REFERENCIA` | 15 | 5 | 261-262 |
| 50 | `SALARIOS` | 33 | 3 | 263-264 |
| 51 | `sysdiagrams` | 0 | 5 | 265-266 |
| 52 | `TABACC` | 74 | 9 | 267-269 |
| 53 | `TABCAR` | 3,279 | 10 | 270-272 |
| 54 | `TABCUAR` | 3,739 | 18 | 273-282 |
| 55 | `TABCUAR_vieja` | 5,541 | 12 | 283-286 |
| 56 | `TABCURSO` | 9,987 | 9 | 287-292 |
| 57 | `TABDIR` | 541 | 14 | 293-298 |
| 58 | `TABDRP` | 10 | 4 | 299-300 |
| 59 | `TABESP` | 278 | 9 | 301-306 |
| 60 | `TABFAM` | 50 | 9 | 307-309 |
| 61 | `TABFUN` | 587 | 9 | 310-312 |
| 62 | `TABLALLAVE` | 81 | 19 | 313-318 |
| 63 | `TABLUGAR` | 5,416 | 9 | 319-324 |
| 64 | `TABMER` | 747 | 9 | 325-327 |
| 65 | `TABMOTIVO` | 12 | 9 | 328-330 |
| 66 | `TABRAN` | 69 | 11 | 331-336 |
| 67 | `TABSAN` | 715 | 9 | 337-339 |
| 68 | `TABSTATUS` | 29 | 10 | 340-343 |
| 69 | `TCREDI` | 95,199 | 10 | 344-346 |
| 70 | `TEMPOACC` | 6,345 | 5 | 347-348 |
| 71 | `TIPO` | 51 | 9 | 349-351 |
| 72 | `TIPOCONDICI` | 42 | 2 | 352-352 |
| 73 | `TRAMITES` | 21,066 | 40 | 353-363 |
| 74 | `TURNOS` | 285 | 5 | 364-365 |
| 75 | `VACANTES` | 4,617 | 52 | 366-389 |
