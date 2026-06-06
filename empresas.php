<?php
declare(strict_types=1);

/**
 * ============================================================
 * Arquivo   : empresas.php
 * Projeto   : CIP — Controlador de Injeção de Potência Elétrica
 * Objetivo  : Cadastro e gestão da empresa/integrador para
 *             personalização institucional (white-label) no sistema.
 *
 * Dependências de hardware:
 *   - Servidor web compatível com PHP 8.3+
 *   - Banco de dados MySQL/MariaDB
 *   - Armazenamento local para upload (pasta uploads/)
 *
 * Dependências de software/arquivos:
 *   - PHP 8.3+
 *   - PDO MySQL
 *   - config/app.php
 *   - app/auth.php
 *   - app/services/Database.php
 *   - includes/app_header.php
 *   - assets/css/app.css
 *   - assets/css/header.css
 *   - assets/css/empresa.css
 *   - assets/js/app-shell.js
 *   - assets/js/upload-logo.js
 *   - Tabela `empresa` no banco
 *
 * Histórico de implementações:
 *   2026-03-26  v1.0.0  Criação do módulo de cadastro da empresa
 *   2026-03-26  v1.1.0  Formulário com validação e sanitização
 *   2026-03-27  v1.2.0  Correção de erro 500 e adequação ao shell padrão
 *   2026-04-02  v1.3.0  Implementação do upload de logomarca
 *   2026-04-15  v1.4.0  [FIX] Suporte a perfis master e master_operador
 *                        além de administrador. Cabeçalho técnico
 *                        atualizado. Histórico padronizado.
 * ============================================================
 */

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/services/Database.php';

use App\Services\Database;

startSecureSession();
requireAuth();

// ── Helpers ──────────────────────────────────────────────────
if (!function_exists('e')) {
    function e($valor): string
    {
        return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
    }
}

function generateCsrfToken(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrfToken(string $token): bool
{
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

if (!function_exists('appUrl')) {
    function appUrl(string $path = ''): string
    {
        return APP_URL . APP_BASE_PATH . $path;
    }
}

// ── Controle de acesso ───────────────────────────────────────
// Apenas master, master_operador e administrador acessam esta página
$usuarioPerfil = isset($_SESSION['usuario_perfil']) ? (string) $_SESSION['usuario_perfil'] : '';
$podeAcessar   = in_array($usuarioPerfil, ['master', 'master_operador', 'administrador'], true);

if (!$podeAcessar) {
    header('Location: ' . appUrl('/dashboard.php'));
    exit;
}

// Somente master e master_operador podem cadastrar/editar empresa
// Administrador pode apenas visualizar
$podeEditar = in_array($usuarioPerfil, ['master', 'master_operador'], true);

// ── Variáveis de página ──────────────────────────────────────
$appTituloPagina    = 'Empresas';
$appSubtituloPagina = 'Cadastro e gestão de empresas';
$appPaginaAtual     = 'empresas';
$appIsAdmin         = true;

$pdo     = Database::getConnection();
$empresa = null;
$sucesso = '';
$erro    = '';

// ── Buscar dados da empresa ──────────────────────────────────
try {
    $stmt    = $pdo->query('SELECT * FROM empresa ORDER BY id DESC LIMIT 1');
    $empresa = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $erro = 'Erro ao carregar dados da empresa.';
}

// ── Processar formulário de dados institucionais ─────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action']) && !isset($_POST['remover_logo'])) {

    if (!$podeEditar) {
        $erro = 'Seu perfil não tem permissão para editar dados da empresa.';
    } else {
        $csrfToken = (string) ($_POST['csrf_token'] ?? '');

        if (!verifyCsrfToken($csrfToken)) {
            $erro = 'Token de segurança inválido. Recarregue a página.';
        } else {
            $nomeFantasia = trim((string) ($_POST['nome_fantasia'] ?? ''));
            $razaoSocial  = trim((string) ($_POST['razao_social']  ?? ''));
            $cnpj         = trim((string) ($_POST['cnpj']          ?? ''));
            $email        = trim((string) ($_POST['email']         ?? ''));
            $telefone     = trim((string) ($_POST['telefone']      ?? ''));
            $endereco     = trim((string) ($_POST['endereco']      ?? ''));

            if ($nomeFantasia === '') {
                $erro = 'Nome fantasia é obrigatório.';
            } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $erro = 'E-mail inválido.';
            } else {
                try {
                    if ($empresa) {
                        $stmt = $pdo->prepare('
                            UPDATE empresa
                            SET nome_fantasia = :nome_fantasia,
                                razao_social  = :razao_social,
                                cnpj          = :cnpj,
                                email         = :email,
                                telefone      = :telefone,
                                endereco      = :endereco,
                                updated_at    = CURRENT_TIMESTAMP
                            WHERE id = :id
                        ');
                        $stmt->bindValue(':id', (int) $empresa['id'], PDO::PARAM_INT);
                    } else {
                        $stmt = $pdo->prepare('
                            INSERT INTO empresa
                                (nome_fantasia, razao_social, cnpj, email, telefone, endereco)
                            VALUES
                                (:nome_fantasia, :razao_social, :cnpj, :email, :telefone, :endereco)
                        ');
                    }

                    $stmt->bindValue(':nome_fantasia', $nomeFantasia);
                    $stmt->bindValue(':razao_social',  $razaoSocial);
                    $stmt->bindValue(':cnpj',          $cnpj);
                    $stmt->bindValue(':email',         $email);
                    $stmt->bindValue(':telefone',      $telefone);
                    $stmt->bindValue(':endereco',      $endereco);
                    $stmt->execute();

                    $sucesso = $empresa
                        ? 'Dados da empresa atualizados com sucesso!'
                        : 'Empresa cadastrada com sucesso!';

                    $stmt    = $pdo->query('SELECT * FROM empresa ORDER BY id DESC LIMIT 1');
                    $empresa = $stmt->fetch(PDO::FETCH_ASSOC);

                } catch (Throwable $e) {
                    $erro = 'Erro ao salvar dados da empresa. Tente novamente.';
                }
            }
        }
    }
}

// ── Upload de logo ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['action'])
    && $_POST['action'] === 'upload_logo'
) {
    if (!$podeEditar) {
        $erro = 'Seu perfil não tem permissão para alterar a logomarca.';
    } else {
        $csrfToken = (string) ($_POST['csrf_token'] ?? '');

        if (!verifyCsrfToken($csrfToken)) {
            $erro = 'Token de segurança inválido. Recarregue a página.';
        } elseif (isset($_FILES['logo_file']) && $_FILES['logo_file']['error'] === UPLOAD_ERR_OK) {
            $file        = $_FILES['logo_file'];
            $fileTmpPath = $file['tmp_name'];
            $fileName    = $file['name'];
            $fileSize    = $file['size'];
            $fileType    = $file['type'];

            $allowedTypes = ['image/jpeg', 'image/png', 'image/svg+xml'];
            $maxSize      = 200 * 1024; // 200KB

            if (!in_array($fileType, $allowedTypes)) {
                $erro = 'Tipo de arquivo inválido. Use JPG, PNG ou SVG.';
            } elseif ($fileSize > $maxSize) {
                $erro = 'Arquivo muito grande. Máximo 200KB.';
            } else {
                $fileExt     = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                $newFileName = 'logo_' . $empresa['id'] . '_' . time() . '.' . $fileExt;
                $uploadPath  = 'uploads/' . $newFileName;

                if (!is_dir('uploads')) {
                    mkdir('uploads', 0755, true);
                }

                if (move_uploaded_file($fileTmpPath, $uploadPath)) {
                    try {
                        $stmt = $pdo->prepare('
                            UPDATE empresa
                            SET logo_path        = :logo_path,
                                logo_updated_at  = CURRENT_TIMESTAMP
                            WHERE id = :id
                        ');
                        $stmt->bindValue(':logo_path', $uploadPath);
                        $stmt->bindValue(':id', (int) $empresa['id'], PDO::PARAM_INT);
                        $stmt->execute();

                        $sucesso = 'Logomarca carregada com sucesso!';

                        $stmt    = $pdo->query('SELECT * FROM empresa ORDER BY id DESC LIMIT 1');
                        $empresa = $stmt->fetch(PDO::FETCH_ASSOC);
                    } catch (Throwable $e) {
                        $erro = 'Erro ao salvar no banco. Tente novamente.';
                        unlink($uploadPath);
                    }
                } else {
                    $erro = 'Erro ao mover arquivo. Verifique permissões da pasta uploads/.';
                }
            }
        } else {
            $erro = 'Erro no upload: ' . ($_FILES['logo_file']['error'] ?? 'Arquivo não enviado');
        }
    }
}

// ── Remover logo ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remover_logo'])) {
    if (!$podeEditar) {
        $erro = 'Seu perfil não tem permissão para remover a logomarca.';
    } else {
        $csrfToken = (string) ($_POST['csrf_token'] ?? '');

        if (verifyCsrfToken($csrfToken) && $empresa && !empty($empresa['logo_path'])) {
            $logoPath = $empresa['logo_path'];

            if (file_exists($logoPath)) {
                unlink($logoPath);
            }

            try {
                $stmt = $pdo->prepare('
                    UPDATE empresa
                    SET logo_path       = NULL,
                        logo_updated_at = CURRENT_TIMESTAMP
                    WHERE id = :id
                ');
                $stmt->bindValue(':id', (int) $empresa['id'], PDO::PARAM_INT);
                $stmt->execute();

                $sucesso              = 'Logomarca removida com sucesso!';
                $empresa['logo_path'] = null;
            } catch (Throwable $e) {
                $erro = 'Erro ao remover do banco.';
            }
        }
    }
}

// ── Valores para o formulário ─────────────────────────────────
$nomeFantasiaValue = $empresa ? e($empresa['nome_fantasia'])       : '';
$razaoSocialValue  = $empresa ? e($empresa['razao_social']  ?? '') : '';
$cnpjValue         = $empresa ? e($empresa['cnpj']          ?? '') : '';
$emailValue        = $empresa ? e($empresa['email']         ?? '') : '';
$telefoneValue     = $empresa ? e($empresa['telefone']      ?? '') : '';
$enderecoValue     = $empresa ? e($empresa['endereco']      ?? '') : '';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Empresas — CIP</title>
    <link rel="stylesheet" href="<?php echo e(appUrl('/assets/css/app.css')); ?>">
    <link rel="stylesheet" href="<?php echo e(appUrl('/assets/css/header.css')); ?>">
    <link rel="stylesheet" href="<?php echo e(appUrl('/assets/css/empresa.css')); ?>">
</head>
<body>
    <?php require __DIR__ . '/includes/app_header.php'; ?>

    <main class="app-content container empresa-container">

        <?php if ($sucesso): ?>
            <div class="alert alert-success" role="alert"><?php echo e($sucesso); ?></div>
        <?php endif; ?>

        <?php if ($erro): ?>
            <div class="alert alert-error" role="alert"><?php echo e($erro); ?></div>
        <?php endif; ?>

        <?php if (!$podeEditar): ?>
            <div class="alert alert-info" role="alert">
                Seu perfil (<strong><?php echo e($usuarioPerfil); ?></strong>) tem acesso somente leitura aos dados da empresa.
            </div>
        <?php endif; ?>

        <div class="empresa-grid">

            <!-- ── Dados institucionais ─────────────────────── -->
            <section class="panel">
                <div class="panel-header">
                    <h2>Dados institucionais</h2>
                    <p class="panel-description">
                        Essas informações aparecerão no dashboard e nos relatórios do sistema.
                    </p>
                </div>

                <form method="POST" class="form-empresa">
                    <input type="hidden" name="csrf_token" value="<?php echo e(generateCsrfToken()); ?>">

                    <div class="form-grid">
                        <div class="form-group">
                            <label for="nome_fantasia" class="form-label required">Nome fantasia</label>
                            <input
                                type="text"
                                id="nome_fantasia"
                                name="nome_fantasia"
                                value="<?php echo $nomeFantasiaValue; ?>"
                                class="form-input"
                                required
                                maxlength="120"
                                placeholder="Nome comercial da empresa"
                                <?php echo !$podeEditar ? 'disabled' : ''; ?>
                            >
                        </div>

                        <div class="form-group">
                            <label for="razao_social" class="form-label">Razão social</label>
                            <input
                                type="text"
                                id="razao_social"
                                name="razao_social"
                                value="<?php echo $razaoSocialValue; ?>"
                                class="form-input"
                                maxlength="200"
                                placeholder="Razão social completa"
                                <?php echo !$podeEditar ? 'disabled' : ''; ?>
                            >
                        </div>

                        <div class="form-group">
                            <label for="cnpj" class="form-label">CNPJ</label>
                            <input
                                type="text"
                                id="cnpj"
                                name="cnpj"
                                value="<?php echo $cnpjValue; ?>"
                                class="form-input"
                                maxlength="18"
                                placeholder="00.000.000/0000-00"
                                <?php echo !$podeEditar ? 'disabled' : ''; ?>
                            >
                        </div>

                        <div class="form-group">
                            <label for="email" class="form-label">E-mail</label>
                            <input
                                type="email"
                                id="email"
                                name="email"
                                value="<?php echo $emailValue; ?>"
                                class="form-input"
                                maxlength="150"
                                placeholder="contato@empresa.com.br"
                                <?php echo !$podeEditar ? 'disabled' : ''; ?>
                            >
                        </div>

                        <div class="form-group">
                            <label for="telefone" class="form-label">Telefone</label>
                            <input
                                type="text"
                                id="telefone"
                                name="telefone"
                                value="<?php echo $telefoneValue; ?>"
                                class="form-input"
                                maxlength="20"
                                placeholder="(11) 99999-9999"
                                <?php echo !$podeEditar ? 'disabled' : ''; ?>
                            >
                        </div>

                        <div class="form-group form-group-full">
                            <label for="endereco" class="form-label">Endereço</label>
                            <textarea
                                id="endereco"
                                name="endereco"
                                class="form-textarea"
                                rows="3"
                                placeholder="Endereço completo da empresa"
                                <?php echo !$podeEditar ? 'disabled' : ''; ?>
                            ><?php echo $enderecoValue; ?></textarea>
                        </div>
                    </div>

                    <?php if ($podeEditar): ?>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <?php echo $empresa ? 'Atualizar empresa' : 'Cadastrar empresa'; ?>
                        </button>
                    </div>
                    <?php endif; ?>
                </form>
            </section>

            <!-- ── Logomarca ────────────────────────────────── -->
            <section class="panel">
                <div class="panel-header">
                    <h2>Logomarca</h2>
                    <p class="panel-description">
                        Upload da logo para personalização do dashboard (máx. 200KB, JPG/PNG/SVG).
                    </p>
                </div>

                <form method="POST" action="<?php echo e($_SERVER['PHP_SELF']); ?>"
                      enctype="multipart/form-data" class="logo-upload-form">
                    <input type="hidden" name="csrf_token" value="<?php echo e(generateCsrfToken()); ?>">
                    <input type="hidden" name="action" value="upload_logo">

                    <?php if ($empresa && !empty($empresa['logo_path'])): ?>
                        <div class="logo-current">
                            <img src="<?php echo e(appUrl('/' . $empresa['logo_path'])); ?>"
                                 alt="Logomarca atual" class="logo-image">
                            <p class="logo-info">
                                Logo atual (atualizada em
                                <?php echo !empty($empresa['logo_updated_at'])
                                    ? date('d/m/Y H:i', strtotime((string) $empresa['logo_updated_at']))
                                    : 'data não disponível'; ?>)
                            </p>

                            <?php if ($podeEditar): ?>
                            <div class="logo-actions">
                                <label for="logo_file" class="btn btn-primary btn-small">
                                    <span>🔄</span> Trocar logo
                                    <input type="file" id="logo_file" name="logo_file"
                                           accept="image/jpeg,image/png,image/svg+xml"
                                           style="display: none;">
                                </label>
                                <button type="submit" name="remover_logo" value="1"
                                        class="btn btn-danger btn-small"
                                        onclick="return confirm('Remover logomarca?')">
                                    🗑️ Remover
                                </button>
                            </div>
                            <?php endif; ?>
                        </div>

                    <?php else: ?>
                        <div class="logo-placeholder">
                            <div class="logo-placeholder-icon">🏢</div>
                            <p>Nenhuma logomarca cadastrada</p>
                            <?php if ($podeEditar): ?>
                            <label for="logo_file" class="btn btn-primary logo-upload-btn">
                                📤 Upload da logomarca
                                <input type="file" id="logo_file" name="logo_file"
                                       accept="image/jpeg,image/png,image/svg+xml"
                                       style="display: none;">
                            </label>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </form>
            </section>

        </div>

        <!-- ── Resumo cadastral ─────────────────────────────── -->
        <?php if ($empresa): ?>
            <section class="panel">
                <div class="panel-header">
                    <h2>Resumo cadastral</h2>
                </div>
                <div class="empresa-summary">
                    <div class="summary-item">
                        <strong>ID:</strong> <?php echo e((string) $empresa['id']); ?>
                    </div>
                    <div class="summary-item">
                        <strong>Cadastrado em:</strong>
                        <?php echo !empty($empresa['created_at'])
                            ? date('d/m/Y H:i', strtotime((string) $empresa['created_at']))
                            : '-'; ?>
                    </div>
                    <div class="summary-item">
                        <strong>Última atualização:</strong>
                        <?php echo !empty($empresa['updated_at'])
                            ? date('d/m/Y H:i', strtotime((string) $empresa['updated_at']))
                            : '-'; ?>
                    </div>
                    <div class="summary-item">
                        <strong>Acesso atual:</strong>
                        <span class="badge-perfil badge-<?php echo e($usuarioPerfil); ?>">
                            <?php echo e($usuarioPerfil); ?>
                        </span>
                    </div>
                </div>
            </section>
        <?php endif; ?>

    </main>

    <script src="<?php echo e(appUrl('/assets/js/app-shell.js')); ?>"></script>
    <script src="<?php echo e(appUrl('/assets/js/upload-logo.js')); ?>"></script>
</body>
</html>
