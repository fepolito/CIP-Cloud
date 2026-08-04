<?php
/**
 * =============================================================
 * PROJETO: Controlador de Injeção de Potência Elétrica
 * ARQUIVO: api/auth/verify.php
 * =============================================================
 * OBJETIVO:
 *   Endpoint HTTP GET — verifica autenticação via sessão PHP
 *   e retorna dados do usuário logado ao frontend.
 *   NÃO deve ser incluído via require_once por outros endpoints.
 *
 * DEPENDÊNCIAS DE ARQUIVOS:
 *   - api/auth/session.php  → isAuthenticated() + sessão iniciada
 *
 * DEPENDÊNCIAS DE HARDWARE:
 *   - Servidor web com suporte a PHP Session
 *
 * HISTÓRICO:
 *   2026-04-08  v1.0  Criação — sessão PHP substituindo JWT
 *   2026-04-11  v1.1  Lógica de autenticação movida para session.php
 *                     verify.php passa a ser exclusivamente endpoint HTTP
 * =============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/session.php';

header('Content-Type: application/json; charset=utf-8');

// Bloqueia métodos não permitidos
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método não permitido']);
    exit;
}

// Verifica sessão
if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Sessão inválida ou expirada']);
    exit;
}

// Calcula segundos restantes
$segundosRestantes = max(0, (int) ($_SESSION['expires_at'] ?? 0) - time());

http_response_code(200);
echo json_encode([
    'success'            => true,
    'usuario'            => [
        'id'     => (int)    ($_SESSION['usuario_id']     ?? 0),
        'nome'   => (string) ($_SESSION['usuario_nome']   ?? ''),
        'email'  => (string) ($_SESSION['usuario_email']  ?? ''),
        'perfil' => (string) ($_SESSION['usuario_perfil'] ?? ''),
    ],
    'segundos_restantes' => $segundosRestantes,
]);
exit;
