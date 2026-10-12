<?php
/**
 * @arquivo       api/sync/cron_run_all.php
 * @versao        1.0.0
 * @modificado_em 2026-10-12
 * @objetivo      Orquestrador único de cron: executa ingestão Solis e FoxESS
 *                em uma única invocação PHP, dentro do limite de 15 min
 *                imposto pelo cron compartilhado da HostGator.
 * @autor         Fernando / CIP Cloud Copilot / ATGY
 */
declare(strict_types=1);

$inicio = microtime(true);
$resultado = ['solis' => null, 'foxess' => null];

try {
    ob_start();
    require __DIR__ . '/cron_solis.php';
    $resultado['solis'] = json_decode(ob_get_clean(), true) ?? 'ok (sem saida parseavel)';
} catch (Throwable $e) {
    ob_end_clean();
    $resultado['solis'] = 'ERRO: ' . $e->getMessage();
}

try {
    ob_start();
    require __DIR__ . '/cron_foxess.php';
    $resultado['foxess'] = json_decode(ob_get_clean(), true) ?? 'ok (sem saida parseavel)';
} catch (Throwable $e) {
    ob_end_clean();
    $resultado['foxess'] = 'ERRO: ' . $e->getMessage();
}

$duracao = round(microtime(true) - $inicio, 2);
$logDir = __DIR__ . '/../../storage/logs';
if (!is_dir($logDir)) { @mkdir($logDir, 0770, true); }
@file_put_contents(
    $logDir . '/cron_run_all.log',
    gmdate('c') . ' [CLI] duracao=' . $duracao . 's ' . json_encode($resultado, JSON_UNESCAPED_UNICODE) . PHP_EOL,
    FILE_APPEND
);

echo json_encode(['sucesso' => true, 'duracao_s' => $duracao, 'resultado' => $resultado], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
