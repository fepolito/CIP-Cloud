<?php
/**
 * Arquivo: api/auth/logout.php
 * Projeto: Controlador de Injeção de Potência Elétrica
 * Objetivo: Invalidar sessão PHP no servidor via chamada AJAX
 *
 * Dependências de hardware:
 * - Servidor web com suporte a PHP Session
 *
 * Dependências de software/arquivos instalados:
 * - PHP 8.3+
 * - config/app.php
 * - app/auth.php
 *
 * Histórico de implementações:
 * - 2026-04-08 11:07: Reescrito para invalidar $_SESSION PHP
 *                     Remove dependência de Bearer JWT Token
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../app/auth.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método não permitido']);
    exit;
}

logoutUser(); // destrói $_SESSION e apaga cookie

http_response_code(200);
echo json_encode(['success' => true, 'message' => 'Sessão encerrada']);
exit;
