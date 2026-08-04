<?php
// ============================================================
// Projeto   : CIP - Controlador de Injecao de Potencia Eletrica
// Arquivo   : api/helpers/response.php
// Objetivo  : Funções utilitárias de resposta JSON padronizada
// Dependências de hardware : Nenhuma
// Dependências de software :
//   - PHP 8.0+
//   - mod_headers habilitado (para Content-Type já definido)
// Histórico :
//   2026-04-07  v1.0.0  Criação inicial
//   2026-04-07  v1.1.0  Adicionado respondCreated (201)
//                        Adicionado respondNoContent (204)
// ============================================================

if (!function_exists('respondOk')) {

    /**
     * Resposta 200 OK com payload
     */
    function respondOk(array $data = []): void
    {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'data'    => $data,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Resposta 201 Created
     */
    function respondCreated(array $data = []): void
    {
        http_response_code(201);
        echo json_encode([
            'success' => true,
            'data'    => $data,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Resposta 204 No Content
     */
    function respondNoContent(): void
    {
        http_response_code(204);
        exit;
    }

    /**
     * Resposta de erro com código HTTP e mensagem
     *
     * @param int    $code    Código HTTP (400, 401, 403, 404, 405, 422, 500...)
     * @param string $message Mensagem legível ao cliente
     * @param array  $extra   Dados adicionais opcionais
     */
    function respondError(int $code, string $message, array $extra = []): void
    {
        http_response_code($code);
        $payload = [
            'success' => false,
            'error'   => [
                'code'    => $code,
                'message' => $message,
            ],
        ];
        if (!empty($extra)) {
            $payload['error']['details'] = $extra;
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Resposta 401 Não autenticado
     */
    function respondUnauthorized(string $message = 'Nao autenticado'): void
    {
        respondError(401, $message);
    }

    /**
     * Resposta 403 Sem permissão
     */
    function respondForbidden(string $message = 'Sem permissao'): void
    {
        respondError(403, $message);
    }

    /**
     * Resposta 404 Recurso não encontrado
     */
    function respondNotFound(string $message = 'Recurso nao encontrado'): void
    {
        respondError(404, $message);
    }

    /**
     * Resposta 422 Validação falhou
     */
    function respondValidationError(array $erros): void
    {
        respondError(422, 'Erro de validacao', $erros);
    }

    /**
     * Resposta 500 Erro interno
     */
    function respondServerError(string $message = 'Erro interno do servidor'): void
    {
        respondError(500, $message);
    }
}
