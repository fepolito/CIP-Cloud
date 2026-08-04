<?php
/**
 * Arquivo: app/services/Logger.php
 * Projeto: Controlador de Injeção de Potência Elétrica
 * Objetivo: Registrar eventos da aplicação em arquivo de log
 * Dependências de hardware:
 * - Servidor com sistema de arquivos gravável
 * Dependências de software:
 * - PHP 8.2+
 */

declare(strict_types=1);

namespace App\Services;

final class Logger
{
    public static function write(string $level, string $origin, string $message, array $context = []): void
    {
        $logFile = defined('APP_LOG_PATH')
            ? APP_LOG_PATH
            : __DIR__ . '/../../storage/logs/app.log';

        $line = sprintf(
            "[%s] [%s] [%s] %s %s%s",
            date('Y-m-d H:i:s'),
            strtoupper($level),
            $origin,
            $message,
            !empty($context) ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '',
            PHP_EOL
        );

        if (!is_dir(dirname($logFile))) {
            @mkdir(dirname($logFile), 0775, true);
        }

        @file_put_contents($logFile, $line, FILE_APPEND);
    }
}
