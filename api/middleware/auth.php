<?php
// ============================================================
// Projeto  : CIP — Controlador de Injeção de Potência Elétrica
// Arquivo  : api/middleware/auth.php
// Objetivo : Geração e validação de Bearer Tokens
// ----------------------------------------------------------
// Dependências de hardware : Nenhuma
// Dependências de arquivos :
//   - api/config/database.php  (classe Database — Singleton PDO)
// ----------------------------------------------------------
// Histórico:
//   2026-04-06  v1.0.0  Criação do módulo de autenticação
//   2026-04-06  v1.0.1  Ajuste para classe Database::getInstance()
//   2026-04-06  v1.0.2  PDO passado via parâmetro — sem global
// ============================================================

require_once __DIR__ . '/../config/database.php';

function gerarToken(PDO $pdo, int $usuario_id): string
{
    $token   = bin2hex(random_bytes(32));   // 64 chars hex
    $expires = date('Y-m-d H:i:s', strtotime('+8 hours'));

    // Invalida tokens anteriores do usuário
    $pdo->prepare("
        UPDATE cip_tokens
        SET ativo = 0
        WHERE usuario_id = ?
    ")->execute([$usuario_id]);

    // Insere novo token
    $pdo->prepare("
        INSERT INTO cip_tokens (usuario_id, token, expires_at, ativo)
        VALUES (?, ?, ?, 1)
    ")->execute([$usuario_id, $token, $expires]);

    return $token;
}

function validarToken(PDO $pdo, string $token): ?array
{
    $stmt = $pdo->prepare("
        SELECT u.id, u.nome, u.email
        FROM cip_tokens t
        JOIN cip_usuarios u ON u.id = t.usuario_id
        WHERE t.token = ?
          AND t.ativo = 1
          AND t.expires_at > NOW()
        LIMIT 1
    ");
    $stmt->execute([$token]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
