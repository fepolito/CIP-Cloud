-- @arquivo       docs/sql/tabela_limites.sql
-- @versao        1.0.0
-- @modificado_em 2026-07-25
-- @objetivo      DDL canônico das tabelas de limites (Fase 2). Fonte da
--                verdade DEV<->PROD. Espelha SHOW CREATE do PROD (MySQL 5.7.44).
-- @autor         Fernando / CIP Cloud Copilot / ATGY

SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS `tabela_limites` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `controlador_id` int(11) NOT NULL,
  `versao` int(10) unsigned NOT NULL DEFAULT '1',
  `payload_json` json NOT NULL,
  `origem` enum('local','cloud','app') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'cloud',
  `usuario_id` int(10) unsigned DEFAULT NULL,
  `sync_status` enum('sincronizada','pendente_ack','timeout','divergente') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pendente_ack',
  `ativa` tinyint(1) NOT NULL DEFAULT '1',
  `ativa_uk` int(11) GENERATED ALWAYS AS (if((`ativa` = 1),`controlador_id`,NULL)) VIRTUAL,
  `criada_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `aplicada_em` datetime DEFAULT NULL,
  `ack_em` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_uma_ativa_por_controlador` (`ativa_uk`),
  KEY `idx_controlador` (`controlador_id`),
  KEY `idx_usuario` (`usuario_id`),
  CONSTRAINT `fk_limites_controlador` FOREIGN KEY (`controlador_id`) REFERENCES `controladores` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tabela_limites_historico` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `controlador_id` int(11) NOT NULL,
  `versao` int(10) unsigned NOT NULL,
  `payload_json` json NOT NULL,
  `origem` enum('local','cloud','app') COLLATE utf8mb4_unicode_ci NOT NULL,
  `usuario_id` int(10) unsigned DEFAULT NULL,
  `sync_status_final` enum('sincronizada','pendente_ack','timeout','divergente') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `arquivada_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ctrl_versao` (`controlador_id`,`versao`),
  KEY `idx_usuario` (`usuario_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
