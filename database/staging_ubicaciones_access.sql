-- Staging SQL para importar tablas Access relacionadas con ubicaciones y dependencias.
-- No contiene datos reales. Usar como puente antes de poblar el modelo normalizado.

DROP TABLE IF EXISTS `stg_tablugar`;
CREATE TABLE `stg_tablugar` (
    `codigo` DOUBLE NULL,
    `deslug` VARCHAR(50) NULL,
    `ceduser_bit` TEXT NULL,
    `cedsuper_bit` TEXT NULL,
    `coderror` TEXT NULL,
    `toper` TEXT NULL,
    `fecha_bit` DATETIME NULL,
    `hora_bit` TEXT NULL,
    `tiemporeg` TEXT NULL,
    `source_loaded_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `stg_tabdir`;
CREATE TABLE `stg_tabdir` (
    `codprov` DOUBLE NULL,
    `desprov` TEXT NULL,
    `coddist` DOUBLE NULL,
    `desdist` TEXT NULL,
    `codcorr` DOUBLE NULL,
    `descorr` TEXT NULL,
    `vigente` DOUBLE NULL,
    `ceduser_bit` TEXT NULL,
    `cedsuper_bit` TEXT NULL,
    `coderror` TEXT NULL,
    `toper` TEXT NULL,
    `fecha_bit` DATETIME NULL,
    `hora_bit` TEXT NULL,
    `tiemporeg` TEXT NULL,
    `source_loaded_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `stg_tabcuar`;
CREATE TABLE `stg_tabcuar` (
    `codigocuar` CHAR(36) NULL,
    `descricuar` VARCHAR(50) NULL,
    `planicuar` DOUBLE NULL,
    `vigente` DOUBLE NULL,
    `sector` DOUBLE NULL,
    `sede` DOUBLE NULL,
    `clasi` DOUBLE NULL,
    `ordsal` DOUBLE NULL,
    `usuario` TEXT NULL,
    `fechadia` DATETIME NULL,
    `hora` TEXT NULL,
    `ceduser_bit` TEXT NULL,
    `cedsuper_bit` TEXT NULL,
    `coderror` TEXT NULL,
    `toper` TEXT NULL,
    `fecha_bit` DATETIME NULL,
    `hora_bit` TEXT NULL,
    `tiemporeg` TEXT NULL,
    `source_loaded_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `stg_bdfuerza`;
CREATE TABLE `stg_bdfuerza` (
    `codcuar` DOUBLE NULL,
    `descrip` TEXT NULL,
    `ordsal` DOUBLE NULL,
    `verord` DOUBLE NULL,
    `ceduser_bit` TEXT NULL,
    `cedsuper_bit` TEXT NULL,
    `coderror` TEXT NULL,
    `toper` TEXT NULL,
    `fecha_bit` DATETIME NULL,
    `hora_bit` TEXT NULL,
    `tiemporeg` TEXT NULL,
    `source_loaded_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `stg_dir`;
CREATE TABLE `stg_dir` (
    `dnemp` DOUBLE NULL,
    `dlocal` TEXT NULL,
    `dlugar` TEXT NULL,
    `dtel` TEXT NULL,
    `dced` TEXT NULL,
    `estado` TEXT NULL,
    `fechadia` DATETIME NULL,
    `usuario` VARCHAR(10) NULL,
    `dtel1` TEXT NULL,
    `dtel2` TEXT NULL,
    `dnombre` TEXT NULL,
    `dapellido` TEXT NULL,
    `dcodp` DOUBLE NULL,
    `dcodd` DOUBLE NULL,
    `dcodc` DOUBLE NULL,
    `hora` TEXT NULL,
    `ceduser_bit` TEXT NULL,
    `cedsuper_bit` TEXT NULL,
    `coderror` TEXT NULL,
    `toper` TEXT NULL,
    `fecha_bit` DATETIME NULL,
    `hora_bit` TEXT NULL,
    `tiemporeg` TEXT NULL,
    `source_loaded_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `stg_polplani`;
CREATE TABLE `stg_polplani` (
    `area` DOUBLE NULL,
    `entidad` DOUBLE NULL,
    `posicion` DOUBLE NULL,
    `ced_prv` TEXT NULL,
    `ced_tomo` DOUBLE NULL,
    `ced_asien` DOUBLE NULL,
    `planilla` DOUBLE NULL,
    `nombres` TEXT NULL,
    `ape_pater` TEXT NULL,
    `ape_mater` TEXT NULL,
    `ape_casada` TEXT NULL,
    `monto_sfij` DOUBLE NULL,
    `monto_anti` DOUBLE NULL,
    `monto_zona` DOUBLE NULL,
    `monto_jefa` DOUBLE NULL,
    `monto_ssob` DOUBLE NULL,
    `monto_sprf` DOUBLE NULL,
    `monto_gast` DOUBLE NULL,
    `estado_pri` TEXT NULL,
    `estado_sec` TEXT NULL,
    `fini_lab` TEXT NULL,
    `tib` TEXT NULL,
    `condicion` TEXT NULL,
    `seguro_soc` TEXT NULL,
    `clave_rent` TEXT NULL,
    `dependient` DOUBLE NULL,
    `source_loaded_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `stg_dota`;
CREATE TABLE `stg_dota` (
    `rango` DOUBLE NULL,
    `placa` TEXT NULL,
    `planilla` DOUBLE NULL,
    `nemp` DOUBLE NULL,
    `nombre` VARCHAR(50) NULL,
    `apellido` VARCHAR(50) NULL,
    `sexo` TEXT NULL,
    `cuartel` CHAR(36) NULL,
    `cedula` TEXT NULL,
    `ssocial` TEXT NULL,
    `posicipn` DOUBLE NULL,
    `posicimi` DOUBLE NULL,
    `salario` DOUBLE NULL,
    `sobsuel` DOUBLE NULL,
    `sobrsptu` DOUBLE NULL,
    `salpre` DOUBLE NULL,
    `fecnac` DATETIME NULL,
    `fecing` DATETIME NULL,
    `fecascen` DATETIME NULL,
    `tiposang` TEXT NULL,
    `fecvac` DATETIME NULL,
    `fectras` DATETIME NULL,
    `fecrot` DATETIME NULL,
    `adm` TEXT NULL,
    `estado` TEXT NULL,
    `part` DOUBLE NULL,
    `deplanta` DOUBLE NULL,
    `discont` TEXT NULL,
    `golpista` TEXT NULL,
    `mesacum` DOUBLE NULL,
    `diacum` DOUBLE NULL,
    `fecnatura` DATETIME NULL,
    `ts` TEXT NULL,
    `fecvacum` DATETIME NULL,
    `tomames` DOUBLE NULL,
    `tomadia` DOUBLE NULL,
    `fecreing` DATETIME NULL,
    `usuario` TEXT NULL,
    `fechadia` DATETIME NULL,
    `idiomas` TEXT NULL,
    `idioma2` TEXT NULL,
    `idioma3` TEXT NULL,
    `estatura` TEXT NULL,
    `religion` DOUBLE NULL,
    `fecsta` DATETIME NULL,
    `cantsta` DOUBLE NULL,
    `hora` TEXT NULL,
    `usafecha` DATETIME NULL,
    `usaestat` DOUBLE NULL,
    `finivie` DATETIME NULL,
    `finipp` DATETIME NULL,
    `faltopp` DATETIME NULL,
    `frinipp` DATETIME NULL,
    `tipopol` TEXT NULL,
    `tr` TEXT NULL,
    `tc` TEXT NULL,
    `turnoe` TEXT NULL,
    `marca` DOUBLE NULL,
    `tipo` TEXT NULL,
    `fechasup` DATETIME NULL,
    `estasup` TEXT NULL,
    `carradmi` TEXT NULL,
    `fecaradm` DATETIME NULL,
    `fecsta1` DATETIME NULL,
    `fecsta2` DATETIME NULL,
    `cansta1` CHAR(36) NULL,
    `cansta2` CHAR(36) NULL,
    `estado1` TEXT NULL,
    `estado2` TEXT NULL,
    `pin` TEXT NULL,
    `codgrupo` DOUBLE NULL,
    `agregar` DOUBLE NULL,
    `guardar` DOUBLE NULL,
    `modificar` DOUBLE NULL,
    `eliminar` DOUBLE NULL,
    `anular` DOUBLE NULL,
    `imprimir` DOUBLE NULL,
    `verglobal` DOUBLE NULL,
    `acctodas` DOUBLE NULL,
    `ceduser_bit` TEXT NULL,
    `cedsuper_bit` TEXT NULL,
    `coderror` TEXT NULL,
    `toper` TEXT NULL,
    `fecha_bit` DATETIME NULL,
    `hora_bit` TEXT NULL,
    `tiemporeg` TEXT NULL,
    `perpend` TEXT NULL,
    `source_loaded_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `stg_vacantes`;
CREATE TABLE `stg_vacantes` (
    `epllave` DOUBLE NULL,
    `epparsu` DOUBLE NULL,
    `epsuvig` DOUBLE NULL,
    `epsuel1` DOUBLE NULL,
    `epmes1` DOUBLE NULL,
    `epsuel2` DOUBLE NULL,
    `epmes2` DOUBLE NULL,
    `epparsb` DOUBLE NULL,
    `epsbsue` DOUBLE NULL,
    `epcargo` DOUBLE NULL,
    `epgreta` TEXT NULL,
    `epposan` DOUBLE NULL,
    `epstat` TEXT NULL,
    `epflag` TEXT NULL,
    `epplan` DOUBLE NULL,
    `epnmbre` TEXT NULL,
    `epape` TEXT NULL,
    `epnoss` DOUBLE NULL,
    `epprov` DOUBLE NULL,
    `epsexo` TEXT NULL,
    `epbruto` DOUBLE NULL,
    `epclir` TEXT NULL,
    `epvir` DOUBLE NULL,
    `epexose` DOUBLE NULL,
    `epcedla` TEXT NULL,
    `epsldacm` DOUBLE NULL,
    `epqncacm` DOUBLE NULL,
    `epstavac` DOUBLE NULL,
    `epqncvac` DOUBLE NULL,
    `epmonvac` DOUBLE NULL,
    `epmonacu` DOUBLE NULL,
    `eptipgo` DOUBLE NULL,
    `epmonpag` DOUBLE NULL,
    `epmonacp` DOUBLE NULL,
    `epqncd` DOUBLE NULL,
    `epcont` DOUBLE NULL,
    `epsuel3` DOUBLE NULL,
    `epmes3` DOUBLE NULL,
    `epsuel4` DOUBLE NULL,
    `epmes4` DOUBLE NULL,
    `eppartg` DOUBLE NULL,
    `epsbgas` DOUBLE NULL,
    `source_loaded_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `stg_cargos`;
CREATE TABLE `stg_cargos` (
    `codigo` DOUBLE NULL,
    `cod` DOUBLE NULL,
    `rot` TEXT NULL,
    `flg_ley_es` DOUBLE NULL,
    `flg_car_ad` DOUBLE NULL,
    `source_loaded_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `stg_tabran`;
CREATE TABLE `stg_tabran` (
    `codigoran` DOUBLE NULL,
    `descripran` VARCHAR(50) NULL,
    `rangocorto` TEXT NULL,
    `salario` DOUBLE NULL,
    `ceduser_bit` TEXT NULL,
    `cedsuper_bit` TEXT NULL,
    `coderror` TEXT NULL,
    `toper` TEXT NULL,
    `fecha_bit` DATETIME NULL,
    `hora_bit` TEXT NULL,
    `tiemporeg` TEXT NULL,
    `source_loaded_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `stg_tabstatus`;
CREATE TABLE `stg_tabstatus` (
    `codigo` DOUBLE NULL,
    `descripcion` TEXT NULL,
    `descripcion_corta` VARCHAR(50) NULL,
    `ceduser_bit` TEXT NULL,
    `cedsuper_bit` TEXT NULL,
    `coderror` TEXT NULL,
    `toper` TEXT NULL,
    `fecha_bit` DATETIME NULL,
    `hora_bit` TEXT NULL,
    `tiemporeg` TEXT NULL,
    `source_loaded_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Indices sugeridos para analisis y migracion
CREATE INDEX idx_stg_tabdir_geo ON stg_tabdir (`codprov`, `coddist`, `codcorr`);
CREATE INDEX idx_stg_tablugar_codigo ON stg_tablugar (`codigo`);
CREATE INDEX idx_stg_tabcuar_codigo ON stg_tabcuar (`codigocuar`);
CREATE INDEX idx_stg_bdfuerza_codcuar ON stg_bdfuerza (`codcuar`);
CREATE INDEX idx_stg_dir_geo ON stg_dir (`dcodp`, `dcodd`, `dcodc`);
CREATE INDEX idx_stg_dir_dnemp ON stg_dir (`dnemp`);
CREATE INDEX idx_stg_polplani_posicion ON stg_polplani (`posicion`);
CREATE INDEX idx_stg_dota_nemp ON stg_dota (`nemp`);
CREATE INDEX idx_stg_dota_cuartel ON stg_dota (`cuartel`);
CREATE INDEX idx_stg_vacantes_llave ON stg_vacantes (`epllave`);

-- Validaciones posteriores a la carga
-- SELECT COUNT(*) FROM stg_tabdir;
-- SELECT codprov, desprov, coddist, desdist, codcorr, descorr FROM stg_tabdir ORDER BY codprov, coddist, codcorr;
-- SELECT codigocuar, descricuar, planicuar, vigente, sector, sede, clasi FROM stg_tabcuar ORDER BY descricuar;
-- SELECT cuartel, COUNT(*) total FROM stg_dota GROUP BY cuartel ORDER BY total DESC;
-- SELECT area, entidad, posicion, COUNT(*) total FROM stg_polplani GROUP BY area, entidad, posicion ORDER BY total DESC;
