<?php
/**
 * Arquivo: login.php
 * Projeto: Controlador de Injeção de Potência Elétrica
 * Objetivo: Realizar autenticação de usuários com proteção CSRF e compatibilidade
 * com a estrutura real da tabela usuarios, utilizando o design system global.
 *
 * Dependências de hardware:
 *   - Servidor web
 *   - Banco MySQL/MariaDB
 *
 * Dependências de software/arquivos instalados:
 *   - config/app.php
 *   - app/auth.php
 *   - app/security.php
 *   - app/services/Database.php
 *   - includes/config.php  ← asset() declarado aqui
 *   - assets/css/app.css
 *   - assets/css/login.css
 *
 * Histórico de implementações:
 *   2026-03-25 20:22  Criação da página de login funcional
 *   2026-03-27 13:15  Inclusão explícita de CSS/JS externos para CSP
 *   2026-03-30 17:15  Redesign integrado ao design system global
 *   2026-04-08 16:15  Substituído appUrl() por asset() — appUrl() só
 *                     existe em app_header.php, não carregado no login
 *                     Adicionado log de erro para diagnóstico seguro
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/app/auth.php';      // ← já carrega includes/config.php com asset()
require_once __DIR__ . '/app/security.php';
require_once __DIR__ . '/app/services/Database.php';

use App\Services\Database;

startSecureSession();

// Já autenticado → vai direto ao dashboard
if (isAuthenticated()) {
    header('Location: ' . asset('/dashboard.php')); // ← era appUrl()
    exit;
}

$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['_token'] ?? null;

    if (!validateCsrfToken(is_string($token) ? $token : null)) {
        $erro = 'Sessão de segurança inválida. Atualize a página e tente novamente.';
    } else {
        $email = trim((string) ($_POST['email'] ?? ''));
        $senha = (string) ($_POST['senha'] ?? '');

        if ($email === '' || $senha === '') {
            $erro = 'Informe e-mail e senha.';
        } else {
            try {
                $pdo = Database::getConnection();

                $stmt = $pdo->prepare('
                SELECT
                    id,
                    nome,
                    email,
                    senha_hash,
                    perfil,
                    papel_global,
                    empresa_id,
                    ativo
                FROM usuarios
                WHERE email = :email
                  AND deleted_at IS NULL
                LIMIT 1
            ');
            $stmt->execute(['email' => $email]);
            
            $usuario = $stmt->fetch(PDO::FETCH_ASSOC);


                if (!$usuario) {
                    $erro = 'Credenciais inválidas.';
                } elseif ((int) ($usuario['ativo'] ?? 0) !== 1) {
                    $erro = 'Usuário inativo.';
                } elseif (
                    !isset($usuario['senha_hash']) ||
                    !password_verify($senha, (string) $usuario['senha_hash'])
                ) {
                    $erro = 'Credenciais inválidas.';
                } else {
                    loginUser([
                        'id'           => (int)    ($usuario['id']           ?? 0),
                        'nome'         => (string) ($usuario['nome']         ?? ''),
                        'email'        => (string) ($usuario['email']        ?? ''),
                        'perfil'       => (string) ($usuario['perfil']       ?? ''),
                        'papel_global' => $usuario['papel_global'] ?? null,        // 🆕
                        'empresa_id'   => $usuario['empresa_id']   !== null         // 🆕
                            ? (int) $usuario['empresa_id']
                            : null,
                    ]);

                    header('Location: ' . asset('/dashboard.php')); // ← era appUrl()
                    exit;
                }

            } catch (\Throwable $e) {
                // Log seguro — nunca expõe detalhes ao usuário
                error_log(
                    '[CIP][login.php] Throwable capturado: ' .
                    $e->getMessage() .
                    ' | Arquivo: ' . $e->getFile() .
                    ' | Linha: ' . $e->getLine()
                );

                $erro = 'Falha ao processar autenticação.';
            }
        }
    }
}

$csrfTokenField = csrfTokenField();
$appName        = defined('APP_NAME') ? APP_NAME : 'CIP';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — <?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="<?= asset('assets/css/app.css') ?>">
    <link rel="stylesheet" href="<?= asset('assets/css/login.css') ?>">
</head>
<body class="login-page">
    <div class="login-card">
        <div class="login-brand">
            <img
                src="<?= asset('assets/img/logo-aeonium.png') ?>"
                alt="Aeonium Energia Sustentável"
                class="login-brand-logo"
                width="200"
                height="88"
            >
            <h1><?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?></h1>
            <p>Acesso ao painel técnico do sistema</p>
        </div>

        <?php if ($erro !== ''): ?>
            <div class="login-erro" role="alert">
                <?= htmlspecialchars($erro, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <form method="post" action="" class="login-form" novalidate>
            <?= $csrfTokenField ?>

            <div class="login-field">
                <label for="email">E-mail</label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    placeholder="usuario@exemplo.com.br"
                    autocomplete="email"
                    required
                >
            </div>

            <div class="login-field">
                <label for="senha">Senha</label>
                <input
                    type="password"
                    id="senha"
                    name="senha"
                    placeholder="••••••••"
                    autocomplete="current-password"
                    required
                >
            </div>

            <button type="submit" class="login-submit">Entrar</button>
        </form>

        <div class="login-footer">
            Controlador de Injeção de Potência Elétrica
        </div>
    </div>
</body>
</html>
