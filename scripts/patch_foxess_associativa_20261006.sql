-- ============================================================
-- Arquivo   : scripts/patch_foxess_associativa_20261006.sql
-- Projeto   : CIP — Controlador de Injeção de Potência Elétrica
-- Versão    : 1.0.0
-- Data      : 2026-10-06
-- Objetivo  : Reestruturação FoxESS para modelo associativo 1:N
-- ============================================================

-- 1. Remoção do campo 1:1 anterior em controladores
ALTER TABLE `controladores` DROP KEY `uq_fox_device_sn`;
ALTER TABLE `controladores` DROP COLUMN `fox_device_sn`;

-- 2. Criação da tabela associativa controlador_inversores
CREATE TABLE IF NOT EXISTS `controlador_inversores` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `controlador_id` INT NOT NULL,
  `inversor_sn` VARCHAR(64) NOT NULL,
  `marca` VARCHAR(30) NOT NULL DEFAULT 'FOX-ESS',
  `status` ENUM('ativo','inativo') NOT NULL DEFAULT 'ativo',
  `criado_em` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_inversor_sn` (`inversor_sn`),
  KEY `idx_ctrl` (`controlador_id`),
  CONSTRAINT `fk_ci_controlador` FOREIGN KEY (`controlador_id`) REFERENCES `controladores` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Cadastro das empresas clientes
INSERT INTO `empresa` (`nome_fantasia`, `tipo`, `ativo`) VALUES
  ('Waldemir de Paulo', 'cliente_real', 1),
  ('Caio Mota', 'cliente_real', 1),
  ('Leo Bischoff', 'cliente_real', 1);

-- 4. Cadastro dos controladores virtuais (IDs 7, 8, 9)
-- Waldemir de Paulo (empresa_id = 2)
INSERT INTO `controladores`
  (`codigo`, `apelido`, `tipo`, `timezone`, `empresa_id`, `token_api_hash`, `status`, `modo_controle`)
VALUES
  ('FOX-VIR-0001', 'Fox ESS - Waldemir de Paulo', 'FOX-ESS-CLOUD', 'America/Sao_Paulo', 2, SHA2(CONCAT('fox-ctrl:', 'FOX-VIR-0001'), 256), 'ativo', 'desativado'),
  ('FOX-VIR-0002', 'Fox ESS - Caio Mota', 'FOX-ESS-CLOUD', 'America/Sao_Paulo', 3, SHA2(CONCAT('fox-ctrl:', 'FOX-VIR-0002'), 256), 'ativo', 'desativado'),
  ('FOX-VIR-0003', 'Fox ESS - Leo Bischoff', 'FOX-ESS-CLOUD', 'America/Sao_Paulo', 4, SHA2(CONCAT('fox-ctrl:', 'FOX-VIR-0003'), 256), 'ativo', 'desativado');

-- 5. Vínculo dos 9 inversores reais em controlador_inversores
INSERT INTO `controlador_inversores` (`controlador_id`, `inversor_sn`, `marca`, `status`) VALUES
  -- Waldemir de Paulo (FOX-VIR-0001 -> id 7)
  (7, '60Q1252054LK326', 'FOX-ESS', 'ativo'),
  (7, '60Q1252054MR535', 'FOX-ESS', 'ativo'),
  (7, '60Q1252054PR004', 'FOX-ESS', 'ativo'),
  -- Caio Mota (FOX-VIR-0002 -> id 8)
  (8, '60Q1252057JR301', 'FOX-ESS', 'ativo'),
  (8, '60Q1252057KR116', 'FOX-ESS', 'ativo'),
  (8, '60Q1252057KR128', 'FOX-ESS', 'ativo'),
  -- Leo Bischoff (FOX-VIR-0003 -> id 9)
  (9, '60Q12520587R729', 'FOX-ESS', 'ativo'),
  (9, '60Q12520587R746', 'FOX-ESS', 'ativo'),
  (9, '60Q1252058AR467', 'FOX-ESS', 'ativo');

-- 6. Consulta de validação dos vínculos
SELECT 
  c.id AS controlador_id,
  c.codigo AS controlador_codigo,
  c.apelido AS controlador_apelido,
  e.id AS empresa_id,
  e.nome_fantasia AS empresa_nome,
  ci.id AS rel_id,
  ci.inversor_sn,
  ci.marca,
  ci.status AS inversor_status
FROM `controladores` c
JOIN `empresa` e ON e.id = c.empresa_id
JOIN `controlador_inversores` ci ON ci.controlador_id = c.id
ORDER BY c.id ASC, ci.id ASC;
