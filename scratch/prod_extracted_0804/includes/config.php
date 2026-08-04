<?php
// ============================================================
// Projeto   : CIP - Controlador de Injecao de Potencia Eletrica
// Arquivo   : includes/config.php
// Objetivo  : Centralizar configuracoes globais do sistema.
//             Detecta automaticamente o ambiente:
//               - PROD: subdominio proprio (raiz = '/')
//               - DEV : Laragon/XAMPP em subpasta
// Dependencias:
//   - PHP 7.4+
//   - Incluido no topo das paginas via require_once
// Historico :
//   2026-04-08  v1.0.0  Criacao com deteccao automatica de base
//   2026-04-08  v1.1.0  Simplificado para subdominio proprio
//   2026-05-31  v1.2.0  Reintroduzida deteccao automatica de
//                       ambiente (dev/prod) para suportar
//                       Laragon local sem quebrar producao.
// ============================================================
declare(strict_types=1);

if (defined('APP_BASE_URL')) {
    return;
}

/**
 * Detecta o base path da aplicacao.
 *
 * Logica:
 *   - Pega o SCRIPT_NAME (ex: /monitor.aeonium.com.br/login.php)
 *   - Remove o nome do script no final (ex: /login.php ou /api/x.php)
 *   - O que sobrar e o prefixo da aplicacao
 *
 * Em producao (subdominio): SCRIPT_NAME = '/login.php' -> base = ''
 * Em local  (subpasta)    : SCRIPT_NAME = '/monitor.aeonium.com.br/login.php'
 *                           -> base = '/monitor.aeonium.com.br'
 */
function detectarBasePath(): string
{
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    if ($scriptName === '') {
        return '';
    }

    // Remove o arquivo final, mantendo so o diretorio
    $dir = str_replace('\\', '/', dirname($scriptName));

    // Se o script estiver em uma subpasta interna (ex: /app/api/x.php),
    // precisamos identificar so o prefixo da aplicacao, nao o caminho cheio.
    // Estrategia: comparar com DOCUMENT_ROOT vs caminho real do projeto.
    $docRoot     = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '');
    $projectRoot = str_replace('\\', '/', realpath(__DIR__ . '/..') ?: '');

    if ($docRoot !== '' && $projectRoot !== '' && strpos($projectRoot, $docRoot) === 0) {
        $base = substr($projectRoot, strlen($docRoot));
        return rtrim($base, '/');
    }

    // Fallback: se dirname for '/', retorna vazio
    return ($dir === '/' || $dir === '\\' || $dir === '.') ? '' : rtrim($dir, '/');
}

define('APP_BASE_URL', detectarBasePath());

/**
 * Gera URL absoluta para assets e rotas internas.
 *
 * Exemplos em PROD (subdominio, APP_BASE_URL=''):
 *   asset('login.php')            -> /login.php
 *   asset('/api/auth/verify.php') -> /api/auth/verify.php
 *
 * Exemplos em DEV (Laragon, APP_BASE_URL='/monitor.aeonium.com.br'):
 *   asset('login.php')            -> /monitor.aeonium.com.br/login.php
 *   asset('/api/auth/verify.php') -> /monitor.aeonium.com.br/api/auth/verify.php
 */
function asset(string $path): string
{
    return APP_BASE_URL . '/' . ltrim($path, '/');
}

/**
 * Alias compativel com codigo que usa appUrl().
 */
if (!function_exists('appUrl')) {
    function appUrl(string $path): string
    {
        return asset($path);
    }
}
