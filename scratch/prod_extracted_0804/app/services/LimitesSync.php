<?php
/**
 * @arquivo       app/services/LimitesSync.php
 * @versao        2.0.0
 * @modificado_em 2026-07-29
 * @objetivo      Canonização e hashing da curva de limites (tabela_limites) para
 *                paridade byte-a-byte com o firmware CIP. Watts inteiros, JSON
 *                minificado, chaves ordenadas, SHA-256 lowercase.
 * @autor         Fernando / CIP Cloud Copilot / ATGY
 */
declare(strict_types=1);

final class LimitesSync
{
    /** Chaves canônicas oficiais (RDC CIP-DEC-20260608-002). */
    private const CHAVES = ['dias_uteis', 'domingo_feriado', 'sabado'];
    private const SLOTS = 24;
    private const W_MIN = 0;
    private const W_MAX = 5_000_000;

    /**
     * Canoniza a curva: ksort recursivo + Watts inteiros nus + JSON denso.
     * Formato de saída (exemplo):
     *   {"dias_uteis":[3500,0,...],"domingo_feriado":[...],"sabado":[...]}
     */
    public static function canonizar(array $curva): string
    {
        self::ksortRecursivo($curva);
        self::normalizarPotencias($curva);

        $json = json_encode(
            $curva,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if ($json === false) {
            throw new RuntimeException('Falha ao serializar curva canonica.');
        }
        return $json;
    }

    /** SHA-256 hex lowercase do canônico. */
    public static function calcularHash(array $curva): string
    {
        return hash('sha256', self::canonizar($curva));
    }

    /**
     * Valida estrutura: 3 chaves obrigatórias, 24 slots int em [0..5.000.000].
     * @return array Curva saneada (int puros).
     */
    public static function validarEstrutura(array $curva): array
    {
        foreach (self::CHAVES as $dia) {
            if (!array_key_exists($dia, $curva) || !is_array($curva[$dia])) {
                throw new InvalidArgumentException("Chave ausente/invalida: {$dia}");
            }
            if (count($curva[$dia]) !== self::SLOTS) {
                throw new InvalidArgumentException(
                    "Dia {$dia}: esperado " . self::SLOTS . ' slots.'
                );
            }
            foreach ($curva[$dia] as $i => $v) {
                if (!is_numeric($v)) {
                    throw new InvalidArgumentException("Slot {$dia}[{$i}] nao numerico.");
                }
                $w = (int) round((float) $v);
                if ($w < self::W_MIN || $w > self::W_MAX) {
                    throw new InvalidArgumentException(
                        "Slot {$dia}[{$i}]={$w}W fora de [".self::W_MIN."..".self::W_MAX."]."
                    );
                }
                $curva[$dia][$i] = $w;
            }
        }
        // Descarta chaves estranhas: só as 3 canônicas entram no hash.
        return array_intersect_key($curva, array_flip(self::CHAVES));
    }

    /** Força Watts inteiros nus em qualquer numérico (RDC CIP-DEC-20260608-005). */
    private static function normalizarPotencias(array &$curva): void
    {
        array_walk_recursive($curva, static function (&$v): void {
            if (is_int($v) || is_float($v) || (is_string($v) && is_numeric($v))) {
                $v = (int) round((float) $v);
            }
        });
    }

    private static function ksortRecursivo(array &$arr): void
    {
        ksort($arr);
        foreach ($arr as &$v) {
            if (is_array($v)) {
                self::ksortRecursivo($v);
            }
        }
        unset($v);
    }
}
