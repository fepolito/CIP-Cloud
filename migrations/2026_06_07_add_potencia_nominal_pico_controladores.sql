-- ============================================================
-- Projeto      : CIP - Controlador de Injecao de Potencia Eletrica
-- Migration    : add_potencia_nominal_pico_controladores
-- Versao       : 1.0.0
-- Data         : 2026-06-07
-- Autor        : CIP Cloud Copilot (gerado via ATGY)
--
-- Objetivo     : Adiciona campos de referencia de potencia em
--                'controladores', consumidos pelos cards/gauges
--                do dashboard (api/energia/instantaneo.php).
--
-- Campos novos :
--   potencia_nominal_kw     DECIMAL(7,3) NULL
--     -> Potencia nominal da planta solar (cadastro)
--   potencia_pico_90d_kw    DECIMAL(7,3) NULL
--     -> Pico observado nos ultimos 90d (job futuro popula)
--
-- Tabela alvo  : controladores
-- Impacto      :
--   - Nenhum endpoint existente quebra (campos NULL aceitos)
--   - api/energia/instantaneo.php passa a retornar valores
--     em 'limites_card' (antes: erro 500 por coluna ausente)
--   - Dashboard exibe "--" enquanto campos NULL
--
-- Rollback     : ver bloco no final do arquivo
-- ============================================================

ALTER TABLE controladores
  ADD COLUMN potencia_nominal_kw DECIMAL(7,3) NULL DEFAULT NULL
    COMMENT 'Potencia nominal da planta solar em kW (configurada no cadastro do controlador)'
    AFTER modo_controle,
  ADD COLUMN potencia_pico_90d_kw DECIMAL(7,3) NULL DEFAULT NULL
    COMMENT 'Pico de geracao observado nos ultimos 90 dias em kW (atualizado por job futuro)'
    AFTER potencia_nominal_kw;

-- ============================================================
-- VERIFICACAO POS-MIGRATION
-- ============================================================
-- Rodar manualmente apos a migration para validar:
--
-- DESCRIBE controladores;
--
-- SELECT id, codigo, apelido, modo_controle,
--        potencia_nominal_kw, potencia_pico_90d_kw
--   FROM controladores
--  LIMIT 5;
--
-- Esperado:
--   - 2 colunas novas aparecem no DESCRIBE
--   - SELECT retorna NULL nos 2 campos novos para todos os registros
-- ============================================================

-- ============================================================
-- ROLLBACK (executar manualmente se necessario)
-- ============================================================
-- ALTER TABLE controladores
--   DROP COLUMN potencia_pico_90d_kw,
--   DROP COLUMN potencia_nominal_kw;
-- ============================================================
