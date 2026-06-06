<?php
declare(strict_types=1);

/**
 * Arquivo: controladores.php
 * Projeto: Controlador de Injeção de Potência Elétrica
 * Objetivo: Gestão dos dispositivos ESP32-S3 de campo (cadastro, status, tokens de API)
 *
 * Dependências de hardware:
 * - Servidor web compatível com PHP
 * - Estação cliente com navegador moderno
 * - Banco de dados MySQL/MariaDB acessível
 *
 * Dependências de software/arquivos instalados:
 * - PHP 8.3+
 * - PDO MySQL
 * - Sessão PHP habilitada
 * - config/app.php
 * - config/database.php
 * - app/auth.php
 * - app/services/Database.php
 * - assets/css/app.css
 * - assets/css/controladores.css
 * - tabela `controladores` criada no banco
 *
 * Histórico de implementações:
 * - 2026-03-26 14:49:28: criação do módulo de gestão de controladores ESP32-S3
 * - 2026-03-26 14:49:28: implementação de CRUD com geração segura de tokens API
 * - 2026-03-26 14:49:28: interface preparada para status e último contato (telemetria fase futura)
 */

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/services/Database.php';

use App\Services\Database;

startSecureSession();
requireAuth();

function e($valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
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

function generateApiToken(): string
{
    return 'CIP_' . bin2hex(random_bytes(32));
}

// Verificar se é admin
$usuarioPerfil = isset($_SESSION['usuario_perfil']) ? (string) $_SESSION['usuario_perfil'] : '';
if ($usuarioPerfil !== 'admin') {
    header('Location: ' . APP_URL . '/dashboard.php');
    exit;
}

$pdo = Database::getConnection();

$sucesso = '';
$erro = '';
$controladorEditando = null;

// Processar ações
$acao = (string) ($_GET['acao'] ?? $_POST['acao'] ?? '');
$controladorId = (int) ($_GET['id'] ?? $_POST['controlador_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = (string) ($_POST['csrf_token'] ?? '');
    
    if (!verifyCsrfToken($csrfToken)) {
        $erro = 'Token de segurança inválido. Recarregue a página.';
    } else {
        try {
            switch ($acao) {
                case 'criar':
                case 'editar':
                    $codigo = trim((string) ($_POST['codigo'] ?? ''));
                    $apelido = trim((string) ($_POST['apelido'] ?? ''));
                    $localInstalacao = trim((string) ($_POST['local_instalacao'] ?? ''));
                    $clienteNome = trim((string) ($_POST['cliente_nome'] ?? ''));
                    $observacoes = trim((string) ($_POST['observacoes'] ?? ''));
                    $status = (string) ($_POST['status'] ?? 'ativo');

                    // Validação básica
                    if ($codigo === '' || $apelido === '') {
                        $erro = 'Código e apelido são obrigatórios.';
                        break;
                    }

                    if (!preg_match('/^[A-Z0-9\-]+$/', $codigo)) {
                        $erro = 'Código deve conter apenas letras maiúsculas, números e hífens.';
                        break;
                    }

                    if (!in_array($status, ['ativo', 'inativo', 'manutencao', 'erro'], true)) {
                        $status = 'ativo';
                    }

                    if ($acao === 'criar') {
                        // Verificar se código já existe
                        $stmt = $pdo->prepare('SELECT id FROM controladores WHERE codigo = :codigo');
                        $stmt->bindParam(':codigo', $codigo);
                        $stmt->execute();
                        
                        if ($stmt->fetch()) {
                            $erro = 'Código já está em uso por outro controlador.';
                            break;
                        }

                        // Gerar token
                        $apiToken = generateApiToken();
                        $tokenHash = password_hash($apiToken, PASSWORD_DEFAULT);

                        // Inserir novo controlador
                        $stmt = $pdo->prepare('
                            INSERT INTO controladores 
                            (codigo, apelido, local_instalacao, cliente_nome, observacoes, status, token_api_hash) 
                            VALUES 
                            (:codigo, :apelido, :local_instalacao, :cliente_nome, :observacoes, :status, :token_hash)
                        ');
                        
                        $stmt->bindParam(':codigo', $codigo);
                        $stmt->bindParam(':apelido', $apelido);
                        $stmt->bindParam(':local_instalacao', $localInstalacao);
                        $stmt->bindParam(':cliente_nome', $clienteNome);
                        $stmt->bindParam(':observacoes', $observacoes);
                        $stmt->bindParam(':status', $status);
                        $stmt->bindParam(':token_hash', $tokenHash);
                        
                        $stmt->execute();
                        
                        $sucesso = "Controlador criado com sucesso! Token API: <code>$apiToken</code> (anote-o, não será exibido novamente)";
                    } else {
                        // Editar controlador existente
                        if ($controladorId <= 0) {
                            $erro = 'ID do controlador inválido.';
                            break;
                        }

                        // Verificar se código já existe em outro controlador
                        $stmt = $pdo->prepare('SELECT id FROM controladores WHERE codigo = :codigo AND id != :id');
                        $stmt->bindParam(':codigo', $codigo);
                        $stmt->bindParam(':id', $controladorId, PDO::PARAM_INT);
                        $stmt->execute();
                        
                        if ($stmt->fetch()) {
                            $erro = 'Código já está em uso por outro controlador.';
                            break;
                        }

                        $stmt = $pdo->prepare('
                            UPDATE controladores 
                            SET codigo = :codigo,
                                apelido = :apelido,
                                local_instalacao = :local_instalacao,
                                cliente_nome = :cliente_nome,
                                observacoes = :observacoes,
                                status = :status,
                                updated_at = CURRENT_TIMESTAMP
                            WHERE id = :id
                        ');
                        
                        $stmt->bindParam(':codigo', $codigo);
                        $stmt->bindParam(':apelido', $apelido);
                        $stmt->bindParam(':local_instalacao', $localInstalacao);
                        $stmt->bindParam(':cliente_nome', $clienteNome);
                        $stmt->bindParam(':observacoes', $observacoes);
                        $stmt->bindParam(':status', $status);
                        $stmt->bindParam(':id', $controladorId, PDO::PARAM_INT);
                        
                        $stmt->execute();
                        
                        $sucesso = 'Controlador atualizado com sucesso!';
                    }
                    break;

                case 'gerar_token':
                    if ($controladorId <= 0) {
                        $erro = 'ID do controlador inválido.';
                        break;
                    }

                    $novoToken = generateApiToken();
                    $novoTokenHash = password_hash($novoToken, PASSWORD_DEFAULT);

                    $stmt = $pdo->prepare('
                        UPDATE controladores 
                        SET token_api_hash = :token_hash, updated_at = CURRENT_TIMESTAMP 
                        WHERE id = :id
                    ');
                    $stmt->bindParam(':token_hash', $novoTokenHash);
                    $stmt->bindParam(':id', $controladorId, PDO::PARAM_INT);
                    $stmt->execute();

                    $sucesso = "Novo token gerado com sucesso! Token API: <code>$novoToken</code> (anote-o, não será exibido novamente)";
                    break;

                case 'excluir':
                    if ($controladorId <= 0) {
                        $erro = 'ID do controlador inválido.';
                        break;
                    }

                    $stmt = $pdo->prepare('DELETE FROM controladores WHERE id = :id');
                    $stmt->bindParam(':id', $controladorId, PDO::PARAM_INT);
                    $stmt->execute();

                    $sucesso = 'Controlador excluído com sucesso!';
                    break;
            }
        } catch (Throwable $e) {
            $erro = 'Erro ao processar operação: ' . $e->getMessage();
        }
    }
}

// Buscar controlador para edição
if ($acao === 'editar' && $controladorId > 0 && !$erro) {
    try {
        $stmt = $pdo->prepare('SELECT * FROM controladores WHERE id = :id');
        $stmt->bindParam(':id', $controladorId, PDO::PARAM_INT);
        $stmt->execute();
        $controladorEditando = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$controladorEditando) {
            $erro = 'Controlador não encontrado.';
        }
    } catch (Throwable $e) {
        $erro = 'Erro ao carregar controlador.';
    }
}

// Buscar todos os controladores
$controladores = [];
try {
    $stmt = $pdo->query('
        SELECT 
            id,
            codigo,
            apelido,
            local_instalacao,
            cliente_nome,
            status,
            fw_version,
            last_seen_at,
            last_telemetry_at,
            created_at,
            updated_at
        FROM controladores 
        ORDER BY created_at DESC
    ');
    $controladores = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    if (!$erro) {
        $erro = 'Erro ao carregar lista de controladores.';
    }
}

function appUrl(string $path = ''): string
{
    return APP_URL . APP_BASE_PATH . $path;
}

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

function formatarDataHora(?string $dataHora): string
{
    if (!$dataHora) return 'Nunca';
    
    try {
        return date('d/m/Y H:i', strtotime($dataHora));
    } catch (Throwable $e) {
        return 'Data inválida';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Controladores</title>
    <link rel="stylesheet" href="<?php echo e(appUrl('/assets/css/app.css')); ?>">
    <link rel="stylesheet" href="<?php echo e(appUrl('/assets/css/controladores.css')); ?>">
</head>
<body>
    <header class="topbar">
        <div class="topbar-inner">
            <div class="topbar-left">
                <h1>Controladores ESP32-S3</h1>
                <p class="subtitle">Gestão dos dispositivos de campo</p>
            </div>
            
            <div class="topbar-right">
                <a href="<?php echo e(appUrl('/dashboard.php')); ?>" class="btn btn-light">Voltar ao Dashboard</a>
                <a href="<?php echo e(appUrl('/logout.php')); ?>" class="btn btn-danger">Sair</a>
            </div>
        </div>
    </header>

    <main class="container controladores-container">
        <?php if ($sucesso): ?>
            <div class="alert alert-success" role="alert">
                <?php echo $sucesso; ?>
            </div>
        <?php endif; ?>

        <?php if ($erro): ?>
            <div class="alert alert-error" role="alert">
                <?php echo e($erro); ?>
            </div>
        <?php endif; ?>

        <div class="controladores-layout">
            <section class="panel">
                <div class="panel-header">
                    <h2><?php echo $controladorEditando ? 'Editar controlador' : 'Novo controlador'; ?></h2>
                </div>

                <form method="POST" class="form-controlador">
                    <input type="hidden" name="csrf_token" value="<?php echo e(generateCsrfToken()); ?>">
                    <input type="hidden" name="acao" value="<?php echo $controladorEditando ? 'editar' : 'criar'; ?>">
                    <?php if ($controladorEditando): ?>
                        <input type="hidden" name="controlador_id" value="<?php echo e($controladorEditando['id']); ?>">
                    <?php endif; ?>

                    <div class="form-grid">
                        <div class="form-group">
                            <label for="codigo" class="form-label required">Código</label>
                            <input 
                                type="text" 
                                id="codigo" 
                                name="codigo" 
                                value="<?php echo $controladorEditando ? e($controladorEditando['codigo']) : ''; ?>" 
                                class="form-input"
                                required
                                maxlength="20"
                                placeholder="AES-XXX-0000"
                                pattern="[A-Z0-9\-]+"
                                title="Use apenas letras maiúsculas, números e hífens"
                            >
                        </div>

                        <div class="form-group">
                            <label for="apelido" class="form-label required">Apelido</label>
                            <input 
                                type="text" 
                                id="apelido" 
                                name="apelido" 
                                value="<?php echo $controladorEditando ? e($controladorEditando['apelido']) : ''; ?>" 
                                class="form-input"
                                required
                                maxlength="100"
                                placeholder="Nome amigável do controlador"
                            >
                        </div>

                        <div class="form-group">
                            <label for="local_instalacao" class="form-label">Local de instalação</label>
                            <input 
                                type="text" 
                                id="local_instalacao" 
                                name="local_instalacao" 
                                value="<?php echo $controladorEditando ? e($controladorEditando['local_instalacao'] ?? '') : ''; ?>" 
                                class="form-input"
                                maxlength="200"
                                placeholder="Endereço ou descrição do local"
                            >
                        </div>

                        <div class="form-group">
                            <label for="cliente_nome" class="form-label">Cliente</label>
                            <input 
                                type="text" 
                                id="cliente_nome" 
                                name="cliente_nome" 
                                value="<?php echo $controladorEditando ? e($controladorEditando['cliente_nome'] ?? '') : ''; ?>" 
                                class="form-input"
                                maxlength="150"
                                placeholder="Nome do cliente proprietário"
                            >
                        </div>

                        <div class="form-group">
                            <label for="status" class="form-label">Status</label>
                            <select id="status" name="status" class="form-select">
                                <option value="ativo" <?php echo ($controladorEditando && ($controladorEditando['status'] ?? 'ativo') === 'ativo') ? 'selected' : ''; ?>>Ativo</option>
                                <option value="inativo" <?php echo ($controladorEditando && ($controladorEditando['status'] ?? '') === 'inativo') ? 'selected' : ''; ?>>Inativo</option>
                                <option value="manutencao" <?php echo ($controladorEditando && ($controladorEditando['status'] ?? '') === 'manutencao') ? 'selected' : ''; ?>>Manutenção</option>
                                <option value="erro" <?php echo ($controladorEditando && ($controladorEditando['status'] ?? '') === 'erro') ? 'selected' : ''; ?>>Erro</option>
                            </select>
                        </div>

                        <div class="form-group form-group-full">
                            <label for="observacoes" class="form-label">Observações</label>
                            <textarea 
                                id="observacoes" 
                                name="observacoes" 
                                class="form-textarea"
                                rows="3"
                                placeholder="Notas adicionais sobre o controlador"
                            ><?php echo $controladorEditando ? e($controladorEditando['observacoes'] ?? '') : ''; ?></textarea>
                        </div>
                    </div>

                    <div class="form-actions">
                        <?php if ($controladorEditando): ?>
                            <a href="<?php echo e(appUrl('/controladores.php')); ?>" class="btn btn-light">Cancelar</a>
                            <button type="submit" class="btn btn-primary">Atualizar controlador</button>
                        <?php else: ?>
                            <button type="submit" class="btn btn-primary">Criar controlador</button>
                        <?php endif; ?>
                    </div>
                </form>
            </section>

            <section class="panel">
                <div class="panel-header">
                    <h2>Lista de controladores</h2>
                    <p class="panel-description">
                        <?php echo count($controladores); ?> controlador<?php echo count($controladores) !== 1 ? 'es' : ''; ?> cadastrado<?php echo count($controladores) !== 1 ? 's' : ''; ?>
                    </p>
                </div>

                <?php if (empty($controladores)): ?>
                    <div class="empty-state">
                        <p>Nenhum controlador cadastrado ainda.</p>
                        <p class="text-muted">Crie o primeiro controlador usando o formulário ao lado.</p>
                    </div>
                <?php else: ?>
                    <div class="controladores-list">
                        <?php foreach ($controladores as $controlador): ?>
                            <?php $statusInfo = formatarStatus($controlador['status']); ?>
                            <article class="controlador-card">
                                <div class="controlador-header">
                                    <div class="controlador-info">
                                        <h3><?php echo e($controlador['apelido']); ?></h3>
                                        <p class="controlador-codigo"><?php echo e($controlador['codigo']); ?></p>
                                        <span class="status-badge <?php echo e($statusInfo['classe']); ?>">
                                            <?php echo e($statusInfo['texto']); ?>
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
                                        <span><strong>Firmware:</strong> <?php echo e($controlador['fw_version'] ?? 'Não informado'); ?></span>
                                        <span><strong>Último contato:</strong> <?php echo formatarDataHora($controlador['last_seen_at']); ?></span>
                                        <span><strong>Cadastrado:</strong> <?php echo formatarDataHora($controlador['created_at']); ?></span>
                                    </div>
                                </div>

                                <div class="controlador-actions">
                                    <a href="<?php echo e(appUrl('/controladores.php?acao=editar&id=' . $controlador['id'])); ?>" class="btn btn-small btn-light">Editar</a>
                                    
                                    <form method="POST" style="display: inline-block;" onsubmit="return confirm('Gerar novo token invalidará o token atual. Continuar?')">
                                        <input type="hidden" name="csrf_token" value="<?php echo e(generateCsrfToken()); ?>">
                                        <input type="hidden" name="acao" value="gerar_token">
                                        <input type="hidden" name="controlador_id" value="<?php echo e($controlador['id']); ?>">
                                        <button type="submit" class="btn btn-small btn-warning">Novo token</button>
                                    </form>

                                    <form method="POST" style="display: inline-block;" onsubmit="return confirm('Excluir permanentemente o controlador? Esta ação não pode ser desfeita.')">
                                        <input type="hidden" name="csrf_token" value="<?php echo e(generateCsrfToken()); ?>">
                                        <input type="hidden" name="acao" value="excluir">
                                        <input type="hidden" name="controlador_id" value="<?php echo e($controlador['id']); ?>">
                                        <button type="submit" class="btn btn-small btn-danger">Excluir</button>
                                    </form>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </main>
</body>
</html>
