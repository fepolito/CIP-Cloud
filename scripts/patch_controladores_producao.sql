ALTER TABLE controladores ADD COLUMN controle_exportacao_ativo TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Se 1, controle de exportacao esta ativo';
ALTER TABLE controladores ADD COLUMN modo_controle ENUM('grid_zero','limite_tabela','desativado') NOT NULL DEFAULT 'desativado';
ALTER TABLE controladores ADD COLUMN potencia_nominal_kw DECIMAL(7,3) DEFAULT NULL;
ALTER TABLE controladores ADD COLUMN potencia_pico_90d_kw DECIMAL(7,3) DEFAULT NULL;
ALTER TABLE controladores ADD COLUMN controle_versao INT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE controladores ADD COLUMN controle_atualizado_em DATETIME DEFAULT NULL;
ALTER TABLE controladores ADD COLUMN controle_origem ENUM('local','cloud','app','firmware_boot') DEFAULT NULL;
