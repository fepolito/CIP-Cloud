-- Arquivo: docs/database_inicial.sql
-- Projeto: Controlador de Injeção de Potência Elétrica
-- Objetivo: Criar estrutura inicial do banco de dados
-- Dependências de hardware:
-- - Servidor com MySQL/MariaDB habilitado
-- Dependências de software:
-- - MySQL 5.7+ ou MariaDB compatível
-- - phpMyAdmin ou cliente SQL

CREATE TABLE usuarios (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(120) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    senha_hash VARCHAR(255) NOT NULL,
    perfil ENUM('admin', 'operador', 'visualizador') NOT NULL DEFAULT 'visualizador',
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE dispositivos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(120) NOT NULL,
    identificador VARCHAR(80) NOT NULL UNIQUE,
    tipo VARCHAR(80) NOT NULL,
    local_instalacao VARCHAR(150) DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    porta INT DEFAULT NULL,
    status_operacao ENUM('ativo', 'inativo', 'manutencao', 'falha') NOT NULL DEFAULT 'ativo',
    observacoes TEXT DEFAULT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE leituras_energia (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    dispositivo_id INT UNSIGNED NOT NULL,
    tensao_v DECIMAL(10,2) DEFAULT NULL,
    corrente_a DECIMAL(10,2) DEFAULT NULL,
    potencia_kw DECIMAL(12,3) DEFAULT NULL,
    energia_kwh DECIMAL(14,3) DEFAULT NULL,
    frequencia_hz DECIMAL(10,2) DEFAULT NULL,
    fator_potencia DECIMAL(6,3) DEFAULT NULL,
    timestamp_medicao DATETIME NOT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_leituras_dispositivo
        FOREIGN KEY (dispositivo_id) REFERENCES dispositivos(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE comandos_controle (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    dispositivo_id INT UNSIGNED NOT NULL,
    usuario_id INT UNSIGNED NOT NULL,
    comando VARCHAR(100) NOT NULL,
    parametros JSON DEFAULT NULL,
    status_execucao ENUM('pendente', 'enviado', 'executado', 'falhou', 'cancelado') NOT NULL DEFAULT 'pendente',
    resposta_equipamento TEXT DEFAULT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    executado_em DATETIME DEFAULT NULL,
    CONSTRAINT fk_comandos_dispositivo
        FOREIGN KEY (dispositivo_id) REFERENCES dispositivos(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_comandos_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE logs_sistema (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nivel ENUM('INFO', 'WARN', 'ERROR', 'DEBUG') NOT NULL DEFAULT 'INFO',
    origem VARCHAR(100) NOT NULL,
    mensagem TEXT NOT NULL,
    contexto JSON DEFAULT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO usuarios (nome, email, senha_hash, perfil, ativo)
VALUES (
    'Administrador Inicial',
    'admin@aeonium.com.br',
    '$2y$10$abcdefghijklmnopqrstuvABCDEFGHIJKLMNOpqrstuvwxyz123456',
    'admin',
    1
);
