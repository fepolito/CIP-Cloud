<?php
// ============================================================
// Projeto      : CIP - Controlador de Injecao de Potencia Eletrica
// Arquivo      : config/database.php
// Objetivo     : Parametros de conexao e factory PDO para MySQL
// Dependencias de hardware:
//   - Servidor com MySQL/MariaDB habilitado
// Dependencias de software:
//   - PHP 8.2+
//   - Extensao PDO MySQL
// Historico:
//   2026-04-07  v1.0.0  Implementacao inicial (array de config)
//   2026-04-08  v1.1.0  Adicionado getDbConnection() — factory PDO
//                       com DSN, charset, atributos de seguranca
//                       e excecao em caso de falha de conexao
//   2026-04-08  v1.1.1  Adicionado return DB_CONFIG para compatibilidade
//                       com Database.php (Singleton via require)
// ============================================================

declare(strict_types=1);

// ── Configuracao de conexao ───────────────────────────────────
const DB_CONFIG = [
    'host'     => 'localhost',
    'port'     => '3306',
    'database' => 'aeoniu71_monitor',
    'username' => 'aeoniu71_monitor',
    'password' => '388144P@ol@',
    'charset'  => 'utf8mb4',
];

// ── Factory PDO ───────────────────────────────────────────────
// Retorna uma conexao PDO configurada com:
//   - ERRMODE_EXCEPTION  → lanca PDOException em erros
//   - FETCH_ASSOC        → fetch padrao como array associativo
//   - EMULATE_PREPARES   → false (prepared statements nativos)
//   - charset utf8mb4    → suporte completo a Unicode / Emoji
function getDbConnection(): PDO
{
    $cfg = DB_CONFIG;

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        $cfg['host'],
        $cfg['port'],
        $cfg['database'],
        $cfg['charset']
    );

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        return new PDO($dsn, $cfg['username'], $cfg['password'], $options);
    } catch (PDOException $e) {
        error_log('[CIP][DB] Falha na conexao: ' . $e->getMessage());
        throw new RuntimeException('Erro ao conectar ao banco de dados.');
    }
}

// ── Retorno para require/include ──────────────────────────────
// Necessário para Database.php (Singleton) que faz:
//   $config = require 'config/database.php';
return DB_CONFIG;
