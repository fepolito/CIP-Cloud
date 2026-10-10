<?php
/**
 * @arquivo       app/services/FoxEss/FoxEssIngestor.php
 * @versao        2.2.0
 * @modificado_em 2026-10-10
 * @objetivo      Coleta telemetria da API FoxESS agrupando por controlador virtual
 *                (1 planta = N inversores), calcula o delta de energia (todayYield)
 *                por equipamento, grava o detalhe em telemetria_equipamento_5min
 *                e delega a consolidação multi-marca ao TelemetriaConsolidador.
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
        $bucket = $this->bucketUtc();
        $grupos = $this->buscarControladoresFox();

        $processados = 0;
        $erros = 0;
        $detalhes = [];

        foreach ($grupos as $grupo) {
            try {
                $this->processarGrupo($grupo, $bucket);
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
            'bucket_utc'   => $bucket,
            'processados'  => $processados,
            'erros'        => $erros,
            'detalhes'     => $detalhes,
        ];
    }

    private function bucketUtc(): string
    {
        $t = time();
        $t -= $t % 300; // trava em múltiplo de 5min UTC
        return gmdate('Y-m-d H:i:s', $t);
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

    private function processarGrupo(array $grupo, string $bucket): void
    {
        $somaGeracaoW = 0.0;
        $somaCargaW = 0.0;
        $somaExportacaoW = 0.0;
        $somaImportacaoW = 0.0;
        $somaEnergiaDeltaKwh = 0.0;
        $somaEnergiaTotalKwh = 0.0;
        $algumSucesso = false;

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

        foreach ($grupo['equipamentos'] as $eq) {
            $vars = $this->client->realQuery($eq['sn']);
            if ($vars === null) {
                continue; // Equipamento indisponível neste ciclo; segue com os demais
            }
            $algumSucesso = true;

            $pvW     = ((float) ($vars['pvPower'] ?? $vars['generationPower'] ?? 0)) * 1000;
            $loadsW  = ((float) ($vars['loadsPower'] ?? 0)) * 1000;
            $feedinW = ((float) ($vars['feedinPower'] ?? 0)) * 1000;
            $gridW   = ((float) ($vars['gridConsumptionPower'] ?? 0)) * 1000;

            // Extração de energias reportadas pela FoxESS
            $todayYield = isset($vars['todayYield']) && is_numeric($vars['todayYield'])
                ? (float) $vars['todayYield']
                : null;

            $generationTotal = null;
            if (isset($vars['generation']) && is_numeric($vars['generation'])) {
                $generationTotal = (float) $vars['generation'];
            } elseif (isset($vars['PVEnergyTotal']) && is_numeric($vars['PVEnergyTotal'])) {
                $generationTotal = (float) $vars['PVEnergyTotal'];
            }

            // Variáveis operacionais adicionais
            $temperaturaC = isset($vars['invTemperation']) && is_numeric($vars['invTemperation'])
                ? (float) $vars['invTemperation']
                : null;

            $statusEq = isset($vars['runningState']) ? (string) $vars['runningState'] : null;

            // Cálculo do delta do equipamento no ciclo de 5 minutos
            $deltaInfo = $this->calcularDeltaEquipamento(
                (int) $eq['vinculo_id'],
                $bucket,
                $todayYield,
                $generationTotal,
                (string) ($grupo['timezone'] ?? 'America/Sao_Paulo')
            );
            $deltaKwh = $deltaInfo['delta_hoje_kwh'];

            $somaGeracaoW        += $pvW;
            $somaCargaW          += $loadsW;
            $somaExportacaoW     += $feedinW;
            $somaImportacaoW     += $gridW;
            $somaEnergiaDeltaKwh += $deltaKwh;

            if ($generationTotal !== null) {
                $somaEnergiaTotalKwh += $generationTotal;
            }

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
        }

        if (!$algumSucesso) {
            throw new RuntimeException('Nenhum equipamento do grupo respondeu nesta janela.');
        }

        $isExporting = $somaExportacaoW > 0;
        $ctrlId = (int)$grupo['controlador_id'];

        // Grava contribuição da marca FOX-ESS e consolida em telemetria_5min
        $this->consolidador->gravarContribuicaoMarca(
            $ctrlId,
            $bucket,
            'FOX-ESS',
            $somaGeracaoW,
            round($somaEnergiaDeltaKwh, 4)
        );

        $this->consolidador->consolidar(
            $ctrlId,
            $bucket,
            [
                'energia_geracao_total_kwh' => $somaEnergiaTotalKwh > 0 ? round($somaEnergiaTotalKwh, 3) : null,
                'potencia_importada_w'     => $somaImportacaoW,
                'potencia_exportada_w'     => $somaExportacaoW,
                'potencia_consumo_total_w' => $somaCargaW,
                'is_exporting'             => $isExporting ? 1 : 0,
                'direction_valid'          => 1,
                'power_injected_w'         => $somaExportacaoW,
                'qualidade_dado'           => 100,
            ]
        );

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
     * Calcula o delta de energia (kWh) para o ciclo de 5 minutos de um inversor individual.
     *
     * @param int        $vinculoId        ID em controlador_inversores
     * @param string     $bucketUtc        Timestamp UTC do bucket (Y-m-d H:i:s)
     * @param float|null $todayYield       Geração acumulada do dia retornado pela FoxESS (kWh)
     * @param float|null $generationTotal  Geração vitalícia acumulada retornado pela FoxESS (kWh)
     * @param string     $tzStr            Fuso horário da instalação (ex: America/Sao_Paulo)
     * @return array{delta_hoje_kwh: float, delta_total_kwh: float}
     */
    private function calcularDeltaEquipamento(
        int $vinculoId,
        string $bucketUtc,
        ?float $todayYield,
        ?float $generationTotal,
        string $tzStr = 'America/Sao_Paulo'
    ): array {
        if ($todayYield === null) {
            return ['delta_hoje_kwh' => 0.0, 'delta_total_kwh' => 0.0];
        }

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
            // Primeiro registro deste equipamento no sistema:
            // Não há referência anterior para calcular o delta de 5min, fallback seguro 0.0
            return ['delta_hoje_kwh' => 0.0, 'delta_total_kwh' => 0.0];
        }

        $lastToday = (float) $last['energia_hoje_kwh'];
        $deltaHoje = $todayYield - $lastToday;

        if ($deltaHoje < 0.0) {
            // Testar virada de dia (reset da meia-noite)
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
                // Houve virada de dia (reset do contador diário):
                // O delta do ciclo é o próprio todayYield gerado desde a meia-noite
                $deltaHoje = ($todayYield >= 0.0 && $todayYield < 50.0) ? $todayYield : 0.0;
            } else {
                // Delta negativo fora da madrugada (ex: glitch/falha de leitura da API):
                // Logar e aplicar fallback seguro 0.0 (nunca grava negativo)
                $this->logErro(
                    0,
                    sprintf(
                        'Delta negativo de todayYield detectado para vinculo_id=%d fora da virada de dia (anterior=%.3f, atual=%.3f). Aplicando fallback 0.0.',
                        $vinculoId,
                        $lastToday,
                        $todayYield
                    )
                );
                $deltaHoje = 0.0;
            }
        } elseif ($deltaHoje > 50.0) {
            // Teto anti-anomalia / anti-spike para um ciclo de 5min
            $this->logErro(
                0,
                sprintf(
                    'Delta anômalo de todayYield detectado para vinculo_id=%d (anterior=%.3f, atual=%.3f, delta=%.3f). Limitando a 0.0.',
                    $vinculoId,
                    $lastToday,
                    $todayYield,
                    $deltaHoje
                )
            );
            $deltaHoje = 0.0;
        }

        // Delta total vitalício (generation/PVEnergyTotal)
        $deltaTotal = 0.0;
        if ($generationTotal !== null && $last['energia_total_kwh'] !== null) {
            $lastTotal = (float) $last['energia_total_kwh'];
            $diff = $generationTotal - $lastTotal;
            if ($diff < 0.0) {
                $this->logErro(
                    0,
                    sprintf(
                        'Delta negativo de generationTotal para vinculo_id=%d (anterior=%.3f, atual=%.3f). Possível reset de firmware/inversor.',
                        $vinculoId,
                        $lastTotal,
                        $generationTotal
                    )
                );
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
            $stmt->execute([
                'controlador_id' => $controladorId,
                'mensagem'       => $mensagem,
            ]);
        } catch (Throwable) {
            // Não deixa falha de log quebrar o ciclo.
        }
    }
}
