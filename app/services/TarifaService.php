<?php
/**
 * @arquivo       app/services/TarifaService.php
 * @versao        1.0.0
 * @modificado_em 2026-07-18
 * @objetivo      Cálculo financeiro de energia (custo/economia) a partir de kWh.
 *                Fórmula v1: autoconsumo × tarifa + injeção × (tarifa × fator).
 * @autor         Fernando / CIP Cloud Copilot / ATGY
 */
declare(strict_types=1);

namespace app\services;

require_once __DIR__ . '/../../api/config/env.php';

final class TarifaService
{
    public static function custoConsumo(float $importadaKwh): float
    {
        return round($importadaKwh * TARIFA_KWH, 2);
    }

    public static function economia(float $geracaoKwh, float $exportadaKwh): array
    {
        $autoconsumoKwh = max(0.0, $geracaoKwh - $exportadaKwh);
        $tarifaInjecao  = TARIFA_KWH * FATOR_INJECAO;   // 0,72065631

        $valorAutoconsumo = $autoconsumoKwh * TARIFA_KWH;
        $valorCredito     = $exportadaKwh   * $tarifaInjecao;

        return [
            'total'              => round($valorAutoconsumo + $valorCredito, 2),
            'autoconsumo_reais'  => round($valorAutoconsumo, 2),
            'credito_reais'      => round($valorCredito, 2),
            'autoconsumo_kwh'    => round($autoconsumoKwh, 3),
            'exportada_kwh'      => round($exportadaKwh, 3),
            'tarifa_kwh'         => TARIFA_KWH,
            'tarifa_injecao_kwh' => round($tarifaInjecao, 8),
            'fator_injecao'      => FATOR_INJECAO,
            'metodo'             => 'simplificado_v1',
        ];
    }

    // TODO: calcularCheio() → Lei 14.300 (Fio B escalonado, ICMS) por tenant.
}
