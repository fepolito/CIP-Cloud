-- =====================================================================
-- MIGRAÇÃO CONSOLIDADA: Solis Multi-Marca para PRODUÇÃO
-- Data: 2026-10-10 21:52:33
-- Objetivo: Criar 25 empresas e 25 controladores virtuais SOLIS-CLOUD,
--           vincular inversores e popular controlador_inversores.
-- Operação: Idempotente (pode rodar mais de uma vez com segurança)
-- =====================================================================

START TRANSACTION;

-- -----------------------------------------------------------------
-- 1. Planta: Ana Maciel (Station ID: 1298491919448959556)
-- -----------------------------------------------------------------
INSERT INTO `empresa` (`nome_fantasia`, `tipo`, `empresa_pai_id`, `ativo`, `created_at`, `updated_at`)
SELECT 'Ana Maciel', 'cliente_real', 1, 1, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `empresa` WHERE `nome_fantasia` = 'Ana Maciel' AND `deleted_at` IS NULL);

INSERT INTO `controladores` (`codigo`, `apelido`, `tipo`, `empresa_id`, `solis_station_id`, `token_api_hash`, `status`, `timezone`, `modo_controle`, `created_at`, `updated_at`)
SELECT 'SOLIS-VIR-0001', 'Solis - Ana Maciel', 'SOLIS-CLOUD', e.id, '1298491919448959556', '23debbe58dea7a1aee282916441d8862778d0a1ff517d0ae6736c7796659d529', 'ativo', 'America/Sao_Paulo', 'desativado', NOW(), NOW()
FROM `empresa` e
WHERE e.nome_fantasia = 'Ana Maciel' AND e.deleted_at IS NULL
  AND NOT EXISTS (SELECT 1 FROM `controladores` WHERE `solis_station_id` = '1298491919448959556' AND `status` <> 'inativo');

UPDATE `inversores` i
JOIN `controladores` c ON c.solis_station_id = '1298491919448959556'
SET i.controlador_id = c.id, i.empresa_id = c.empresa_id
WHERE i.station_id = '1298491919448959556';

INSERT INTO `controlador_inversores` (`controlador_id`, `inversor_sn`, `marca`, `tipo_equipamento`, `status`, `inversor_ref_id`, `criado_em`)
SELECT c.id, '100206021A130438', 'SOLIS', 'inversor', 'ativo', 21, NOW()
FROM `controladores` c
WHERE c.solis_station_id = '1298491919448959556'
ON DUPLICATE KEY UPDATE `controlador_id` = VALUES(`controlador_id`), `status` = 'ativo', `inversor_ref_id` = VALUES(`inversor_ref_id`);

-- -----------------------------------------------------------------
-- 2. Planta: Av. Rio de janeiro 581 casa 1 (Station ID: 1298491919448959550)
-- -----------------------------------------------------------------
INSERT INTO `empresa` (`nome_fantasia`, `tipo`, `empresa_pai_id`, `ativo`, `created_at`, `updated_at`)
SELECT 'Av. Rio de janeiro 581 casa 1', 'cliente_real', 1, 1, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `empresa` WHERE `nome_fantasia` = 'Av. Rio de janeiro 581 casa 1' AND `deleted_at` IS NULL);

INSERT INTO `controladores` (`codigo`, `apelido`, `tipo`, `empresa_id`, `solis_station_id`, `token_api_hash`, `status`, `timezone`, `modo_controle`, `created_at`, `updated_at`)
SELECT 'SOLIS-VIR-0002', 'Solis - Av. Rio de janeiro 581 casa 1', 'SOLIS-CLOUD', e.id, '1298491919448959550', 'add60226787f39698f7b599409506d95c4bfa33897184668ba51715d5d9a0fff', 'ativo', 'America/Sao_Paulo', 'desativado', NOW(), NOW()
FROM `empresa` e
WHERE e.nome_fantasia = 'Av. Rio de janeiro 581 casa 1' AND e.deleted_at IS NULL
  AND NOT EXISTS (SELECT 1 FROM `controladores` WHERE `solis_station_id` = '1298491919448959550' AND `status` <> 'inativo');

UPDATE `inversores` i
JOIN `controladores` c ON c.solis_station_id = '1298491919448959550'
SET i.controlador_id = c.id, i.empresa_id = c.empresa_id
WHERE i.station_id = '1298491919448959550';

INSERT INTO `controlador_inversores` (`controlador_id`, `inversor_sn`, `marca`, `tipo_equipamento`, `status`, `inversor_ref_id`, `criado_em`)
SELECT c.id, '110D0221B270006', 'SOLIS', 'inversor', 'ativo', 19, NOW()
FROM `controladores` c
WHERE c.solis_station_id = '1298491919448959550'
ON DUPLICATE KEY UPDATE `controlador_id` = VALUES(`controlador_id`), `status` = 'ativo', `inversor_ref_id` = VALUES(`inversor_ref_id`);

-- -----------------------------------------------------------------
-- 3. Planta: Barney (Station ID: 1298491919448959560)
-- -----------------------------------------------------------------
INSERT INTO `empresa` (`nome_fantasia`, `tipo`, `empresa_pai_id`, `ativo`, `created_at`, `updated_at`)
SELECT 'Barney', 'cliente_real', 1, 1, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `empresa` WHERE `nome_fantasia` = 'Barney' AND `deleted_at` IS NULL);

INSERT INTO `controladores` (`codigo`, `apelido`, `tipo`, `empresa_id`, `solis_station_id`, `token_api_hash`, `status`, `timezone`, `modo_controle`, `created_at`, `updated_at`)
SELECT 'SOLIS-VIR-0003', 'Solis - Barney', 'SOLIS-CLOUD', e.id, '1298491919448959560', '5d3673f9a71c49aad25ceb5f64601287a8d323ad6b1d413634b657395a90dd90', 'ativo', 'America/Sao_Paulo', 'desativado', NOW(), NOW()
FROM `empresa` e
WHERE e.nome_fantasia = 'Barney' AND e.deleted_at IS NULL
  AND NOT EXISTS (SELECT 1 FROM `controladores` WHERE `solis_station_id` = '1298491919448959560' AND `status` <> 'inativo');

UPDATE `inversores` i
JOIN `controladores` c ON c.solis_station_id = '1298491919448959560'
SET i.controlador_id = c.id, i.empresa_id = c.empresa_id
WHERE i.station_id = '1298491919448959560';

INSERT INTO `controlador_inversores` (`controlador_id`, `inversor_sn`, `marca`, `tipo_equipamento`, `status`, `inversor_ref_id`, `criado_em`)
SELECT c.id, '100105021C220234', 'SOLIS', 'inversor', 'ativo', 10, NOW()
FROM `controladores` c
WHERE c.solis_station_id = '1298491919448959560'
ON DUPLICATE KEY UPDATE `controlador_id` = VALUES(`controlador_id`), `status` = 'ativo', `inversor_ref_id` = VALUES(`inversor_ref_id`);
INSERT INTO `controlador_inversores` (`controlador_id`, `inversor_sn`, `marca`, `tipo_equipamento`, `status`, `inversor_ref_id`, `criado_em`)
SELECT c.id, '100105021C060048', 'SOLIS', 'inversor', 'ativo', 18, NOW()
FROM `controladores` c
WHERE c.solis_station_id = '1298491919448959560'
ON DUPLICATE KEY UPDATE `controlador_id` = VALUES(`controlador_id`), `status` = 'ativo', `inversor_ref_id` = VALUES(`inversor_ref_id`);

-- -----------------------------------------------------------------
-- 4. Planta: Bessa Madruga PV (Station ID: 1298491919448959534)
-- -----------------------------------------------------------------
INSERT INTO `empresa` (`nome_fantasia`, `tipo`, `empresa_pai_id`, `ativo`, `created_at`, `updated_at`)
SELECT 'Bessa Madruga PV', 'cliente_real', 1, 1, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `empresa` WHERE `nome_fantasia` = 'Bessa Madruga PV' AND `deleted_at` IS NULL);

INSERT INTO `controladores` (`codigo`, `apelido`, `tipo`, `empresa_id`, `solis_station_id`, `token_api_hash`, `status`, `timezone`, `modo_controle`, `created_at`, `updated_at`)
SELECT 'SOLIS-VIR-0004', 'Solis - Bessa Madruga PV', 'SOLIS-CLOUD', e.id, '1298491919448959534', '9da725ad1383a4428c8bd1980d6818dacf1cfc98d90bd487b5e3fb2ffa414a93', 'ativo', 'America/Sao_Paulo', 'desativado', NOW(), NOW()
FROM `empresa` e
WHERE e.nome_fantasia = 'Bessa Madruga PV' AND e.deleted_at IS NULL
  AND NOT EXISTS (SELECT 1 FROM `controladores` WHERE `solis_station_id` = '1298491919448959534' AND `status` <> 'inativo');

UPDATE `inversores` i
JOIN `controladores` c ON c.solis_station_id = '1298491919448959534'
SET i.controlador_id = c.id, i.empresa_id = c.empresa_id
WHERE i.station_id = '1298491919448959534';

INSERT INTO `controlador_inversores` (`controlador_id`, `inversor_sn`, `marca`, `tipo_equipamento`, `status`, `inversor_ref_id`, `criado_em`)
SELECT c.id, '110AC1211220235', 'SOLIS', 'inversor', 'ativo', 55, NOW()
FROM `controladores` c
WHERE c.solis_station_id = '1298491919448959534'
ON DUPLICATE KEY UPDATE `controlador_id` = VALUES(`controlador_id`), `status` = 'ativo', `inversor_ref_id` = VALUES(`inversor_ref_id`);

-- -----------------------------------------------------------------
-- 5. Planta: Casa Gabi (Station ID: 1298491919448959547)
-- -----------------------------------------------------------------
INSERT INTO `empresa` (`nome_fantasia`, `tipo`, `empresa_pai_id`, `ativo`, `created_at`, `updated_at`)
SELECT 'Casa Gabi', 'cliente_real', 1, 1, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `empresa` WHERE `nome_fantasia` = 'Casa Gabi' AND `deleted_at` IS NULL);

INSERT INTO `controladores` (`codigo`, `apelido`, `tipo`, `empresa_id`, `solis_station_id`, `token_api_hash`, `status`, `timezone`, `modo_controle`, `created_at`, `updated_at`)
SELECT 'SOLIS-VIR-0005', 'Solis - Casa Gabi', 'SOLIS-CLOUD', e.id, '1298491919448959547', '645309eda9d5e1351dcc5240028611678a741407a4e1829f0952101fc6b56664', 'ativo', 'America/Sao_Paulo', 'desativado', NOW(), NOW()
FROM `empresa` e
WHERE e.nome_fantasia = 'Casa Gabi' AND e.deleted_at IS NULL
  AND NOT EXISTS (SELECT 1 FROM `controladores` WHERE `solis_station_id` = '1298491919448959547' AND `status` <> 'inativo');

UPDATE `inversores` i
JOIN `controladores` c ON c.solis_station_id = '1298491919448959547'
SET i.controlador_id = c.id, i.empresa_id = c.empresa_id
WHERE i.station_id = '1298491919448959547';

INSERT INTO `controlador_inversores` (`controlador_id`, `inversor_sn`, `marca`, `tipo_equipamento`, `status`, `inversor_ref_id`, `criado_em`)
SELECT c.id, '100203021C080053', 'SOLIS', 'inversor', 'ativo', 17, NOW()
FROM `controladores` c
WHERE c.solis_station_id = '1298491919448959547'
ON DUPLICATE KEY UPDATE `controlador_id` = VALUES(`controlador_id`), `status` = 'ativo', `inversor_ref_id` = VALUES(`inversor_ref_id`);
INSERT INTO `controlador_inversores` (`controlador_id`, `inversor_sn`, `marca`, `tipo_equipamento`, `status`, `inversor_ref_id`, `criado_em`)
SELECT c.id, '100203021C08005', 'SOLIS', 'inversor', 'ativo', 30, NOW()
FROM `controladores` c
WHERE c.solis_station_id = '1298491919448959547'
ON DUPLICATE KEY UPDATE `controlador_id` = VALUES(`controlador_id`), `status` = 'ativo', `inversor_ref_id` = VALUES(`inversor_ref_id`);

-- -----------------------------------------------------------------
-- 6. Planta: Felipe Alencar (Station ID: 1298491919448959544)
-- -----------------------------------------------------------------
INSERT INTO `empresa` (`nome_fantasia`, `tipo`, `empresa_pai_id`, `ativo`, `created_at`, `updated_at`)
SELECT 'Felipe Alencar', 'cliente_real', 1, 1, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `empresa` WHERE `nome_fantasia` = 'Felipe Alencar' AND `deleted_at` IS NULL);

INSERT INTO `controladores` (`codigo`, `apelido`, `tipo`, `empresa_id`, `solis_station_id`, `token_api_hash`, `status`, `timezone`, `modo_controle`, `created_at`, `updated_at`)
SELECT 'SOLIS-VIR-0006', 'Solis - Felipe Alencar', 'SOLIS-CLOUD', e.id, '1298491919448959544', '3c8ac3c90b637815d3eae20dbb1cf928688fef09e11efa2642097e102e09fe01', 'ativo', 'America/Sao_Paulo', 'desativado', NOW(), NOW()
FROM `empresa` e
WHERE e.nome_fantasia = 'Felipe Alencar' AND e.deleted_at IS NULL
  AND NOT EXISTS (SELECT 1 FROM `controladores` WHERE `solis_station_id` = '1298491919448959544' AND `status` <> 'inativo');

UPDATE `inversores` i
JOIN `controladores` c ON c.solis_station_id = '1298491919448959544'
SET i.controlador_id = c.id, i.empresa_id = c.empresa_id
WHERE i.station_id = '1298491919448959544';

INSERT INTO `controlador_inversores` (`controlador_id`, `inversor_sn`, `marca`, `tipo_equipamento`, `status`, `inversor_ref_id`, `criado_em`)
SELECT c.id, '1804030243140143', 'SOLIS', 'inversor', 'ativo', 4, NOW()
FROM `controladores` c
WHERE c.solis_station_id = '1298491919448959544'
ON DUPLICATE KEY UPDATE `controlador_id` = VALUES(`controlador_id`), `status` = 'ativo', `inversor_ref_id` = VALUES(`inversor_ref_id`);
INSERT INTO `controlador_inversores` (`controlador_id`, `inversor_sn`, `marca`, `tipo_equipamento`, `status`, `inversor_ref_id`, `criado_em`)
SELECT c.id, '180203021C291952', 'SOLIS', 'inversor', 'ativo', 9, NOW()
FROM `controladores` c
WHERE c.solis_station_id = '1298491919448959544'
ON DUPLICATE KEY UPDATE `controlador_id` = VALUES(`controlador_id`), `status` = 'ativo', `inversor_ref_id` = VALUES(`inversor_ref_id`);
INSERT INTO `controlador_inversores` (`controlador_id`, `inversor_sn`, `marca`, `tipo_equipamento`, `status`, `inversor_ref_id`, `criado_em`)
SELECT c.id, '110AF1216280029', 'SOLIS', 'inversor', 'ativo', 50, NOW()
FROM `controladores` c
WHERE c.solis_station_id = '1298491919448959544'
ON DUPLICATE KEY UPDATE `controlador_id` = VALUES(`controlador_id`), `status` = 'ativo', `inversor_ref_id` = VALUES(`inversor_ref_id`);

-- -----------------------------------------------------------------
-- 7. Planta: Ingo Thaler (Station ID: 1298491919448959548)
-- -----------------------------------------------------------------
INSERT INTO `empresa` (`nome_fantasia`, `tipo`, `empresa_pai_id`, `ativo`, `created_at`, `updated_at`)
SELECT 'Ingo Thaler', 'cliente_real', 1, 1, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `empresa` WHERE `nome_fantasia` = 'Ingo Thaler' AND `deleted_at` IS NULL);

INSERT INTO `controladores` (`codigo`, `apelido`, `tipo`, `empresa_id`, `solis_station_id`, `token_api_hash`, `status`, `timezone`, `modo_controle`, `created_at`, `updated_at`)
SELECT 'SOLIS-VIR-0007', 'Solis - Ingo Thaler', 'SOLIS-CLOUD', e.id, '1298491919448959548', 'fc4aab29a2477782983990f01ec02288d0bbd93340d6f4a09299cecd9aef323b', 'ativo', 'America/Sao_Paulo', 'desativado', NOW(), NOW()
FROM `empresa` e
WHERE e.nome_fantasia = 'Ingo Thaler' AND e.deleted_at IS NULL
  AND NOT EXISTS (SELECT 1 FROM `controladores` WHERE `solis_station_id` = '1298491919448959548' AND `status` <> 'inativo');

UPDATE `inversores` i
JOIN `controladores` c ON c.solis_station_id = '1298491919448959548'
SET i.controlador_id = c.id, i.empresa_id = c.empresa_id
WHERE i.station_id = '1298491919448959548';

INSERT INTO `controlador_inversores` (`controlador_id`, `inversor_sn`, `marca`, `tipo_equipamento`, `status`, `inversor_ref_id`, `criado_em`)
SELECT c.id, '180105021C280785', 'SOLIS', 'inversor', 'ativo', 7, NOW()
FROM `controladores` c
WHERE c.solis_station_id = '1298491919448959548'
ON DUPLICATE KEY UPDATE `controlador_id` = VALUES(`controlador_id`), `status` = 'ativo', `inversor_ref_id` = VALUES(`inversor_ref_id`);

-- -----------------------------------------------------------------
-- 8. Planta: Jorge (Station ID: 1298491919448959559)
-- -----------------------------------------------------------------
INSERT INTO `empresa` (`nome_fantasia`, `tipo`, `empresa_pai_id`, `ativo`, `created_at`, `updated_at`)
SELECT 'Jorge', 'cliente_real', 1, 1, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `empresa` WHERE `nome_fantasia` = 'Jorge' AND `deleted_at` IS NULL);

INSERT INTO `controladores` (`codigo`, `apelido`, `tipo`, `empresa_id`, `solis_station_id`, `token_api_hash`, `status`, `timezone`, `modo_controle`, `created_at`, `updated_at`)
SELECT 'SOLIS-VIR-0008', 'Solis - Jorge', 'SOLIS-CLOUD', e.id, '1298491919448959559', 'e5ba5f547b1a94829a57a988eea88c90df99aa3bab31703ab8331b00a369c352', 'ativo', 'America/Sao_Paulo', 'desativado', NOW(), NOW()
FROM `empresa` e
WHERE e.nome_fantasia = 'Jorge' AND e.deleted_at IS NULL
  AND NOT EXISTS (SELECT 1 FROM `controladores` WHERE `solis_station_id` = '1298491919448959559' AND `status` <> 'inativo');

UPDATE `inversores` i
JOIN `controladores` c ON c.solis_station_id = '1298491919448959559'
SET i.controlador_id = c.id, i.empresa_id = c.empresa_id
WHERE i.station_id = '1298491919448959559';

INSERT INTO `controlador_inversores` (`controlador_id`, `inversor_sn`, `marca`, `tipo_equipamento`, `status`, `inversor_ref_id`, `criado_em`)
SELECT c.id, '180203021C291503', 'SOLIS', 'inversor', 'ativo', 11, NOW()
FROM `controladores` c
WHERE c.solis_station_id = '1298491919448959559'
ON DUPLICATE KEY UPDATE `controlador_id` = VALUES(`controlador_id`), `status` = 'ativo', `inversor_ref_id` = VALUES(`inversor_ref_id`);

-- -----------------------------------------------------------------
-- 9. Planta: Luiz C Mendes (Station ID: 1298491919449672612)
-- -----------------------------------------------------------------
INSERT INTO `empresa` (`nome_fantasia`, `tipo`, `empresa_pai_id`, `ativo`, `created_at`, `updated_at`)
SELECT 'Luiz C Mendes', 'cliente_real', 1, 1, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `empresa` WHERE `nome_fantasia` = 'Luiz C Mendes' AND `deleted_at` IS NULL);

INSERT INTO `controladores` (`codigo`, `apelido`, `tipo`, `empresa_id`, `solis_station_id`, `token_api_hash`, `status`, `timezone`, `modo_controle`, `created_at`, `updated_at`)
SELECT 'SOLIS-VIR-0009', 'Solis - Luiz C Mendes', 'SOLIS-CLOUD', e.id, '1298491919449672612', '72c1dc2b8b4d2a6825c11ed488b45858726297ff20812848840095e6b98b4927', 'ativo', 'America/Sao_Paulo', 'desativado', NOW(), NOW()
FROM `empresa` e
WHERE e.nome_fantasia = 'Luiz C Mendes' AND e.deleted_at IS NULL
  AND NOT EXISTS (SELECT 1 FROM `controladores` WHERE `solis_station_id` = '1298491919449672612' AND `status` <> 'inativo');

UPDATE `inversores` i
JOIN `controladores` c ON c.solis_station_id = '1298491919449672612'
SET i.controlador_id = c.id, i.empresa_id = c.empresa_id
WHERE i.station_id = '1298491919449672612';

INSERT INTO `controlador_inversores` (`controlador_id`, `inversor_sn`, `marca`, `tipo_equipamento`, `status`, `inversor_ref_id`, `criado_em`)
SELECT c.id, '1801150244070017', 'SOLIS', 'inversor', 'ativo', 3, NOW()
FROM `controladores` c
WHERE c.solis_station_id = '1298491919449672612'
ON DUPLICATE KEY UPDATE `controlador_id` = VALUES(`controlador_id`), `status` = 'ativo', `inversor_ref_id` = VALUES(`inversor_ref_id`);

-- -----------------------------------------------------------------
-- 10. Planta: Magni América do Sul (Station ID: 1298491919449617735)
-- -----------------------------------------------------------------
INSERT INTO `empresa` (`nome_fantasia`, `tipo`, `empresa_pai_id`, `ativo`, `created_at`, `updated_at`)
SELECT 'Magni América do Sul', 'cliente_real', 1, 1, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `empresa` WHERE `nome_fantasia` = 'Magni América do Sul' AND `deleted_at` IS NULL);

INSERT INTO `controladores` (`codigo`, `apelido`, `tipo`, `empresa_id`, `solis_station_id`, `token_api_hash`, `status`, `timezone`, `modo_controle`, `created_at`, `updated_at`)
SELECT 'SOLIS-VIR-0010', 'Solis - Magni América do Sul', 'SOLIS-CLOUD', e.id, '1298491919449617735', '1683ec2c27cf9d1c1a7cd44482801c5f98d0c78bab29bfb68fa8c415d4291759', 'ativo', 'America/Sao_Paulo', 'desativado', NOW(), NOW()
FROM `empresa` e
WHERE e.nome_fantasia = 'Magni América do Sul' AND e.deleted_at IS NULL
  AND NOT EXISTS (SELECT 1 FROM `controladores` WHERE `solis_station_id` = '1298491919449617735' AND `status` <> 'inativo');

UPDATE `inversores` i
JOIN `controladores` c ON c.solis_station_id = '1298491919449617735'
SET i.controlador_id = c.id, i.empresa_id = c.empresa_id
WHERE i.station_id = '1298491919449617735';

INSERT INTO `controlador_inversores` (`controlador_id`, `inversor_sn`, `marca`, `tipo_equipamento`, `status`, `inversor_ref_id`, `criado_em`)
SELECT c.id, '1018FB023A150011', 'SOLIS', 'inversor', 'ativo', 5, NOW()
FROM `controladores` c
WHERE c.solis_station_id = '1298491919449617735'
ON DUPLICATE KEY UPDATE `controlador_id` = VALUES(`controlador_id`), `status` = 'ativo', `inversor_ref_id` = VALUES(`inversor_ref_id`);

-- -----------------------------------------------------------------
-- 11. Planta: Mané - Casa (Station ID: 1298491919448959539)
-- -----------------------------------------------------------------
INSERT INTO `empresa` (`nome_fantasia`, `tipo`, `empresa_pai_id`, `ativo`, `created_at`, `updated_at`)
SELECT 'Mané - Casa', 'cliente_real', 1, 1, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `empresa` WHERE `nome_fantasia` = 'Mané - Casa' AND `deleted_at` IS NULL);

INSERT INTO `controladores` (`codigo`, `apelido`, `tipo`, `empresa_id`, `solis_station_id`, `token_api_hash`, `status`, `timezone`, `modo_controle`, `created_at`, `updated_at`)
SELECT 'SOLIS-VIR-0011', 'Solis - Mané - Casa', 'SOLIS-CLOUD', e.id, '1298491919448959539', 'c1ad71d3f8c14755490ef99d1e2f27ec19cada8f0d755dcbea65115fbd514bca', 'ativo', 'America/Sao_Paulo', 'desativado', NOW(), NOW()
FROM `empresa` e
WHERE e.nome_fantasia = 'Mané - Casa' AND e.deleted_at IS NULL
  AND NOT EXISTS (SELECT 1 FROM `controladores` WHERE `solis_station_id` = '1298491919448959539' AND `status` <> 'inativo');

UPDATE `inversores` i
JOIN `controladores` c ON c.solis_station_id = '1298491919448959539'
SET i.controlador_id = c.id, i.empresa_id = c.empresa_id
WHERE i.station_id = '1298491919448959539';

INSERT INTO `controlador_inversores` (`controlador_id`, `inversor_sn`, `marca`, `tipo_equipamento`, `status`, `inversor_ref_id`, `criado_em`)
SELECT c.id, '110AF1214070042', 'SOLIS', 'inversor', 'ativo', 53, NOW()
FROM `controladores` c
WHERE c.solis_station_id = '1298491919448959539'
ON DUPLICATE KEY UPDATE `controlador_id` = VALUES(`controlador_id`), `status` = 'ativo', `inversor_ref_id` = VALUES(`inversor_ref_id`);

-- -----------------------------------------------------------------
-- 12. Planta: Marcus Lains (Station ID: 1298491919448959542)
-- -----------------------------------------------------------------
INSERT INTO `empresa` (`nome_fantasia`, `tipo`, `empresa_pai_id`, `ativo`, `created_at`, `updated_at`)
SELECT 'Marcus Lains', 'cliente_real', 1, 1, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `empresa` WHERE `nome_fantasia` = 'Marcus Lains' AND `deleted_at` IS NULL);

INSERT INTO `controladores` (`codigo`, `apelido`, `tipo`, `empresa_id`, `solis_station_id`, `token_api_hash`, `status`, `timezone`, `modo_controle`, `created_at`, `updated_at`)
SELECT 'SOLIS-VIR-0012', 'Solis - Marcus Lains', 'SOLIS-CLOUD', e.id, '1298491919448959542', 'e3c67d5a696733aad54e89e59ff9f827a877fb51dc95db852327218bc931ab8c', 'ativo', 'America/Sao_Paulo', 'desativado', NOW(), NOW()
FROM `empresa` e
WHERE e.nome_fantasia = 'Marcus Lains' AND e.deleted_at IS NULL
  AND NOT EXISTS (SELECT 1 FROM `controladores` WHERE `solis_station_id` = '1298491919448959542' AND `status` <> 'inativo');

UPDATE `inversores` i
JOIN `controladores` c ON c.solis_station_id = '1298491919448959542'
SET i.controlador_id = c.id, i.empresa_id = c.empresa_id
WHERE i.station_id = '1298491919448959542';

INSERT INTO `controlador_inversores` (`controlador_id`, `inversor_sn`, `marca`, `tipo_equipamento`, `status`, `inversor_ref_id`, `criado_em`)
SELECT c.id, '110AC1216280007', 'SOLIS', 'inversor', 'ativo', 51, NOW()
FROM `controladores` c
WHERE c.solis_station_id = '1298491919448959542'
ON DUPLICATE KEY UPDATE `controlador_id` = VALUES(`controlador_id`), `status` = 'ativo', `inversor_ref_id` = VALUES(`inversor_ref_id`);

-- -----------------------------------------------------------------
-- 13. Planta: Nelson Scarpato (Station ID: 1298491919448959555)
-- -----------------------------------------------------------------
INSERT INTO `empresa` (`nome_fantasia`, `tipo`, `empresa_pai_id`, `ativo`, `created_at`, `updated_at`)
SELECT 'Nelson Scarpato', 'cliente_real', 1, 1, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `empresa` WHERE `nome_fantasia` = 'Nelson Scarpato' AND `deleted_at` IS NULL);

INSERT INTO `controladores` (`codigo`, `apelido`, `tipo`, `empresa_id`, `solis_station_id`, `token_api_hash`, `status`, `timezone`, `modo_controle`, `created_at`, `updated_at`)
SELECT 'SOLIS-VIR-0013', 'Solis - Nelson Scarpato', 'SOLIS-CLOUD', e.id, '1298491919448959555', 'f6202556a0e085bd5ae5777e5c4c882eed355ec6b33ffe12434da51428098150', 'ativo', 'America/Sao_Paulo', 'desativado', NOW(), NOW()
FROM `empresa` e
WHERE e.nome_fantasia = 'Nelson Scarpato' AND e.deleted_at IS NULL
  AND NOT EXISTS (SELECT 1 FROM `controladores` WHERE `solis_station_id` = '1298491919448959555' AND `status` <> 'inativo');

UPDATE `inversores` i
JOIN `controladores` c ON c.solis_station_id = '1298491919448959555'
SET i.controlador_id = c.id, i.empresa_id = c.empresa_id
WHERE i.station_id = '1298491919448959555';

INSERT INTO `controlador_inversores` (`controlador_id`, `inversor_sn`, `marca`, `tipo_equipamento`, `status`, `inversor_ref_id`, `criado_em`)
SELECT c.id, '100205021C150106', 'SOLIS', 'inversor', 'ativo', 16, NOW()
FROM `controladores` c
WHERE c.solis_station_id = '1298491919448959555'
ON DUPLICATE KEY UPDATE `controlador_id` = VALUES(`controlador_id`), `status` = 'ativo', `inversor_ref_id` = VALUES(`inversor_ref_id`);

-- -----------------------------------------------------------------
-- 14. Planta: Padaria Corcovado (Station ID: 1298491919448959551)
-- -----------------------------------------------------------------
INSERT INTO `empresa` (`nome_fantasia`, `tipo`, `empresa_pai_id`, `ativo`, `created_at`, `updated_at`)
SELECT 'Padaria Corcovado', 'cliente_real', 1, 1, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `empresa` WHERE `nome_fantasia` = 'Padaria Corcovado' AND `deleted_at` IS NULL);

INSERT INTO `controladores` (`codigo`, `apelido`, `tipo`, `empresa_id`, `solis_station_id`, `token_api_hash`, `status`, `timezone`, `modo_controle`, `created_at`, `updated_at`)
SELECT 'SOLIS-VIR-0014', 'Solis - Padaria Corcovado', 'SOLIS-CLOUD', e.id, '1298491919448959551', '7f24e1283077c9cdc8941f11545d10361efb67b71f2daa8e2d78ca04edb90234', 'ativo', 'America/Sao_Paulo', 'desativado', NOW(), NOW()
FROM `empresa` e
WHERE e.nome_fantasia = 'Padaria Corcovado' AND e.deleted_at IS NULL
  AND NOT EXISTS (SELECT 1 FROM `controladores` WHERE `solis_station_id` = '1298491919448959551' AND `status` <> 'inativo');

UPDATE `inversores` i
JOIN `controladores` c ON c.solis_station_id = '1298491919448959551'
SET i.controlador_id = c.id, i.empresa_id = c.empresa_id
WHERE i.station_id = '1298491919448959551';

INSERT INTO `controlador_inversores` (`controlador_id`, `inversor_sn`, `marca`, `tipo_equipamento`, `status`, `inversor_ref_id`, `criado_em`)
SELECT c.id, '110D0221B270025', 'SOLIS', 'inversor', 'ativo', 20, NOW()
FROM `controladores` c
WHERE c.solis_station_id = '1298491919448959551'
ON DUPLICATE KEY UPDATE `controlador_id` = VALUES(`controlador_id`), `status` = 'ativo', `inversor_ref_id` = VALUES(`inversor_ref_id`);

-- -----------------------------------------------------------------
-- 15. Planta: Reinert (Station ID: 1298491919449207146)
-- -----------------------------------------------------------------
INSERT INTO `empresa` (`nome_fantasia`, `tipo`, `empresa_pai_id`, `ativo`, `created_at`, `updated_at`)
SELECT 'Reinert', 'cliente_real', 1, 1, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `empresa` WHERE `nome_fantasia` = 'Reinert' AND `deleted_at` IS NULL);

INSERT INTO `controladores` (`codigo`, `apelido`, `tipo`, `empresa_id`, `solis_station_id`, `token_api_hash`, `status`, `timezone`, `modo_controle`, `created_at`, `updated_at`)
SELECT 'SOLIS-VIR-0015', 'Solis - Reinert', 'SOLIS-CLOUD', e.id, '1298491919449207146', '59d2749a597255a9c96d8ca5c1c5c29085977a8bb660c503b52baf8b1f07dc35', 'ativo', 'America/Sao_Paulo', 'desativado', NOW(), NOW()
FROM `empresa` e
WHERE e.nome_fantasia = 'Reinert' AND e.deleted_at IS NULL
  AND NOT EXISTS (SELECT 1 FROM `controladores` WHERE `solis_station_id` = '1298491919449207146' AND `status` <> 'inativo');

UPDATE `inversores` i
JOIN `controladores` c ON c.solis_station_id = '1298491919449207146'
SET i.controlador_id = c.id, i.empresa_id = c.empresa_id
WHERE i.station_id = '1298491919449207146';

INSERT INTO `controlador_inversores` (`controlador_id`, `inversor_sn`, `marca`, `tipo_equipamento`, `status`, `inversor_ref_id`, `criado_em`)
SELECT c.id, '180205021C290513', 'SOLIS', 'inversor', 'ativo', 8, NOW()
FROM `controladores` c
WHERE c.solis_station_id = '1298491919449207146'
ON DUPLICATE KEY UPDATE `controlador_id` = VALUES(`controlador_id`), `status` = 'ativo', `inversor_ref_id` = VALUES(`inversor_ref_id`);

-- -----------------------------------------------------------------
-- 16. Planta: Renato Valbert (Station ID: 1298491919448959557)
-- -----------------------------------------------------------------
INSERT INTO `empresa` (`nome_fantasia`, `tipo`, `empresa_pai_id`, `ativo`, `created_at`, `updated_at`)
SELECT 'Renato Valbert', 'cliente_real', 1, 1, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `empresa` WHERE `nome_fantasia` = 'Renato Valbert' AND `deleted_at` IS NULL);

INSERT INTO `controladores` (`codigo`, `apelido`, `tipo`, `empresa_id`, `solis_station_id`, `token_api_hash`, `status`, `timezone`, `modo_controle`, `created_at`, `updated_at`)
SELECT 'SOLIS-VIR-0016', 'Solis - Renato Valbert', 'SOLIS-CLOUD', e.id, '1298491919448959557', '7cb767035eff1cc25ded43ad5821171a45fde4570eb34d245c6a0cb4c660c0e4', 'ativo', 'America/Sao_Paulo', 'desativado', NOW(), NOW()
FROM `empresa` e
WHERE e.nome_fantasia = 'Renato Valbert' AND e.deleted_at IS NULL
  AND NOT EXISTS (SELECT 1 FROM `controladores` WHERE `solis_station_id` = '1298491919448959557' AND `status` <> 'inativo');

UPDATE `inversores` i
JOIN `controladores` c ON c.solis_station_id = '1298491919448959557'
SET i.controlador_id = c.id, i.empresa_id = c.empresa_id
WHERE i.station_id = '1298491919448959557';

INSERT INTO `controlador_inversores` (`controlador_id`, `inversor_sn`, `marca`, `tipo_equipamento`, `status`, `inversor_ref_id`, `criado_em`)
SELECT c.id, '110AE121C290707', 'SOLIS', 'inversor', 'ativo', 12, NOW()
FROM `controladores` c
WHERE c.solis_station_id = '1298491919448959557'
ON DUPLICATE KEY UPDATE `controlador_id` = VALUES(`controlador_id`), `status` = 'ativo', `inversor_ref_id` = VALUES(`inversor_ref_id`);

-- -----------------------------------------------------------------
-- 17. Planta: Ripper (Station ID: 1298491919448959540)
-- -----------------------------------------------------------------
INSERT INTO `empresa` (`nome_fantasia`, `tipo`, `empresa_pai_id`, `ativo`, `created_at`, `updated_at`)
SELECT 'Ripper', 'cliente_real', 1, 1, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `empresa` WHERE `nome_fantasia` = 'Ripper' AND `deleted_at` IS NULL);

INSERT INTO `controladores` (`codigo`, `apelido`, `tipo`, `empresa_id`, `solis_station_id`, `token_api_hash`, `status`, `timezone`, `modo_controle`, `created_at`, `updated_at`)
SELECT 'SOLIS-VIR-0017', 'Solis - Ripper', 'SOLIS-CLOUD', e.id, '1298491919448959540', '12f8f0d92320793318589af8068612db0fa2462abdb0fbf99b335fc3e4b4250b', 'ativo', 'America/Sao_Paulo', 'desativado', NOW(), NOW()
FROM `empresa` e
WHERE e.nome_fantasia = 'Ripper' AND e.deleted_at IS NULL
  AND NOT EXISTS (SELECT 1 FROM `controladores` WHERE `solis_station_id` = '1298491919448959540' AND `status` <> 'inativo');

UPDATE `inversores` i
JOIN `controladores` c ON c.solis_station_id = '1298491919448959540'
SET i.controlador_id = c.id, i.empresa_id = c.empresa_id
WHERE i.station_id = '1298491919448959540';

INSERT INTO `controlador_inversores` (`controlador_id`, `inversor_sn`, `marca`, `tipo_equipamento`, `status`, `inversor_ref_id`, `criado_em`)
SELECT c.id, '110AF1214070030', 'SOLIS', 'inversor', 'ativo', 52, NOW()
FROM `controladores` c
WHERE c.solis_station_id = '1298491919448959540'
ON DUPLICATE KEY UPDATE `controlador_id` = VALUES(`controlador_id`), `status` = 'ativo', `inversor_ref_id` = VALUES(`inversor_ref_id`);

-- -----------------------------------------------------------------
-- 18. Planta: Rodrigo César Toledo (Station ID: 1298491919450727657)
-- -----------------------------------------------------------------
INSERT INTO `empresa` (`nome_fantasia`, `tipo`, `empresa_pai_id`, `ativo`, `created_at`, `updated_at`)
SELECT 'Rodrigo César Toledo', 'cliente_real', 1, 1, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `empresa` WHERE `nome_fantasia` = 'Rodrigo César Toledo' AND `deleted_at` IS NULL);

INSERT INTO `controladores` (`codigo`, `apelido`, `tipo`, `empresa_id`, `solis_station_id`, `token_api_hash`, `status`, `timezone`, `modo_controle`, `created_at`, `updated_at`)
SELECT 'SOLIS-VIR-0018', 'Solis - Rodrigo César Toledo', 'SOLIS-CLOUD', e.id, '1298491919450727657', '1dcdfe9790046495fc14309c4d4a0bddb555ad844b485ddbe71a007299efe107', 'ativo', 'America/Sao_Paulo', 'desativado', NOW(), NOW()
FROM `empresa` e
WHERE e.nome_fantasia = 'Rodrigo César Toledo' AND e.deleted_at IS NULL
  AND NOT EXISTS (SELECT 1 FROM `controladores` WHERE `solis_station_id` = '1298491919450727657' AND `status` <> 'inativo');

UPDATE `inversores` i
JOIN `controladores` c ON c.solis_station_id = '1298491919450727657'
SET i.controlador_id = c.id, i.empresa_id = c.empresa_id
WHERE i.station_id = '1298491919450727657';

INSERT INTO `controlador_inversores` (`controlador_id`, `inversor_sn`, `marca`, `tipo_equipamento`, `status`, `inversor_ref_id`, `criado_em`)
SELECT c.id, '100311A255180866', 'SOLIS', 'inversor', 'ativo', 1, NOW()
FROM `controladores` c
WHERE c.solis_station_id = '1298491919450727657'
ON DUPLICATE KEY UPDATE `controlador_id` = VALUES(`controlador_id`), `status` = 'ativo', `inversor_ref_id` = VALUES(`inversor_ref_id`);
INSERT INTO `controlador_inversores` (`controlador_id`, `inversor_sn`, `marca`, `tipo_equipamento`, `status`, `inversor_ref_id`, `criado_em`)
SELECT c.id, '100311A255070076', 'SOLIS', 'inversor', 'ativo', 2, NOW()
FROM `controladores` c
WHERE c.solis_station_id = '1298491919450727657'
ON DUPLICATE KEY UPDATE `controlador_id` = VALUES(`controlador_id`), `status` = 'ativo', `inversor_ref_id` = VALUES(`inversor_ref_id`);

-- -----------------------------------------------------------------
-- 19. Planta: Tais Stort (Station ID: 1298491919449020020)
-- -----------------------------------------------------------------
INSERT INTO `empresa` (`nome_fantasia`, `tipo`, `empresa_pai_id`, `ativo`, `created_at`, `updated_at`)
SELECT 'Tais Stort', 'cliente_real', 1, 1, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `empresa` WHERE `nome_fantasia` = 'Tais Stort' AND `deleted_at` IS NULL);

INSERT INTO `controladores` (`codigo`, `apelido`, `tipo`, `empresa_id`, `solis_station_id`, `token_api_hash`, `status`, `timezone`, `modo_controle`, `created_at`, `updated_at`)
SELECT 'SOLIS-VIR-0019', 'Solis - Tais Stort', 'SOLIS-CLOUD', e.id, '1298491919449020020', '06de1b4dfd0c32c28cf36333807c3e2ea17295fd835d57d10fce737c067df8fb', 'ativo', 'America/Sao_Paulo', 'desativado', NOW(), NOW()
FROM `empresa` e
WHERE e.nome_fantasia = 'Tais Stort' AND e.deleted_at IS NULL
  AND NOT EXISTS (SELECT 1 FROM `controladores` WHERE `solis_station_id` = '1298491919449020020' AND `status` <> 'inativo');

UPDATE `inversores` i
JOIN `controladores` c ON c.solis_station_id = '1298491919449020020'
SET i.controlador_id = c.id, i.empresa_id = c.empresa_id
WHERE i.station_id = '1298491919449020020';

INSERT INTO `controlador_inversores` (`controlador_id`, `inversor_sn`, `marca`, `tipo_equipamento`, `status`, `inversor_ref_id`, `criado_em`)
SELECT c.id, '110AF2219260088', 'SOLIS', 'inversor', 'ativo', 22, NOW()
FROM `controladores` c
WHERE c.solis_station_id = '1298491919449020020'
ON DUPLICATE KEY UPDATE `controlador_id` = VALUES(`controlador_id`), `status` = 'ativo', `inversor_ref_id` = VALUES(`inversor_ref_id`);

-- -----------------------------------------------------------------
-- 20. Planta: UFV Guilherme Succi (Station ID: 1298491919448959549)
-- -----------------------------------------------------------------
INSERT INTO `empresa` (`nome_fantasia`, `tipo`, `empresa_pai_id`, `ativo`, `created_at`, `updated_at`)
SELECT 'UFV Guilherme Succi', 'cliente_real', 1, 1, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `empresa` WHERE `nome_fantasia` = 'UFV Guilherme Succi' AND `deleted_at` IS NULL);

INSERT INTO `controladores` (`codigo`, `apelido`, `tipo`, `empresa_id`, `solis_station_id`, `token_api_hash`, `status`, `timezone`, `modo_controle`, `created_at`, `updated_at`)
SELECT 'SOLIS-VIR-0020', 'Solis - UFV Guilherme Succi', 'SOLIS-CLOUD', e.id, '1298491919448959549', '6a7e35c5df16bd56f86714c3f558bb0569bd609717c1bede4a37f594495067be', 'ativo', 'America/Sao_Paulo', 'desativado', NOW(), NOW()
FROM `empresa` e
WHERE e.nome_fantasia = 'UFV Guilherme Succi' AND e.deleted_at IS NULL
  AND NOT EXISTS (SELECT 1 FROM `controladores` WHERE `solis_station_id` = '1298491919448959549' AND `status` <> 'inativo');

UPDATE `inversores` i
JOIN `controladores` c ON c.solis_station_id = '1298491919448959549'
SET i.controlador_id = c.id, i.empresa_id = c.empresa_id
WHERE i.station_id = '1298491919448959549';

INSERT INTO `controlador_inversores` (`controlador_id`, `inversor_sn`, `marca`, `tipo_equipamento`, `status`, `inversor_ref_id`, `criado_em`)
SELECT c.id, '180105021C280005', 'SOLIS', 'inversor', 'ativo', 6, NOW()
FROM `controladores` c
WHERE c.solis_station_id = '1298491919448959549'
ON DUPLICATE KEY UPDATE `controlador_id` = VALUES(`controlador_id`), `status` = 'ativo', `inversor_ref_id` = VALUES(`inversor_ref_id`);

-- -----------------------------------------------------------------
-- 21. Planta: UFV JP (Station ID: 1298491919449415230)
-- -----------------------------------------------------------------
INSERT INTO `empresa` (`nome_fantasia`, `tipo`, `empresa_pai_id`, `ativo`, `created_at`, `updated_at`)
SELECT 'UFV JP', 'cliente_real', 1, 1, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `empresa` WHERE `nome_fantasia` = 'UFV JP' AND `deleted_at` IS NULL);

INSERT INTO `controladores` (`codigo`, `apelido`, `tipo`, `empresa_id`, `solis_station_id`, `token_api_hash`, `status`, `timezone`, `modo_controle`, `created_at`, `updated_at`)
SELECT 'SOLIS-VIR-0021', 'Solis - UFV JP', 'SOLIS-CLOUD', e.id, '1298491919449415230', 'd844b2846845e1a4e434a85d5ccae7dbb412203c9064ce57cbe167d108661989', 'ativo', 'America/Sao_Paulo', 'desativado', NOW(), NOW()
FROM `empresa` e
WHERE e.nome_fantasia = 'UFV JP' AND e.deleted_at IS NULL
  AND NOT EXISTS (SELECT 1 FROM `controladores` WHERE `solis_station_id` = '1298491919449415230' AND `status` <> 'inativo');

UPDATE `inversores` i
JOIN `controladores` c ON c.solis_station_id = '1298491919449415230'
SET i.controlador_id = c.id, i.empresa_id = c.empresa_id
WHERE i.station_id = '1298491919449415230';

INSERT INTO `controlador_inversores` (`controlador_id`, `inversor_sn`, `marca`, `tipo_equipamento`, `status`, `inversor_ref_id`, `criado_em`)
SELECT c.id, '110AC1211220240', 'SOLIS', 'inversor', 'ativo', 56, NOW()
FROM `controladores` c
WHERE c.solis_station_id = '1298491919449415230'
ON DUPLICATE KEY UPDATE `controlador_id` = VALUES(`controlador_id`), `status` = 'ativo', `inversor_ref_id` = VALUES(`inversor_ref_id`);

-- -----------------------------------------------------------------
-- 22. Planta: UFV PAULO STORTI (Station ID: 1298491919448959552)
-- -----------------------------------------------------------------
INSERT INTO `empresa` (`nome_fantasia`, `tipo`, `empresa_pai_id`, `ativo`, `created_at`, `updated_at`)
SELECT 'UFV PAULO STORTI', 'cliente_real', 1, 1, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `empresa` WHERE `nome_fantasia` = 'UFV PAULO STORTI' AND `deleted_at` IS NULL);

INSERT INTO `controladores` (`codigo`, `apelido`, `tipo`, `empresa_id`, `solis_station_id`, `token_api_hash`, `status`, `timezone`, `modo_controle`, `created_at`, `updated_at`)
SELECT 'SOLIS-VIR-0022', 'Solis - UFV PAULO STORTI', 'SOLIS-CLOUD', e.id, '1298491919448959552', 'e086dfe6025447ac98a58447fa6a5f1fb0af4e1e9e4a7bfb2969e7ac0f2d80ff', 'ativo', 'America/Sao_Paulo', 'desativado', NOW(), NOW()
FROM `empresa` e
WHERE e.nome_fantasia = 'UFV PAULO STORTI' AND e.deleted_at IS NULL
  AND NOT EXISTS (SELECT 1 FROM `controladores` WHERE `solis_station_id` = '1298491919448959552' AND `status` <> 'inativo');

UPDATE `inversores` i
JOIN `controladores` c ON c.solis_station_id = '1298491919448959552'
SET i.controlador_id = c.id, i.empresa_id = c.empresa_id
WHERE i.station_id = '1298491919448959552';

INSERT INTO `controlador_inversores` (`controlador_id`, `inversor_sn`, `marca`, `tipo_equipamento`, `status`, `inversor_ref_id`, `criado_em`)
SELECT c.id, '100206021B170154', 'SOLIS', 'inversor', 'ativo', 14, NOW()
FROM `controladores` c
WHERE c.solis_station_id = '1298491919448959552'
ON DUPLICATE KEY UPDATE `controlador_id` = VALUES(`controlador_id`), `status` = 'ativo', `inversor_ref_id` = VALUES(`inversor_ref_id`);
INSERT INTO `controlador_inversores` (`controlador_id`, `inversor_sn`, `marca`, `tipo_equipamento`, `status`, `inversor_ref_id`, `criado_em`)
SELECT c.id, '100206021B170132', 'SOLIS', 'inversor', 'ativo', 15, NOW()
FROM `controladores` c
WHERE c.solis_station_id = '1298491919448959552'
ON DUPLICATE KEY UPDATE `controlador_id` = VALUES(`controlador_id`), `status` = 'ativo', `inversor_ref_id` = VALUES(`inversor_ref_id`);

-- -----------------------------------------------------------------
-- 23. Planta: UFV TMA Yticom (Station ID: 1298491919448959553)
-- -----------------------------------------------------------------
INSERT INTO `empresa` (`nome_fantasia`, `tipo`, `empresa_pai_id`, `ativo`, `created_at`, `updated_at`)
SELECT 'UFV TMA Yticom', 'cliente_real', 1, 1, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `empresa` WHERE `nome_fantasia` = 'UFV TMA Yticom' AND `deleted_at` IS NULL);

INSERT INTO `controladores` (`codigo`, `apelido`, `tipo`, `empresa_id`, `solis_station_id`, `token_api_hash`, `status`, `timezone`, `modo_controle`, `created_at`, `updated_at`)
SELECT 'SOLIS-VIR-0023', 'Solis - UFV TMA Yticom', 'SOLIS-CLOUD', e.id, '1298491919448959553', '7f52a9d23fee61ec2feb210e03ef2dfdfe9a502852d089181866992a1793d858', 'ativo', 'America/Sao_Paulo', 'desativado', NOW(), NOW()
FROM `empresa` e
WHERE e.nome_fantasia = 'UFV TMA Yticom' AND e.deleted_at IS NULL
  AND NOT EXISTS (SELECT 1 FROM `controladores` WHERE `solis_station_id` = '1298491919448959553' AND `status` <> 'inativo');

UPDATE `inversores` i
JOIN `controladores` c ON c.solis_station_id = '1298491919448959553'
SET i.controlador_id = c.id, i.empresa_id = c.empresa_id
WHERE i.station_id = '1298491919448959553';

INSERT INTO `controlador_inversores` (`controlador_id`, `inversor_sn`, `marca`, `tipo_equipamento`, `status`, `inversor_ref_id`, `criado_em`)
SELECT c.id, '110D12218240005', 'SOLIS', 'inversor', 'ativo', 23, NOW()
FROM `controladores` c
WHERE c.solis_station_id = '1298491919448959553'
ON DUPLICATE KEY UPDATE `controlador_id` = VALUES(`controlador_id`), `status` = 'ativo', `inversor_ref_id` = VALUES(`inversor_ref_id`);

-- -----------------------------------------------------------------
-- 24. Planta: Ufv Bioterra (Station ID: 1298491919448959554)
-- -----------------------------------------------------------------
INSERT INTO `empresa` (`nome_fantasia`, `tipo`, `empresa_pai_id`, `ativo`, `created_at`, `updated_at`)
SELECT 'Ufv Bioterra', 'cliente_real', 1, 1, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `empresa` WHERE `nome_fantasia` = 'Ufv Bioterra' AND `deleted_at` IS NULL);

INSERT INTO `controladores` (`codigo`, `apelido`, `tipo`, `empresa_id`, `solis_station_id`, `token_api_hash`, `status`, `timezone`, `modo_controle`, `created_at`, `updated_at`)
SELECT 'SOLIS-VIR-0024', 'Solis - Ufv Bioterra', 'SOLIS-CLOUD', e.id, '1298491919448959554', 'a43150084e29b1ec6ba2477589ee0b9b525ff3f6fef42b7c4ed7ead3067ab3d5', 'ativo', 'America/Sao_Paulo', 'desativado', NOW(), NOW()
FROM `empresa` e
WHERE e.nome_fantasia = 'Ufv Bioterra' AND e.deleted_at IS NULL
  AND NOT EXISTS (SELECT 1 FROM `controladores` WHERE `solis_station_id` = '1298491919448959554' AND `status` <> 'inativo');

UPDATE `inversores` i
JOIN `controladores` c ON c.solis_station_id = '1298491919448959554'
SET i.controlador_id = c.id, i.empresa_id = c.empresa_id
WHERE i.station_id = '1298491919448959554';

INSERT INTO `controlador_inversores` (`controlador_id`, `inversor_sn`, `marca`, `tipo_equipamento`, `status`, `inversor_ref_id`, `criado_em`)
SELECT c.id, '110D12219140030', 'SOLIS', 'inversor', 'ativo', 24, NOW()
FROM `controladores` c
WHERE c.solis_station_id = '1298491919448959554'
ON DUPLICATE KEY UPDATE `controlador_id` = VALUES(`controlador_id`), `status` = 'ativo', `inversor_ref_id` = VALUES(`inversor_ref_id`);

-- -----------------------------------------------------------------
-- 25. Planta: Zoom Ubatuba (Station ID: 1298491919448959535)
-- -----------------------------------------------------------------
INSERT INTO `empresa` (`nome_fantasia`, `tipo`, `empresa_pai_id`, `ativo`, `created_at`, `updated_at`)
SELECT 'Zoom Ubatuba', 'cliente_real', 1, 1, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `empresa` WHERE `nome_fantasia` = 'Zoom Ubatuba' AND `deleted_at` IS NULL);

INSERT INTO `controladores` (`codigo`, `apelido`, `tipo`, `empresa_id`, `solis_station_id`, `token_api_hash`, `status`, `timezone`, `modo_controle`, `created_at`, `updated_at`)
SELECT 'SOLIS-VIR-0025', 'Solis - Zoom Ubatuba', 'SOLIS-CLOUD', e.id, '1298491919448959535', '5e36b3abbf7ee3ce4e7c69ee173d16be6738a80469267873286d6fdb56d1f367', 'ativo', 'America/Sao_Paulo', 'desativado', NOW(), NOW()
FROM `empresa` e
WHERE e.nome_fantasia = 'Zoom Ubatuba' AND e.deleted_at IS NULL
  AND NOT EXISTS (SELECT 1 FROM `controladores` WHERE `solis_station_id` = '1298491919448959535' AND `status` <> 'inativo');

UPDATE `inversores` i
JOIN `controladores` c ON c.solis_station_id = '1298491919448959535'
SET i.controlador_id = c.id, i.empresa_id = c.empresa_id
WHERE i.station_id = '1298491919448959535';

INSERT INTO `controlador_inversores` (`controlador_id`, `inversor_sn`, `marca`, `tipo_equipamento`, `status`, `inversor_ref_id`, `criado_em`)
SELECT c.id, '110E51211210453', 'SOLIS', 'inversor', 'ativo', 54, NOW()
FROM `controladores` c
WHERE c.solis_station_id = '1298491919448959535'
ON DUPLICATE KEY UPDATE `controlador_id` = VALUES(`controlador_id`), `status` = 'ativo', `inversor_ref_id` = VALUES(`inversor_ref_id`);

-- Garante vínculo do inversor Solis do controlador físico Polito (id 3)
UPDATE `inversores` SET `controlador_id` = 3, `empresa_id` = 1 WHERE `solis_sn` = '110AE121C290228';
INSERT INTO `controlador_inversores` (`controlador_id`, `inversor_sn`, `marca`, `tipo_equipamento`, `status`, `inversor_ref_id`, `criado_em`)
SELECT 3, '110AE121C290228', 'SOLIS', 'inversor', 'ativo', i.id, NOW()
FROM `inversores` i WHERE i.solis_sn = '110AE121C290228'
ON DUPLICATE KEY UPDATE `controlador_id` = 3, `status` = 'ativo', `inversor_ref_id` = VALUES(`inversor_ref_id`);

COMMIT;
