-- =====================================================================
-- Migration: adicionar flags de controle de exportacao em controladores
-- @versao   0.1.0
-- @data     2026-06-02
-- @autor    CIP Cloud Copilot + Fernando
-- @motivo   Permitir cloud refletir estado de Grid Zero / limite tabela
--           reportado pelo firmware (espelhamento bidirecional)
-- =====================================================================

ALTER TABLE `controladores`
    ADD COLUMN `controle_exportacao_ativo` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Se 1, controle de exportacao esta ativo (Grid Zero ou limite tabela)'
        AFTER `online`,
    ADD COLUMN `modo_controle` ENUM('grid_zero','limite_tabela','desativado')
        NOT NULL DEFAULT 'desativado'
        COMMENT 'grid_zero=injecao zero; limite_tabela=usa tabela horaria; desativado=sem controle'
        AFTER `controle_exportacao_ativo`,
    ADD COLUMN `controle_versao` INT UNSIGNED NOT NULL DEFAULT 0
        COMMENT 'Versao incremental do estado de controle (para sync firmware<->cloud)'
        AFTER `modo_controle`,
    ADD COLUMN `controle_atualizado_em` DATETIME NULL DEFAULT NULL
        COMMENT 'Timestamp UTC da ultima alteracao do estado de controle'
        AFTER `controle_versao`,
    ADD COLUMN `controle_origem` ENUM('local','cloud','app','firmware_boot') NULL DEFAULT NULL
        COMMENT 'Origem da ultima alteracao do estado de controle'
        AFTER `controle_atualizado_em`;
