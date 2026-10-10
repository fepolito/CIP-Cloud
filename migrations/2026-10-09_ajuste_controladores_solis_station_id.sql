-- =============================================================================
-- Migration: Ajuste da tabela controladores em PROD para paridade total com DEV
-- Data     : 2026-10-09
-- Objetivo : Adiciona coluna solis_station_id (com índice UNIQUE) e refina os
--            tipos das colunas de controle (modo_controle, controle_versao, etc.)
-- =============================================================================

-- 1. Inserir solis_station_id e índice UNIQUE (posicionada após a coluna 'tipo')
ALTER TABLE `controladores`
  ADD COLUMN `solis_station_id` VARCHAR(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `tipo`,
  ADD UNIQUE KEY `solis_station_id` (`solis_station_id`);

-- 2. Sanitizar valores existentes e refinar modo_controle para ENUM restritivo
UPDATE `controladores`
  SET `modo_controle` = 'desativado'
  WHERE `modo_controle` NOT IN ('grid_zero', 'limite_tabela', 'desativado');

ALTER TABLE `controladores`
  MODIFY COLUMN `modo_controle` ENUM('grid_zero', 'limite_tabela', 'desativado') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'desativado'
  COMMENT 'grid_zero=injecao zero; limite_tabela=usa tabela horaria; desativado=sem controle';

-- 3. Sanitizar e refinar controle_versao para INT UNSIGNED NOT NULL DEFAULT 0
UPDATE `controladores`
  SET `controle_versao` = 0
  WHERE `controle_versao` IS NULL OR `controle_versao` < 0;

ALTER TABLE `controladores`
  MODIFY COLUMN `controle_versao` INT UNSIGNED NOT NULL DEFAULT 0
  COMMENT 'Versao incremental do estado de controle (para sync firmware<->cloud)';

-- 4. Refinar controle_atualizado_em para permitir NULL inicial (sem CURRENT_TIMESTAMP forçado)
ALTER TABLE `controladores`
  MODIFY COLUMN `controle_atualizado_em` DATETIME DEFAULT NULL
  COMMENT 'Timestamp UTC da ultima alteracao do estado de controle';

-- 5. Sanitizar valores existentes e refinar controle_origem para ENUM
UPDATE `controladores`
  SET `controle_origem` = NULL
  WHERE `controle_origem` NOT IN ('local', 'cloud', 'app', 'firmware_boot');

ALTER TABLE `controladores`
  MODIFY COLUMN `controle_origem` ENUM('local', 'cloud', 'app', 'firmware_boot') COLLATE utf8mb4_unicode_ci DEFAULT NULL
  COMMENT 'Origem da ultima alteracao do estado de controle';
