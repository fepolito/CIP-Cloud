<?php
/**
 * @arquivo       app/services/TarifaService.php
 * @versao        2.0.0
 * @modificado_em 2026-07-22
 * @objetivo      Cálculo financeiro de energia (custo/economia) a partir de kWh.
 *                Fórmula dinâmica: tarifas providas pela tabela do controlador.
 * @autor         Fernando / CIP Cloud Copilot / ATGY
 */
declare(strict_types=1);

namespace app\services;

final class TarifaService
{
    public static function custoConsumo(float $importadaKwh, float $tarifaKwh): float
    {
        return round($importadaKwh * $tarifaKwh, 2);
    }

    public static function economia(float $geracaoKwh, float $exportadaKwh, float $tarifaKwh, float $fatorInjecao): array
    {
        $autoconsumoKwh = max(0.0, $geracaoKwh - $exportadaKwh);
        $tarifaInjecao  = $tarifaKwh * $fatorInjecao;

        $valorAutoconsumo = $autoconsumoKwh * $tarifaKwh;
        $valorCredito     = $exportadaKwh   * $tarifaInjecao;

        return [
            'total'              => round($valorAutoconsumo + $valorCredito, 2),
            'autoconsumo_reais'  => round($valorAutoconsumo, 2),
            'credito_reais'      => round($valorCredito, 2),
            'autoconsumo_kwh'    => round($autoconsumoKwh, 3),
            'exportada_kwh'      => round($exportadaKwh, 3),
            'tarifa_kwh'         => $tarifaKwh,
            'tarifa_injecao_kwh' => round($tarifaInjecao, 8),
            'fator_injecao'      => $fatorInjecao,
            'metodo'             => 'dinamico_v2',
        ];
    }
}
