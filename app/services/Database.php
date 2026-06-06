<?php
// ============================================================
// Projeto   : CIP - Controlador de Injeção de Potência Elétrica
// Arquivo   : app/services/Database.php
// Objetivo  : Centralizar criação e gerenciamento da conexão
//             PDO com o banco de dados MySQL/MariaDB.
//             Implementa padrão Singleton thread-safe.
// Dependências de hardware:
//   - Servidor MySQL/MariaDB acessível via localhost:3306
// Dependências de arquivos:
//   - config/database.php  (array de credenciais PDO)
// Histórico :
//   2026-04-06  v1.0.0  Criação da classe Singleton PDO
//   2026-04-07  v1.1.0  Corrigido exit() texto puro → respondError JSON
//   2026-04-07  v1.1.1  Criação automática de storage/logs/
//                        Fallback de log para error_log nativo do PHP
// ============================================================

declare(strict_types=1);

namespace App\Services;

use PDO;
use PDOException;

final class Database
{
    private static ?PDO $connection = null;

    private function __construct() {}

    public static function getConnection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $config = require __DIR__ . '/../../config/database.php';

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $config['host'],
            $config['port'],
            $config['database'],
            $config['charset']
        );

        try {
            self::$connection = new PDO($dsn, $config['username'], $config['password'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);

        } catch (PDOException $e) {

            // -- Log seguro com criação automática do diretório ----------
            $logDir  = __DIR__ . '/../../storage/logs';
            $logFile = $logDir . '/app.log';

            if (!is_dir($logDir)) {
                mkdir($logDir, 0755, true);
            }

            $entry = sprintf(
                '[%s] FATAL DB: %s%s',
                date('Y-m-d H:i:s'),
                $e->getMessage(),
                PHP_EOL
            );

            // Tenta gravar no arquivo; se falhar, usa error_log do PHP
            if (is_writable($logDir)) {
                file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
            } else {
                error_log($entry);
            }

            // -- Resposta JSON padronizada --------------------------------
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success'   => false,
                'message'   => 'Erro interno ao conectar com o banco de dados.',
                'data'      => null,
                'timestamp' => date('c'),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        return self::$connection;
    }
}
