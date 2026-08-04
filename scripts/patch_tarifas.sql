-- Patch para dinamizar a tarifa e fator de injeção dos controladores
-- Adiciona colunas para definir o perfil de faturamento de cada instalação

ALTER TABLE `controladores`
ADD COLUMN `tarifa_kwh` DECIMAL(6,4) DEFAULT 0.9482 COMMENT 'Tarifa TE+TUSD (R$/kWh) aplicada ao consumo',
ADD COLUMN `fator_injecao` DECIMAL(4,3) DEFAULT 0.760 COMMENT 'Fator aplicado ao crédito de injeção (Lei 14.300 = 0.76, Direito Adquirido = ~0.89)',
ADD COLUMN `modalidade_compensacao` ENUM('GD_I_DIREITO_ADQUIRIDO', 'GD_II_LEI_14300', 'MERCADO_LIVRE', 'OUTRO') DEFAULT 'GD_II_LEI_14300' COMMENT 'Categoria do consumidor para histórico e regras de cálculo';

-- Para o controlador 3 (exemplo), o Fernando Polito tem Direito Adquirido:
-- UPDATE `controladores` SET `modalidade_compensacao` = 'GD_I_DIREITO_ADQUIRIDO', `tarifa_kwh` = 0.9342, `fator_injecao` = 0.890 WHERE `id` = 3;
