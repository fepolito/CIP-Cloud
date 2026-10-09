<?php
declare(strict_types=1);
/**
 * @arquivo       controladores.php
 * @versao        2.0.0
 * @modificado_em 2026-10-08
 * @objetivo      Lista global de controladores (sem filtro por empresa). Cadastro/edição
 *                foi migrado para controlador_form.php; aqui restam apenas ações rápidas
 *                de lista (gerar token, excluir) e navegação para o form.
 * @autor         Fernando / CIP Cloud Copilot / ATGY
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

if (!function_exists('generateApiToken')) {
    function generateApiToken(): string
    {
        return 'CIP_' . bin2hex(random_bytes(32));
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
$ehAdmin = in_array($usuarioPerfil, ['master', 'master_operador', 'administrador'], true);
if (!$ehAdmin) {
    header('Location: ' . APP_URL . '/dashboard.php');
    exit;
}

$appTituloPagina = 'Controladores ESP32-S3';
$appPaginaAtual  = 'controladores';
$pdo = Database::getConnection();
$sucesso = '';
$erro = '';

// Ações permitidas nesta tela: apenas gerar_token e excluir
// (criar/editar foram migrados para controlador_form.php)
$acao = (string) ($_POST['acao'] ?? '');
$controladorId = (int) ($_POST['controlador_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($acao, ['gerar_token', 'excluir'], true)) {
    $csrfToken = (string) ($_POST['csrf_token'] ?? '');

    if (!verifyCsrfToken($csrfToken)) {
        $erro = 'Token de segurança inválido. Recarregue a página.';
    } else {
        try {
            if ($controladorId <= 0) {
                $erro = 'ID do controlador inválido.';
            } elseif ($acao === 'gerar_token') {
                $novoToken = generateApiToken();
                $novoTokenHash = password_hash($novoToken, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare(
                    'UPDATE controladores
                        SET token_api_hash = :token_hash, updated_at = CURRENT_TIMESTAMP
                      WHERE id = :id'
                );
                $stmt->bindParam(':token_hash', $novoTokenHash);
                $stmt->bindParam(':id', $controladorId, PDO::PARAM_INT);
                $stmt->execute();
                $sucesso = "Novo token gerado com sucesso! Token API: <code>$novoToken</code> (anote-o, não será exibido novamente)";
            } elseif ($acao === 'excluir') {
                $stmt = $pdo->prepare('DELETE FROM controladores WHERE id = :id');
                $stmt->bindParam(':id', $controladorId, PDO::PARAM_INT);
                $stmt->execute();
                $sucesso = 'Controlador excluído com sucesso!';
            }
        } catch (Throwable $e) {
            $erro = 'Erro ao processar operação: ' . $e->getMessage();
        }
    }
}

// Lista global — sem filtro por empresa (confirmado)
$controladores = [];
try {
    $stmt = $pdo->query(
        "SELECT
            c.id, c.codigo, c.apelido, c.local_instalacao, c.cliente_nome, c.status,
            c.fw_version, c.last_seen_at, c.last_telemetry_at,
            c.modalidade_compensacao, c.tarifa_kwh, c.fator_injecao,
            c.created_at, c.updated_at,
            c.empresa_id, emp.nome_fantasia
         FROM controladores c
         LEFT JOIN empresa emp ON emp.id = c.empresa_id
         ORDER BY c.created_at DESC"
    );
    $controladores = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    if (!$erro) {
        $erro = 'Erro ao carregar lista de controladores.';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR" data-tema="escuro">
<head>
    <?php require __DIR__ . '/includes/app_head.php'; ?>
    <link rel="stylesheet" href="<?php echo e(appUrl('/assets/css/controladores.css')); ?>">
</head>
<body>
    <div id="loading">
        <div class="spin"></div>
        <p>VERIFICANDO SESSÃO...</p>
    </div>
    <?php require __DIR__ . '/includes/app_header.php'; ?>
    <div class="wrap controladores-container">
        <?php if ($sucesso): ?>
            <div class="alert alert-success" role="alert"><?php echo $sucesso; ?></div>
        <?php endif; ?>
        <?php if ($erro): ?>
            <div class="alert alert-error" role="alert"><?php echo e($erro); ?></div>
        <?php endif; ?>

        <section class="panel">
            <div class="panel-header" style="display:flex; justify-content:space-between; align-items:center;">
                <div>
                    <h2>Lista de controladores</h2>
                    <p class="panel-description">
                        <?php echo count($controladores); ?> controlador<?php echo count($controladores) !== 1 ? 'es' : ''; ?> cadastrado<?php echo count($controladores) !== 1 ? 's' : ''; ?>
                    </p>
                </div>
                <a href="<?php echo e(appUrl('/controlador_form.php')); ?>" class="btn btn-primary">+ Novo controlador</a>
            </div>

            <?php if (empty($controladores)): ?>
                <div class="empty-state">
                    <p>Nenhum controlador cadastrado ainda.</p>
                    <p class="text-muted">Crie o primeiro controlador no botão acima.</p>
                </div>
            <?php else: ?>
                <div class="filtro-busca" style="margin-bottom: 16px;">
                    <input
                        type="text"
                        id="filtroInstalacoes"
                        class="form-input"
                        placeholder="🔍 Buscar por apelido, código, cliente, local ou empresa..."
                        autocomplete="off"
                        style="width: 100%; max-width: 480px;"
                    >
                    <p class="text-muted" id="filtroContador" style="font-size: 0.8rem; margin-top: 4px;"></p>
                </div>

                <div class="controladores-list">
                    <?php foreach ($controladores as $controlador): ?>
                        <?php $statusInfo = formatarStatus($controlador['status']); ?>
                        <article class="controlador-card"
                            data-busca="<?php echo e(mb_strtolower(
                                $controlador['apelido'] . ' ' .
                                $controlador['codigo'] . ' ' .
                                ($controlador['cliente_nome'] ?? '') . ' ' .
                                ($controlador['local_instalacao'] ?? '') . ' ' .
                                ($controlador['nome_fantasia'] ?? '')
                            )); ?>">
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
                                <p><strong>Empresa:</strong> <?php echo e($controlador['nome_fantasia'] ?? '— Sem empresa vinculada —'); ?></p>
                                <?php if (!empty($controlador['local_instalacao'])): ?>
                                    <p><strong>Local:</strong> <?php echo e($controlador['local_instalacao']); ?></p>
                                <?php endif; ?>
                                <?php if (!empty($controlador['cliente_nome'])): ?>
                                    <p><strong>Cliente:</strong> <?php echo e($controlador['cliente_nome']); ?></p>
                                <?php endif; ?>
                                <div class="controlador-meta">
                                    <span><strong>Firmware:</strong> <?php echo e($controlador['fw_version'] ?? 'Não informado'); ?></span>
                                    <span><strong>Último contato:</strong> <?php echo formatarDataHora($controlador['last_seen_at']); ?></span>
                                    <span><strong>Tarifa:</strong> R$ <?php echo number_format((float) ($controlador['tarifa_kwh'] ?? 0), 4, ',', '.'); ?> (Fator <?php echo number_format(((float) ($controlador['fator_injecao'] ?? 0)) * 100, 1, ',', '.'); ?>%)</span>
                                </div>
                            </div>
                            <div class="controlador-actions">
                                <a href="<?php echo e(appUrl('/controlador_form.php?id=' . $controlador['id'])); ?>" class="btn btn-small btn-light">Editar</a>

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
    <script src="<?php echo e(appUrl('/assets/js/smart-filter.js')); ?>"></script>
</body>
</html>
