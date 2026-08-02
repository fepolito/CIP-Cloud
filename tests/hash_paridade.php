<?php
/**
 * @arquivo       tests/hash_paridade.php
 * @versao        1.0.0
 * @modificado_em 2026-07-29
 * @objetivo      Gera a string canonica e o SHA-256 esperado de uma curva de
 *                exemplo, para o firmware CIP conferir paridade byte-a-byte.
 * @autor         Fernando / CIP Cloud Copilot / ATGY
 */
declare(strict_types=1);

require_once __DIR__ . '/../app/services/LimitesSync.php';

// Curva de exemplo: 3500 W nas horas de pico, 0 no resto (dias uteis).
$curva = [
    'sabado'          => array_fill(0, 24, 0),
    'weekday_extra'   => 'IGNORADO',           // chave estranha -> descartada
    'dias_uteis'      => array_fill(0, 24, 0),
    'domingo_feriado' => array_fill(0, 24, 0),
];
$curva['dias_uteis'][10] = 3500;
$curva['dias_uteis'][11] = 3500;
$curva['dias_uteis'][18] = 1500;

// Sanitiza (valida estrutura + int nu) e canoniza.
$saneada = LimitesSync::validarEstrutura($curva);
$canon   = LimitesSync::canonizar($saneada);
$hash    = LimitesSync::calcularHash($saneada);

echo "=== PARIDADE DE HASH — CIP LIMITES ===\n\n";
echo "Canonico (byte-a-byte que o ESP deve reproduzir):\n";
echo $canon . "\n\n";
echo "Comprimento (bytes): " . strlen($canon) . "\n";
echo "SHA-256 esperado:     " . $hash . "\n\n";

// Vetor de teste extra: curva toda zerada.
$zero = [
    'dias_uteis'      => array_fill(0, 24, 0),
    'domingo_feriado' => array_fill(0, 24, 0),
    'sabado'          => array_fill(0, 24, 0),
];
$zeroCanon = LimitesSync::canonizar(LimitesSync::validarEstrutura($zero));
echo "--- Vetor zerado ---\n";
echo $zeroCanon . "\n";
echo "SHA-256: " . hash('sha256', $zeroCanon) . "\n";
