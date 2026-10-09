<?php
declare(strict_types=1);
/**
 * ============================================================
 * Arquivo   : empresas.php
 * Projeto   : CIP — Controlador de Injeção de Potência Elétrica
 * Objetivo  : Detalhe/edição de uma empresa específica (via ?id=X),
 *             com abas "Dados Gerais" (institucional + logo + resumo)
 *             e "Controladores" (lista filtrada por empresa_id, com
 *             atalho para cadastro/edição em controlador_form.php).
 *
 * Histórico de implementações:
 *   2026-03-26  v1.0.0  Criação do módulo de cadastro da empresa
 *   2026-04-15  v1.4.0  Suporte a perfis master/master_operador/administrador
 *   2026-10-08  v2.0.0  PASSO 2: busca por ?id=X (fim do LIMIT 1 legado);
 *                        nova aba "Controladores" com lista por empresa_id
 *                        e atalho para controlador_form.php?empresa_id=X
 * ============================================================
 */
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/services/Database.php';
use App\Services\Database;

startSecureSession();
requireAuth();

if (!function_exists('e')) {
    function e($valor): string
    {
        return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('generateCsrfToken')) {
    function generateCsrfToken(): string
    {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}
if (!function_exists('verifyCsrfToken')) {
    function verifyCsrfToken(string $token): bool
    {
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }
}
if (!function_exists('appUrl')) {
    function appUrl(string $path = ''): string
    {
        return APP_URL . APP_BASE_PATH . $path;
    }
}
if (!function_exists('formatarStatus')) {
    function formatarStatus(string $status): array
    {
        $statusConfig = [
            'ativo' => ['texto' => 'Ativo', 'classe' => 'status-ativo'],
            'inativo' => ['texto' => 'Inativo', 'classe' => 'status-inativo'],
            'manutencao' => ['texto' => 'Manutenção', 'classe' => 'status-manutencao'],
            'erro' => ['texto' => 'Erro', 'classe' => 'status-erro'],
        ];
        return $statusConfig[$status] ?? ['texto' => 'Desconhecido', 'classe' => 'status-desconhecido'];
    }
}
if (!function_exists('formatarDataHora')) {
    function formatarDataHora(?string $dataHora): string
    {
        if (!$dataHora) {
            return 'Nunca';
        }
        try {
            return date('d/m/Y H:i', strtotime($dataHora));
        } catch (Throwable $e) {
            return 'Data inválida';
        }
    }
}

$usuarioPerfil = isset($_SESSION['usuario_perfil']) ? (string) $_SESSION['usuario_perfil'] : '';
$podeAcessar   = in_array($usuarioPerfil, ['master', 'master_operador', 'administrador'], true);
if (!$podeAcessar) {
    header('Location: ' . appUrl('/dashboard.php'));
    exit;
}
$podeEditar = in_array($usuarioPerfil, ['master', 'master_operador'], true);

$appTituloPagina    = 'Empresas';
$appSubtituloPagina = 'Cadastro e gestão de empresas';
$appPaginaAtual     = 'empresas';
$appIsAdmin         = true;

$pdo     = Database::getConnection();
$empresa = null;
$sucesso = '';
$erro    = '';

// PASSO 2: busca por ID explícito (fim do LIMIT 1 legado)
$empresaId = (int) ($_GET['id'] ?? $_POST['empresa_id'] ?? 0);

if ($empresaId <= 0) {
    header('Location: ' . appUrl('/empresas_lista.php'));
    exit;
}

try {
    $stmt = $pdo->prepare('SELECT * FROM empresa WHERE id = :id');
    $stmt->bindValue(':id', $empresaId, PDO::PARAM_INT);
    $stmt->execute();
    $empresa = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$empresa) {
        header('Location: ' . appUrl('/empresas_lista.php'));
        exit;
    }
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
                    $stmt->bindValue(':id', $empresaId, PDO::PARAM_INT);
                    $stmt->bindValue(':nome_fantasia', $nomeFantasia);
                    $stmt->bindValue(':razao_social',  $razaoSocial);
                    $stmt->bindValue(':cnpj',          $cnpj);
                    $stmt->bindValue(':email',         $email);
                    $stmt->bindValue(':telefone',      $telefone);
                    $stmt->bindValue(':endereco',      $endereco);
                    $stmt->execute();

                    $sucesso = 'Dados da empresa atualizados com sucesso!';

                    $stmt = $pdo->prepare('SELECT * FROM empresa WHERE id = :id');
                    $stmt->bindValue(':id', $empresaId, PDO::PARAM_INT);
                    $stmt->execute();
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
            $maxSize      = 200 * 1024;

            if (!in_array($fileType, $allowedTypes)) {
                $erro = 'Tipo de arquivo inválido. Use JPG, PNG ou SVG.';
            } elseif ($fileSize > $maxSize) {
                $erro = 'Arquivo muito grande. Máximo 200KB.';
            } else {
                $fileExt     = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                $newFileName = 'logo_' . $empresaId . '_' . time() . '.' . $fileExt;
                $uploadPath  = 'uploads/' . $newFileName;

                if (!is_dir('uploads')) {
                    mkdir('uploads', 0755, true);
                }

                if (move_uploaded_file($fileTmpPath, $uploadPath)) {
                    try {
                        $stmt = $pdo->prepare('
                            UPDATE empresa
                            SET logo_path       = :logo_path,
                                logo_updated_at = CURRENT_TIMESTAMP
                            WHERE id = :id
                        ');
                        $stmt->bindValue(':logo_path', $uploadPath);
                        $stmt->bindValue(':id', $empresaId, PDO::PARAM_INT);
                        $stmt->execute();

                        $sucesso = 'Logomarca carregada com sucesso!';

                        $stmt = $pdo->prepare('SELECT * FROM empresa WHERE id = :id');
                        $stmt->bindValue(':id', $empresaId, PDO::PARAM_INT);
                        $stmt->execute();
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
                $stmt->bindValue(':id', $empresaId, PDO::PARAM_INT);
                $stmt->execute();

                $sucesso              = 'Logomarca removida com sucesso!';
                $empresa['logo_path'] = null;
            } catch (Throwable $e) {
                $erro = 'Erro ao remover do banco.';
            }
        }
    }
}

// ── Lista de controladores da empresa (PASSO 2) ──────────────
$controladoresDaEmpresa = [];
try {
    $stmt = $pdo->prepare('
        SELECT id, codigo, apelido, tipo, status, cliente_nome, local_instalacao,
               ultimo_contato, created_at AS criado_em
          FROM controladores
         WHERE empresa_id = :empresa_id
         ORDER BY codigo ASC
    ');
    $stmt->bindValue(':empresa_id', $empresaId, PDO::PARAM_INT);
    $stmt->execute();
    $controladoresDaEmpresa = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    if (!$erro) {
        $erro = 'Erro ao carregar controladores da empresa.';
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
    <link rel="stylesheet" href="<?php echo e(appUrl('/assets/css/controladores.css')); ?>">
    <style>
        .tabs { display: flex; gap: 4px; margin-bottom: 16px; border-bottom: 1px solid var(--border-color); }
        .tab-btn {
            padding: 10px 18px; cursor: pointer; border: none; background: none;
            color: var(--text-muted-color, #999); font-weight: 600; border-bottom: 2px solid transparent;
        }
        .tab-btn.active { color: var(--text-color); border-bottom-color: var(--accent-color, #4f8ef7); }
        .tab-panel { display: none; }
        .tab-panel.active { display: block; }
    </style>
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

        <h1 style="margin-bottom: 10px;"><?php echo e($nomeFantasiaValue); ?></h1>

        <div class="tabs">
            <button type="button" class="tab-btn active" data-tab="dados">Dados Gerais</button>
            <button type="button" class="tab-btn" data-tab="controladores">
                Controladores (<?php echo count($controladoresDaEmpresa); ?>)
            </button>
        </div>

        <!-- ══════════ ABA: DADOS GERAIS ══════════ -->
        <div class="tab-panel active" data-tab-panel="dados">
            <div class="empresa-grid">
                <section class="panel">
                    <div class="panel-header">
                        <h2>Dados institucionais</h2>
                        <p class="panel-description">
                            Essas informações aparecerão no dashboard e nos relatórios do sistema.
                        </p>
                    </div>
                    <form method="POST" class="form-empresa">
                        <input type="hidden" name="csrf_token" value="<?php echo e(generateCsrfToken()); ?>">
                        <input type="hidden" name="empresa_id" value="<?php echo e($empresaId); ?>">
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="nome_fantasia" class="form-label required">Nome fantasia</label>
                                <input type="text" id="nome_fantasia" name="nome_fantasia"
                                       value="<?php echo $nomeFantasiaValue; ?>" class="form-input" required
                                       maxlength="120" placeholder="Nome comercial da empresa"
                                       <?php echo !$podeEditar ? 'disabled' : ''; ?>>
                            </div>
                            <div class="form-group">
                                <label for="razao_social" class="form-label">Razão social</label>
                                <input type="text" id="razao_social" name="razao_social"
                                       value="<?php echo $razaoSocialValue; ?>" class="form-input"
                                       maxlength="200" placeholder="Razão social completa"
                                       <?php echo !$podeEditar ? 'disabled' : ''; ?>>
                            </div>
                            <div class="form-group">
                                <label for="cnpj" class="form-label">CNPJ</label>
                                <input type="text" id="cnpj" name="cnpj"
                                       value="<?php echo $cnpjValue; ?>" class="form-input"
                                       maxlength="18" placeholder="00.000.000/0000-00"
                                       <?php echo !$podeEditar ? 'disabled' : ''; ?>>
                            </div>
                            <div class="form-group">
                                <label for="email" class="form-label">E-mail</label>
                                <input type="email" id="email" name="email"
                                       value="<?php echo $emailValue; ?>" class="form-input"
                                       maxlength="150" placeholder="contato@empresa.com.br"
                                       <?php echo !$podeEditar ? 'disabled' : ''; ?>>
                            </div>
                            <div class="form-group">
                                <label for="telefone" class="form-label">Telefone</label>
                                <input type="text" id="telefone" name="telefone"
                                       value="<?php echo $telefoneValue; ?>" class="form-input"
                                       maxlength="20" placeholder="(11) 99999-9999"
                                       <?php echo !$podeEditar ? 'disabled' : ''; ?>>
                            </div>
                            <div class="form-group form-group-full">
                                <label for="endereco" class="form-label">Endereço</label>
                                <textarea id="endereco" name="endereco" class="form-textarea" rows="3"
                                          placeholder="Endereço completo da empresa"
                                          <?php echo !$podeEditar ? 'disabled' : ''; ?>><?php echo $enderecoValue; ?></textarea>
                            </div>
                        </div>
                        <?php if ($podeEditar): ?>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">Atualizar empresa</button>
                        </div>
                        <?php endif; ?>
                    </form>
                </section>

                <section class="panel">
                    <div class="panel-header">
                        <h2>Logomarca</h2>
                        <p class="panel-description">
                            Upload da logo para personalização do dashboard (máx. 200KB, JPG/PNG/SVG).
                        </p>
                    </div>
                    <form method="POST" action="<?php echo e($_SERVER['PHP_SELF'] . '?id=' . $empresaId); ?>"
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
                                               accept="image/jpeg,image/png,image/svg+xml" style="display: none;">
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
                                           accept="image/jpeg,image/png,image/svg+xml" style="display: none;">
                                </label>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </form>
                </section>
            </div>

            <section class="panel">
                <div class="panel-header">
                    <h2>Resumo cadastral</h2>
                </div>
                <div class="empresa-summary">
                    <div class="summary-item"><strong>ID:</strong> <?php echo e((string) $empresaId); ?></div>
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
        </div>

        <!-- ══════════ ABA: CONTROLADORES ══════════ -->
        <div class="tab-panel" data-tab-panel="controladores">
            <section class="panel">
                <div class="panel-header" style="display:flex; justify-content:space-between; align-items:center;">
                    <div>
                        <h2>Controladores desta empresa</h2>
                        <p class="panel-description">
                            <?php echo count($controladoresDaEmpresa); ?> controlador<?php echo count($controladoresDaEmpresa) !== 1 ? 'es' : ''; ?> vinculado<?php echo count($controladoresDaEmpresa) !== 1 ? 's' : ''; ?>
                        </p>
                    </div>
                    <a href="<?php echo e(appUrl('/controlador_form.php?empresa_id=' . $empresaId)); ?>" class="btn btn-primary">
                        + Novo Controlador
                    </a>
                </div>

                <?php if (empty($controladoresDaEmpresa)): ?>
                    <div class="empty-state">
                        <p>Nenhum controlador vinculado a esta empresa ainda.</p>
                        <p class="text-muted">Use o botão acima para cadastrar o primeiro.</p>
                    </div>
                <?php else: ?>
                    <div class="filtro-busca" style="margin-bottom: 16px;">
                        <input
                            type="text"
                            id="filtroControladoresEmpresa"
                            class="form-input"
                            placeholder="🔍 Buscar por apelido, código, cliente ou local..."
                            autocomplete="off"
                            style="width: 100%; max-width: 480px;"
                        >
                        <p class="text-muted" id="filtroContadorEmpresa" style="font-size: 0.8rem; margin-top: 4px;"></p>
                    </div>

                    <div class="controladores-list">
                        <?php foreach ($controladoresDaEmpresa as $controlador): ?>
                            <?php $statusInfo = formatarStatus($controlador['status']); ?>
                            <article class="controlador-card"
                                data-busca="<?php echo e(mb_strtolower(
                                    $controlador['apelido'] . ' ' .
                                    $controlador['codigo'] . ' ' .
                                    ($controlador['cliente_nome'] ?? '') . ' ' .
                                    ($controlador['local_instalacao'] ?? '')
                                )); ?>">
                                <div class="controlador-header">
                                    <div class="controlador-info">
                                        <h3><?php echo e($controlador['apelido']); ?></h3>
                                        <p class="controlador-codigo"><?php echo e($controlador['codigo']); ?></p>
                                        <span class="status-badge <?php echo e($statusInfo['classe']); ?>">
                                            <?php echo e($statusInfo['texto']); ?>
                                        </span>
                                        <span class="status-badge" style="background: var(--border-color);">
                                            <?php echo e($controlador['tipo']); ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="controlador-details">
                                    <?php if (!empty($controlador['local_instalacao'])): ?>
                                        <p><strong>Local:</strong> <?php echo e($controlador['local_instalacao']); ?></p>
                                    <?php endif; ?>
                                    <?php if (!empty($controlador['cliente_nome'])): ?>
                                        <p><strong>Cliente:</strong> <?php echo e($controlador['cliente_nome']); ?></p>
                                    <?php endif; ?>
                                    <div class="controlador-meta">
                                        <span><strong>Último contato:</strong> <?php echo formatarDataHora($controlador['ultimo_contato']); ?></span>
                                        <span><strong>Cadastrado em:</strong> <?php echo formatarDataHora($controlador['criado_em']); ?></span>
                                    </div>
                                </div>
                                <div class="controlador-actions">
                                    <a href="<?php echo e(appUrl('/controlador_form.php?id=' . $controlador['id'])); ?>" class="btn btn-small btn-light">
                                        Editar
                                    </a>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </main>
    <script src="<?php echo e(appUrl('/assets/js/app-shell.js')); ?>"></script>
    <script src="<?php echo e(appUrl('/assets/js/upload-logo.js')); ?>"></script>
    <script src="<?php echo e(appUrl('/assets/js/smart-filter.js')); ?>"></script>
    <script>
        document.querySelectorAll('.tab-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                document.querySelectorAll('.tab-btn').forEach(function (b) { b.classList.remove('active'); });
                document.querySelectorAll('.tab-panel').forEach(function (p) { p.classList.remove('active'); });
                btn.classList.add('active');
                document.querySelector('.tab-panel[data-tab-panel="' + btn.dataset.tab + '"]').classList.add('active');
            });
        });

        document.addEventListener('DOMContentLoaded', function () {
            attachSmartFilter('filtroControladoresEmpresa', '.controlador-card', 'filtroContadorEmpresa');
        });
    </script>
</body>
</html>
