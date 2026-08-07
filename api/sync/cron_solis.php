<?php
/**
 * @arquivo       api/sync/cron_solis.php
 * @versao        1.1.1
 * @modificado_em 2026-08-07
 * @objetivo      Endpoint/CLI de cron (5min) que dispara SolisIngestor.
 *                HTTP exige token; CLI (php-cli) é liberado (ambiente confiável).
 * @autor         Fernando / CIP Cloud Copilot / ATGY
 */
declare(strict_types=1);

$isCli = (PHP_SAPI === 'cli');

if (!$isCli) {
    header('Content-Type: application/json; charset=utf-8');
}

$cfg = require __DIR__ . '/../../config/solis.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../app/services/Solis/SolisIngestor.php';

// Auth: HTTP exige token; CLI (ou --token=) tambem aceito.
if (!$isCli) {
    $token = $_GET['token'] ?? '';
    if ($cfg['cron_token'] === '' || !hash_equals($cfg['cron_token'], (string)$token)) {
        http_response_code(403);
        echo json_encode(['erro' => 'forbidden']);
        exit;
    }
}

try {
    $pdo = getDbConnection();
    $stats = (new SolisIngestor($pdo, $cfg))->run();

    $logDir = __DIR__ . '/../../storage/logs';
    if (!is_dir($logDir)) { @mkdir($logDir, 0770, true); }
    @file_put_contents(
        $logDir . '/solis_cron.log',
        gmdate('c') . ' ' . ($isCli ? '[CLI] ' : '[HTTP] ') . json_encode($stats) . PHP_EOL,
        FILE_APPEND
    );

    $out = json_encode(['sucesso' => true, 'data' => $stats], JSON_PRETTY_PRINT);
    echo $isCli ? ($out . PHP_EOL) : $out;
} catch (Throwable $e) {
    if (!$isCli) { http_response_code(500); }
    echo json_encode(['erro' => 'ingestor_falhou']);
    
    if (str_contains($e->getMessage(), 'refused') || str_contains($e->getMessage(), '13333')) {
        @error_log('[solis_cron] aguardando liberacao porta 13333 (esperado)');
    } else {
        @error_log('[solis_cron] Solis API erro: ' . $e->getMessage());
    }

    if ($isCli) { fwrite(STDERR, PHP_EOL . $e->getMessage() . PHP_EOL); } // debug visível em DEV
}
