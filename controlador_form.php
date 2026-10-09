<?php
declare(strict_types=1);
/**
 * @arquivo       controlador_form.php
 * @versao        1.0.0
 * @modificado_em 2026-10-08
 * @objetivo      Cadastro/edição de controlador em tela própria (destino único de
 *                navegação a partir de controladores.php, empresas.php e usuarios.php).
 *                Aba "Dados Gerais" replica o form antigo + empresa_id obrigatório;
 *                aba "Inversores" é placeholder até o CRUD de controlador_inversores.
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

$usuarioPerfil = isset($_SESSION['usuario_perfil']) ? (string) $_SESSION['usuario_perfil'] : '';
$ehAdmin = in_array($usuarioPerfil, ['master', 'master_operador', 'administrador'], true);
if (!$ehAdmin) {
    header('Location: ' . APP_URL . '/dashboard.php');
    exit;
}

$appTituloPagina = 'Cadastro de Controlador';
$appPaginaAtual  = 'controladores';
$pdo = Database::getConnection();
$sucesso = '';
$erro = '';
$controladorEditando = null;

$controladorId = (int) ($_GET['id'] ?? $_POST['controlador_id'] ?? 0);
$modoEdicao = $controladorId > 0;
$empresaIdPrevia = (int) ($_GET['empresa_id'] ?? 0);

// Lista de empresas para o select (exclui integradoras — confirmado)
$empresas = [];
try {
    $stmtEmp = $pdo->query(
        "SELECT id, nome_fantasia, is_integradora, tipo
           FROM empresa
          WHERE ativo = 1
            AND deleted_at IS NULL
            AND is_integradora = 0
          ORDER BY nome_fantasia ASC"
    );
    $empresas = $stmtEmp->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $erro = 'Erro ao carregar lista de empresas.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = (string) ($_POST['csrf_token'] ?? '');

    if (!verifyCsrfToken($csrfToken)) {
        $erro = 'Token de segurança inválido. Recarregue a página.';
    } else {
        $codigo = trim((string) ($_POST['codigo'] ?? ''));
        $apelido = trim((string) ($_POST['apelido'] ?? ''));
        $localInstalacao = trim((string) ($_POST['local_instalacao'] ?? ''));
        $clienteNome = trim((string) ($_POST['cliente_nome'] ?? ''));
        $observacoes = trim((string) ($_POST['observacoes'] ?? ''));
        $status = (string) ($_POST['status'] ?? 'ativo');
        $modalidadeCompensacao = (string) ($_POST['modalidade_compensacao'] ?? 'GD_II_LEI_14300');
        $tarifaKwh = (float) ($_POST['tarifa_kwh'] ?? 0.9482);
        $fatorInjecao = (float) ($_POST['fator_injecao'] ?? 0.760);
        $empresaIdPost = (int) ($_POST['empresa_id'] ?? 0);

        if ($codigo === '' || $apelido === '') {
            $erro = 'Código e apelido são obrigatórios.';
        } elseif (!preg_match('/^[A-Z0-9\-]+$/', $codigo)) {
            $erro = 'Código deve conter apenas letras maiúsculas, números e hífens.';
        } elseif ($empresaIdPost <= 0) {
            // Regra confirmada: empresa_id obrigatório no form a partir de agora
            // (exceção histórica do controlador de teste Python não é afetada,
            // pois ele não passa por este formulário salvando empresa_id = NULL novamente
            // a menos que o usuário explicitamente deixe de selecionar).
            $erro = 'Selecione a empresa vinculada ao controlador.';
        } else {
            if (!in_array($status, ['ativo', 'inativo', 'manutencao', 'erro'], true)) {
                $status = 'ativo';
            }

            try {
                if (!$modoEdicao) {
                    $stmt = $pdo->prepare('SELECT id FROM controladores WHERE codigo = :codigo');
                    $stmt->bindParam(':codigo', $codigo);
                    $stmt->execute();

                    if ($stmt->fetch()) {
                        $erro = 'Código já está em uso por outro controlador.';
                    } else {
                        $apiToken = generateApiToken();
                        $tokenHash = password_hash($apiToken, PASSWORD_DEFAULT);

                        $stmt = $pdo->prepare('
                            INSERT INTO controladores
                            (codigo, apelido, local_instalacao, cliente_nome, observacoes, status,
                             token_api_hash, modalidade_compensacao, tarifa_kwh, fator_injecao, empresa_id)
                            VALUES
                            (:codigo, :apelido, :local_instalacao, :cliente_nome, :observacoes, :status,
                             :token_hash, :modalidade, :tarifa, :fator, :empresa_id)
                        ');
                        $stmt->bindParam(':codigo', $codigo);
                        $stmt->bindParam(':apelido', $apelido);
                        $stmt->bindParam(':local_instalacao', $localInstalacao);
                        $stmt->bindParam(':cliente_nome', $clienteNome);
                        $stmt->bindParam(':observacoes', $observacoes);
                        $stmt->bindParam(':status', $status);
                        $stmt->bindParam(':token_hash', $tokenHash);
                        $stmt->bindParam(':modalidade', $modalidadeCompensacao);
                        $stmt->bindParam(':tarifa', $tarifaKwh);
                        $stmt->bindParam(':fator', $fatorInjecao);
                        $stmt->bindParam(':empresa_id', $empresaIdPost, PDO::PARAM_INT);
                        $stmt->execute();

                        $novoId = (int) $pdo->lastInsertId();
                        $sucesso = "Controlador criado com sucesso! Token API: <code>$apiToken</code> (anote-o, não será exibido novamente)";

                        // Recarrega como edição para manter o usuário na mesma tela com dados atualizados
                        $controladorId = $novoId;
                        $modoEdicao = true;
                    }
                } else {
                    $stmt = $pdo->prepare('SELECT id FROM controladores WHERE codigo = :codigo AND id != :id');
                    $stmt->bindParam(':codigo', $codigo);
                    $stmt->bindParam(':id', $controladorId, PDO::PARAM_INT);
                    $stmt->execute();

                    if ($stmt->fetch()) {
                        $erro = 'Código já está em uso por outro controlador.';
                    } else {
                        $stmt = $pdo->prepare('
                            UPDATE controladores
                            SET codigo = :codigo,
                                apelido = :apelido,
                                local_instalacao = :local_instalacao,
                                cliente_nome = :cliente_nome,
                                observacoes = :observacoes,
                                status = :status,
                                modalidade_compensacao = :modalidade,
                                tarifa_kwh = :tarifa,
                                fator_injecao = :fator,
                                empresa_id = :empresa_id,
                                updated_at = CURRENT_TIMESTAMP
                            WHERE id = :id
                        ');
                        $stmt->bindParam(':codigo', $codigo);
                        $stmt->bindParam(':apelido', $apelido);
                        $stmt->bindParam(':local_instalacao', $localInstalacao);
                        $stmt->bindParam(':cliente_nome', $clienteNome);
                        $stmt->bindParam(':observacoes', $observacoes);
                        $stmt->bindParam(':status', $status);
                        $stmt->bindParam(':modalidade', $modalidadeCompensacao);
                        $stmt->bindParam(':tarifa', $tarifaKwh);
                        $stmt->bindParam(':fator', $fatorInjecao);
                        $stmt->bindParam(':empresa_id', $empresaIdPost, PDO::PARAM_INT);
                        $stmt->bindParam(':id', $controladorId, PDO::PARAM_INT);
                        $stmt->execute();

                        $sucesso = 'Controlador atualizado com sucesso!';
                    }
                }
            } catch (Throwable $e) {
                $erro = 'Erro ao processar operação: ' . $e->getMessage();
            }
        }
    }
}

// Carrega dados do controlador em edição (GET inicial ou após criação via POST acima)
if ($modoEdicao && $controladorId > 0 && !$erro) {
    try {
        $stmt = $pdo->prepare('SELECT * FROM controladores WHERE id = :id');
        $stmt->bindParam(':id', $controladorId, PDO::PARAM_INT);
        $stmt->execute();
        $controladorEditando = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$controladorEditando) {
            $erro = 'Controlador não encontrado.';
            $modoEdicao = false;
        }
    } catch (Throwable $e) {
        $erro = 'Erro ao carregar controlador.';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR" data-tema="escuro">
<head>
    <?php require __DIR__ . '/includes/app_head.php'; ?>
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
            <div class="panel-header">
                <h2><?php echo $modoEdicao ? 'Editar controlador' : 'Novo controlador'; ?></h2>
            </div>

            <div class="tabs">
                <button type="button" class="tab-btn active" data-tab="dados">Dados Gerais</button>
                <button type="button" class="tab-btn" data-tab="inversores" <?php echo !$modoEdicao ? 'disabled title="Salve o controlador primeiro"' : ''; ?>>Inversores</button>
            </div>

            <div class="tab-panel active" data-tab-panel="dados">
                <form method="POST" class="form-controlador">
                    <input type="hidden" name="csrf_token" value="<?php echo e(generateCsrfToken()); ?>">
                    <?php if ($modoEdicao): ?>
                        <input type="hidden" name="controlador_id" value="<?php echo e($controladorId); ?>">
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

                        <div class="form-group form-group-full">
                            <label for="empresa_id" class="form-label required">Empresa</label>
                            <select id="empresa_id" name="empresa_id" class="form-select" required>
                                <option value="">Selecione a empresa...</option>
                                <?php foreach ($empresas as $emp): ?>
                                    <?php
                                        $empIdNum = (int) $emp['id'];
                                        $isSelected = false;
                                        if ($controladorEditando) {
                                            $isSelected = ((int) ($controladorEditando['empresa_id'] ?? 0) === $empIdNum);
                                        } elseif ($empresaIdPrevia > 0) {
                                            $isSelected = ($empresaIdPrevia === $empIdNum);
                                        }
                                    ?>
                                    <option value="<?php echo e($emp['id']); ?>" <?php echo $isSelected ? 'selected' : ''; ?>>
                                        <?php echo e($emp['nome_fantasia']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($controladorEditando && $controladorEditando['empresa_id'] === null): ?>
                                <small class="text-muted" style="font-size: 0.75rem;">
                                    Este controlador não possui empresa vinculada (exceção histórica). Selecione uma empresa para salvar.
                                </small>
                            <?php endif; ?>
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
                            <hr style="margin: 10px 0; border: none; border-top: 1px solid var(--border-color);">
                            <h3 style="margin-bottom: 10px; font-size: 1rem; color: var(--text-color);">Configurações de Tarifa e Compensação</h3>
                        </div>
                        <div class="form-group form-group-full">
                            <label for="modalidade_compensacao" class="form-label">Modalidade de Compensação</label>
                            <select id="modalidade_compensacao" name="modalidade_compensacao" class="form-select">
                                <option value="GD_I_DIREITO_ADQUIRIDO" <?php echo ($controladorEditando && ($controladorEditando['modalidade_compensacao'] ?? '') === 'GD_I_DIREITO_ADQUIRIDO') ? 'selected' : ''; ?>>GD I (Direito Adquirido - Isento de Fio B)</option>
                                <option value="GD_II_LEI_14300" <?php echo ($controladorEditando && ($controladorEditando['modalidade_compensacao'] ?? 'GD_II_LEI_14300') === 'GD_II_LEI_14300') ? 'selected' : ''; ?>>GD II (Lei 14.300 - Paga pedágio Fio B)</option>
                                <option value="MERCADO_LIVRE" <?php echo ($controladorEditando && ($controladorEditando['modalidade_compensacao'] ?? '') === 'MERCADO_LIVRE') ? 'selected' : ''; ?>>Mercado Livre</option>
                                <option value="OUTRO" <?php echo ($controladorEditando && ($controladorEditando['modalidade_compensacao'] ?? '') === 'OUTRO') ? 'selected' : ''; ?>>Outro</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="tarifa_kwh" class="form-label required">Tarifa de Consumo (R$/kWh)</label>
                            <input
                                type="number"
                                step="0.0001"
                                min="0"
                                id="tarifa_kwh"
                                name="tarifa_kwh"
                                value="<?php echo $controladorEditando ? e($controladorEditando['tarifa_kwh'] ?? '0.9482') : '0.9482'; ?>"
                                class="form-input"
                                required
                            >
                            <small class="text-muted" style="font-size: 0.75rem;">Soma da TUSD + TE (com impostos).</small>
                        </div>
                        <div class="form-group">
                            <label for="fator_injecao" class="form-label required">Fator de Injeção</label>
                            <input
                                type="number"
                                step="0.001"
                                min="0"
                                max="1"
                                id="fator_injecao"
                                name="fator_injecao"
                                value="<?php echo $controladorEditando ? e($controladorEditando['fator_injecao'] ?? '0.760') : '0.760'; ?>"
                                class="form-input"
                                required
                            >
                            <small class="text-muted" style="font-size: 0.75rem;">Ex: 0.76 para Lei 14.300 ou 0.89 para GD I SP.</small>
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
                        <a href="<?php echo e(appUrl('/controladores.php')); ?>" class="btn btn-light">Voltar para lista</a>
                        <button type="submit" class="btn btn-primary">
                            <?php echo $modoEdicao ? 'Atualizar controlador' : 'Criar controlador'; ?>
                        </button>
                    </div>
                </form>
            </div>

            <div class="tab-panel" data-tab-panel="inversores">
                <div class="empty-state">
                    <p>CRUD de inversores (<code>controlador_inversores</code>) ainda não implementado nesta aba.</p>
                    <p class="text-muted">Será habilitado após a unificação do vínculo inversor↔controlador (RDC CIP-DEC-20261006-004).</p>
                </div>
            </div>
        </section>
    </div>

    <script>
        document.querySelectorAll('.tab-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (btn.hasAttribute('disabled')) return;
                document.querySelectorAll('.tab-btn').forEach(function (b) { b.classList.remove('active'); });
                document.querySelectorAll('.tab-panel').forEach(function (p) { p.classList.remove('active'); });
                btn.classList.add('active');
                document.querySelector('.tab-panel[data-tab-panel="' + btn.dataset.tab + '"]').classList.add('active');
            });
        });
    </script>
</body>
</html>
