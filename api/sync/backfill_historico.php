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

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Acesso negado: script exclusivo CLI.');
}

require_once __DIR__ . '/../../config/database.php';
$cfgSolis  = require __DIR__ . '/../../config/solis.php';
$cfgFoxEss = require __DIR__ . '/../../config/foxess.php';
require_once __DIR__ . '/../../app/services/Solis/SolisClient.php';
require_once __DIR__ . '/../../app/services/Solis/SolisIngestor.php';
require_once __DIR__ . '/../../app/services/FoxEss/FoxEssClient.php';
require_once __DIR__ . '/../../app/services/FoxEss/FoxEssIngestor.php';

use App\Services\FoxEss\FoxEssClient;
use App\Services\FoxEss\FoxEssIngestor;

$opts = getopt('', ['inicio:', 'fim:', 'marca:']);
$inicio = $opts['inicio'] ?? gmdate('Y-m-d', time() - 30 * 86400);
$fim    = $opts['fim']    ?? gmdate('Y-m-d');
$marca  = $opts['marca']  ?? 'todas'; // solis|foxess|todas

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

$dias = listaDias($inicio, $fim);
echo "Backfill de {$inicio} a {$fim} ({$marca}) — " . count($dias) . " dias.\n";

// ===================== SOLIS =====================
if ($marca === 'solis' || $marca === 'todas') {
    $solis = class_exists('\App\Services\Solis\SolisIngestor')
        ? new \App\Services\Solis\SolisIngestor($pdo, $cfgSolis)
        : new \SolisIngestor($pdo, $cfgSolis);

    $inversores = $solis->listarInversoresParaBackfill();
    echo "[SOLIS] " . count($inversores) . " inversores encontrados.\n";

    foreach ($dias as $dia) {
        foreach ($inversores as $inv) {
            $ref = (string)($inv['inverterId'] ?? $inv['sn']);
            if (jaProcessado($pdo, 'solis', $ref, $dia)) {
                continue;
            }
            try {
                $qtd = $solis->backfillDia($inv, $dia);
                marcarCheckpoint($pdo, 'solis', $ref, $dia, 'ok', "{$qtd} buckets");
                echo "[SOLIS] {$dia} inv={$ref}: {$qtd} buckets OK\n";
            } catch (Throwable $e) {
                marcarCheckpoint($pdo, 'solis', $ref, $dia, 'erro', $e->getMessage());
                echo "[SOLIS] {$dia} inv={$ref}: ERRO {$e->getMessage()}\n";
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
    echo "[FOXESS] " . count($grupos) . " grupos (controladores) encontrados.\n";

    foreach ($dias as $dia) {
        foreach ($grupos as $grupo) {
            foreach ($grupo['sns'] as $sn) {
                // Monta array equipamento compativel com backfillDiaEquipamento
                $eq = ['sn' => $sn, 'vinculo_id' => $grupo['vinculo_id_por_sn'][$sn] ?? null];
                if ($eq['vinculo_id'] === null) {
                    echo "[FOXESS] AVISO: vinculo_id nao resolvido para SN={$sn}, pulando.\n";
                    continue;
                }

                $ref = $sn;
                if (jaProcessado($pdo, 'foxess', $ref, $dia)) {
                    continue;
                }
                try {
                    $qtd = $fox->backfillDiaEquipamento($grupo, $eq, $dia);
                    marcarCheckpoint($pdo, 'foxess', $ref, $dia, 'ok', "{$qtd} buckets");
                    echo "[FOXESS] {$dia} sn={$ref}: {$qtd} buckets OK\n";
                } catch (Throwable $e) {
                    marcarCheckpoint($pdo, 'foxess', $ref, $dia, 'erro', $e->getMessage());
                    echo "[FOXESS] {$dia} sn={$ref}: ERRO {$e->getMessage()}\n";
                }
                usleep(300000);
            }

            try {
                $fox->consolidarDiaGrupo($grupo, $dia);
                echo "[FOXESS] {$dia} ctrl={$grupo['controlador_id']}: consolidado.\n";
            } catch (Throwable $e) {
                echo "[FOXESS] {$dia} ctrl={$grupo['controlador_id']}: ERRO ao consolidar: {$e->getMessage()}\n";
            }
        }
    }
}

echo "Backfill concluido.\n";
