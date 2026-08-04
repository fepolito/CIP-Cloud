<?php
/**
 * Arquivo: app/security.php
 * Projeto: Controlador de Injeção de Potência Elétrica
 * Objetivo: Fornecer rotinas de segurança para CSRF, rate limit e utilidades de endurecimento
 * Dependências de hardware:
 * - Servidor com sistema de arquivos gravável
 * Dependências de software:
 * - PHP 8.2+
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/auth.php';

function ensureSecurityDirectories(): void
{
    $directories = [
        __DIR__ . '/../storage/rate_limit',
        __DIR__ . '/../storage/logs',
        __DIR__ . '/../storage/sessions',
    ];

    foreach ($directories as $directory) {
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }
    }
}

function getClientIpAddress(): string
{
    $keys = [
        'HTTP_CF_CONNECTING_IP',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_REAL_IP',
        'REMOTE_ADDR',
    ];

    foreach ($keys as $key) {
        if (!empty($_SERVER[$key])) {
            $value = trim((string) $_SERVER[$key]);

            if ($key === 'HTTP_X_FORWARDED_FOR') {
                $parts = explode(',', $value);
                $value = trim($parts[0]);
            }

            if (filter_var($value, FILTER_VALIDATE_IP)) {
                return $value;
            }
        }
    }

    return '0.0.0.0';
}

function generateCsrfToken(): string
{
    startSecureSession();

    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token']) || $_SESSION['csrf_token'] === '') {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['csrf_token'];
}

function validateCsrfToken(?string $token): bool
{
    startSecureSession();

    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        return false;
    }

    if ($token === null || $token === '') {
        return false;
    }

    return hash_equals((string) $_SESSION['csrf_token'], $token);
}

function rotateCsrfToken(): void
{
    startSecureSession();
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function getRateLimitFilePath(string $scope, string $identifier): string
{
    ensureSecurityDirectories();

    $safeScope = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $scope);
    $hash = hash('sha256', $identifier);

    return __DIR__ . '/../storage/rate_limit/' . $safeScope . '_' . $hash . '.json';
}

function readRateLimitData(string $scope, string $identifier): array
{
    $file = getRateLimitFilePath($scope, $identifier);

    if (!file_exists($file)) {
        return [
            'attempts' => [],
            'blocked_until' => 0,
        ];
    }

    $content = file_get_contents($file);
    $data = json_decode((string) $content, true);

    if (!is_array($data)) {
        return [
            'attempts' => [],
            'blocked_until' => 0,
        ];
    }

    return [
        'attempts' => isset($data['attempts']) && is_array($data['attempts']) ? $data['attempts'] : [],
        'blocked_until' => isset($data['blocked_until']) ? (int) $data['blocked_until'] : 0,
    ];
}

function writeRateLimitData(string $scope, string $identifier, array $data): void
{
    $file = getRateLimitFilePath($scope, $identifier);

    file_put_contents(
        $file,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}

function clearOldAttempts(array $attempts, int $windowSeconds): array
{
    $now = time();

    return array_values(array_filter(
        $attempts,
        static fn ($timestamp): bool => is_int($timestamp) && ($timestamp >= ($now - $windowSeconds))
    ));
}

function isRateLimited(string $scope, string $identifier, int $maxAttempts, int $windowSeconds, int $blockSeconds): array
{
    $now = time();
    $data = readRateLimitData($scope, $identifier);
    $data['attempts'] = clearOldAttempts($data['attempts'], $windowSeconds);

    if (($data['blocked_until'] ?? 0) > $now) {
        return [
            'limited' => true,
            'remaining_seconds' => (int) $data['blocked_until'] - $now,
            'attempts' => count($data['attempts']),
        ];
    }

    if (count($data['attempts']) >= $maxAttempts) {
        $data['blocked_until'] = $now + $blockSeconds;
        writeRateLimitData($scope, $identifier, $data);

        return [
            'limited' => true,
            'remaining_seconds' => $blockSeconds,
            'attempts' => count($data['attempts']),
        ];
    }

    return [
        'limited' => false,
        'remaining_seconds' => 0,
        'attempts' => count($data['attempts']),
    ];
}

function registerRateLimitFailure(string $scope, string $identifier, int $windowSeconds): void
{
    $data = readRateLimitData($scope, $identifier);
    $data['attempts'] = clearOldAttempts($data['attempts'], $windowSeconds);
    $data['attempts'][] = time();

    writeRateLimitData($scope, $identifier, $data);
}

function clearRateLimit(string $scope, string $identifier): void
{
    $file = getRateLimitFilePath($scope, $identifier);

    if (file_exists($file)) {
        @unlink($file);
    }
}
