<?php
/**
 * @arquivo       app/helpers/DeviceAuth.php
 * @versao        1.0.0
 * @modificado_em 2026-07-25
 * @objetivo      Valida autenticacao de dispositivo (firmware CIP) via headers
 *                X-CIP-Serial + X-CIP-Token, reusando credencial da telemetria.
 * @autor         Fernando / CIP Cloud Copilot / ATGY
 */
declare(strict_types=1);

final class DeviceAuth
{
    /**
     * Retorna array do controlador autenticado ou lanca RuntimeException.
     * @return array{id:int, empresa_id:int, serial:string, timezone:string}
     */
    public static function autenticar(PDO $pdo): array
    {
        $serial = self::header('X-CIP-Serial');
        $token  = self::header('X-CIP-Token');

        if ($serial === '' || $token === '') {
            self::abort(401, 'Credenciais ausentes');
        }

        $stmt = $pdo->prepare(
            'SELECT id, empresa_id, codigo AS serial, timezone, hmac_secret
               FROM controladores
              WHERE codigo = :serial
              LIMIT 1'
        );
        $stmt->execute([':serial' => $serial]);
        $c = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$c || !hash_equals((string)$c['hmac_secret'], $token)) {
            self::abort(403, 'Credenciais invalidas');
        }

        return [
            'id'         => (int)$c['id'],
            'empresa_id' => (int)$c['empresa_id'],
            'serial'     => (string)$c['serial'],
            'timezone'   => (string)($c['timezone'] ?? 'UTC'),
        ];
    }

    private static function header(string $name): string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return trim((string)($_SERVER[$key] ?? ''));
    }

    private static function abort(int $code, string $msg): never
    {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode(['erro' => $msg], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
