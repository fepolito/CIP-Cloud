-- @arquivo       database/migrations/2026_07_24_create_tabela_limites.sql
-- @versao        1.0.0
-- @modificado_em 2026-07-24
-- @objetivo      Cria tabela_limites (versao ativa + JSON snapshot) e
--                tabela_limites_historico (append-only) para o modulo de
--                limites de potencia (24h x 3 perfis), com sync_status proprio.
-- @autor         Fernando / CIP Cloud Copilot / ATGY

SET time_zone = '+00:00';

CREATE TABLE tabela_limites (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    controlador_id    INT NOT NULL,
    versao            INT UNSIGNED NOT NULL DEFAULT 1,
    payload_json      JSON NOT NULL,
    origem            ENUM('local','cloud','app') NOT NULL DEFAULT 'cloud',
    usuario_id        INT UNSIGNED NULL,  -- auditoria (quem salvou). SEM FK: historico persiste se usuario sumir.
    sync_status       ENUM('sincronizada','pendente_ack','timeout','divergente')
                        NOT NULL DEFAULT 'pendente_ack',
    ativa             TINYINT(1) NOT NULL DEFAULT 1,
    ativa_uk          INT AS (IF(ativa = 1, controlador_id, NULL)) VIRTUAL,
    criada_em         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    aplicada_em       DATETIME NULL,
    ack_em            DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_uma_ativa_por_controlador (ativa_uk),
    KEY idx_controlador (controlador_id),
    KEY idx_usuario (usuario_id),
    CONSTRAINT fk_limites_controlador
        FOREIGN KEY (controlador_id) REFERENCES controladores(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tabela_limites_historico (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    controlador_id    INT NOT NULL,
    versao            INT UNSIGNED NOT NULL,
    payload_json      JSON NOT NULL,
    origem            ENUM('local','cloud','app') NOT NULL,
    usuario_id        INT UNSIGNED NULL,  -- auditoria, SEM FK
    sync_status_final ENUM('sincronizada','pendente_ack','timeout','divergente') NULL,
    arquivada_em      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ctrl_versao (controlador_id, versao),
    KEY idx_usuario (usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
