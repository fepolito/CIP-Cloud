<?php
/**
 * @arquivo       api/sync/backfill_historico.php
 * @versao        1.1.0
 * @modificado_em 2026-10-12
 * @objetivo      Backfill historico de telemetria (Solis + FoxESS), dia a dia,
 *                reaproveitando a logica de bucket/consolidacao dos Ingestors
 *                de tempo real. Suporta execucao continua via CLI e paginacao
 *                automatica com auto-refresh via Web para prevenir Timeout 503.
 *                Garante criacao da tabela de checkpoint e seed das estacoes.
 * @autor         Fernando / CIP Cloud Copilot / ATGY
 */
declare(strict_types=1);

$isCli = (PHP_SAPI === 'cli');

$cfgSolis  = require __DIR__ . '/../../config/solis.php';
$cfgFoxEss = require __DIR__ . '/../../config/foxess.php';

// Auth: CLI e liberado; HTTP exige token valido (ex: ?token=aeonium123)
if (!$isCli) {
    header('Content-Type: text/html; charset=utf-8');
    $token = $_GET['token'] ?? '';
    $cronToken = $cfgSolis['cron_token'] ?? 'aeonium123';
    if (!hash_equals((string)$cronToken, (string)$token)) {
        http_response_code(403);
        exit("Acesso negado: informe token valido (?token=...) ou execute via CLI.<br>\n");
    }
    @set_time_limit(180);
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

function logMsg(string $msg, bool $isCli = true): void
{
    $logDir = __DIR__ . '/../../storage/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0770, true);
    }
    $line = date('Y-m-d H:i:s') . ' ' . $msg . "\n";
    @file_put_contents($logDir . '/backfill.log', $line, FILE_APPEND);

    if ($isCli) {
        echo $msg . "\n";
    } else {
        echo htmlspecialchars($msg) . "<br>\n";
        @ob_flush();
        @flush();
    }
}

require_once __DIR__ . '/../../app/services/Solis/SolisSeed.php';
\App\Services\Solis\SolisSeed::executarSeNecessario($pdo, function (string $msg) use ($isCli): void {
    logMsg($msg, $isCli);
});

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

$todosDias = listaDias($inicio, $fim);

// Instancia Ingestors
$solis = ($marca === 'solis' || $marca === 'todas')
    ? (class_exists('\App\Services\Solis\SolisIngestor')
        ? new \App\Services\Solis\SolisIngestor($pdo, $cfgSolis)
        : new \SolisIngestor($pdo, $cfgSolis))
    : null;
$inversoresSolis = $solis ? $solis->listarInversoresParaBackfill() : [];

$clientFox = ($marca === 'foxess' || $marca === 'todas') ? new FoxEssClient($cfgFoxEss) : null;
$fox = $clientFox ? new FoxEssIngestor($pdo, $clientFox) : null;
$gruposFox = $fox ? $fox->listarGruposParaBackfill() : [];

// Determina quais dias precisam rodar
$diasParaProcessar = [];
if (!$isCli) {
    // Via Web: busca apenas o PRIMEIRO dia pendente para nao estourar timeout 503
    foreach ($todosDias as $d) {
        $temPendente = false;
        if ($solis) {
            foreach ($inversoresSolis as $inv) {
                $ref = (string)($inv['inverterId'] ?? $inv['sn']);
                if (!jaProcessado($pdo, 'solis', $ref, $d)) {
                    $temPendente = true;
                    break;
                }
            }
        }
        if (!$temPendente && $fox) {
            foreach ($gruposFox as $g) {
                foreach ($g['sns'] as $sn) {
                    if (!jaProcessado($pdo, 'foxess', (string)$sn, $d)) {
                        $temPendente = true;
                        break 2;
                    }
                }
            }
        }
        if ($temPendente) {
            $diasParaProcessar = [$d];
            break;
        }
    }
} else {
    // Via CLI: roda todos os dias sequencialmente
    $diasParaProcessar = $todosDias;
}

if (!$isCli) {
    echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>Backfill Historico</title>";
    echo "<style>body{font-family:monospace;background:#0d1117;color:#c9d1d9;padding:20px;line-height:1.5}</style>";
    echo "</head><body>";
    echo "<h2>Backfill de Telemetria — CIP Cloud</h2>";
}

if (empty($diasParaProcessar)) {
    logMsg("🎉 PARABENS! Todos os dias de {$inicio} a {$fim} ja estao 100% concluidos no banco de dados!", $isCli);
    if (!$isCli) {
        echo "<p style='color:#3fb950;'>Todos os dados historicos estao gravados. Voce ja pode consultar o painel Energia!</p>";
        echo "</body></html>";
    }
    exit;
}

$diaAtualProcessado = $diasParaProcessar[0];
logMsg("Executando backfill do dia {$diaAtualProcessado} ({$marca})...", $isCli);

// ===================== SOLIS =====================
if ($solis) {
    foreach ($diasParaProcessar as $dia) {
        $qtdOk = 0;
        foreach ($inversoresSolis as $inv) {
            $ref = (string)($inv['inverterId'] ?? $inv['sn']);
            if (jaProcessado($pdo, 'solis', $ref, $dia)) {
                continue;
            }
            try {
                $qtd = $solis->backfillDia($inv, $dia);
                marcarCheckpoint($pdo, 'solis', $ref, $dia, 'ok', "{$qtd} buckets");
                logMsg("[SOLIS] {$dia} inv={$ref}: {$qtd} buckets OK", $isCli);
                $qtdOk++;
            } catch (Throwable $e) {
                marcarCheckpoint($pdo, 'solis', $ref, $dia, 'erro', $e->getMessage());
                logMsg("[SOLIS] {$dia} inv={$ref}: ERRO {$e->getMessage()}", $isCli);
            }
            usleep(100000); // 0.1s
        }

        // Consolida controladores Solis para o dia inteiro
        try {
            $qtdCons = $solis->consolidarDiaControladores($dia);
            logMsg("[SOLIS] {$dia}: {$qtdCons} buckets consolidados em telemetria_5min.", $isCli);
        } catch (Throwable $e) {
            logMsg("[SOLIS] {$dia}: ERRO consolidacao controladores: {$e->getMessage()}", $isCli);
        }
    }
}

// ===================== FOXESS =====================
if ($fox) {
    foreach ($diasParaProcessar as $dia) {
        foreach ($gruposFox as $grupo) {
            foreach ($grupo['sns'] as $sn) {
                $eq = ['sn' => $sn, 'vinculo_id' => $grupo['vinculo_id_por_sn'][$sn] ?? null];
                if ($eq['vinculo_id'] === null) {
                    continue;
                }

                $ref = (string)$sn;
                if (jaProcessado($pdo, 'foxess', $ref, $dia)) {
                    continue;
                }
                try {
                    $qtd = $fox->backfillDiaEquipamento($grupo, $eq, $dia);
                    marcarCheckpoint($pdo, 'foxess', $ref, $dia, 'ok', "{$qtd} buckets");
                    logMsg("[FOXESS] {$dia} sn={$ref}: {$qtd} buckets OK", $isCli);
                } catch (Throwable $e) {
                    marcarCheckpoint($pdo, 'foxess', $ref, $dia, 'erro', $e->getMessage());
                    logMsg("[FOXESS] {$dia} sn={$ref}: ERRO {$e->getMessage()}", $isCli);
                }
                usleep(100000);
            }

            try {
                $fox->consolidarDiaGrupo($grupo, $dia);
                logMsg("[FOXESS] {$dia} ctrl={$grupo['controlador_id']}: consolidado.", $isCli);
            } catch (Throwable $e) {
                logMsg("[FOXESS] {$dia} ctrl={$grupo['controlador_id']}: ERRO ao consolidar: {$e->getMessage()}", $isCli);
            }
        }
    }
}

logMsg("Dia {$diaAtualProcessado} concluido com sucesso!", $isCli);

// Se for WEB, recarrega automaticamente a pagina para fazer o proximo dia sem timeout
if (!$isCli) {
    $urlAtual = $_SERVER['REQUEST_URI'];
    echo "<hr><p style='color:#58a6ff;'>⏳ Dia {$diaAtualProcessado} gravado! Avancando para o proximo dia automaticamente em 2 segundos...</p>";
    echo "<script>setTimeout(function(){ window.location.reload(); }, 2000);</script>";
    echo "</body></html>";
}
