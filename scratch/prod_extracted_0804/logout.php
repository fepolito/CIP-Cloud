<?php
/**
 * Arquivo: logout.php
 * Projeto: Controlador de Injeção de Potência Elétrica
 * Objetivo: Encerrar sessão do usuário autenticado com segurança
 * Dependências de hardware:
 *   - Servidor web
 * Dependências de software:
 *   - PHP 8.3+
 *   - app/auth.php
 *   - includes/config.php
 * Histórico:
 *   2026-04-08  v1.0.0  Criação
 *   2026-04-08  v1.1.0  Substituído appUrl() por asset() — função
 *                       canônica declarada em includes/config.php
 */

declare(strict_types=1);

require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/includes/config.php';

logoutUser();

header('Location: ' . asset('/login.php'));
exit;
