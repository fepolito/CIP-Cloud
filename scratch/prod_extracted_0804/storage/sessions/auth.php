<?php
/**
 * Arquivo: app/auth.php
 * Projeto: Controlador de Injeção de Potência Elétrica
 * Objetivo: Controlar autenticação, sessão segura e proteção de rotas com armazenamento local de sessões
 * Dependências de hardware:
 * - Servidor web com suporte a sessão PHP
 * - Sistema de arquivos gravável
 * Dependências de software:
 * - PHP 8.2+
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';

function isHttps(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }

    if (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) {
        return true;
    }

    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
        return true;
    }

    if (!empty($_SERVER['REQUEST_SCHEME']) && strtolower((string) $_SERVER['REQUEST_SCHEME']) === 'https') {
        return true;
    }

    return false;
}

function appUrl(string $path = ''): string
{
    $basePath = defined('APP_BASE_PATH') ? APP_BASE_PATH : '';
    return $basePath . $path;
}

function ensureSessionDirectory(): string
{
    $sessionDir = __DIR__ . '/../storage/sessions';

    if (!is_dir($sessionDir)) {
        @mkdir($sessionDir, 0775, true);
    }

    return $sessionDir;
}

function sendSecurityHeaders(): void
{
    if (headers_sent()) {
        return;
    }

    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header("Permissions-Policy: geolocation=(), microphone=(), camera=()");
    header('X-XSS-Protection: 0');
    header("Content-Security-Policy: default-src 'self'; frame-ancestors 'self'; form-action 'self'; base-uri 'self'");

    if (isHttps()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
    }
}

function startSecureSession(): void
{
    sendSecurityHeaders();

    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $sessionDir = ensureSessionDirectory();

    session_save_path($sessionDir);
    session_name(SESSION_NAME);

    $secureCookie = isHttps();

    ini_set('session.save_path', $sessionDir);
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '0');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', $secureCookie ? '1' : '0');
    ini_set('session.cookie_samesite', 'Lax');

    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path' => '/',
        'domain' => '',
        'secure' => $secureCookie,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();

    if (session_id() === '') {
        throw new RuntimeException('Falha ao iniciar sessão PHP.');
    }

    if (!isset($_SESSION['session_started_at'])) {
        $_SESSION['session_started_at'] = time();
    }

    if (!isset($_SESSION['last_regeneration'])) {
        $_SESSION['last_regeneration'] = time();
    }
}

function refreshAuthenticatedSession(): void
{
    startSecureSession();

    if ((time() - (int) ($_SESSION['last_regeneration'] ?? 0)) > 300) {
        session_regenerate_id(true);
        $_SESSION['last_regeneration'] = time();
    }

    $_SESSION['expires_at'] = time() + SESSION_LIFETIME;
}

function isAuthenticated(): bool
{
    startSecureSession();

    if (empty($_SESSION['usuario_id'])) {
        return false;
    }

    if (!isset($_SESSION['user_agent']) || $_SESSION['user_agent'] !== ($_SERVER['HTTP_USER_AGENT'] ?? '')) {
        return false;
    }

    if (!isset($_SESSION['expires_at']) || time() > (int) $_SESSION['expires_at']) {
        return false;
    }

    refreshAuthenticatedSession();

    return true;
}

function requireAuth(): void
{
    if (!isAuthenticated()) {
        logoutUser();
        header('Location: ' . appUrl('/login.php'));
        exit;
    }
}

function loginUser(array $usuario): void
{
    startSecureSession();

    session_regenerate_id(true);

    $_SESSION['usuario_id'] = (int) $usuario['id'];
    $_SESSION['usuario_nome'] = (string) $usuario['nome'];
    $_SESSION['usuario_email'] = (string) $usuario['email'];
    $_SESSION['usuario_perfil'] = (string) $usuario['perfil'];
    $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $_SESSION['session_started_at'] = time();
    $_SESSION['last_regeneration'] = time();
    $_SESSION['expires_at'] = time() + SESSION_LIFETIME;
}

function logoutUser(): void
{
    startSecureSession();

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => (bool) $params['secure'],
                'httponly' => (bool) $params['httponly'],
                'samesite' => 'Lax',
            ]
        );
    }

    session_destroy();
}
