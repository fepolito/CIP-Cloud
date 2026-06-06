<?php
/**
 * Arquivo: app/config/Constantes.php
 * Projeto: Controlador de Injeção de Potência Elétrica
 * Objetivo: Constantes globais do sistema.
 *
 * @versao 1.0.0
 * @data 2026-06-04
 */

declare(strict_types=1);

namespace App\Config;

final class Constantes
{
    /** Filtro mínimo de qualidade nas queries de telemetria */
    public const QUALIDADE_DADO_MINIMA = 1;

    /** Controlador é "online" se a última leitura ocorreu em menos de X segundos */
    public const TIMEOUT_TELEMETRIA_ONLINE_SEG = 30;

    /** Versão atual da API */
    public const VERSAO_API = '1.0.0';

    private function __construct() {}
}
