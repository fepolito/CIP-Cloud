<?php
/**
 * ============================================================
 * Arquivo   : config/foxess.php
 * Projeto   : CIP — Controlador de Injeção de Potência Elétrica
 * Versão    : 1.0.0
 * Modificado: 2026-10-06
 * Objetivo  : Credenciais e parâmetros de conexão com a FoxESS Cloud OpenAPI
 *
 * Dependências de software/arquivos:
 *   - config/app.php
 *   - .env (opcional em DEV)
 *
 * Histórico de implementações:
 *   2026-10-06  v1.0.0  Criação inicial da configuração FoxESS
 * ============================================================
 */

declare(strict_types=1);

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (str_contains($line, '=')) {
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k);
            $v = trim($v, " \t\n\r\0\x0B\"'");
            if (getenv($k) === false) {
                putenv("{$k}={$v}");
                $_ENV[$k] = $v;
            }
        }
    }
}

return [
    'base_url'   => getenv('FOXESS_BASE_URL')   ?: 'https://www.foxesscloud.com',
    'api_key'    => getenv('FOXESS_API_KEY')    ?: '2cad6282-dc06-44e8-98f5-fa111ec3c2b2',
    'cron_token' => getenv('FOXESS_CRON_TOKEN') ?: 'aeonium_fox_8d4e9b21f37c5a06',
    'timeout'    => 30,
];
