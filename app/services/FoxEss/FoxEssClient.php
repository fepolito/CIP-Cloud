<?php
/**
 * ============================================================
 * Arquivo   : app/services/FoxEss/FoxEssClient.php
 * Projeto   : CIP — Controlador de Injeção de Potência Elétrica
 * Versão    : 1.0.0
 * Modificado: 2026-10-06
 * Objetivo  : Cliente HTTP da FoxESS Cloud OpenAPI v0 com assinatura MD5
 *
 * Dependências de software/arquivos:
 *   - PHP 8.3+ (ext-curl, ext-json)
 *
 * Histórico de implementações:
 *   2026-10-06  v1.0.0  Criação inicial do client HTTP FoxESS OpenAPI
 * ============================================================
 */

declare(strict_types=1);

namespace App\Services\FoxEss;

use RuntimeException;

final class FoxEssClient
{
    private string $baseUrl;
    private string $apiKey;
    private int $timeout;

    public function __construct(array $config)
    {
        $this->baseUrl = rtrim((string)($config['base_url'] ?? 'https://www.foxesscloud.com'), '/');
        $this->apiKey  = (string)($config['api_key'] ?? '');
        $this->timeout = (int)($config['timeout'] ?? 30);
    }

    /**
     * Executa uma requisição POST assinada para a OpenAPI da FoxESS.
     *
     * Regra de assinatura:
     *   signature = MD5(path + "\r\n" + token + "\r\n" + timestamp)
     *
     * @param string $resource Caminho relativo da API (ex: /op/v0/device/list)
     * @param array  $payload  Corpo JSON da requisição
     * @return array           Array associativo decodificado do retorno
     */
    public function post(string $resource, array $payload = []): array
    {
        $path      = '/' . ltrim($resource, '/');
        $timestamp = (string) round(microtime(true) * 1000);
        $toSign    = "{$path}\r\n{$this->apiKey}\r\n{$timestamp}";
        $signature = md5($toSign);

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            throw new RuntimeException('Falha ao codificar payload JSON para FoxESS.');
        }

        $headers = [
            'Content-Type: application/json',
            "token: {$this->apiKey}",
            "timestamp: {$timestamp}",
            "signature: {$signature}",
            'lang: en',
        ];

        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $response = curl_exec($ch);
        if ($response === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("FoxESS cURL falhou: {$err}");
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new RuntimeException("FoxESS resposta inválida (HTTP {$httpCode}): " . substr($response, 0, 300));
        }

        if (($data['errno'] ?? 0) !== 0) {
            $msg   = $data['msg'] ?? 'Erro desconhecido';
            $errno = $data['errno'] ?? -1;
            throw new RuntimeException("FoxESS API erro [{$errno}]: {$msg}");
        }

        return $data;
    }

    /**
     * Lista dispositivos cadastrados na conta (inversores e registradores).
     */
    public function deviceList(int $currentPage = 1, int $pageSize = 20): array
    {
        return $this->post('/op/v0/device/list', [
            'currentPage' => $currentPage,
            'pageSize'    => $pageSize,
        ]);
    }

    /**
     * Consulta variáveis de tempo real para um número de série (SN).
     * Retorna array associativo ['variavel' => valor, ...] ou null em falha individual.
     *
     * @param string        $sn        Número de série do dispositivo
     * @param array<string> $variables Variáveis desejadas ([] = todas disponíveis)
     * @return array<string, mixed>|null Array associativo com as variáveis ou null em falha
     */
    public function realQuery(string $sn, array $variables = []): ?array
    {
        try {
            $resp = $this->post('/op/v0/device/real/query', [
                'sn'        => $sn,
                'variables' => $variables,
            ]);

            $itens = $resp['result'] ?? [];
            if (empty($itens) || !is_array($itens)) {
                return null;
            }

            $datas = $itens[0]['datas'] ?? [];
            if (empty($datas) || !is_array($datas)) {
                return null;
            }

            $vars = [];
            foreach ($datas as $v) {
                if (isset($v['variable'])) {
                    $vars[$v['variable']] = $v['value'] ?? null;
                }
            }

            return $vars;
        } catch (\Throwable $e) {
            @error_log("[FoxEssClient] Falha em realQuery para SN {$sn}: " . $e->getMessage());
            return null;
        }
    }
}
