<?php
/**
 * @arquivo       app/helpers/EnergiaCalc.php
 * @versao        1.0.0
 * @modificado_em 2026-09-09
 * @objetivo      Cálculo canônico de energia (importada/exportada/geração) via
 *                soma de deltas com guarda (LAG), imune a glitch/reset do hodômetro.
 * @autor         Fernando / CIP Cloud Copilot / ATGY
 */
declare(strict_types=1);

final class EnergiaCalc
{
    /**
     * Soma dos deltas positivos de uma coluna acumulada (hodômetro),
     * descartando saltos espúrios (glitch) e quedas (reset/rollover).
     *
     * @param PDO    $pdo
     * @param int    $controladorId
     * @param string $iniUtc  'Y-m-d H:i:s' em UTC (inclusive)
     * @param string $fimUtc  'Y-m-d H:i:s' em UTC (exclusive)
     * @param string $coluna  coluna acumulada permitida (whitelist)
     * @param float  $deltaMax teto físico por bucket (kWh)
     */
    public static function somaDeltas(
        PDO $pdo,
        int $controladorId,
        string $iniUtc,
        string $fimUtc,
        string $coluna,
        float $deltaMax
    ): float {
        // Whitelist: coluna NUNCA vem de input livre -> anti SQL injection em identificador
        $permitidas = [
            'energia_importada_kwh',
            'energia_exportada_kwh',
            'energia_geracao_kwh', // corrigido para energia_geracao_kwh, pois na base é geracao
        ];
        if (!in_array($coluna, $permitidas, true)) {
            throw new InvalidArgumentException("Coluna não permitida: {$coluna}");
        }

        $sql = "
            WITH deltas AS (
                SELECT
                    {$coluna} - LAG({$coluna}) OVER (ORDER BY timestamp_utc) AS delta
                FROM telemetria_5min
                WHERE controlador_id = :cid
                  AND timestamp_utc >= :ini
                  AND timestamp_utc <  :fim
            )
            SELECT COALESCE(SUM(
                CASE WHEN delta BETWEEN 0 AND :dmax THEN delta ELSE 0 END
            ), 0) AS total
            FROM deltas
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':cid' => $controladorId,
            ':ini' => $iniUtc,
            ':fim' => $fimUtc,
            ':dmax' => $deltaMax,
        ]);

        return (float) $stmt->fetchColumn();
    }

    /**
     * Método de auditoria cruzada: integração da potência (kW * h).
     * Usar apenas para conciliação/telas de aderência, não como fonte primária.
     */
    public static function integraPotencia(
        PDO $pdo,
        int $controladorId,
        string $iniUtc,
        string $fimUtc,
        string $colunaPotenciaW,
        int $intervaloMin = 5
    ): float {
        $permitidas = ['potencia_importada_w', 'potencia_exportada_w', 'potencia_geracao_w'];
        if (!in_array($colunaPotenciaW, $permitidas, true)) {
            throw new InvalidArgumentException("Coluna não permitida: {$colunaPotenciaW}");
        }

        // (W * min/60 / 1000) => kWh por bucket
        $fator = $intervaloMin / 60 / 1000;

        $sql = "
            SELECT COALESCE(SUM({$colunaPotenciaW}), 0) * :fator AS total
            FROM telemetria_5min
            WHERE controlador_id = :cid
              AND timestamp_utc >= :ini
              AND timestamp_utc <  :fim
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':cid' => $controladorId,
            ':ini' => $iniUtc,
            ':fim' => $fimUtc,
            ':fator' => $fator,
        ]);

        return (float) $stmt->fetchColumn();
    }
}
