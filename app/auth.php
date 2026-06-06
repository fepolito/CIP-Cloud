<?php
/**
 * Arquivo: app/auth.php
 * Projeto: Controlador de Injeção de Potência Elétrica
 * Objetivo: Autenticação, sessão segura e encerramento de sessão
 * Dependências de hardware:
 *   - Servidor web com suporte a PHP Session
 * Dependências de software:
 *   - PHP 8.3+
 *   - config/app.php
 *   - includes/config.php   ← asset() declarado aqui
 * Histórico:
 *   2026-04-08  v1.0.0  Criação
 *   2026-04-08  v1.4.0  Fix caminho __DIR__
 *   2026-04-08  v1.4.1  Fix CSP
 *   2026-04-08  v1.4.2  Removido appUrl() — conflito com config.php
 *   2026-04-08  v1.4.3  requireAuth() usa asset() de includes/config.php
 */
 // =============================================================
// HISTÓRICO (continuação):
//   2026-05-15  v1.5.0  [ADD] authControlador() — HMAC-SHA256 para IoT
//                        [ADD] authUsuario()     — sessão web para API
// =============================================================


declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/config.php'; // ← asset() canônico

function isHttps(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    if (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) {
        return true;
    }
    if (
        !empty($_SERVER['HTTP_X_FORWARDED_PROTO']) &&
        strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https'
    ) {
        return true;
    }
    return false;
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
    header(
        "Content-Security-Policy: " .
        "default-src 'self'; " .
        "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com https://cdn.jsdelivr.net; " .
        "font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; " .
        "script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://cdn.jsdelivr.net; " .
        "img-src 'self' data:; " .
        "connect-src 'self'; " .
        "frame-ancestors 'self'; " .
        "form-action 'self'; " .
        "base-uri 'self';"
    );
}

function startSecureSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    if (headers_sent($file, $line)) {
        throw new RuntimeException(
            'Headers já enviados em ' . $file . ':' . $line
        );
    }

    sendSecurityHeaders();
    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'domain'   => '',
        'secure'   => isHttps(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

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
    if (
        !isset($_SESSION['user_agent']) ||
        $_SESSION['user_agent'] !== ($_SERVER['HTTP_USER_AGENT'] ?? '')
    ) {
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
        header('Location: ' . asset('/login.php')); // ← corrigido
        exit;
    }
}

function loginUser(array $usuario): void
{
    startSecureSession();
    session_regenerate_id(true);

    $_SESSION['usuario_id']         = (int)    ($usuario['id']     ?? 0);
    $_SESSION['usuario_nome']       = (string) ($usuario['nome']   ?? '');
    $_SESSION['usuario_email']      = (string) ($usuario['email']  ?? '');
    $_SESSION['usuario_perfil']     = (string) ($usuario['perfil'] ?? '');

    // 🆕 Multi-empresa: papel global (null = usuário escopado a uma empresa)
    $_SESSION['papel_global'] = isset($usuario['papel_global']) && $usuario['papel_global'] !== null
        ? (string) $usuario['papel_global']
        : null;

    // 🆕 Multi-empresa: empresa_id (null = usuário global, vê tudo)
    $_SESSION['empresa_id'] = isset($usuario['empresa_id']) && $usuario['empresa_id'] !== null
        ? (int) $usuario['empresa_id']
        : null;

    $_SESSION['user_agent']         = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $_SESSION['session_started_at'] = time();
    $_SESSION['last_regeneration']  = time();
    $_SESSION['expires_at']         = time() + SESSION_LIFETIME;
}

function logoutUser(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        if (headers_sent()) {
            return;
        }
        session_name(SESSION_NAME);
        session_start();
    }

    $_SESSION = [];

    if (ini_get('session.use_cookies') && !headers_sent()) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $params['path'],
            'domain'   => $params['domain'],
            'secure'   => (bool) $params['secure'],
            'httponly' => (bool) $params['httponly'],
            'samesite' => 'Lax',
        ]);
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

/**
 * Versão da requireAuth() para endpoints de API.
 * Retorna JSON 401 em vez de redirecionar para login.php
 * Adicionada em 2026-04-11 v1.4.4
 */
function requireAuthApi(): void
{
    if (!isAuthenticated()) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        http_response_code(401);
        echo json_encode(['sucesso' => false, 'erro' => 'Sessão inválida ou expirada']);
        exit;
    }
}
/**
 * Autentica requisição de dispositivo IoT via HMAC-SHA256.
 * Usada por: POST /api/leituras (LeituraController::store)
 *
 * Lê headers X-CIP-Serial, X-CIP-TS, X-CIP-Sig e valida
 * contra o hmac_secret gravado na tabela controladores.
 *
 * @return array  Row do controlador autenticado (sem hmac_secret)
 */
function authControlador(): array
{
    require_once __DIR__ . '/services/HmacAuth.php';

    try {
        return HmacAuth::autenticar();

    } catch (RuntimeException $e) {
        $code = (int) $e->getCode();
        $httpCode = in_array($code, [401, 403], true) ? $code : 401;

        error_log(
            '[authControlador][' . date('Y-m-d H:i:s') . '] ' .
            'Falha: ' . $e->getMessage() .
            ' | IP: ' . ($_SERVER['REMOTE_ADDR'] ?? '?') .
            ' | Serial: ' . ($_SERVER['HTTP_X_CIP_SERIAL'] ?? '?')
        );

        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        http_response_code($httpCode);
        echo json_encode([
            'sucesso' => false,
            'erro'    => $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

/**
 * Autentica usuário humano via sessão web.
 * Usada por: GET /api/leituras/agora|historico|resumo
 *
 * @return array  Dados do usuário autenticado
 */
function authUsuario(): array
{
    if (!isAuthenticated()) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        http_response_code(401);
        echo json_encode(
            ['sucesso' => false, 'erro' => 'Sessão inválida ou expirada'],
            JSON_UNESCAPED_UNICODE
        );
        exit;
    }

    return [
        'id'           => (int)    ($_SESSION['usuario_id']     ?? 0),
        'nome'         => (string) ($_SESSION['usuario_nome']   ?? ''),
        'email'        => (string) ($_SESSION['usuario_email']  ?? ''),
        'perfil'       => (string) ($_SESSION['usuario_perfil'] ?? ''),
        'papel_global' => $_SESSION['papel_global'] ?? null,     // 🆕 null = escopado
        'empresa_id'   => $_SESSION['empresa_id']   ?? null,     // 🆕 null = global
    ];
}


