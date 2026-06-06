<?php
/**
 * Arquivo: config/app.php
 * Projeto: Controlador de Injecao de Potencia Eletrica
 * Objetivo: Centralizar configuracoes globais da aplicacao
 * Dependencias:
 *   - PHP 8.3+
 *   - includes/config.php (define APP_BASE_URL e asset())
 * Historico:
 *   2026-04-08  v1.0.0  Criacao inicial
 *   2026-05-31  v1.1.0  APP_URL agora e dinamico (detecta
 *                       protocolo + host em tempo de execucao)
 *                       para suportar dev/prod sem alteracao.
 */

declare(strict_types=1);

if (!function_exists('is_ambiente_dev')) {
    function is_ambiente_dev(): bool {
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
        
        if ($host === 'localhost') {
            return true;
        }
        
        // Domínios locais comuns (.local, .test, .dev)
        if (preg_match('/\.(local|test|dev)(:[0-9]+)?$/', $host)) {
            return true;
        }
        
        // IPs privados (10.x, 192.168.x, 172.16.x) ou loopback
        $ip = preg_replace('/:[0-9]+$/', '', $host);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return true;
            }
        }
        
        return false;
    }
}

define('APP_NAME', 'Controlador de Injecao de Potencia Eletrica');
define('APP_ENV',  is_ambiente_dev() ? 'development' : 'production');

// APP_URL dinamico: detecta protocolo e host do request atual.
// Em CLI (sem $_SERVER), usa valor de fallback de producao.
if (!defined('APP_URL')) {
    $_proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        ? 'https'
        : 'http';
    $_host  = $_SERVER['HTTP_HOST'] ?? 'monitor.aeonium.com.br';
    define('APP_URL', $_proto . '://' . $_host);
}

// APP_BASE_PATH mantido para compatibilidade com codigo legado.
// Agora reflete o mesmo valor detectado em includes/config.php.
if (!defined('APP_BASE_PATH')) {
    // Carrega config.php se ainda nao foi carregado (define APP_BASE_URL)
    if (!defined('APP_BASE_URL')) {
        require_once __DIR__ . '/../includes/config.php';
    }
    define('APP_BASE_PATH', APP_BASE_URL);
}

define('SESSION_NAME', 'CIPESID');
define('SESSION_LIFETIME', 7200);

// Em DEV, sincroniza telemetria a cada 15 minutos (900s). Em PROD, a cada 60s.
define('TELEMETRIA_CICLO_SEGUNDOS', is_ambiente_dev() ? 900 : 60);

date_default_timezone_set('America/Sao_Paulo');
