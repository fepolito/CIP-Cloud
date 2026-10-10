<?php
/**
 * @arquivo       app/services/Solis/SolisClient.php
 * @versao        1.0.0
 * @modificado_em 2026-08-04
 * @objetivo      Cliente HTTP da SolisCloud API: monta assinatura HMAC-SHA1 e executa POST via cURL.
 * @autor         Fernando / CIP Cloud Copilot
 */
declare(strict_types=1);

namespace App\Services\Solis;

use RuntimeException;

final class SolisClient
{
    private string $baseUrl;
    private string $keyId;
    private string $keySecret;

    public function __construct(array $config)
    {
        $this->baseUrl   = rtrim($config['base_url'], '/');
        $this->keyId     = $config['key_id'];
        $this->keySecret = $config['key_secret'];
    }

    /**
     * Executa um POST assinado.
     * @param string $resource Path canônico, ex: /v1/api/inverterList
     * @param array  $payload  Corpo da requisição
     * @return array Resposta decodificada
     */
    public function post(string $resource, array $payload = []): array
    {
        // Body byte-idêntico ao que assinamos
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            throw new RuntimeException('Falha ao codificar payload JSON.');
        }

        $contentMd5  = base64_encode(md5($body, true));
        $contentType = 'application/json';
        $date        = gmdate('D, d M Y H:i:s \G\M\T');

        // StringToSign: VERB \n Content-MD5 \n Content-Type \n Date \n CanonicalizedResource
        $stringToSign = "POST\n{$contentMd5}\n{$contentType}\n{$date}\n{$resource}";

        $signature = base64_encode(
            hash_hmac('sha1', $stringToSign, $this->keySecret, true)
        );

        $authorization = "API {$this->keyId}:{$signature}";

        $headers = [
            "Content-Type: {$contentType}",
            "Content-MD5: {$contentMd5}",
            "Date: {$date}",
            "Authorization: {$authorization}",
        ];

        $ch = curl_init($this->baseUrl . $resource);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException("Erro cURL: {$curlErr}");
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("Resposta inválida (HTTP {$httpCode}): {$response}");
        }

        return [
            'http_code' => $httpCode,
            'data'      => $decoded,
        ];
    }
}
