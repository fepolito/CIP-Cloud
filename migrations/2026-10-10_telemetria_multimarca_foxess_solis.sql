-- =============================================================================
-- Migration: Infraestrutura de Telemetria Multi-Marca e Controle de Acesso
-- Data     : 2026-10-10
-- Versão   : 2.0.0
-- Objetivo : 1. Adiciona coluna is_master_sistema em empresa e ativa ID 1 (Aeonium)
--            2. Cria tabela usuario_controlador_acesso (grants diretos a controladores)
--            3. Cria tabela associativa 1:N controlador_inversores
--            4. Cria tabela de segregação por marca telemetria_5min_marca
--            5. Cria tabela granular telemetria_equipamento_5min (com deltas e unique key)
-- Requisitos: MySQL 5.7+ / 8.0+ | Engine InnoDB | Charset utf8mb4_unicode_ci
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------------------------------
-- 1. Ajuste em empresa: Flag is_master_sistema (Bypass para Aeonium Energia)
-- -----------------------------------------------------------------------------
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
      AND TABLE_NAME = 'empresa' 
      AND COLUMN_NAME = 'is_master_sistema'
);

SET @sql_col = IF(@col_exists = 0, 
    'ALTER TABLE `empresa` ADD COLUMN `is_master_sistema` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_integradora`', 
    'SELECT "Coluna is_master_sistema ja existe" AS status'
);
PREPARE stmt_col FROM @sql_col;
EXECUTE stmt_col;
DEALLOCATE PREPARE stmt_col;

UPDATE `empresa` SET `is_master_sistema` = 1 WHERE `id` = 1;

-- -----------------------------------------------------------------------------
-- 2. Tabela de Grants Diretos: usuario_controlador_acesso
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `usuario_controlador_acesso` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `usuario_id` int(10) UNSIGNED NOT NULL,
  `controlador_id` int(11) NOT NULL,
  `papel` enum('proprietario','visualizador') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'proprietario',
  `ativo` tinyint(1) NOT NULL DEFAULT '1',
  `criado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `criado_por` int(10) UNSIGNED DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_usuario_controlador` (`usuario_id`,`controlador_id`),
  KEY `idx_uca_usuario` (`usuario_id`),
  KEY `idx_uca_controlador` (`controlador_id`),
  CONSTRAINT `fk_uca_controlador` FOREIGN KEY (`controlador_id`) REFERENCES `controladores` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_uca_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 3. Tabela Associativa 1:N: controlador_inversores
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `controlador_inversores` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `controlador_id` int(11) NOT NULL,
  `inversor_sn` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `marca` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'FOX-ESS',
  `tipo_equipamento` enum('inversor','bateria','medidor') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'inversor',
  `status` enum('ativo','substituido','inativo') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ativo',
  `inversor_ref_id` int(11) DEFAULT NULL COMMENT 'FK de referencia para inversores.id quando marca=SOLIS',
  `substituido_em` datetime DEFAULT NULL,
  `substituido_por_id` int(11) DEFAULT NULL COMMENT 'Auto-referencia para o ID que substituiu este equipamento',
  `criado_em` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_inversor_sn` (`inversor_sn`),
  KEY `idx_ctrl` (`controlador_id`),
  KEY `idx_inv_ref` (`inversor_ref_id`),
  CONSTRAINT `fk_ci_controlador` FOREIGN KEY (`controlador_id`) REFERENCES `controladores` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 4. Tabela de Segregação por Marca: telemetria_5min_marca
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `telemetria_5min_marca` (
  `controlador_id` int(11) NOT NULL,
  `timestamp_utc` datetime NOT NULL,
  `marca` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `potencia_geracao_w` decimal(10,2) NOT NULL DEFAULT '0.00',
  `energia_geracao_kwh` decimal(10,4) NOT NULL DEFAULT '0.0000',
  `atualizado_em` datetime NOT NULL,
  PRIMARY KEY (`controlador_id`,`timestamp_utc`,`marca`),
  KEY `idx_ctrl_ts` (`controlador_id`,`timestamp_utc`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 5. Tabela Granular de Equipamentos: telemetria_equipamento_5min
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `telemetria_equipamento_5min` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `controlador_inversor_id` int(10) UNSIGNED NOT NULL,
  `timestamp_utc` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `potencia_geracao_w` decimal(10,2) DEFAULT NULL,
  `potencia_consumo_w` decimal(10,2) DEFAULT NULL,
  `potencia_exportada_w` decimal(10,2) DEFAULT NULL,
  `potencia_importada_w` decimal(10,2) DEFAULT NULL,
  `energia_hoje_kwh` decimal(10,3) DEFAULT NULL COMMENT 'todayYield acumulado do dia em kWh (referencia para delta)',
  `energia_total_kwh` decimal(14,3) DEFAULT NULL COMMENT 'generation / PVEnergyTotal vitalicio acumulado em kWh',
  `energia_delta_kwh` decimal(10,4) DEFAULT NULL COMMENT 'Delta de geracao no ciclo de 5min em kWh',
  `soc_percentual` decimal(5,2) DEFAULT NULL,
  `potencia_bateria_w` decimal(10,2) DEFAULT NULL,
  `status_equipamento` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `temperatura_c` decimal(5,2) DEFAULT NULL,
  `qualidade_dado` tinyint(3) UNSIGNED DEFAULT 100,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ci_ts` (`controlador_inversor_id`,`timestamp_utc`),
  KEY `idx_ci_ts` (`controlador_inversor_id`,`timestamp_utc`),
  CONSTRAINT `fk_teq_ci` FOREIGN KEY (`controlador_inversor_id`) REFERENCES `controlador_inversores` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
