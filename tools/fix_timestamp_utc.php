<?php
/**
 * Arquivo: tools/fix_timestamp_utc.php
 * Projeto: Controlador de Injeção de Potência Elétrica
 * Objetivo: Corrigir timestamps da tabela telemetria_5min
 *           que foram gravados com horário local (UTC-3)
 *           no lugar de UTC verdadeiro.
 *           soma 6 horas de todos os registros.
 * Dependências de hardware:
 *   - Servidor MySQL com acesso à tabela telemetria_5min
 * Dependências de software:
 *   - PHP 8.3+
 *   - config/app.php
 *   - config/database.php
 * ⚠️  ATENÇÃO: Executar UMA ÚNICA VEZ. Remover após confirmação.
 * Histórico:
 *   2026-04-11  v1.0.0  Criação — correção de fuso UTC-3 → UTC
 */

declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

// ─── Segurança: só roda via CLI ou com token ──────────────────
$token = $_GET['token'] ?? '';
$tokenEsperado = 'CIPE_FIX_2026'; // ← troque se quiser

if (PHP_SAPI !== 'cli' && $token !== $tokenEsperado) {
    http_response_code(403);
    die('Acesso negado. Use ?token=CIPE_FIX_2026');
}

try {
    $pdo = getDbConnection();

    // ─── 1. Total de registros ────────────────────────────────
    $total = (int) $pdo->query(
        "SELECT COUNT(*) FROM telemetria_5min"
    )->fetchColumn();

    echo "📊 Total de registros encontrados: {$total}\n\n";

    // ─── 2. Amostra ANTES da correção ────────────────────────
    $antes = $pdo->query(
        "SELECT id, timestamp_utc 
         FROM telemetria_5min 
         ORDER BY id ASC 
         LIMIT 5"
    )->fetchAll(PDO::FETCH_ASSOC);

    echo "🕐 Amostra ANTES da correção:\n";
    foreach ($antes as $row) {
        echo "   ID {$row['id']} → {$row['timestamp_utc']}\n";
    }

    // ─── 3. Backup de segurança (cria tabela de backup) ──────
    echo "\n⏳ Criando backup da tabela...\n";

    $pdo->exec("DROP TABLE IF EXISTS telemetria_5min_backup_20260411");
    $pdo->exec("
        CREATE TABLE telemetria_5min_backup_20260411
        AS SELECT * FROM telemetria_5min
    ");

    $totalBackup = (int) $pdo->query(
        "SELECT COUNT(*) FROM telemetria_5min_backup_20260411"
    )->fetchColumn();

    echo "✅ Backup criado: telemetria_5min_backup_20260411 ({$totalBackup} registros)\n";

    if ($totalBackup !== $total) {
        throw new RuntimeException(
            "❌ ABORT: Backup com {$totalBackup} registros, esperado {$total}. Operação cancelada."
        );
    }

    // ─── 4. Aplica a correção ─────────────────────────────────
    echo "\n⏳ Aplicando correção (timestamp_utc = timestamp_utc  +6h)...\n";

    $linhasAfetadas = $pdo->exec("
        UPDATE telemetria_5min
        SET timestamp_utc = DATE_ADD(timestamp_utc, INTERVAL 18 HOUR)
    ");

    echo "✅ Registros corrigidos: {$linhasAfetadas}\n";

    if ((int) $linhasAfetadas !== $total) {
        throw new RuntimeException(
            "⚠️  ATENÇÃO: {$linhasAfetadas} linhas afetadas, esperado {$total}. Verifique!"
        );
    }

    // ─── 5. Amostra APÓS a correção ───────────────────────────
    $depois = $pdo->query(
        "SELECT id, timestamp_utc 
         FROM telemetria_5min 
         ORDER BY id ASC 
         LIMIT 5"
    )->fetchAll(PDO::FETCH_ASSOC);

    echo "\n🕐 Amostra APÓS a correção:\n";
    foreach ($depois as $row) {
        echo "   ID {$row['id']} → {$row['timestamp_utc']}\n";
    }

    // ─── 6. Validação cruzada ─────────────────────────────────
    $divergentes = (int) $pdo->query("
        SELECT COUNT(*) 
        FROM telemetria_5min t
        JOIN telemetria_5min_backup_20260411 b ON t.id = b.id
        WHERE t.timestamp_utc != DATE_SUB(b.timestamp_utc, INTERVAL 3 HOUR)
    ")->fetchColumn();

    if ($divergentes > 0) {
        echo "\n⚠️  ATENÇÃO: {$divergentes} registros com divergência detectada!\n";
    } else {
        echo "\n✅ Validação cruzada: 0 divergências — correção perfeita!\n";
    }

    echo "\n🎉 CONCLUÍDO COM SUCESSO!\n";
    echo "ℹ️  Backup disponível em: telemetria_5min_backup_20260411\n";
    echo "ℹ️  Após confirmar os dados, execute:\n";
    echo "    DROP TABLE telemetria_5min_backup_20260411;\n";
    echo "\n⚠️  REMOVA este arquivo após a execução!\n";

} catch (RuntimeException $e) {
    echo "\n❌ ERRO: " . $e->getMessage() . "\n";
    exit(1);
} catch (PDOException $e) {
    echo "\n❌ ERRO PDO: " . $e->getMessage() . "\n";
    exit(1);
}
