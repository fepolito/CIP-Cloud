<?php
/**
 * @arquivo       app/services/FoxEss/FoxEssIngestor.php
 * @versao        3.1.0
 * @modificado_em 2026-10-12
 * @objetivo      Idem v3.0.0, adicionado retry (2 tentativas, sleep 4s) para o
 *                bucket mais recente da janela quando algum equipamento do grupo
 *                nao reportou dado (upload atrasado na nuvem FoxESS), evitando
 *                soma parcial sem necessidade de sobrepor janelas entre execucoes.
 * @autor         Fernando / CIP Cloud Copilot / ATGY
 */
declare(strict_types=1);

namespace App\Services\FoxEss;

require_once __DIR__ . '/../Shared/TelemetriaConsolidador.php';

use App\Services\Shared\TelemetriaConsolidador;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Throwable;

final class FoxEssIngestor
{
    private TelemetriaConsolidador $consolidador;

    public function __construct(
        private readonly PDO $pdo,
        private readonly FoxEssClient $client,
        ?TelemetriaConsolidador $consolidador = null
    ) {
        $this->consolidador = $consolidador ?? new TelemetriaConsolidador($pdo);
    }

    public function run(): array
    {
        $buckets = $this->janelaBuckets();
        $grupos  = $this->buscarControladoresFox();

        $processados = 0;
        $erros = 0;
        $detalhes = [];

        foreach ($grupos as $grupo) {
            try {
                $this->processarGrupo($grupo, $buckets);
                $processados++;
                $sns = array_column($grupo['equipamentos'], 'sn');
                $detalhes[] = "OK controlador_id={$grupo['controlador_id']} ({$grupo['codigo']}) SNs=" . implode(',', $sns);
            } catch (Throwable $e) {
                $erros++;
                $detalhes[] = "ERRO controlador_id={$grupo['controlador_id']}: {$e->getMessage()}";
                $this->logErro($grupo['controlador_id'], $e->getMessage());
            }
        }

        return [
            'buckets_utc'  => $buckets,
            'processados'  => $processados,
            'erros'        => $erros,
            'detalhes'     => $detalhes,
        ];
    }

    /**
     * Gera os buckets de 5min (UTC) cobrindo a janela dos ultimos 15min.
     * Substitui o antigo bucketUtc() (ponto unico) para compensar o limite de
     * cron de 15min do hosting compartilhado.
     *
     * @return array<int,string> buckets em ordem cronologica (mais antigo -> mais recente)
     */
    private function janelaBuckets(int $janelaMin = 15): array
    {
        $fim = time();
        $fim -= $fim % 300;

        $buckets = [];
        $qtd = (int) ceil($janelaMin / 5);
        for ($i = $qtd - 1; $i >= 0; $i--) {
            $buckets[] = gmdate('Y-m-d H:i:s', $fim - ($i * 300));
        }
        return $buckets;
    }

    /**
     * @return array<int,array{controlador_id:int, codigo:string, empresa_id:int, timezone:string,
     *                          equipamentos:array<int,array{vinculo_id:int, sn:string, tipo:string}>}>
     */
    private function buscarControladoresFox(): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT c.id AS controlador_id, c.codigo, c.empresa_id, c.timezone,
                    ci.id AS vinculo_id, ci.inversor_sn, ci.tipo_equipamento
             FROM controladores c
             INNER JOIN controlador_inversores ci
                     ON ci.controlador_id = c.id
                    AND ci.marca = 'FOX-ESS'
                    AND ci.status = 'ativo'
                    AND ci.tipo_equipamento = 'inversor'
             WHERE c.status = 'ativo'
               AND c.tipo IN ('FOX-ESS-CLOUD', 'SOLIS-CLOUD')"
        );
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $grupos = [];
        foreach ($rows as $r) {
            $id = (int) $r['controlador_id'];
            if (!isset($grupos[$id])) {
                $grupos[$id] = [
                    'controlador_id' => $id,
                    'codigo'         => $r['codigo'],
                    'empresa_id'     => (int) $r['empresa_id'],
                    'timezone'       => $r['timezone'],
                    'equipamentos'   => [],
                ];
            }
            $grupos[$id]['equipamentos'][] = [
                'vinculo_id' => (int) $r['vinculo_id'],
                'sn'         => $r['inversor_sn'],
                'tipo'       => $r['tipo_equipamento'],
            ];
        }

        return array_values($grupos);
    }

    /**
     * Converte string de timestamp FoxESS (ex: "2026-10-12 13:03:08 BRT-0300")
     * para DateTimeImmutable em UTC real. Extrai o offset numerico explicito
     * (-0300) via regex, ignorando a abreviacao de fuso (BRT), que pode ser
     * ambigua entre bibliotecas/SO. Nunca confia em strtotime() puro com
     * abreviacao de timezone.
     */
    private function parseFoxTimeToUtc(string $time): ?DateTimeImmutable
    {
        if (!preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\s*[A-Za-z]*([+-]\d{4})$/', trim($time), $m)) {
            return null;
        }
        try {
            $dt = new DateTimeImmutable($m[1] . ' ' . $m[2]);
            return $dt->setTimezone(new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Despivota a resposta de historyQuery (agrupada por variavel) em um mapa
     * [bucket_utc => [variavel => valor]], usando o timestamp real de cada
     * amostra (convertido para UTC) para determinar o bucket de 5min.
     * Em caso de multiplas amostras no mesmo bucket, mantem a mais recente.
     *
     * @param array<int,array{variable:string,data:array<int,array{time:string,value:mixed}>}> $datas
     * @return array<string, array<string, mixed>>
     */
    private function pivotarPorBucket(array $datas): array
    {
        $porBucket = []; // bucket_utc => [variavel => valor, '_ts' => epoch_mais_recente]

        foreach ($datas as $variavel) {
            $nomeVar = (string)($variavel['variable'] ?? '');
            if ($nomeVar === '') continue;

            foreach (($variavel['data'] ?? []) as $amostra) {
                $dtUtc = $this->parseFoxTimeToUtc((string)($amostra['time'] ?? ''));
                if ($dtUtc === null) continue;

                $ts = $dtUtc->getTimestamp();
                $bucketSec = $ts - ($ts % 300);
                $bucket = gmdate('Y-m-d H:i:s', $bucketSec);

                if (!isset($porBucket[$bucket])) {
                    $porBucket[$bucket] = ['_ts' => $ts];
                }
                // Mantem o valor da amostra mais recente dentro do bucket.
                if ($ts >= $porBucket[$bucket]['_ts']) {
                    $porBucket[$bucket][$nomeVar] = $amostra['value'] ?? null;
                    $porBucket[$bucket]['_ts'] = $ts;
                }
            }
        }

        ksort($porBucket);
        return $porBucket;
    }

    private function processarGrupo(array $grupo, array $buckets): void
    {
        $ctrlId = (int)$grupo['controlador_id'];

        $stmtEq = $this->pdo->prepare(
            "INSERT INTO telemetria_equipamento_5min
                (controlador_inversor_id, timestamp_utc,
                 potencia_geracao_w, potencia_consumo_w,
                 potencia_exportada_w, potencia_importada_w,
                 energia_hoje_kwh, energia_total_kwh, energia_delta_kwh,
                 temperatura_c, status_equipamento, qualidade_dado)
             VALUES (:vid, :ts, :ger, :cons, :exp, :imp, :ehoje, :etot, :edelta, :temp, :status, 100)
             ON DUPLICATE KEY UPDATE
                 potencia_geracao_w   = VALUES(potencia_geracao_w),
                 potencia_consumo_w   = VALUES(potencia_consumo_w),
                 potencia_exportada_w = VALUES(potencia_exportada_w),
                 potencia_importada_w = VALUES(potencia_importada_w),
                 energia_hoje_kwh     = VALUES(energia_hoje_kwh),
                 energia_total_kwh    = VALUES(energia_total_kwh),
                 energia_delta_kwh    = VALUES(energia_delta_kwh),
                 temperatura_c        = VALUES(temperatura_c),
                 status_equipamento   = VALUES(status_equipamento),
                 qualidade_dado       = VALUES(qualidade_dado)"
        );

        $beginMs = (int)(strtotime($buckets[0] . ' UTC') * 1000);
        $endMs   = (int)((strtotime(end($buckets) . ' UTC') + 299) * 1000);

        $algumSucessoGrupo = false;
        $equipamentosProcessados = [];
        $ultimosPorSn = [];

        // Mapa: bucket -> somas acumuladas de todos os equipamentos do grupo
        $somasPorBucket = [];
        foreach ($buckets as $b) {
            $somasPorBucket[$b] = [
                'ger' => 0.0, 'carga' => 0.0, 'exp' => 0.0, 'imp' => 0.0,
                'delta' => 0.0, 'total' => 0.0, 'algumSucesso' => false,
                'qtd_equip' => 0,
            ];
        }

        foreach ($grupo['equipamentos'] as $eq) {
            $datasRaw = $this->client->historyQuery((string)$eq['sn'], $beginMs, $endMs);
            if ($datasRaw === null) {
                continue; // Equipamento indisponivel neste ciclo; segue com os demais
            }

            $pontosPorBucket = $this->pivotarPorBucket($datasRaw);
            if (empty($pontosPorBucket)) {
                continue;
            }

            $algumSucessoGrupo = true;
            $ultimoHoje = null; // referencia sequencial do todayYield dentro do loop
            $ultimoTotal = null;
            $tsAnterior = null;

            // Processa os buckets em ordem cronologica (contrato da janela),
            // mesmo que o equipamento so tenha retornado dados para alguns deles.
            foreach ($buckets as $bucket) {
                if (!isset($pontosPorBucket[$bucket])) continue;
                $vars = $pontosPorBucket[$bucket];

                $pvW     = ((float) ($vars['pvPower'] ?? $vars['generationPower'] ?? 0)) * 1000;
                $loadsW  = ((float) ($vars['loadsPower'] ?? 0)) * 1000;
                $feedinW = ((float) ($vars['feedinPower'] ?? 0)) * 1000;
                $gridW   = ((float) ($vars['gridConsumptionPower'] ?? 0)) * 1000;

                $todayYield = isset($vars['todayYield']) && is_numeric($vars['todayYield'])
                    ? (float) $vars['todayYield'] : null;

                $generationTotal = null;
                if (isset($vars['generation']) && is_numeric($vars['generation'])) {
                    $generationTotal = (float) $vars['generation'];
                } elseif (isset($vars['PVEnergyTotal']) && is_numeric($vars['PVEnergyTotal'])) {
                    $generationTotal = (float) $vars['PVEnergyTotal'];
                }

                $temperaturaC = isset($vars['invTemperation']) && is_numeric($vars['invTemperation'])
                    ? (float) $vars['invTemperation'] : null;
                $statusEq = isset($vars['runningState']) ? (string) $vars['runningState'] : null;

                $deltaInfo = $this->calcularDeltaEquipamentoSequencial(
                    (int) $eq['vinculo_id'],
                    $bucket,
                    $todayYield,
                    $generationTotal,
                    (string) ($grupo['timezone'] ?? 'America/Sao_Paulo'),
                    $ultimoHoje,
                    $ultimoTotal
                );
                $ultimoHoje  = $todayYield ?? $ultimoHoje;
                $ultimoTotal = $generationTotal ?? $ultimoTotal;

                $deltaKwh = $deltaInfo['delta_hoje_kwh'];

                $somasPorBucket[$bucket]['ger']   += $pvW;
                $somasPorBucket[$bucket]['carga'] += $loadsW;
                $somasPorBucket[$bucket]['exp']   += $feedinW;
                $somasPorBucket[$bucket]['imp']   += $gridW;
                $somasPorBucket[$bucket]['delta'] += $deltaKwh;
                $somasPorBucket[$bucket]['qtd_equip'] = ($somasPorBucket[$bucket]['qtd_equip'] ?? 0) + 1;
                if ($generationTotal !== null) {
                    $somasPorBucket[$bucket]['total'] += $generationTotal;
                }
                $somasPorBucket[$bucket]['algumSucesso'] = true;

                $stmtEq->execute([
                    'vid'    => (int) $eq['vinculo_id'],
                    'ts'     => $bucket,
                    'ger'    => $pvW,
                    'cons'   => $loadsW,
                    'exp'    => $feedinW,
                    'imp'    => $gridW,
                    'ehoje'  => $todayYield,
                    'etot'   => $generationTotal,
                    'edelta' => $deltaKwh,
                    'temp'   => $temperaturaC,
                    'status' => $statusEq,
                ]);

                $equipamentosProcessados[$eq['sn']][$bucket] = $vars;
                $ultimosPorSn[$eq['sn']] = ['hoje' => $ultimoHoje, 'total' => $ultimoTotal];
            }
        }

        // Retry para o bucket mais recente (upload atrasado na nuvem FoxESS).
        $this->retryBucketIncompleto(
            $grupo,
            $buckets,
            $somasPorBucket,
            $equipamentosProcessados,
            function (array $eq, string $bucket, array $vars) use (&$somasPorBucket, $stmtEq, $grupo, &$ultimosPorSn): void {
                $pvW     = ((float) ($vars['pvPower'] ?? $vars['generationPower'] ?? 0)) * 1000;
                $loadsW  = ((float) ($vars['loadsPower'] ?? 0)) * 1000;
                $feedinW = ((float) ($vars['feedinPower'] ?? 0)) * 1000;
                $gridW   = ((float) ($vars['gridConsumptionPower'] ?? 0)) * 1000;
                $todayYield = isset($vars['todayYield']) && is_numeric($vars['todayYield']) ? (float) $vars['todayYield'] : null;
                $generationTotal = isset($vars['generation']) && is_numeric($vars['generation'])
                    ? (float) $vars['generation']
                    : (isset($vars['PVEnergyTotal']) && is_numeric($vars['PVEnergyTotal']) ? (float) $vars['PVEnergyTotal'] : null);
                $temperaturaC = isset($vars['invTemperation']) && is_numeric($vars['invTemperation']) ? (float) $vars['invTemperation'] : null;
                $statusEq = isset($vars['runningState']) ? (string) $vars['runningState'] : null;

                $ref = $ultimosPorSn[$eq['sn']] ?? ['hoje' => null, 'total' => null];
                $deltaInfo = $this->calcularDeltaEquipamentoSequencial(
                    (int) $eq['vinculo_id'], $bucket, $todayYield, $generationTotal,
                    (string) ($grupo['timezone'] ?? 'America/Sao_Paulo'), $ref['hoje'], $ref['total']
                );
                $deltaKwh = $deltaInfo['delta_hoje_kwh'];

                $somasPorBucket[$bucket]['ger']   += $pvW;
                $somasPorBucket[$bucket]['carga'] += $loadsW;
                $somasPorBucket[$bucket]['exp']   += $feedinW;
                $somasPorBucket[$bucket]['imp']   += $gridW;
                $somasPorBucket[$bucket]['delta'] += $deltaKwh;
                $somasPorBucket[$bucket]['qtd_equip'] = ($somasPorBucket[$bucket]['qtd_equip'] ?? 0) + 1;
                if ($generationTotal !== null) {
                    $somasPorBucket[$bucket]['total'] += $generationTotal;
                }
                $somasPorBucket[$bucket]['algumSucesso'] = true;

                $stmtEq->execute([
                    'vid' => (int) $eq['vinculo_id'], 'ts' => $bucket,
                    'ger' => $pvW, 'cons' => $loadsW, 'exp' => $feedinW, 'imp' => $gridW,
                    'ehoje' => $todayYield, 'etot' => $generationTotal, 'edelta' => $deltaKwh,
                    'temp' => $temperaturaC, 'status' => $statusEq,
                ]);

                $ultimosPorSn[$eq['sn']] = ['hoje' => $todayYield ?? $ref['hoje'], 'total' => $generationTotal ?? $ref['total']];
            }
        );

        if (!$algumSucessoGrupo) {
            throw new RuntimeException('Nenhum equipamento do grupo respondeu nesta janela.');
        }

        // Consolida cada bucket sequencialmente (ordem cronologica da janela).
        foreach ($buckets as $bucket) {
            $soma = $somasPorBucket[$bucket];
            if (!$soma['algumSucesso']) continue; // bucket sem dados de nenhum equipamento

            $isExporting = $soma['exp'] > 0;

            $totalEquip = count($grupo['equipamentos']);
            $qualidade = $totalEquip > 0 ? (int) round(100 * ($soma['qtd_equip'] ?? 0) / $totalEquip) : 100;

            $this->consolidador->gravarContribuicaoMarca(
                $ctrlId, $bucket, 'FOX-ESS', $soma['ger'], round($soma['delta'], 4)
            );

            $this->consolidador->consolidar($ctrlId, $bucket, [
                'energia_geracao_total_kwh' => $soma['total'] > 0 ? round($soma['total'], 3) : null,
                'potencia_importada_w'      => $soma['imp'],
                'potencia_exportada_w'      => $soma['exp'],
                'potencia_consumo_total_w'  => $soma['carga'],
                'is_exporting'              => $isExporting ? 1 : 0,
                'direction_valid'           => 1,
                'power_injected_w'          => $soma['exp'],
                'qualidade_dado'            => $qualidade,
            ]);
        }

        $up = $this->pdo->prepare(
            "UPDATE controladores
                SET last_telemetry_at = UTC_TIMESTAMP(),
                    last_seen_at = UTC_TIMESTAMP(),
                    online = 1
              WHERE id = :id"
        );
        $up->execute(['id' => $ctrlId]);
    }

    /**
     * Executa retry (2 tentativas, sleep 4s) para o bucket mais recente da janela
     * quando algum equipamento do grupo nao reportou dado (upload atrasado na nuvem FoxESS).
     *
     * @param array $grupo
     * @param array<int,string> $buckets
     * @param array $somasPorBucket
     * @param array<string, array<string, array>> $equipamentosProcessados
     * @param callable $processarPonto
     */
    private function retryBucketIncompleto(
        array $grupo,
        array $buckets,
        array &$somasPorBucket,
        array &$equipamentosProcessados,
        callable $processarPonto
    ): void {
        $bucketMaisRecente = end($buckets);
        if (!$bucketMaisRecente) {
            return;
        }

        $faltando = [];
        foreach ($grupo['equipamentos'] as $eq) {
            $sn = (string)$eq['sn'];
            if (!isset($equipamentosProcessados[$sn][$bucketMaisRecente])) {
                $faltando[$sn] = $eq;
            }
        }

        if (empty($faltando)) {
            return;
        }

        $maxTentativas = 2;
        $sleepSegundos = 4;
        $beginMs = (int)(strtotime($buckets[0] . ' UTC') * 1000);
        $endMs   = (int) round(microtime(true) * 1000);

        for ($tentativa = 1; $tentativa <= $maxTentativas; $tentativa++) {
            sleep($sleepSegundos);

            foreach ($faltando as $sn => $eq) {
                $datasRaw = $this->client->historyQuery($sn, $beginMs, $endMs);
                if ($datasRaw === null) {
                    continue;
                }

                $pontos = $this->pivotarPorBucket($datasRaw);
                if (isset($pontos[$bucketMaisRecente])) {
                    $vars = $pontos[$bucketMaisRecente];
                    $processarPonto($eq, $bucketMaisRecente, $vars);
                    $equipamentosProcessados[$sn][$bucketMaisRecente] = $vars;
                    unset($faltando[$sn]);
                }
            }

            if (empty($faltando)) {
                break;
            }
        }
    }


    /**
     * Calcula o delta de energia (kWh) para um bucket de 5min de um inversor,
     * dentro do loop sequencial da janela. Prioriza comparar com o valor do
     * bucket anterior JA PROCESSADO NESTA EXECUCAO (parametros $refHoje/$refTotal);
     * se nao houver (ex: primeiro bucket da janela), cai para a consulta ao
     * banco (ultimo registro conhecido antes do bucket), preservando o
     * comportamento historico do metodo original.
     */
    private function calcularDeltaEquipamentoSequencial(
        int $vinculoId,
        string $bucketUtc,
        ?float $todayYield,
        ?float $generationTotal,
        string $tzStr,
        ?float $refHoje,
        ?float $refTotal
    ): array {
        if ($todayYield === null) {
            return ['delta_hoje_kwh' => 0.0, 'delta_total_kwh' => 0.0];
        }

        if ($refHoje === null) {
            // Nenhum bucket anterior processado nesta execucao -> consulta o banco.
            return $this->calcularDeltaViaBanco($vinculoId, $bucketUtc, $todayYield, $generationTotal, $tzStr);
        }

        $deltaHoje = $todayYield - $refHoje;

        if ($deltaHoje < 0.0) {
            // Virada de dia dentro da propria janela de 15min (raro, mas possivel perto da meia-noite local).
            $deltaHoje = ($todayYield >= 0.0 && $todayYield < 50.0) ? $todayYield : 0.0;
        } elseif ($deltaHoje > 50.0) {
            $this->logErro(0, sprintf(
                'Delta anomalo (sequencial) vinculo_id=%d bucket=%s (ref=%.3f, atual=%.3f).',
                $vinculoId, $bucketUtc, $refHoje, $todayYield
            ));
            $deltaHoje = 0.0;
        }

        $deltaTotal = 0.0;
        if ($generationTotal !== null && $refTotal !== null) {
            $diff = $generationTotal - $refTotal;
            $deltaTotal = ($diff >= 0.0 && $diff < 50.0) ? $diff : 0.0;
        }

        return [
            'delta_hoje_kwh'  => round(max(0.0, $deltaHoje), 4),
            'delta_total_kwh' => round(max(0.0, $deltaTotal), 4),
        ];
    }

    /** Fallback: calcula delta consultando o ultimo registro no banco antes do bucket (comportamento original). */
    private function calcularDeltaViaBanco(
        int $vinculoId,
        string $bucketUtc,
        float $todayYield,
        ?float $generationTotal,
        string $tzStr
    ): array {
        $sql = "SELECT energia_hoje_kwh, energia_total_kwh, timestamp_utc
                  FROM telemetria_equipamento_5min
                 WHERE controlador_inversor_id = :vid
                   AND timestamp_utc < :ts
                   AND energia_hoje_kwh IS NOT NULL
                 ORDER BY timestamp_utc DESC
                 LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':vid' => $vinculoId, ':ts' => $bucketUtc]);
        $last = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$last) {
            return ['delta_hoje_kwh' => 0.0, 'delta_total_kwh' => 0.0];
        }

        $lastToday = (float) $last['energia_hoje_kwh'];
        $deltaHoje = $todayYield - $lastToday;

        if ($deltaHoje < 0.0) {
            $isViradaDeDia = false;
            try {
                $dtBucket = new DateTimeImmutable($bucketUtc, new DateTimeZone('UTC'));
                $dtBucketLocal = $dtBucket->setTimezone(new DateTimeZone($tzStr ?: 'America/Sao_Paulo'));
                $dtAnt = new DateTimeImmutable((string)$last['timestamp_utc'], new DateTimeZone('UTC'));
                $dtAntLocal = $dtAnt->setTimezone(new DateTimeZone($tzStr ?: 'America/Sao_Paulo'));
                $mudouData = ($dtBucketLocal->format('Y-m-d') !== $dtAntLocal->format('Y-m-d'));
                $horaLocal = (int) $dtBucketLocal->format('H');
                $isViradaDeDia = $mudouData || ($horaLocal >= 0 && $horaLocal < 6);
            } catch (Throwable) {
                $isViradaDeDia = false;
            }

            if ($isViradaDeDia) {
                $deltaHoje = ($todayYield >= 0.0 && $todayYield < 50.0) ? $todayYield : 0.0;
            } else {
                $this->logErro(0, sprintf(
                    'Delta negativo todayYield vinculo_id=%d fora virada de dia (ant=%.3f, atual=%.3f).',
                    $vinculoId, $lastToday, $todayYield
                ));
                $deltaHoje = 0.0;
            }
        } elseif ($deltaHoje > 50.0) {
            $this->logErro(0, sprintf(
                'Delta anomalo todayYield vinculo_id=%d (ant=%.3f, atual=%.3f, delta=%.3f).',
                $vinculoId, $lastToday, $todayYield, $deltaHoje
            ));
            $deltaHoje = 0.0;
        }

        $deltaTotal = 0.0;
        if ($generationTotal !== null && $last['energia_total_kwh'] !== null) {
            $lastTotal = (float) $last['energia_total_kwh'];
            $diff = $generationTotal - $lastTotal;
            if ($diff < 0.0) {
                $this->logErro(0, sprintf(
                    'Delta negativo generationTotal vinculo_id=%d (ant=%.3f, atual=%.3f).',
                    $vinculoId, $lastTotal, $generationTotal
                ));
                $deltaTotal = 0.0;
            } else {
                $deltaTotal = ($diff < 50.0) ? $diff : 0.0;
            }
        }

        return [
            'delta_hoje_kwh'  => round(max(0.0, $deltaHoje), 4),
            'delta_total_kwh' => round(max(0.0, $deltaTotal), 4),
        ];
    }

    private function logErro(int $controladorId, string $mensagem): void
    {
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO logs_sistema (controlador_id, nivel, origem, mensagem, criado_em)
                 VALUES (:controlador_id, 'ERROR', 'fox_ess_ingestor', :mensagem, UTC_TIMESTAMP())"
            );
            $stmt->execute(['controlador_id' => $controladorId, 'mensagem' => $mensagem]);
        } catch (Throwable) {
            // Nao deixa falha de log quebrar o ciclo.
        }
    }
}
