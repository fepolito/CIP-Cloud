<?php
/**
 * @arquivo       api/sync/cron_solis.php
 * @versao        1.0.0
 * @modificado_em 2026-08-04
 * @objetivo      Endpoint de cron (a cada 5min) que dispara SolisIngestor.
 *                Protegido por token timing-safe. Loga resultado em storage/.
 * @autor         Fernando / CIP Cloud Copilot / ATGY
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$cfg = require __DIR__ . '/../../config/solis.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../app/services/Solis/SolisIngestor.php';

$token = $_GET['token'] ?? '';
if ($cfg['cron_token'] === '' || !hash_equals($cfg['cron_token'], (string)$token)) {
    http_response_code(403);
    echo json_encode(['erro' => 'forbidden']);
    exit;
}

try {
    $pdo = getDbConnection();
    $ingestor = new SolisIngestor($pdo, $cfg);
    $stats = $ingestor->run();

    $logDir = __DIR__ . '/../../storage/logs';
    if (!is_dir($logDir)) { @mkdir($logDir, 0770, true); }
    @file_put_contents(
        $logDir . '/solis_cron.log',
        gmdate('c') . ' ' . json_encode($stats) . PHP_EOL,
        FILE_APPEND
    );

    echo json_encode(['sucesso' => true, 'data' => $stats]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['erro' => 'ingestor_falhou', 'message' => $e->getMessage()]); // stack opcional para debug local
    @error_log('[solis_cron] ' . $e->getMessage());
}
