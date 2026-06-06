<?php
/**
 * =============================================================
 * PROJETO: Controlador de Injeção de Potência Elétrica
 * ARQUIVO: api/auth/session.php
 * =============================================================
 * OBJETIVO:
 *   Provide funções de autenticação para ser incluído via
 *   require_once em outros endpoints da API.
 *   NÃO emite nenhuma saída — apenas define funções e inicia sessão.
 *
 * DEPENDÊNCIAS DE ARQUIVOS:
 *   - config/app.php
 *   - app/auth.php
 *
 * DEPENDÊNCIAS DE HARDWARE:
 *   - Servidor web com suporte a PHP Session
 *
 * HISTÓRICO:
 *   2026-04-11  v1.0  Criado a partir da separação do verify.php
 *                     para evitar echo/exit em includes
 * =============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../app/auth.php';

/**
 * Encerra a execução com 401 se o usuário não estiver autenticado.
 * Não emite saída se a sessão for válida.
 
function requireAuth(): void
{
    if (!isAuthenticated()) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        http_response_code(401);
        echo json_encode(['sucesso' => false, 'erro' => 'Sessão inválida ou expirada']);
        exit;
    }
}*/
