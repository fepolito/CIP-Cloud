<?php
// ============================================================
// Projeto   : CIP - Controlador de Injecao de Potencia Eletrica
// Arquivo   : api/config/database.php
// Objetivo  : Adaptador Singleton para a camada API REST.
//             Delega a conexao PDO ao servico central
//             App\Services\Database, eliminando duplicidade
//             de configuracao e garantindo fonte unica de
//             credenciais via config/database.php
// Dependencias de arquivos:
//   - config/database.php      (array de configuracao PDO)
//   - app/services/Database.php (servico PDO central)
// Historico :
//   2026-04-06  v1.0.0  Criacao da classe Database Singleton
//   2026-04-06  v1.0.1  Ajuste para classe Database::getInstance()
//   2026-04-07  v1.0.2  require env.php — corrige Undefined constant DB_HOST
//   2026-04-07  v1.1.0  Refatorado: elimina config duplicada.
//                        Delega conexao para App\Services\Database.
//                        Remove dependencia de api/config/env.php
// ============================================================

require_once __DIR__ . '/../../app/services/Database.php';

/**
 * Wrapper global compativel com Database::getInstance()
 * Toda a camada api/ continua funcionando sem alteracoes.
 * A conexao real e gerenciada por App\Services\Database.
 */
class Database
{
    public static function getInstance(): PDO
    {
        return \App\Services\Database::getConnection();
    }
}
