<?php
declare(strict_types=1);
/**
 * @arquivo       usuario_form.php
 * @versao        1.0.0
 * @modificado_em 2026-10-09
 * @objetivo      Formulário único de criação/edição de usuário, com gestão de vínculos
 *                em usuario_empresa, reset de senha (senha fixa 123456 + forcar_troca_senha)
 *                e regras de edição restrita para o próprio usuário.
 * @autor         Fernando / CIP Cloud Copilot / ATGY
 */

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/config/database.php';

startSecureSession();
requireAuth();

if (!function_exists('e')) {
    function e($valor): string
    {
        return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('appUrl')) {
    function appUrl(string $path = ''): string
    {
        return APP_URL . APP_BASE_PATH . $path;
    }
}

$appTituloPagina = 'Formulário de Usuário';
$appPaginaAtual  = 'usuarios';

$usuarioLogado   = $_SESSION['usuario'] ?? [];
$perfilLogado    = (string) ($usuarioLogado['perfil'] ?? $_SESSION['usuario_perfil'] ?? '');
$empresaIdLogado = $usuarioLogado['empresa_id'] ?? $_SESSION['empresa_id'] ?? null;
$idLogado        = (int) ($usuarioLogado['id'] ?? $_SESSION['usuario_id'] ?? 0);

$escopoGlobal = in_array($perfilLogado, ['master', 'master_operador'], true);
$ehAdmin      = $perfilLogado === 'administrador';

// Apenas operadores com permissão, administradores e masters podem gerenciar usuários
if (!in_array($perfilLogado, ['master', 'master_operador', 'administrador'], true)) {
    http_response_code(403);
    echo '<p style="font-family:sans-serif;padding:20px;color:#d97f7f;">Acesso negado. Perfil sem permissão para criar ou editar usuários.</p>';
    exit;
}

$id           = isset($_GET['id']) ? (int) $_GET['id'] : null;
$empresaPre   = isset($_GET['empresa_id']) ? (int) $_GET['empresa_id'] : null;
$editandoSelf = ($id !== null && $id === $idLogado);

$usuario = null;
$vinculosAtuais = [];
$erro = null;
$pdo = getDbConnection();

if ($id) {
    $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE id = :id AND deleted_at IS NULL");
    $stmt->execute(['id' => $id]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$usuario) {
        http_response_code(404);
        exit('Usuário não encontrado.');
    }

    // Permissão: master/master_operador tudo; administrador só da própria empresa; próprio usuário sempre
    if (!$escopoGlobal && !$editandoSelf) {
        if (!$ehAdmin || (int) ($usuario['empresa_id'] ?? 0) !== (int) $empresaIdLogado) {
            http_response_code(403);
            exit('Sem permissão para editar este usuário.');
        }
    }

    $stmtV = $pdo->prepare("
        SELECT ue.empresa_id, ue.papel_empresa, e.nome_fantasia AS empresa_nome
        FROM usuario_empresa ue
        JOIN empresa e ON e.id = ue.empresa_id
        WHERE ue.usuario_id = :uid AND ue.ativo = 1 AND ue.deleted_at IS NULL
        ORDER BY e.nome_fantasia ASC
    ");
    $stmtV->execute(['uid' => $id]);
    $vinculosAtuais = $stmtV->fetchAll(PDO::FETCH_ASSOC);
} else {
    // Criação: administrador só cria dentro da própria empresa
    if (!$escopoGlobal && !$ehAdmin) {
        http_response_code(403);
        exit('Sem permissão para criar usuário.');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? 'salvar';

    try {
        if ($acao === 'resetar_senha' && $id) {
            if ($editandoSelf) {
                throw new InvalidArgumentException('Você não pode resetar a própria senha por este painel.');
            }

            $novaHash = password_hash('123456', PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("
                UPDATE usuarios
                SET senha_hash = :hash, forcar_troca_senha = 1, atualizado_em = NOW()
                WHERE id = :id
            ");
            $stmt->execute(['hash' => $novaHash, 'id' => $id]);
            header('Location: ' . appUrl('/usuario_form.php?id=' . $id . '&reset=1'));
            exit;
        }

        $nome          = trim((string) ($_POST['nome'] ?? ''));
        $email         = trim((string) ($_POST['email'] ?? ''));
        $perfil        = (string) ($_POST['perfil'] ?? 'usuario');
        $empresaIdForm = !empty($_POST['empresa_id']) ? (int) $_POST['empresa_id'] : null;
        $senha         = (string) ($_POST['senha'] ?? '');
        $ativo         = isset($_POST['ativo']) ? 1 : 0;

        // Regras de auto-edição restrita
        if ($editandoSelf && $id) {
            $nome          = (string) $usuario['nome'];
            $email         = (string) $usuario['email'];
            $perfil        = (string) $usuario['perfil'];
            $empresaIdForm = $usuario['empresa_id'] !== null ? (int) $usuario['empresa_id'] : null;
            $ativo         = (int) $usuario['ativo'];
        }

        if ($nome === '' || $email === '') {
            throw new InvalidArgumentException('Nome e e-mail são obrigatórios.');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Formato de e-mail inválido.');
        }

        if (!in_array($perfil, ['master', 'master_operador', 'administrador', 'operador', 'usuario'], true)) {
            throw new InvalidArgumentException('Perfil de acesso inválido.');
        }

        // Não-masters não podem atribuir papel master/master_operador
        if (!$escopoGlobal && in_array($perfil, ['master', 'master_operador'], true)) {
            throw new InvalidArgumentException('Permissão insuficiente para atribuir perfil global.');
        }

        if (!$escopoGlobal) {
            $empresaIdForm = (int) $empresaIdLogado;
        }

        $empresaGlobal = in_array($perfil, ['master', 'master_operador'], true);
        if (!$empresaGlobal && empty($empresaIdForm)) {
            throw new InvalidArgumentException('Empresa padrão é obrigatória para este perfil.');
        }

        // Validação de unicidade de e-mail
        $stmtChk = $pdo->prepare("SELECT id FROM usuarios WHERE email = :email AND id != :id AND deleted_at IS NULL LIMIT 1");
        $stmtChk->execute(['email' => $email, 'id' => (int) ($id ?? 0)]);
        if ($stmtChk->fetch()) {
            throw new InvalidArgumentException('Este e-mail já está cadastrado para outro usuário.');
        }

        $pdo->beginTransaction();

        if ($id) {
            $sqlUpd = "UPDATE usuarios SET nome = :nome, email = :email, perfil = :perfil,
                       empresa_id = :empresa_id, ativo = :ativo, atualizado_em = NOW()";
            $params = [
                'nome'       => $nome,
                'email'      => $email,
                'perfil'     => $perfil,
                'empresa_id' => $empresaGlobal ? null : $empresaIdForm,
                'ativo'      => $ativo,
                'id'         => $id,
            ];

            if ($senha !== '') {
                if (strlen($senha) < 6) {
                    throw new InvalidArgumentException('A nova senha deve ter no mínimo 6 caracteres.');
                }
                $sqlUpd .= ", senha_hash = :hash";
                $params['hash'] = password_hash($senha, PASSWORD_DEFAULT);
            }

            $sqlUpd .= " WHERE id = :id";
            $stmt = $pdo->prepare($sqlUpd);
            $stmt->execute($params);
        } else {
            if ($senha === '') {
                throw new InvalidArgumentException('Senha é obrigatória no cadastro inicial.');
            }
            if (strlen($senha) < 6) {
                throw new InvalidArgumentException('A senha deve ter no mínimo 6 caracteres.');
            }

            $stmtIns = $pdo->prepare("
                INSERT INTO usuarios (nome, email, senha_hash, perfil, empresa_id, ativo, criado_por, criado_em)
                VALUES (:nome, :email, :hash, :perfil, :empresa_id, :ativo, :criado_por, NOW())
            ");
            $stmtIns->execute([
                'nome'       => $nome,
                'email'      => $email,
                'hash'       => password_hash($senha, PASSWORD_DEFAULT),
                'perfil'     => $perfil,
                'empresa_id' => $empresaGlobal ? null : $empresaIdForm,
                'ativo'      => $ativo,
                'criado_por' => $idLogado,
            ]);
            $id = (int) $pdo->lastInsertId();
        }

        // Gestão de vínculos usuario_empresa
        $pdo->prepare("UPDATE usuario_empresa SET ativo = 0, deleted_at = NOW(), deleted_by = :by WHERE usuario_id = :uid AND deleted_at IS NULL")
            ->execute(['by' => $idLogado, 'uid' => $id]);

        if (!empty($_POST['vinculo_empresa_id']) && is_array($_POST['vinculo_empresa_id'])) {
            $stmtVincIns = $pdo->prepare("
                INSERT INTO usuario_empresa (usuario_id, empresa_id, papel_empresa, ativo, criado_em, criado_por)
                VALUES (:uid, :eid, :papel, 1, NOW(), :by)
            ");

            $inseridos = [];
            foreach ($_POST['vinculo_empresa_id'] as $i => $eidRaw) {
                $eid = (int) $eidRaw;
                if ($eid <= 0 || in_array($eid, $inseridos, true)) {
                    continue;
                }
                $papel = (string) ($_POST['vinculo_papel'][$i] ?? 'operador');
                if (!in_array($papel, ['administrador', 'operador', 'usuario'], true)) {
                    $papel = 'operador';
                }

                $stmtVincIns->execute([
                    'uid'   => $id,
                    'eid'   => $eid,
                    'papel' => $papel,
                    'by'    => $idLogado,
                ]);
                $inseridos[] = $eid;
            }
        }

        $pdo->commit();
        header('Location: ' . appUrl('/usuarios.php?salvo=1'));
        exit;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $erro = $e->getMessage();
        error_log('[usuario_form.php] Erro: ' . $e->getMessage());
    }
}

// Carregar empresas para os selects
if ($escopoGlobal) {
    $empresas = $pdo->query("SELECT id, nome_fantasia FROM empresa WHERE deleted_at IS NULL ORDER BY nome_fantasia ASC")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmtE = $pdo->prepare("SELECT id, nome_fantasia FROM empresa WHERE id = :eid AND deleted_at IS NULL");
    $stmtE->execute(['eid' => $empresaIdLogado]);
    $empresas = $stmtE->fetchAll(PDO::FETCH_ASSOC);
}

$empresaSelecionadaId = $usuario['empresa_id'] ?? $empresaPre;
?>
<!DOCTYPE html>
<html lang="pt-BR" data-tema="escuro">
<head>
    <?php require __DIR__ . '/includes/app_head.php'; ?>
    <style>
        .form-container {
            max-width: 900px;
            margin: 0 auto;
            padding: 16px;
        }
        .panel {
            background: var(--card-bg, #10233f);
            border-radius: 10px;
            border: 1px solid var(--border-color, #1a3a60);
            padding: 24px;
            margin-top: 16px;
        }
        .panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            border-bottom: 1px solid #1a3a60;
            padding-bottom: 16px;
        }
        .panel-title {
            margin: 0;
            font-size: 1.5rem;
            color: #fff;
        }
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        .form-group-full {
            grid-column: 1 / -1;
        }
        .form-label {
            display: block;
            margin-bottom: 6px;
            font-size: 13px;
            font-weight: 500;
            color: #8ba3c7;
        }
        .form-label.required::after {
            content: ' *';
            color: #ff6b6b;
        }
        .form-input, .form-select {
            width: 100%;
            box-sizing: border-box;
            padding: 10px 12px;
            border-radius: 6px;
            border: 1px solid #2a4365;
            background: #0d1526;
            color: #fff;
            font-size: 14px;
            outline: none;
            transition: border-color 0.2s;
        }
        .form-input:focus, .form-select:focus {
            border-color: #38bdf8;
        }
        .form-input[readonly], .form-select[disabled] {
            background: #162032;
            color: #718096;
            cursor: not-allowed;
        }
        .form-checkbox-label {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            color: #fff;
            font-size: 14px;
            user-select: none;
        }
        .section-divider {
            margin: 28px 0 16px 0;
            border-top: 1px solid #1a3a60;
            padding-top: 16px;
        }
        .section-title {
            font-size: 16px;
            font-weight: 600;
            color: #38bdf8;
            margin: 0 0 4px 0;
        }
        .section-desc {
            font-size: 12px;
            color: #8ba3c7;
            margin: 0 0 14px 0;
        }
        .vinculo-row {
            display: grid;
            grid-template-columns: 1.5fr 1fr auto;
            gap: 10px;
            align-items: center;
            margin-bottom: 10px;
            background: rgba(13, 21, 38, 0.4);
            padding: 8px;
            border-radius: 6px;
            border: 1px solid #1a2d4a;
        }
        .btn-remover-vinculo {
            background: #4a1e1e;
            color: #ff8888;
            border: 1px solid #6a2d2d;
            padding: 9px 12px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
        }
        .btn-remover-vinculo:hover {
            background: #6a2d2d;
            color: #fff;
        }
        .btn-add-vinculo {
            background: transparent;
            color: #38bdf8;
            border: 1px dashed #2a4365;
            padding: 8px 14px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.2s;
        }
        .btn-add-vinculo:hover {
            border-color: #38bdf8;
            background: rgba(56, 189, 248, 0.08);
        }
        .form-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 28px;
            padding-top: 16px;
            border-top: 1px solid #1a3a60;
            flex-wrap: wrap;
            gap: 12px;
        }
        .btn-salvar {
            background: #00d26a;
            color: #0b192c;
            border: none;
            padding: 10px 24px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            transition: background 0.2s;
        }
        .btn-salvar:hover {
            background: #00b85c;
        }
        .btn-cancelar {
            background: #1e3a5f;
            color: #cbd5e1;
            padding: 10px 20px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
        }
        .btn-cancelar:hover {
            background: #2b568d;
            color: #fff;
        }
        .btn-resetar {
            background: #ef4444;
            color: #fff;
            border: none;
            padding: 10px 18px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 13px;
            cursor: pointer;
            transition: background 0.2s;
        }
        .btn-resetar:hover {
            background: #dc2626;
        }
        .alert {
            padding: 12px 16px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .alert-error {
            background: rgba(74, 30, 30, 0.85);
            color: #ff8888;
            border: 1px solid #6a2d2d;
        }
        .alert-success {
            background: rgba(30, 70, 32, 0.85);
            color: #7fd98a;
            border: 1px solid #2d6a30;
        }
        @media (max-width: 650px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
            .vinculo-row {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div id="loading">
        <div class="spin"></div>
        <p>CARREGANDO FORMULÁRIO...</p>
    </div>
    <?php require __DIR__ . '/includes/app_header.php'; ?>

    <main class="form-container">
        <section class="panel">
            <div class="panel-header">
                <h1 class="panel-title"><?php echo $id ? 'Editar Usuário' : 'Novo Usuário'; ?></h1>
                <a href="<?php echo e(appUrl('/usuarios.php')); ?>" class="btn-cancelar">← Voltar para a lista</a>
            </div>

            <?php if ($erro): ?>
                <div class="alert alert-error"><?php echo e($erro); ?></div>
            <?php endif; ?>

            <?php if (isset($_GET['reset'])): ?>
                <div class="alert alert-success">
                    ✅ Senha resetada para <strong>123456</strong> com sucesso! O usuário será obrigado a criar uma nova senha assim que efetuar o login.
                </div>
            <?php endif; ?>

            <form method="POST" action="<?php echo e(appUrl('/usuario_form.php' . ($id ? '?id=' . $id : ''))); ?>">
                <input type="hidden" name="acao" value="salvar">

                <div class="form-grid">
                    <div>
                        <label class="form-label required" for="nome">Nome Completo</label>
                        <input type="text"
                               id="nome"
                               name="nome"
                               class="form-input"
                               value="<?php echo e($usuario['nome'] ?? ''); ?>"
                               <?php echo ($editandoSelf && $id) ? 'readonly' : ''; ?>
                               required>
                    </div>

                    <div>
                        <label class="form-label required" for="email">E-mail</label>
                        <input type="email"
                               id="email"
                               name="email"
                               class="form-input"
                               value="<?php echo e($usuario['email'] ?? ''); ?>"
                               <?php echo ($editandoSelf && $id) ? 'readonly' : ''; ?>
                               required>
                    </div>

                    <div>
                        <label class="form-label required" for="perfil">Perfil de Acesso</label>
                        <select id="perfil"
                                name="perfil"
                                class="form-select"
                                <?php echo ($editandoSelf && $id) ? 'disabled' : ''; ?>
                                onchange="atualizarRegraPerfil()">
                            <?php
                            $perfisPermitidos = $escopoGlobal
                                ? ['master', 'master_operador', 'administrador', 'operador', 'usuario']
                                : ['administrador', 'operador', 'usuario'];
                            $perfilAtual = $usuario['perfil'] ?? 'usuario';
                            foreach ($perfisPermitidos as $p):
                            ?>
                                <option value="<?php echo $p; ?>" <?php echo $perfilAtual === $p ? 'selected' : ''; ?>>
                                    <?php echo ucfirst($p); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="form-label" id="labelEmpresa" for="empresa_id">Empresa (Padrão)</label>
                        <select id="empresa_id"
                                name="empresa_id"
                                class="form-select"
                                <?php echo ($editandoSelf && $id) ? 'disabled' : ''; ?>>
                            <?php if ($escopoGlobal): ?>
                                <option value="">— Global (Apenas para Master) —</option>
                            <?php endif; ?>
                            <?php foreach ($empresas as $e): ?>
                                <option value="<?php echo (int) $e['id']; ?>"
                                    <?php echo ((int) $empresaSelecionadaId === (int) $e['id']) ? 'selected' : ''; ?>>
                                    <?php echo e($e['nome_fantasia']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="form-label <?php echo $id ? '' : 'required'; ?>" for="senha">
                            Senha <?php echo $id ? '<small style="color:#8ba3c7;">(deixe em branco para não alterar)</small>' : ''; ?>
                        </label>
                        <input type="password"
                               id="senha"
                               name="senha"
                               class="form-input"
                               minlength="6"
                               autocomplete="new-password"
                               <?php echo $id ? '' : 'required'; ?>>
                    </div>

                    <div style="display:flex;align-items:center;padding-top:24px;">
                        <label class="form-checkbox-label">
                            <input type="checkbox"
                                   name="ativo"
                                   value="1"
                                   <?php echo ($usuario['ativo'] ?? 1) ? 'checked' : ''; ?>
                                   <?php echo ($editandoSelf && $id) ? 'disabled' : ''; ?>>
                            Usuário Ativo no Sistema
                        </label>
                    </div>
                </div>

                <div class="section-divider">
                    <h2 class="section-title">Vínculos Adicionais (Multi-Empresa)</h2>
                    <p class="section-desc">Permite ao usuário visualizar ou operar empresas adicionais além da empresa padrão.</p>

                    <div id="containerVinculos">
                        <?php foreach ($vinculosAtuais as $v): ?>
                            <div class="vinculo-row">
                                <select name="vinculo_empresa_id[]" class="form-select">
                                    <?php foreach ($empresas as $emp): ?>
                                        <option value="<?php echo (int) $emp['id']; ?>"
                                            <?php echo ((int) $emp['id'] === (int) $v['empresa_id']) ? 'selected' : ''; ?>>
                                            <?php echo e($emp['nome_fantasia']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <select name="vinculo_papel[]" class="form-select">
                                    <?php foreach (['administrador', 'operador', 'usuario'] as $pap): ?>
                                        <option value="<?php echo $pap; ?>" <?php echo $pap === $v['papel_empresa'] ? 'selected' : ''; ?>>
                                            <?php echo ucfirst($pap); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="btn-remover-vinculo" onclick="this.closest('.vinculo-row').remove()">Excluir</button>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <button type="button" class="btn-add-vinculo" onclick="adicionarVinculo()">+ Adicionar Vínculo</button>
                </div>

                <div class="form-actions">
                    <div style="display:flex;gap:12px;align-items:center;">
                        <button type="submit" class="btn-salvar">Gravar Alterações</button>
                        <a href="<?php echo e(appUrl('/usuarios.php')); ?>" class="btn-cancelar">Cancelar</a>
                    </div>
                </div>
            </form>

            <?php if ($id && !$editandoSelf): ?>
                <div style="margin-top:28px;padding-top:20px;border-top:1px solid #2d1e1e;">
                    <form method="POST"
                          action="<?php echo e(appUrl('/usuario_form.php?id=' . $id)); ?>"
                          onsubmit="return confirm('ATENÇÃO: A senha do usuário será resetada para 123456 e ele será obrigado a definir uma nova senha no próximo acesso. Deseja continuar?');">
                        <input type="hidden" name="acao" value="resetar_senha">
                        <button type="submit" class="btn-resetar">⚠️ Resetar Senha para 123456 (Forçar Troca)</button>
                    </form>
                </div>
            <?php endif; ?>
        </section>
    </main>

    <script>
    const empresasDisponiveis = <?php echo json_encode($empresas, JSON_UNESCAPED_UNICODE); ?>;

    function adicionarVinculo() {
        const container = document.getElementById('containerVinculos');
        const div = document.createElement('div');
        div.className = 'vinculo-row';

        let optionsEmpresa = empresasDisponiveis.map(e =>
            `<option value="${e.id}">${e.nome_fantasia}</option>`
        ).join('');

        div.innerHTML = `
            <select name="vinculo_empresa_id[]" class="form-select">
                ${optionsEmpresa}
            </select>
            <select name="vinculo_papel[]" class="form-select">
                <option value="operador" selected>Operador</option>
                <option value="administrador">Administrador</option>
                <option value="usuario">Usuario</option>
            </select>
            <button type="button" class="btn-remover-vinculo" onclick="this.closest('.vinculo-row').remove()">Excluir</button>
        `;
        container.appendChild(div);
    }

    function atualizarRegraPerfil() {
        const perfilSelect = document.getElementById('perfil');
        const empresaSelect = document.getElementById('empresa_id');
        const labelEmpresa = document.getElementById('labelEmpresa');
        if (!perfilSelect || !empresaSelect || !labelEmpresa) return;

        const val = perfilSelect.value;
        const isMaster = val === 'master' || val === 'master_operador';

        if (isMaster) {
            labelEmpresa.classList.remove('required');
        } else {
            labelEmpresa.classList.add('required');
        }
    }

    document.addEventListener('DOMContentLoaded', atualizarRegraPerfil);
    </script>
</body>
</html>
