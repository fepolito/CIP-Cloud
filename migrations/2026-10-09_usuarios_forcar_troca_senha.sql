-- @arquivo migrations/2026-10-09_usuarios_forcar_troca_senha.sql
-- @versao  1.0.0
-- @objetivo Adiciona flag para forçar troca de senha no primeiro login ou após reset
ALTER TABLE `usuarios`
  ADD COLUMN `forcar_troca_senha` TINYINT(1) NOT NULL DEFAULT 0 AFTER `ativo`;
