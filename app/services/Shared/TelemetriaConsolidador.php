<?php
/**
 * @arquivo       app/services/Shared/TelemetriaConsolidador.php
 * @versao        1.0.0
 * @modificado_em 2026-10-07
 * @objetivo      Centraliza a gravação por-marca em telemetria_5min_marca e a
 *                consolidação (soma de todas as marcas) em telemetria_5min,
 *                permitindo convivência de múltiplas marcas no mesmo controlador
 *                sem duplicação em reprocessamento.
 * @autor         Fernando / CIP Cloud Copilot / ATGY
 */

declare(strict_types=1);

namespace App\Services\Shared;

use PDO;

final class TelemetriaConsolidador
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Grava a contribuição de uma marca específica no bucket de 5 minutos.
     * Operação idempotente: substitui (não soma) o valor da própria marca naquele bucket.
     */
    public function gravarContribuicaoMarca(
        int $controladorId,
        string $bucketUtc,
        string $marca,
        float $potenciaGeracaoW,
        float $energiaGeracaoKwh = 0.0
    ): void {
        $sql = "
            INSERT INTO telemetria_5min_marca
              (controlador_id, timestamp_utc, marca, potencia_geracao_w, energia_geracao_kwh, atualizado_em)
            VALUES
              (:ctrl, :ts, :marca, :potencia, :energia, UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE
              potencia_geracao_w  = VALUES(potencia_geracao_w),
              energia_geracao_kwh = VALUES(energia_geracao_kwh),
              atualizado_em       = VALUES(atualizado_em)
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':ctrl'     => $controladorId,
            ':ts'       => $bucketUtc,
            ':marca'    => $marca,
            ':potencia' => $potenciaGeracaoW,
            ':energia'  => $energiaGeracaoKwh,
        ]);
    }

    /**
     * Consolida a telemetria somando as contribuições de todas as marcas naquele bucket
     * a partir de telemetria_5min_marca e grava em telemetria_5min de forma idempotente.
     *
     * @param int    $controladorId
     * @param string $bucketUtc
     * @param array  $extras Campos adicionais opcionais (ex: potência importada/exportada, status_inversor, etc.)
     */
    public function consolidar(int $controladorId, string $bucketUtc, array $extras = []): void
    {
        // 1. Somar todas as marcas para o controlador neste bucket
        $sqlSoma = "
            SELECT COALESCE(SUM(potencia_geracao_w), 0) AS total_potencia,
                   COALESCE(SUM(energia_geracao_kwh), 0) AS total_energia
              FROM telemetria_5min_marca
             WHERE controlador_id = :ctrl
               AND timestamp_utc = :ts
        ";
        $stmtSoma = $this->pdo->prepare($sqlSoma);
        $stmtSoma->execute([
            ':ctrl' => $controladorId,
            ':ts'   => $bucketUtc,
        ]);
        $soma = $stmtSoma->fetch(PDO::FETCH_ASSOC);

        $totPotencia = (float)($soma['total_potencia'] ?? 0.0);
        $totEnergia  = (float)($soma['total_energia'] ?? 0.0);

        // 2. Gravar consolidado em telemetria_5min (sobrescreve os totais com a soma consolidada)
        $sqlConsolida = "
            INSERT INTO telemetria_5min
              (controlador_id, timestamp_utc,
               potencia_geracao_w, energia_geracao_kwh,
               potencia_importada_w, potencia_exportada_w, potencia_consumo_total_w,
               is_exporting, direction_valid, power_injected_w,
               energia_geracao_total_kwh, status_inversor,
               geracao_origem, qualidade_dado)
            VALUES
              (:ctrl, :ts,
               :potencia, :energia,
               :importada, :exportada, :consumo,
               :is_exporting, :direction_valid, :injetado,
               :etot, :st,
               'api_externa', :qualidade)
            ON DUPLICATE KEY UPDATE
              potencia_geracao_w        = VALUES(potencia_geracao_w),
              energia_geracao_kwh       = VALUES(energia_geracao_kwh),
              potencia_importada_w      = COALESCE(VALUES(potencia_importada_w), potencia_importada_w),
              potencia_exportada_w      = COALESCE(VALUES(potencia_exportada_w), potencia_exportada_w),
              potencia_consumo_total_w  = COALESCE(VALUES(potencia_consumo_total_w), potencia_consumo_total_w),
              is_exporting              = COALESCE(VALUES(is_exporting), is_exporting),
              direction_valid           = COALESCE(VALUES(direction_valid), direction_valid),
              power_injected_w          = COALESCE(VALUES(power_injected_w), power_injected_w),
              energia_geracao_total_kwh = COALESCE(VALUES(energia_geracao_total_kwh), energia_geracao_total_kwh),
              status_inversor           = COALESCE(VALUES(status_inversor), status_inversor),
              geracao_origem            = VALUES(geracao_origem),
              qualidade_dado            = VALUES(qualidade_dado)
        ";

        $stmtConsolida = $this->pdo->prepare($sqlConsolida);
        $stmtConsolida->execute([
            ':ctrl'            => $controladorId,
            ':ts'              => $bucketUtc,
            ':potencia'        => $totPotencia,
            ':energia'         => $totEnergia,
            ':importada'       => $extras['potencia_importada_w'] ?? null,
            ':exportada'       => $extras['potencia_exportada_w'] ?? null,
            ':consumo'         => $extras['potencia_consumo_total_w'] ?? null,
            ':is_exporting'    => isset($extras['is_exporting']) ? ($extras['is_exporting'] ? 1 : 0) : null,
            ':direction_valid' => $extras['direction_valid'] ?? null,
            ':injetado'        => $extras['power_injected_w'] ?? null,
            ':etot'            => $extras['energia_geracao_total_kwh'] ?? null,
            ':st'              => $extras['status_inversor'] ?? null,
            ':qualidade'       => $extras['qualidade_dado'] ?? 80,
        ]);
    }
}
