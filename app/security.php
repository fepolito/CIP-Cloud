<?php
/**
 * Arquivo: app/security.php
 * Projeto: Controlador de Injeção de Potência Elétrica
 * Objetivo: Implementar proteção CSRF e utilidades de segurança para formulários
 * Dependências de hardware:
 * - Servidor web
 * Dependências de software:
 * - PHP 8.3+
 * - app/auth.php
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function generateCsrfToken(): string
{
    startSecureSession();

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['csrf_token'];
}

function csrfTokenField(): string
{
    $token = generateCsrfToken();
    return '<input type="hidden" name="_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
}

function validateCsrfToken(?string $token): bool
{
    startSecureSession();

    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }

    return hash_equals((string) $_SESSION['csrf_token'], (string) $token);
}
