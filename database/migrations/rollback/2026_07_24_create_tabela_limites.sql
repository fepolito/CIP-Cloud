-- @arquivo       database/migrations/rollback/2026_07_24_create_tabela_limites.sql
-- @versao        1.0.0
-- @modificado_em 2026-07-24
-- @objetivo      Rollback: remove tabela_limites e tabela_limites_historico.
-- @autor         Fernando / CIP Cloud Copilot / ATGY

DROP TABLE IF EXISTS tabela_limites_historico;
DROP TABLE IF EXISTS tabela_limites;
