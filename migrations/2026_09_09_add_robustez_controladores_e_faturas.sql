-- ============================================================
-- PROJETO   : CIP - Monitor de Energia
-- MIGRATION : add_robustez_controladores_e_faturas
-- DATA      : 2026-09-09
-- ============================================================

ALTER TABLE `controladores`
  ADD COLUMN `tipo_ligacao` ENUM('monofasico','bifasico','trifasico')
    NOT NULL DEFAULT 'trifasico' AFTER `modalidade_compensacao`,
  ADD COLUMN `custo_disponibilidade_kwh` SMALLINT UNSIGNED
    NOT NULL DEFAULT 100 AFTER `tipo_ligacao`,
  ADD COLUMN `delta_max_kwh` DECIMAL(6,3) UNSIGNED
    NOT NULL DEFAULT 2.000 AFTER `custo_disponibilidade_kwh`;

CREATE TABLE IF NOT EXISTS `faturas_distribuidora` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `empresa_id` int NOT NULL,
  `controlador_id` int NOT NULL,
  `distribuidora` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'CPFL',
  `numero_instalacao` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mes_referencia` char(7) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'AAAA-MM',
  `data_leitura_ant` date NOT NULL,
  `data_leitura_atual` date NOT NULL,
  `dias_faturados` smallint unsigned DEFAULT NULL,
  `energia_importada_kwh` decimal(12,4) NOT NULL DEFAULT '0.0000',
  `energia_injetada_kwh` decimal(12,4) NOT NULL DEFAULT '0.0000',
  `leitura_ant_registro` decimal(12,4) DEFAULT NULL COMMENT 'Registro do medidor CPFL (opcional)',
  `leitura_atual_registro` decimal(12,4) DEFAULT NULL,
  `observacao` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `criado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `atualizado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ctrl_mes` (`controlador_id`,`mes_referencia`),
  KEY `idx_empresa` (`empresa_id`),
  CONSTRAINT `fk_fatura_controlador` FOREIGN KEY (`controlador_id`) REFERENCES `controladores` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
