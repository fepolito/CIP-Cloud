<?php
/**
 * =============================================================================
 * Projeto    : CIP - Controlador de Injecao de Potencia Eletrica
 * Arquivo    : public_html/usuarios.php
 * Objetivo   : Modulo administrativo de usuarios com segregacao por perfil e
 *              por empresa, incluindo listagem e fluxo de pre-cadastro por convite
 *
 * Dependencias de hardware:
 *   - Servidor com MySQL/MariaDB acessivel
 *   - Navegador com suporte a HTML5, CSS3 e JavaScript
 *
 * Dependencias de software:
 *   - PHP 7.4+
 *   - app/auth.php
 *   - app/services/UsuarioPermissaoService.php
 *   - app/services/UsuarioConviteService.php
 *   - config/database.php
 *   - includes/app_head.php
 *   - includes/app_header.php
 *
 * Regras obrigatorias:
 *   - viewer nao acessa usuarios.php
 *   - operador atua apenas na propria empresa
 *   - master/master_operador possuem visao global
 *   - cadastro segue fluxo de pre-cadastro por convite
 *   - remocao fisica nao deve ser utilizada
 *
 * Historico de implementacoes:
 *   - 2026-04-29 | v1.0 | Estrutura inicial aderente ao documento funcional
 *   - 2026-04-30 | v1.1 | Inclusao da acao novo com pre-cadastro por convite
 *   - 2026-04-30 | v1.2 | Persistencia real via PDO para pre-cadastro de usuarios
 * =============================================================================
 */

declare(strict_types=1);

$baseDir = dirname(__DIR__);
if (is_dir(__DIR__ . '/app') && is_dir(__DIR__ . '/includes')) {
    $baseDir = __DIR__;
}

require_once $baseDir . '/app/auth.php';
require_once $baseDir . '/app/services/UsuarioPermissaoService.php';
require_once $baseDir . '/app/services/UsuarioConviteService.php';
require_once $baseDir . '/config/database.php';

requireAuth();

try {
    $pdo = getDbConnection();
} catch (Throwable $e) {
    http_response_code(500);
    exit('Erro interno: falha ao conectar ao banco de dados.');
}

$perfilUsuario = (string) ($_SESSION['usuario_perfil'] ?? '');
$usuarioId     = (int) ($_SESSION['usuario_id'] ?? 0);
$empresaSessao = (int) ($_SESSION['empresa_id'] ?? 0);
$usuarioNome   = (string) ($_SESSION['usuario_nome'] ?? 'Usuário');

if (!UsuarioPermissaoService::podeAcessarModulo($perfilUsuario)) {
    header('Location: dashboard.php?msg=acesso_negado');
    exit;
}

$ehMasterGlobal = UsuarioPermissaoService::temVisaoGlobal($perfilUsuario);
$ehOperador     = ($perfilUsuario === UsuarioPermissaoService::PERFIL_OPERADOR);

$appTituloPagina     = 'Usuários';
$appPaginaAtual      = 'usuarios';
$appUsuarioNome      = $usuarioNome;
$appEmpresaNome      = 'Controlador de Injeção de Potência Elétrica';
$appEmpresaLogoTexto = 'CI';
$appIsAdmin          = in_array($perfilUsuario, [
    UsuarioPermissaoService::PERFIL_MASTER,
    UsuarioPermissaoService::PERFIL_MASTER_OPERADOR,
    UsuarioPermissaoService::PERFIL_ADMINISTRADOR,
], true);

$usuarioAutenticado = [
    'id' => $usuarioId,
    'perfil' => $perfilUsuario,
    'empresa_id' => $empresaSessao,
    'nome' => $usuarioNome,
];

$acao = (string) ($_GET['acao'] ?? 'listar');

$empresaIdSolicitada = isset($_GET['empresa_id']) ? (int) $_GET['empresa_id'] : null;
if ($empresaIdSolicitada !== null && $empresaIdSolicitada <= 0) {
    $empresaIdSolicitada = null;
}

$empresaIdSelecionada = UsuarioPermissaoService::resolverEmpresaAlvo(
    $perfilUsuario,
    $empresaSessao > 0 ? $empresaSessao : null,
    $empresaIdSolicitada
);

if ($empresaIdSelecionada === null) {
    $empresaIdSelecionada = 0;
}

$mensagem = null;
$erros = [];
$empresas = [];
$usuarios = [];

$formNovo = [
    'empresa_id' => $empresaIdSelecionada,
    'email' => '',
    'perfil' => UsuarioPermissaoService::PERFIL_VIEWER,
];

/**
 * -----------------------------------------------------------------------------
 * Funcoes auxiliares locais
 * -----------------------------------------------------------------------------
 */
function buscarEmpresas(PDO $pdo): array
{
    $sql = '
        SELECT
            id,
            nome_fantasia AS nome
        FROM empresa
        ORDER BY nome_fantasia ASC
    ';

    $stmt = $pdo->query($sql);

    if (!$stmt instanceof PDOStatement) {
        return [];
    }

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}


function buscarUsuariosPorEmpresa(PDO $pdo, ?int $empresaId = null, bool $visaoGlobal = false): array
{
    $sql = '
        SELECT
            u.id,
            u.nome,
            u.email,
            u.perfil,
            u.empresa_id,
            u.ativo,
            u.criado_em,
            u.atualizado_em,
            e.nome_fantasia AS empresa_nome
        FROM usuarios u
        LEFT JOIN empresa e ON e.id = u.empresa_id
    ';

    $params = [];

    if (!$visaoGlobal) {
        $sql .= ' WHERE u.empresa_id = :empresa_id ';
        $params[':empresa_id'] = $empresaId;
    } elseif ($empresaId !== null) {
        $sql .= ' WHERE u.empresa_id = :empresa_id ';
        $params[':empresa_id'] = $empresaId;
    }

    $sql .= ' ORDER BY u.nome ASC ';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}


function existeUsuarioPorEmailEmpresa(PDO $pdo, int $empresaId, string $email): bool
{
    $sql = '
        SELECT id
        FROM usuarios
        WHERE empresa_id = :empresa_id
          AND LOWER(email) = LOWER(:email)
        LIMIT 1
    ';

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':empresa_id', $empresaId, PDO::PARAM_INT);
    $stmt->bindValue(':email', $email, PDO::PARAM_STR);
    $stmt->execute();

    return (bool) $stmt->fetchColumn();
}

function inserirPreCadastroUsuario(PDO $pdo, array $dados): int
{
    $sql = '
        INSERT INTO usuarios (
            empresa_id,
            nome,
            email,
            perfil,
            ativo,
            status_cadastro,
            token_convite,
            convite_expira_em,
            criado_por,
            ultimo_acesso,
            created_at,
            updated_at
        ) VALUES (
            :empresa_id,
            :nome,
            :email,
            :perfil,
            :ativo,
            :status_cadastro,
            :token_convite,
            :convite_expira_em,
            :criado_por,
            :ultimo_acesso,
            :created_at,
            :updated_at
        )
    ';

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':empresa_id', (int) $dados['empresa_id'], PDO::PARAM_INT);
    $stmt->bindValue(':nome', $dados['nome'], PDO::PARAM_NULL);
    $stmt->bindValue(':email', (string) $dados['email'], PDO::PARAM_STR);
    $stmt->bindValue(':perfil', (string) $dados['perfil'], PDO::PARAM_STR);
    $stmt->bindValue(':ativo', (int) $dados['ativo'], PDO::PARAM_INT);
    $stmt->bindValue(':status_cadastro', (string) $dados['status_cadastro'], PDO::PARAM_STR);
    $stmt->bindValue(':token_convite', (string) $dados['token_convite'], PDO::PARAM_STR);
    $stmt->bindValue(':convite_expira_em', (string) $dados['convite_expira_em'], PDO::PARAM_STR);
    $stmt->bindValue(':criado_por', (int) $dados['criado_por'], PDO::PARAM_INT);
    $stmt->bindValue(':ultimo_acesso', null, PDO::PARAM_NULL);
    $stmt->bindValue(':created_at', (string) $dados['created_at'], PDO::PARAM_STR);
    $stmt->bindValue(':updated_at', (string) $dados['updated_at'], PDO::PARAM_STR);
    $stmt->execute();

    return (int) $pdo->lastInsertId();
}

/**
 * -----------------------------------------------------------------------------
 * Carga inicial de empresas
 * -----------------------------------------------------------------------------
 */
if ($ehMasterGlobal) {
    try {
        $empresas = buscarEmpresas($pdo);
    } catch (Throwable $e) {
        $erros[] = 'Não foi possível carregar a lista de empresas.';
    }
}

/**
 * -----------------------------------------------------------------------------
 * Processamento da acao novo
 * -----------------------------------------------------------------------------
 */
if ($acao === 'novo' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $formNovo['empresa_id'] = isset($_POST['empresa_id']) ? (int) $_POST['empresa_id'] : 0;
    $formNovo['email'] = trim((string) ($_POST['email'] ?? ''));
    $formNovo['perfil'] = trim((string) ($_POST['perfil'] ?? ''));

    if ($ehOperador) {
        $formNovo['empresa_id'] = $empresaSessao;
    }

    if ($formNovo['empresa_id'] <= 0) {
        $erros[] = 'Selecione uma empresa válida.';
    }

    if (!UsuarioConviteService::emailValido($formNovo['email'])) {
        $erros[] = 'Informe um e-mail válido.';
    }

    if (!UsuarioPermissaoService::podeCriarPerfil($perfilUsuario, $formNovo['perfil'])) {
        $erros[] = 'Você não possui permissão para criar este perfil.';
    }

    if ($ehOperador && $formNovo['empresa_id'] !== $empresaSessao) {
        $erros[] = 'Operador pode cadastrar usuários apenas na própria empresa.';
    }

    if ($erros === []) {
        try {
            $emailNormalizado = UsuarioConviteService::normalizarEmail($formNovo['email']);

            if (existeUsuarioPorEmailEmpresa($pdo, $formNovo['empresa_id'], $emailNormalizado)) {
                $erros[] = 'Já existe um usuário cadastrado com este e-mail para a empresa selecionada.';
            } else {
                $preCadastro = UsuarioConviteService::montarPreCadastro(
                    $formNovo['empresa_id'],
                    $emailNormalizado,
                    $formNovo['perfil'],
                    $usuarioId,
                    1,
                    7
                );

                $preCadastro['nome'] = null;

                $pdo->beginTransaction();
                $novoUsuarioId = inserirPreCadastroUsuario($pdo, $preCadastro);
                $pdo->commit();

                header(
                    'Location: usuarios.php?empresa_id='
                    . (int) $preCadastro['empresa_id']
                    . '&msg=pre_cadastro_criado&id='
                    . $novoUsuarioId
                );
                exit;
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $erros[] = 'Falha ao criar o pré-cadastro do usuário.';
        }
    }
}

/**
 * -----------------------------------------------------------------------------
 * Mensagens de retorno
 * -----------------------------------------------------------------------------
 */
$msg = (string) ($_GET['msg'] ?? '');
if ($msg === 'pre_cadastro_criado') {
    $mensagem = 'Pré-cadastro criado com sucesso. O usuário foi registrado com convite pendente.';
}

/**
 * -----------------------------------------------------------------------------
 * Perfis disponiveis para criacao
 * -----------------------------------------------------------------------------
 */
$perfisDisponiveis = [];
foreach ([
    UsuarioPermissaoService::PERFIL_ADMINISTRADOR,
    UsuarioPermissaoService::PERFIL_OPERADOR,
    UsuarioPermissaoService::PERFIL_VIEWER,
] as $perfilNovo) {
    if (UsuarioPermissaoService::podeCriarPerfil($perfilUsuario, $perfilNovo)) {
        $perfisDisponiveis[] = $perfilNovo;
    }
}

/**
 * -----------------------------------------------------------------------------
 * Listagem
 * -----------------------------------------------------------------------------
 */
if ($acao !== 'novo') {
    if ($ehOperador && $empresaSessao > 0) {
        $empresaIdSelecionada = $empresaSessao;
    }

    if ((!$ehMasterGlobal && $empresaIdSelecionada > 0) || ($ehMasterGlobal && $empresaIdSelecionada > 0)) {
        try {
            $usuarios = buscarUsuariosPorEmpresa($pdo, $empresaIdSelecionada);
        } catch (Throwable $e) {
            $erros[] = 'Não foi possível carregar os usuários da empresa selecionada.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR" data-tema="escuro">
<head>
    <?php require $baseDir . '/includes/app_head.php'; ?>
    <style>
        .wrap {
            max-width: 1440px;
            margin: 0 auto;
            padding: 80px 24px 40px;
        }

        .page-card {
            background: #0d1526;
            border: 1px solid #1a2d4a;
            border-radius: 14px;
            padding: 24px;
            color: #e0eaf8;
            box-shadow: 0 2px 12px rgba(0,0,0,.4);
        }

        .page-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }

        .page-title {
            font-size: 22px;
            font-weight: 700;
            margin: 0;
        }

        .page-subtitle {
            font-size: 14px;
            color: #7a9cc4;
            margin-top: 6px;
        }

        .toolbar {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
            margin-bottom: 20px;
        }

        .sel, .inp {
            background: #0d1526;
            color: #e0eaf8;
            border: 1px solid #1a2d4a;
            border-radius: 10px;
            padding: 10px 14px;
            min-width: 260px;
        }

        .btn {
            background: #0070cc;
            color: #fff;
            border: none;
            border-radius: 10px;
            padding: 10px 16px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }

        .btn-sec {
            background: transparent;
            border: 1px solid #1a2d4a;
            color: #e0eaf8;
            border-radius: 8px;
            padding: 6px 10px;
            font-size: 12px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        th, td {
            text-align: left;
            padding: 12px 10px;
            border-bottom: 1px solid #1a2d4a;
        }

        th {
            color: #7a9cc4;
            font-size: 11px;
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        td {
            color: #e0eaf8;
            vertical-align: middle;
        }

        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
        }

        .b-ativo     { background: rgba(0, 230, 118, 0.12); color: #00e676; }
        .b-inativo   { background: rgba(255, 82, 82, 0.12); color: #ff5252; }
        .b-pendente  { background: rgba(255, 193, 7, 0.12); color: #ffc107; }
        .b-concluido { background: rgba(0, 180, 255, 0.12); color: #00b4ff; }

        .acoes {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .empty {
            color: #7a9cc4;
            padding: 20px 0 4px;
            font-size: 14px;
        }

        .footer {
            text-align: center;
            color: #3a5070;
            font-size: 11px;
            padding-top: 20px;
            border-top: 1px solid #1a2d4a;
            margin-top: 24px;
        }

        .alert {
            border-radius: 10px;
            padding: 12px 14px;
            margin-bottom: 16px;
            font-size: 14px;
        }

        .alert-ok {
            background: rgba(0, 230, 118, 0.10);
            color: #7fffb0;
            border: 1px solid rgba(0, 230, 118, 0.35);
        }

        .alert-erro {
            background: rgba(255, 82, 82, 0.10);
            color: #ff9e9e;
            border: 1px solid rgba(255, 82, 82, 0.35);
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }

        .field {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .field label {
            font-size: 13px;
            color: #7a9cc4;
        }

        .form-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 12px;
        }
    </style>
</head>
<body>
<?php require $baseDir . '/includes/app_header.php'; ?>

<div class="wrap">
    <section class="page-card">
        <div class="page-head">
            <div>
                <h1 class="page-title">Gerenciamento de Usuários</h1>
                <p class="page-subtitle">
                    Administração de usuários com segregação por perfil e empresa.
                </p>
            </div>

            <?php if ($acao !== 'novo' && UsuarioPermissaoService::podeCriarPerfil($perfilUsuario, UsuarioPermissaoService::PERFIL_VIEWER)): ?>
                <a class="btn" href="usuarios.php?acao=novo<?= $empresaIdSelecionada > 0 ? '&empresa_id=' . $empresaIdSelecionada : '' ?>">
                    Novo Usuário
                </a>
            <?php endif; ?>
        </div>

        <?php if ($mensagem !== null): ?>
            <div class="alert alert-ok">
                <?= htmlspecialchars($mensagem, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <?php if ($erros !== []): ?>
            <div class="alert alert-erro">
                <ul>
                    <?php foreach ($erros as $erro): ?>
                        <li><?= htmlspecialchars($erro, ENT_QUOTES, 'UTF-8') ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($acao === 'novo'): ?>
            <form method="post" action="usuarios.php?acao=novo<?= $empresaIdSelecionada > 0 ? '&empresa_id=' . $empresaIdSelecionada : '' ?>">
                <div class="form-grid">
                    <div class="field">
                        <label for="empresa_id">Empresa integradora</label>
                        <?php if ($ehMasterGlobal): ?>
                            <select name="empresa_id" id="empresa_id" class="sel" required>
                                <option value="0">Selecione uma empresa</option>
                                <?php foreach ($empresas as $empresa): ?>
                                    <option value="<?= (int) $empresa['id'] ?>" <?= (int) $formNovo['empresa_id'] === (int) $empresa['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars((string) $empresa['nome'], ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php else: ?>
                            <input type="hidden" name="empresa_id" value="<?= (int) $empresaSessao ?>">
                            <input type="text" class="inp" value="Empresa #<?= (int) $empresaSessao ?>" disabled>
                        <?php endif; ?>
                    </div>

                    <div class="field">
                        <label for="email">E-mail do convidado</label>
                        <input
                            type="email"
                            name="email"
                            id="email"
                            class="inp"
                            maxlength="150"
                            value="<?= htmlspecialchars((string) $formNovo['email'], ENT_QUOTES, 'UTF-8') ?>"
                            required
                        >
                    </div>

                    <div class="field">
                        <label for="perfil">Perfil</label>
                        <select name="perfil" id="perfil" class="sel" required>
                            <?php foreach ($perfisDisponiveis as $perfilNovo): ?>
                                <option value="<?= htmlspecialchars($perfilNovo, ENT_QUOTES, 'UTF-8') ?>" <?= $formNovo['perfil'] === $perfilNovo ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($perfilNovo, ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn">Criar pré-cadastro</button>
                    <a class="btn-sec" href="usuarios.php<?= $empresaIdSelecionada > 0 ? '?empresa_id=' . $empresaIdSelecionada : '' ?>">
                        Voltar
                    </a>
                </div>
            </form>
        <?php else: ?>
            <?php if ($ehMasterGlobal): ?>
                <form method="get" class="toolbar">
                    <input type="hidden" name="acao" value="listar">
                    <label for="empresa_id">Empresa integradora:</label>
                    <select name="empresa_id" id="empresa_id" class="sel" onchange="this.form.submit()">
                        <option value="0">Selecione uma empresa</option>
                        <?php foreach ($empresas as $empresa): ?>
                            <option value="<?= (int) $empresa['id'] ?>" <?= $empresaIdSelecionada === (int) $empresa['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars((string) $empresa['nome'], ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            <?php endif; ?>

            <?php if ($ehMasterGlobal && $empresaIdSelecionada === 0): ?>
                <p class="empty">Selecione uma empresa para visualizar os usuários.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Nome</th>
                                <th>E-mail</th>
                                <th>Perfil</th>
                                <th>Status cadastro</th>
                                <th>Situação</th>
                                <th>Último acesso</th>
                                <th>Criação</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($usuarios === []): ?>
                                <tr>
                                    <td colspan="8">Nenhum usuário encontrado.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($usuarios as $usuario): ?>
                                    <?php
                                    $nomeExibicao = $usuario['nome'] ?: 'Cadastro pendente';
                                    $ativoClasse = ((int) $usuario['ativo'] === 1) ? 'b-ativo' : 'b-inativo';
                                    $ativoTexto = ((int) $usuario['ativo'] === 1) ? 'Ativo' : 'Inativo';
                                    $cadClasse = ((string) $usuario['status_cadastro'] === 'concluido') ? 'b-concluido' : 'b-pendente';
                                    $podeEditar = UsuarioPermissaoService::podeEditarUsuario($usuarioAutenticado, $usuario);
                                    $podeReenviarConvite = UsuarioPermissaoService::podeReenviarConvite($usuarioAutenticado, $usuario);
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars((string) $nomeExibicao, ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars((string) $usuario['email'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars((string) $usuario['perfil'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td>
                                            <span class="badge <?= $cadClasse ?>">
                                                <?= htmlspecialchars((string) $usuario['status_cadastro'], ENT_QUOTES, 'UTF-8') ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge <?= $ativoClasse ?>">
                                                <?= htmlspecialchars((string) $ativoTexto, ENT_QUOTES, 'UTF-8') ?>
                                            </span>
                                        </td>
                                        <td><?= htmlspecialchars((string) ($usuario['ultimo_acesso'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars((string) ($usuario['created_at'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td>
                                            <div class="acoes">
                                                <?php if ($podeEditar): ?>
                                                    <a class="btn-sec" href="usuarios.php?acao=editar&usuario_id=<?= (int) $usuario['id'] ?><?= $empresaIdSelecionada > 0 ? '&empresa_id=' . $empresaIdSelecionada : '' ?>">
                                                        Editar
                                                    </a>
                                                <?php endif; ?>

                                                <?php if (((string) ($usuario['status_cadastro'] ?? '') === 'pendente') && $podeReenviarConvite): ?>
                                                    <a class="btn-sec" href="usuarios.php?acao=reenviar_convite&usuario_id=<?= (int) $usuario['id'] ?><?= $empresaIdSelecionada > 0 ? '&empresa_id=' . $empresaIdSelecionada : '' ?>">
                                                        Reenviar convite
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <div class="footer">
            CIP - Controlador de Injeção de Potência Elétrica
        </div>
    </section>
</div>
</body>
</html>
