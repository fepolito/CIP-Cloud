-- =====================================================================
-- Rollback: remover flags de controle de exportacao em controladores
-- @versao   0.1.0
-- @data     2026-06-02
-- @autor    CIP Cloud Copilot + Fernando
-- =====================================================================

ALTER TABLE `controladores`
    DROP COLUMN `controle_origem`,
    DROP COLUMN `controle_atualizado_em`,
    DROP COLUMN `controle_versao`,
    DROP COLUMN `modo_controle`,
    DROP COLUMN `controle_exportacao_ativo`;
