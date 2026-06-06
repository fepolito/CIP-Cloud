<?php
/**
 * Arquivo: index.php
 * Projeto: Controlador de Injecao de Potencia Eletrica
 * Objetivo: Ponto de entrada da aplicacao. Redireciona o usuario
 *           para dashboard.php (se autenticado) ou login.php.
 *
 * Dependencias de software/arquivos:
 *   - config/app.php
 *   - app/auth.php        (isAuthenticated, startSecureSession)
 *   - includes/config.php (asset, APP_BASE_URL) — carregado via app/auth.php
 *
 * Historico:
 *   2026-XX-XX  v1.0.0  Versao inicial
 *   2026-05-31  v1.1.0  Substituidos redirects '/dashboard.php' e
 *                       '/login.php' por asset() para respeitar
 *                       APP_BASE_URL em dev (Laragon) e prod.
 *   2026-05-31  v1.2.0  Usa isAuthenticated()/startSecureSession()
 *                       do app/auth.php em vez de checagem manual
 *                       de $_SESSION, mantendo consistencia com
 *                       o restante do sistema.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/app/auth.php';   // ja carrega includes/config.php (asset)

startSecureSession();

// Se ja esta autenticado -> dashboard
if (isAuthenticated()) {
    header('Location: ' . asset('/dashboard.php'));
    exit;
}

// Caso contrario -> login
header('Location: ' . asset('/login.php'));
exit;
