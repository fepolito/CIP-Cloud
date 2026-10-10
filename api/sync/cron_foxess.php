<?php
/**
 * ============================================================
 * Arquivo   : api/sync/cron_foxess.php
 * Projeto   : CIP — Controlador de Injeção de Potência Elétrica
 * Versão    : 1.0.0
 * Modificado: 2026-10-06
 * Objetivo  : Endpoint/CLI de cron (5min) que dispara a coleta de telemetria
 *             dos inversores FoxESS Cloud. Suporta parâmetro ?debug=1 para teste.
 *
 * Dependências de software/arquivos:
 *   - config/foxess.php
 *   - config/database.php
 *   - app/services/FoxEss/FoxEssClient.php
 *   - app/services/FoxEss/FoxEssIngestor.php
 *
 * Histórico de implementações:
 *   2026-10-06  v1.0.0  Criação inicial do endpoint de sincronização FoxESS
 * ============================================================
 */

declare(strict_types=1);

use App\Services\FoxEss\FoxEssClient;
use App\Services\FoxEss\FoxEssIngestor;

$isCli = (PHP_SAPI === 'cli');

if (!$isCli) {
    header('Content-Type: application/json; charset=utf-8');
}

$cfg = require __DIR__ . '/../../config/foxess.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../app/services/FoxEss/FoxEssClient.php';
require_once __DIR__ . '/../../app/services/FoxEss/FoxEssIngestor.php';

// Suporte a argumentos CLI (ex: php cron_foxess.php debug=1)
if ($isCli && isset($argv)) {
    foreach ($argv as $arg) {
        if (str_contains($arg, 'debug=1') || $arg === '--debug') {
            $_GET['debug'] = '1';
        }
        if (str_contains($arg, 'devicelist')) {
            $_GET['devicelist'] = '1';
        }
    }
}

// Autenticação: HTTP exige token; CLI é ambiente confiável
if (!$isCli) {
    $token = $_GET['token'] ?? '';
    if ($cfg['cron_token'] === '' || !hash_equals($cfg['cron_token'], (string)$token)) {
        http_response_code(403);
        echo json_encode(['sucesso' => false, 'erro' => 'Acesso negado: token inválido ou ausente.']);
        exit;
    }
}

try {
    $pdo      = getDbConnection();
    $client   = new FoxEssClient($cfg);
    $ingestor = new FoxEssIngestor($pdo, $client);

    // Modo devicelist cru (para validação exclusiva de credencial na conta)
    if (isset($_GET['devicelist']) && $_GET['devicelist'] === '1') {
        $debugData = $client->deviceList();
        $out = json_encode(['sucesso' => true, 'modo' => 'debug_device_list', 'data' => $debugData], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        echo $isCli ? ($out . PHP_EOL) : $out;
        exit;
    }

    $stats = $ingestor->run();

    $logDir = __DIR__ . '/../../storage/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0770, true);
    }
    @file_put_contents(
        $logDir . '/foxess_cron.log',
        gmdate('c') . ' ' . ($isCli ? '[CLI] ' : '[HTTP] ') . json_encode($stats) . PHP_EOL,
        FILE_APPEND
    );

    $out = json_encode(['sucesso' => true, 'data' => $stats], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    echo $isCli ? ($out . PHP_EOL) : $out;

} catch (Throwable $e) {
    if (!$isCli) {
        http_response_code(500);
    }
    $errPayload = [
        'sucesso' => false,
        'erro'    => $e->getMessage(),
    ];
    echo json_encode($errPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    @error_log('[foxess_cron] Erro: ' . $e->getMessage());

    if ($isCli) {
        fwrite(STDERR, PHP_EOL . '[Erro FoxESS] ' . $e->getMessage() . PHP_EOL);
    }
}
