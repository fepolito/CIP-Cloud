<?php
/**
 * ============================================================
 * Arquivo   : empresas_lista.php
 * Projeto   : CIP — Controlador de Injeção de Potência Elétrica
 * Versão    : 1.0.0
 * Modificado: 2026-09-14
 * Objetivo  : Listagem hierárquica de empresas (SolisCloud-style) com rollup
 *             de geração dia/mês, escopo por perfil e drill-down por linha.
 *
 * Dependências de hardware:
 *   - Servidor web compatível com PHP 8.3+
 *   - Banco de dados MySQL 8.0+ / MariaDB 10.2+ (CTE recursiva)
 *
 * Dependências de software/arquivos:
 *   - PHP 8.3+ (PSR-12, strict_types)
 *   - config/app.php
 *   - config/database.php
 *   - app/auth.php
 *   - app/services/Database.php
 *   - app/services/EmpresaService.php
 *   - includes/app_header.php
 *   - assets/css/app.css
 *   - assets/css/header.css
 *   - assets/css/empresas_lista.css
 *   - assets/js/app-shell.js
 *   - assets/js/empresas_lista.js
 *
 * Histórico de implementações:
 *   2026-09-14  v1.0.0  Criação inicial da listagem hierárquica
 *                        (SolisCloud-style) com rollup dia/mês (CIP-DEC-20260914-003)
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/services/Database.php';
require_once __DIR__ . '/app/services/EmpresaService.php';

use App\Services\Database;
use App\Services\EmpresaService;

startSecureSession();
requireAuth();

if (!function_exists('e')) {
    function e($v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('appUrl')) {
    function appUrl(string $p = ''): string
    {
        return APP_URL . APP_BASE_PATH . $p;
    }
}

// ── Controle de acesso (perfis autorizados: master, master_operador, administrador, operador) ──
$perfil = (string) ($_SESSION['usuario_perfil'] ?? '');
if (!in_array($perfil, ['master', 'master_operador', 'administrador', 'operador'], true)) {
    header('Location: ' . appUrl('/dashboard.php'));
    exit;
}

// ── Compatibilidade de sessão: 'empresa_id' (padrão do CIP) e fallback 'usuario_empresa_id' ──
$empresaUsuarioId = isset($_SESSION['empresa_id'])
    ? (int) $_SESSION['empresa_id']
    : (isset($_SESSION['usuario_empresa_id']) ? (int) $_SESSION['usuario_empresa_id'] : null);

$periodo = (($_GET['periodo'] ?? 'mes') === 'dia') ? 'dia' : 'mes';

$appTituloPagina = 'Empresas';
$appPaginaAtual  = 'empresas_lista';

$erro = '';
$empresas = [];

try {
    $pdo      = Database::getConnection();
    $service  = new EmpresaService($pdo);
    $empresas = $service->listarComGeracao($perfil, $empresaUsuarioId, $periodo);
} catch (Throwable $ex) {
    error_log('[CIP][empresas_lista] ' . $ex->getMessage());
    $erro = 'Erro ao carregar a listagem de empresas.';
}

$rotuloTipo = [
    'integradora_virtual' => 'Integradora',
    'parceiro'            => 'Parceiro',
    'cliente_real'        => 'Cliente',
    'demo'                => 'Demo',
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <?php require __DIR__ . '/includes/app_head.php'; ?>
    <link rel="stylesheet" href="<?php echo e(cssVersion('assets/css/empresas_lista.css')); ?>">
    <style>
        main.app-content.empresas-lista {
            padding-top: 96px;
        }
        @media (min-width: 768px) {
            main.app-content.empresas-lista {
                padding-top: 104px;
            }
        }
    </style>
</head>
<body>
    <?php require __DIR__ . '/includes/app_header.php'; ?>

    <main class="app-content container empresas-lista">

        <div class="empresas-header-box">
            <div class="header-left">
                <h1 class="pagina-titulo">Hierarquia de Empresas</h1>
                <p class="pagina-subtitulo">Visão consolidada da árvore com rollup de geração energética.</p>
            </div>

            <div class="lista-toolbar">
                <div class="filtro-busca">
                    <input
                        type="text"
                        id="filtroEmpresas"
                        class="form-input"
                        placeholder="🔍 Buscar por nome, tipo ou ID..."
                        autocomplete="off"
                        style="min-width: 260px;"
                    >
                </div>
                <div class="periodo-toggle" role="tablist" aria-label="Seletor de período">
                    <a href="?periodo=dia"
                       class="periodo-btn <?php echo $periodo === 'dia' ? 'is-active' : ''; ?>">Dia</a>
                    <a href="?periodo=mes"
                       class="periodo-btn <?php echo $periodo === 'mes' ? 'is-active' : ''; ?>">Mês</a>
                </div>
                <?php if (in_array($perfil, ['master', 'master_operador'], true)): ?>
                <a href="<?php echo e(appUrl('/empresas.php')); ?>" class="btn btn-primary btn-nova-empresa">
                    + Nova Empresa
                </a>
                <?php endif; ?>
            </div>
            <p class="text-muted" id="filtroContadorEmpresas" style="font-size: 0.8rem; margin: 4px 0 0;"></p>
        </div>

        <?php if ($erro !== ''): ?>
            <div class="alert alert-error" role="alert"><?php echo e($erro); ?></div>
        <?php endif; ?>

        <div class="empresa-tabela-container">
            <div class="empresa-tabela">
                <div class="et-head">
                    <span class="col-empresa">Empresa</span>
                    <span class="col-tipo">Tipo</span>
                    <span class="col-center">Filhas</span>
                    <span class="col-center">Controladores</span>
                    <span class="col-right">Geração (<?php echo $periodo === 'dia' ? 'Dia' : 'Mês'; ?>)</span>
                    <span class="col-acoes">Ações</span>
                </div>

                <?php if (empty($empresas)): ?>
                    <div class="et-empty">Nenhuma empresa encontrada no seu escopo de visualização.</div>
                <?php endif; ?>

                <?php foreach ($empresas as $emp): ?>
                    <div class="et-row"
                         data-empresa-id="<?php echo e((string) $emp['id']); ?>"
                         data-busca="<?php echo e(mb_strtolower(
                             $emp['nome_fantasia'] . ' ' .
                             ($rotuloTipo[$emp['tipo']] ?? $emp['tipo']) . ' #' .
                             $emp['id']
                         )); ?>">
                        <div class="et-main col-empresa">
                            <button type="button" class="et-toggle" aria-expanded="false" title="Expandir detalhes">▸</button>
                            <span class="et-nome"><?php echo e($emp['nome_fantasia']); ?></span>
                            <?php if (!$emp['ativo']): ?>
                                <span class="badge badge-inativo">Inativa</span>
                            <?php endif; ?>
                        </div>
                        <span class="et-tipo col-tipo">
                            <span class="badge-tipo badge-<?php echo e($emp['tipo']); ?>">
                                <?php echo e($rotuloTipo[$emp['tipo']] ?? $emp['tipo']); ?>
                            </span>
                        </span>
                        <span class="col-center"><span class="badge-num"><?php echo e((string) $emp['num_filhas']); ?></span></span>
                        <span class="col-center"><span class="badge-num"><?php echo e((string) $emp['num_controlad']); ?></span></span>
                        <span class="et-geracao col-right">
                            <strong><?php echo e(number_format((float) $emp['geracao_kwh'], 2, ',', '.')); ?></strong> <small>kWh</small>
                        </span>
                        <div class="col-acoes">
                            <a class="btn-detalhe"
                               href="<?php echo e(appUrl('/empresas.php?id=' . (int) $emp['id'])); ?>"
                               title="Gerenciar cadastro institucional">Abrir</a>
                        </div>

                        <div class="et-expand" hidden>
                            <div class="et-expand-content">
                                <div class="expand-field">
                                    <span class="field-label">ID Empresa:</span>
                                    <span class="field-value">#<?php echo e((string) $emp['id']); ?></span>
                                </div>
                                <div class="expand-field">
                                    <span class="field-label">Status:</span>
                                    <span class="field-value"><?php echo $emp['ativo'] ? 'Ativa' : 'Inativa'; ?></span>
                                </div>
                                <div class="expand-field">
                                    <span class="field-label">Vínculo:</span>
                                    <span class="field-value"><?php echo empty($emp['empresa_pai_id']) ? 'Empresa Raiz / Topo' : 'Filha da Empresa #' . e((string) $emp['empresa_pai_id']); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

    </main>

    <script src="<?php echo e(appUrl('/assets/js/app-shell.js')); ?>"></script>
    <script src="<?php echo e(appUrl('/assets/js/empresas_lista.js')); ?>"></script>
    <script src="<?php echo e(appUrl('/assets/js/smart-filter.js')); ?>"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        attachSmartFilter('filtroEmpresas', '.et-row', 'filtroContadorEmpresas');
    });
    </script>
</body>
</html>
