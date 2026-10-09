<?php
declare(strict_types=1);
/**
 * @arquivo       usuarios.php
 * @versao        2.0.0
 * @modificado_em 2026-10-09
 * @objetivo      Listagem de usuários do sistema, com escopo por perfil (master/master_operador
 *                vê todos; administrador/operador vê apenas da própria empresa), exibição de
 *                empresas vinculadas (empresa padrão + dropdown "+"), geração agora (kW) agregada
 *                e filtro inteligente client-side.
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

$appTituloPagina = 'Usuários';
$appPaginaAtual  = 'usuarios';

$usuarioLogado   = $_SESSION['usuario'] ?? [];
$perfilLogado    = (string) ($usuarioLogado['perfil'] ?? $_SESSION['usuario_perfil'] ?? '');
$empresaIdLogado = $usuarioLogado['empresa_id'] ?? $_SESSION['empresa_id'] ?? null;

// Apenas operadores, administradores e masters têm acesso à gestão
if (!in_array($perfilLogado, ['master', 'master_operador', 'administrador', 'operador'], true)) {
    http_response_code(403);
    echo '<p style="font-family:sans-serif;padding:20px;color:#d97f7f;">Acesso negado. Perfil sem permissão para gerenciar usuários.</p>';
    exit;
}

$escopoGlobal = in_array($perfilLogado, ['master', 'master_operador'], true);

$erro = null;
$usuarios = [];

try {
    $pdo = getDbConnection();

    if ($escopoGlobal) {
        $sql = "
            SELECT u.id, u.nome, u.email, u.perfil, u.ativo, u.empresa_id,
                   e.nome_fantasia AS empresa_padrao
            FROM usuarios u
            LEFT JOIN empresa e ON e.id = u.empresa_id
            WHERE u.deleted_at IS NULL
            ORDER BY u.nome ASC
        ";
        $stmt = $pdo->query($sql);
    } else {
        $sql = "
            SELECT u.id, u.nome, u.email, u.perfil, u.ativo, u.empresa_id,
                   e.nome_fantasia AS empresa_padrao
            FROM usuarios u
            LEFT JOIN empresa e ON e.id = u.empresa_id
            WHERE u.deleted_at IS NULL
              AND u.empresa_id = :empresa_id
            ORDER BY u.nome ASC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['empresa_id' => $empresaIdLogado]);
    }
    $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Vínculos extras (usuario_empresa) por usuário
    $vinculos = [];
    if (!empty($usuarios)) {
        $ids = array_column($usuarios, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmtV = $pdo->prepare("
            SELECT ue.usuario_id, ue.empresa_id, e.nome_fantasia AS empresa_nome, ue.papel_empresa
            FROM usuario_empresa ue
            JOIN empresa e ON e.id = ue.empresa_id
            WHERE ue.usuario_id IN ($placeholders)
              AND ue.ativo = 1
              AND ue.deleted_at IS NULL
            ORDER BY e.nome_fantasia ASC
        ");
        $stmtV->execute($ids);
        foreach ($stmtV->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $vinculos[$row['usuario_id']][] = $row;
        }
    }

    // Geração agora (kW) — soma dos controladores das empresas vinculadas (padrão + usuario_empresa)
    foreach ($usuarios as &$u) {
        $empresaIds = [];
        if (!empty($u['empresa_id'])) {
            $empresaIds[] = (int) $u['empresa_id'];
        }
        foreach ($vinculos[$u['id']] ?? [] as $v) {
            $empresaIds[] = (int) $v['empresa_id'];
        }
        $empresaIds = array_unique($empresaIds);

        $geracaoW = 0.0;
        if (!empty($empresaIds)) {
            $placeholdersE = implode(',', array_fill(0, count($empresaIds), '?'));
            $stmtG = $pdo->prepare("
                SELECT COALESCE(SUM(t.potencia_geracao_w), 0) AS total_w
                FROM controladores c
                JOIN telemetria_5min t ON t.controlador_id = c.id
                WHERE c.empresa_id IN ($placeholdersE)
                  AND t.timestamp_utc = (
                      SELECT MAX(t2.timestamp_utc)
                      FROM telemetria_5min t2
                      WHERE t2.controlador_id = c.id
                  )
            ");
            $stmtG->execute(array_values($empresaIds));
            $geracaoW = (float) ($stmtG->fetchColumn() ?: 0);
        }

        // telemetria_5min.potencia_geracao_w está em Watts → dividir por 1000 para kW
        $u['geracao_kw']      = round($geracaoW / 1000, 2);
        $u['_empresas_extra'] = $vinculos[$u['id']] ?? [];
    }
    unset($u);

} catch (Throwable $e) {
    error_log('[usuarios.php] Erro ao carregar usuários: ' . $e->getMessage());
    $erro = 'Erro ao processar listagem de usuários.';
    $usuarios = [];
}

$sucesso = isset($_GET['salvo']) ? 'Usuário salvo com sucesso.' : null;
?>
<!DOCTYPE html>
<html lang="pt-BR" data-tema="escuro">
<head>
    <?php require __DIR__ . '/includes/app_head.php'; ?>
    <script src="<?php echo e(appUrl('/assets/js/smart-filter.js')); ?>"></script>
    <style>
        .usuarios-container {
            max-width: 1400px;
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
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 12px;
        }
        .panel-title {
            margin: 0;
            font-size: 1.5rem;
            color: #fff;
        }
        .panel-subtitle {
            margin: 4px 0 0 0;
            font-size: 0.875rem;
            color: #8ba3c7;
        }
        .btn-novo {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #00d26a;
            color: #0b192c;
            padding: 10px 18px;
            border-radius: 6px;
            font-weight: 600;
            text-decoration: none;
            font-size: 14px;
            transition: background 0.2s;
        }
        .btn-novo:hover {
            background: #00b85c;
        }
        .filtro-box {
            margin-bottom: 20px;
            display: flex;
            gap: 12px;
            align-items: center;
            background: rgba(13, 21, 38, 0.7);
            padding: 12px 16px;
            border-radius: 8px;
            border: 1px solid #1a3a60;
        }
        .filtro-box input {
            flex: 1;
            padding: 10px 14px;
            border-radius: 6px;
            border: 1px solid #2a4365;
            background: #0d1526;
            color: #fff;
            font-size: 14px;
            outline: none;
        }
        .filtro-box input:focus {
            border-color: #38bdf8;
        }
        .filtro-contador {
            font-size: 13px;
            color: #8ba3c7;
            white-space: nowrap;
        }
        .usuarios-grid {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .usuario-card-header {
            display: grid;
            grid-template-columns: 2.2fr 2fr 1.2fr 1.2fr 1.2fr;
            padding: 10px 16px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            color: #8ba3c7;
            letter-spacing: 0.05em;
            border-bottom: 1px solid #1a3a60;
        }
        .usuario-card {
            display: grid;
            grid-template-columns: 2.2fr 2fr 1.2fr 1.2fr 1.2fr;
            align-items: center;
            gap: 12px;
            padding: 14px 16px;
            background: rgba(13, 21, 38, 0.5);
            border: 1px solid #1a3a60;
            border-radius: 8px;
            transition: background 0.15s, border-color 0.15s;
        }
        .usuario-card:hover {
            background: rgba(16, 35, 63, 0.9);
            border-color: #2b568d;
        }
        .usuario-info-nome {
            font-weight: 600;
            color: #fff;
            font-size: 14px;
        }
        .usuario-info-email {
            font-size: 13px;
            color: #8ba3c7;
            margin-top: 2px;
        }
        .badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            text-align: center;
        }
        .badge-ativo {
            background: rgba(30, 70, 32, 0.6);
            color: #7fd98a;
            border: 1px solid #2d6a30;
        }
        .badge-inativo {
            background: rgba(74, 30, 30, 0.6);
            color: #d97f7f;
            border: 1px solid #6a2d2d;
        }
        .badge-perfil {
            background: rgba(23, 42, 69, 0.8);
            color: #38bdf8;
            border: 1px solid #1e3a5f;
            text-transform: capitalize;
        }
        .badge-perfil-master {
            background: rgba(88, 28, 135, 0.4);
            color: #c084fc;
            border: 1px solid #7e22ce;
        }
        .badge-perfil-admin {
            background: rgba(29, 78, 216, 0.4);
            color: #93c5fd;
            border: 1px solid #2563eb;
        }
        .empresa-extra {
            position: relative;
            display: inline-block;
            margin-left: 6px;
            cursor: pointer;
            background: #1e3a5f;
            color: #38bdf8;
            padding: 2px 7px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: bold;
            user-select: none;
        }
        .empresa-extra-dropdown {
            display: none;
            position: absolute;
            top: 120%;
            left: 0;
            background: #0d1526;
            border: 1px solid #2a4365;
            border-radius: 6px;
            padding: 8px 12px;
            z-index: 100;
            white-space: nowrap;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.5);
            min-width: 180px;
        }
        .empresa-extra.aberto .empresa-extra-dropdown {
            display: block;
        }
        .empresa-extra-item {
            padding: 4px 0;
            font-size: 12px;
            color: #cbd5e1;
            border-bottom: 1px solid #1a2d4a;
        }
        .empresa-extra-item:last-child {
            border-bottom: none;
        }
        .geracao-val {
            font-size: 14px;
            font-weight: 600;
            color: #facc15;
        }
        .acoes-col {
            display: flex;
            align-items: center;
            gap: 10px;
            justify-content: flex-start;
        }
        .btn-editar {
            padding: 5px 12px;
            border-radius: 4px;
            background: #1e3a5f;
            color: #93c5fd;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            transition: background 0.2s, color 0.2s;
        }
        .btn-editar:hover {
            background: #2b568d;
            color: #fff;
        }
        .alert {
            padding: 12px 16px;
            border-radius: 6px;
            margin-bottom: 16px;
            font-size: 14px;
        }
        .alert-success {
            background: rgba(30, 70, 32, 0.8);
            color: #7fd98a;
            border: 1px solid #2d6a30;
        }
        .alert-error {
            background: rgba(74, 30, 30, 0.8);
            color: #d97f7f;
            border: 1px solid #6a2d2d;
        }
        @media (max-width: 900px) {
            .usuario-card-header {
                display: none;
            }
            .usuario-card {
                grid-template-columns: 1fr;
                gap: 8px;
            }
            .acoes-col {
                justify-content: space-between;
                margin-top: 8px;
                padding-top: 8px;
                border-top: 1px solid #1a2d4a;
            }
        }
    </style>
</head>
<body>
    <div id="loading">
        <div class="spin"></div>
        <p>CARREGANDO USUÁRIOS...</p>
    </div>
    <?php require __DIR__ . '/includes/app_header.php'; ?>

    <main class="usuarios-container">
        <?php if ($sucesso): ?>
            <div class="alert alert-success"><?php echo e($sucesso); ?></div>
        <?php endif; ?>
        <?php if ($erro): ?>
            <div class="alert alert-error"><?php echo e($erro); ?></div>
        <?php endif; ?>

        <section class="panel">
            <div class="panel-header">
                <div>
                    <h1 class="panel-title">Usuários<?php echo $escopoGlobal ? '' : ' da Empresa'; ?></h1>
                    <p class="panel-subtitle">Gestão de acessos, perfis operacionais e vínculos de empresas</p>
                </div>
                <a href="<?php echo e(appUrl('/usuario_form.php')); ?>" class="btn-novo">+ Novo Usuário</a>
            </div>

            <div class="filtro-box">
                <input type="text"
                       id="filtroUsuarios"
                       class="form-input"
                       placeholder="🔍 Buscar por nome, e-mail, perfil ou empresa..."
                       autocomplete="off">
                <span id="filtroContadorUsuarios" class="filtro-contador"></span>
            </div>

            <div class="usuario-card-header">
                <div>Usuário</div>
                <div>Empresa</div>
                <div>Perfil</div>
                <div>Geração Agora</div>
                <div>Ações</div>
            </div>

            <div class="usuarios-grid" id="listaUsuarios">
                <?php if (empty($usuarios)): ?>
                    <div style="text-align:center;padding:30px;color:#8ba3c7;">
                        Nenhum usuário encontrado.
                    </div>
                <?php else: ?>
                    <?php foreach ($usuarios as $u): ?>
                        <?php
                            $extras   = $u['_empresas_extra'];
                            $busca    = mb_strtolower(
                                $u['nome'] . ' ' .
                                $u['email'] . ' ' .
                                $u['perfil'] . ' ' .
                                ($u['empresa_padrao'] ?? '') . ' ' .
                                implode(' ', array_column($extras, 'empresa_nome'))
                            );

                            $perfilClass = 'badge-perfil';
                            if (str_starts_with($u['perfil'], 'master')) {
                                $perfilClass = 'badge-perfil-master';
                            } elseif ($u['perfil'] === 'administrador') {
                                $perfilClass = 'badge-perfil-admin';
                            }
                        ?>
                        <div class="usuario-card" data-busca="<?php echo e($busca); ?>">
                            <div>
                                <div class="usuario-info-nome"><?php echo e($u['nome']); ?></div>
                                <div class="usuario-info-email"><?php echo e($u['email']); ?></div>
                            </div>
                            <div>
                                <span style="color:#e2e8f0;"><?php echo e($u['empresa_padrao'] ?? '—'); ?></span>
                                <?php if (!empty($extras)): ?>
                                    <span class="empresa-extra" onclick="this.classList.toggle('aberto')">
                                        +<?php echo count($extras); ?>
                                        <div class="empresa-extra-dropdown">
                                            <?php foreach ($extras as $ex): ?>
                                                <div class="empresa-extra-item">
                                                    <strong><?php echo e($ex['empresa_nome']); ?></strong><br>
                                                    <span style="color:#8ba3c7;font-size:11px;"><?php echo e($ex['papel_empresa']); ?></span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div>
                                <span class="badge <?php echo $perfilClass; ?>">
                                    <?php echo e($u['perfil']); ?>
                                </span>
                            </div>
                            <div>
                                <span class="geracao-val"><?php echo number_format($u['geracao_kw'], 2, ',', '.'); ?> kW</span>
                            </div>
                            <div class="acoes-col">
                                <span class="badge <?php echo $u['ativo'] ? 'badge-ativo' : 'badge-inativo'; ?>">
                                    <?php echo $u['ativo'] ? 'Ativo' : 'Inativo'; ?>
                                </span>
                                <a href="<?php echo e(appUrl('/usuario_form.php?id=' . (int) $u['id'])); ?>" class="btn-editar">Editar</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>
    </main>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        if (typeof attachSmartFilter === 'function') {
            attachSmartFilter('filtroUsuarios', '.usuario-card', 'filtroContadorUsuarios');
        }

        // Fechar dropdown de empresa ao clicar fora
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.empresa-extra')) {
                document.querySelectorAll('.empresa-extra.aberto').forEach(function(el) {
                    el.classList.remove('aberto');
                });
            }
        });
    });
    </script>
</body>
</html>
