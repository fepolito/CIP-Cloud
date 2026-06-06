<?php
/**
 * =============================================================
 * PROJETO: Controlador de Injecao de Potencia Eletrica (CIP)
 * ARQUIVO: app/helpers.php
 * =============================================================
 * OBJETIVO:
 *   Funcoes utilitarias compartilhadas pela API REST.
 *   Padroniza respostas JSON de sucesso e erro.
 *
 * DEPENDENCIAS DE HARDWARE:
 *   - Nenhuma
 *
 * DEPENDENCIAS DE ARQUIVOS:
 *   - Carregado por api/index.php
 *
 * HISTORICO:
 *   2026-05-14  v1.0.0  Criacao - destrava bootstrap da API
 *                        (anteriormente o index.php tentava
 *                        carregar este arquivo mas ele nao
 *                        existia, causando Fatal Error)
 * =============================================================
 */

declare(strict_types=1);

if (!function_exists('respondOk')) {
    /**
     * Resposta JSON de sucesso (HTTP 200 por padrao).
     *
     * @param mixed $data    Payload a ser serializado
     * @param int   $status  Codigo HTTP (default: 200)
     */
    function respondOk(mixed $data = null, int $status = 200): never
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(
            ['sucesso' => true, 'data' => $data],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        exit;
    }
}

if (!function_exists('respondError')) {
    /**
     * Resposta JSON de erro.
     *
     * @param int    $status   Codigo HTTP (400, 401, 404, 500, ...)
     * @param string $message  Mensagem legivel
     * @param mixed  $details  (opcional) Contexto adicional
     */
    function respondError(int $status, string $message, mixed $details = null): never
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
        }
        $payload = ['sucesso' => false, 'erro' => $message];
        if ($details !== null) {
            $payload['detalhes'] = $details;
        }
        echo json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        exit;
    }
}

if (!function_exists('readJsonBody')) {
    /**
     * Le e decodifica o corpo JSON da requisicao.
     * Retorna array vazio se body invalido ou ausente.
     */
    function readJsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
