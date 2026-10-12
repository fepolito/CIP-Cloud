<?php
/**
 * @arquivo       api/sync/backfill_historico.php
 * @versao        1.0.0
 * @modificado_em 2026-10-12
 * @objetivo      Backfill historico de telemetria (Solis + FoxESS), dia a dia,
 *                reaproveitando a logica de bucket/consolidacao dos Ingestors
 *                de tempo real. Rodavel apenas via CLI, com checkpoint para
 *                retomada em caso de interrupcao. Nao e endpoint web.
 * @autor         Fernando / CIP Cloud Copilot / ATGY
 */
declare(strict_types=1);

$isCli = (PHP_SAPI === 'cli');

$cfgSolis  = require __DIR__ . '/../../config/solis.php';
$cfgFoxEss = require __DIR__ . '/../../config/foxess.php';

// Auth: CLI e liberado; HTTP exige token valido (ex: ?token=aeonium123)
if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
    $token = $_GET['token'] ?? '';
    $cronToken = $cfgSolis['cron_token'] ?? 'aeonium123';
    if (!hash_equals((string)$cronToken, (string)$token)) {
        http_response_code(403);
        exit("Acesso negado: informe token valido (?token=...) ou execute via CLI.\n");
    }
    @set_time_limit(0);
    @ignore_user_abort(true);
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../app/services/Solis/SolisClient.php';
require_once __DIR__ . '/../../app/services/Solis/SolisIngestor.php';
require_once __DIR__ . '/../../app/services/FoxEss/FoxEssClient.php';
require_once __DIR__ . '/../../app/services/FoxEss/FoxEssIngestor.php';

use App\Services\FoxEss\FoxEssClient;
use App\Services\FoxEss\FoxEssIngestor;

$opts   = $isCli ? (getopt('', ['inicio:', 'fim:', 'marca:']) ?: []) : [];
$inicio = $opts['inicio'] ?? $_GET['inicio'] ?? gmdate('Y-m-d', time() - 30 * 86400);
$fim    = $opts['fim']    ?? $_GET['fim']    ?? gmdate('Y-m-d');
$marca  = $opts['marca']  ?? $_GET['marca']  ?? 'todas'; // solis|foxess|todas

$pdo = getDbConnection();

$pdo->exec("CREATE TABLE IF NOT EXISTS backfill_checkpoint (
    id INT AUTO_INCREMENT PRIMARY KEY,
    marca VARCHAR(20) NOT NULL,
    referencia VARCHAR(100) NOT NULL,
    data_processada DATE NOT NULL,
    status ENUM('ok','erro') NOT NULL,
    mensagem TEXT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_marca_ref_data (marca, referencia, data_processada)
) ENGINE=InnoDB");


function jaProcessado(PDO $pdo, string $marca, string $ref, string $data): bool
{
    $st = $pdo->prepare("SELECT 1 FROM backfill_checkpoint WHERE marca=:m AND referencia=:r AND data_processada=:d AND status='ok'");
    $st->execute([':m' => $marca, ':r' => $ref, ':d' => $data]);
    return (bool) $st->fetchColumn();
}

function marcarCheckpoint(PDO $pdo, string $marca, string $ref, string $data, string $status, string $msg = ''): void
{
    $st = $pdo->prepare("INSERT INTO backfill_checkpoint (marca, referencia, data_processada, status, mensagem)
                          VALUES (:m,:r,:d,:s,:msg)
                          ON DUPLICATE KEY UPDATE status=VALUES(status), mensagem=VALUES(mensagem), criado_em=UTC_TIMESTAMP()");
    $st->execute([':m' => $marca, ':r' => $ref, ':d' => $data, ':s' => $status, ':msg' => $msg]);
}

function listaDias(string $inicio, string $fim): array
{
    $dias = [];
    $cur = new DateTimeImmutable($inicio);
    $end = new DateTimeImmutable($fim);
    while ($cur <= $end) {
        $dias[] = $cur->format('Y-m-d');
        $cur = $cur->modify('+1 day');
    }
    return $dias;
}

function logMsg(string $msg): void
{
    echo $msg . "\n";
    $logDir = __DIR__ . '/../../storage/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0770, true);
    }
    @file_put_contents($logDir . '/backfill.log', date('Y-m-d H:i:s') . ' ' . $msg . "\n", FILE_APPEND);
}

$dias = listaDias($inicio, $fim);
logMsg("Backfill de {$inicio} a {$fim} ({$marca}) — " . count($dias) . " dias.");

// ===================== SOLIS =====================
if ($marca === 'solis' || $marca === 'todas') {
    $solis = class_exists('\App\Services\Solis\SolisIngestor')
        ? new \App\Services\Solis\SolisIngestor($pdo, $cfgSolis)
        : new \SolisIngestor($pdo, $cfgSolis);

    $inversores = $solis->listarInversoresParaBackfill();
    logMsg("[SOLIS] " . count($inversores) . " inversores encontrados.");

    foreach ($dias as $dia) {
        foreach ($inversores as $inv) {
            $ref = (string)($inv['inverterId'] ?? $inv['sn']);
            if (jaProcessado($pdo, 'solis', $ref, $dia)) {
                continue;
            }
            try {
                $qtd = $solis->backfillDia($inv, $dia);
                marcarCheckpoint($pdo, 'solis', $ref, $dia, 'ok', "{$qtd} buckets");
                logMsg("[SOLIS] {$dia} inv={$ref}: {$qtd} buckets OK");
            } catch (Throwable $e) {
                marcarCheckpoint($pdo, 'solis', $ref, $dia, 'erro', $e->getMessage());
                logMsg("[SOLIS] {$dia} inv={$ref}: ERRO {$e->getMessage()}");
            }
            usleep(300000);
        }
    }
}

// ===================== FOXESS =====================
if ($marca === 'foxess' || $marca === 'todas') {
    $client = new FoxEssClient($cfgFoxEss);
    $fox = new FoxEssIngestor($pdo, $client);
    $grupos = $fox->listarGruposParaBackfill();
    logMsg("[FOXESS] " . count($grupos) . " grupos (controladores) encontrados.");

    foreach ($dias as $dia) {
        foreach ($grupos as $grupo) {
            foreach ($grupo['sns'] as $sn) {
                // Monta array equipamento compativel com backfillDiaEquipamento
                $eq = ['sn' => $sn, 'vinculo_id' => $grupo['vinculo_id_por_sn'][$sn] ?? null];
                if ($eq['vinculo_id'] === null) {
                    logMsg("[FOXESS] AVISO: vinculo_id nao resolvido para SN={$sn}, pulando.");
                    continue;
                }

                $ref = $sn;
                if (jaProcessado($pdo, 'foxess', $ref, $dia)) {
                    continue;
                }
                try {
                    $qtd = $fox->backfillDiaEquipamento($grupo, $eq, $dia);
                    marcarCheckpoint($pdo, 'foxess', $ref, $dia, 'ok', "{$qtd} buckets");
                    logMsg("[FOXESS] {$dia} sn={$ref}: {$qtd} buckets OK");
                } catch (Throwable $e) {
                    marcarCheckpoint($pdo, 'foxess', $ref, $dia, 'erro', $e->getMessage());
                    logMsg("[FOXESS] {$dia} sn={$ref}: ERRO {$e->getMessage()}");
                }
                usleep(300000);
            }

            try {
                $fox->consolidarDiaGrupo($grupo, $dia);
                logMsg("[FOXESS] {$dia} ctrl={$grupo['controlador_id']}: consolidado.");
            } catch (Throwable $e) {
                logMsg("[FOXESS] {$dia} ctrl={$grupo['controlador_id']}: ERRO ao consolidar: {$e->getMessage()}");
            }
        }
    }
}

logMsg("Backfill concluido.");

