<?php
/*
 * ============================================================
 * Arquivo   : includes/app_header.php
 * Projeto   : CIP — Controlador de Injeção de Potência Elétrica
 * Objetivo  : Cabeçalho fixo global com menu hambúrguer,
 *             logo/iniciais da empresa e menu engrenagem.
 *
 * Dependências de hardware:
 *   - Dispositivos móveis, tablets e desktops
 *
 * Dependências de software/arquivos:
 *   - assets/css/header.css
 *   - assets/css/app.css
 *   - assets/js/app-shell.js
 *   - app/auth.php (sessão ativa)
 *   - config/database.php → $pdo instanciado pela página pai
 *
 * Histórico de implementações:
 *   2026-03-27  v1.0.0  Criação do header fixo global
 *   2026-04-15  v2.0.0  Reescrita completa — drawers hambúrguer
 *                        e engrenagem. Logo dinâmica.
 *   2026-04-15  v2.1.0  [FIX] Adicionado energia.php no menu
 *                        hambúrguer. Corrigido id 'dashboard'
 *                        para bater com $appPaginaAtual das páginas.
 *   2026-06-07  vB1.0.0  Patch B1: portado padrao multi-tenant de energia.php
 *                        (Tenant::filtroSQL, $controladorAtivo, persistencia
 *                        em $_SESSION). Adicionado seletor pill.
 * ============================================================
 */

if (!function_exists('appUrl')) {
    function appUrl(string $path = ''): string {
        return APP_URL . APP_BASE_PATH . $path;
    }
}
if (!function_exists('e')) {
    function e($valor): string {
        return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
    }
}

// ── Logo da empresa ──────────────────────────────────────────
$_headerLogoPath = '';
$_headerEmpNome  = '';

try {
    // $pdo é injetado pela página pai (dashboard, energia, etc.)
    if (isset($pdo)) {
        $stmtEmp = $pdo->query(
            'SELECT nome_fantasia, logo_path FROM empresa ORDER BY id DESC LIMIT 1'
        );
        $rowEmp = $stmtEmp->fetch(PDO::FETCH_ASSOC);
        if ($rowEmp) {
            $_headerLogoPath = $rowEmp['logo_path']    ?? '';
            $_headerEmpNome  = $rowEmp['nome_fantasia'] ?? '';
        }
    }
} catch (Throwable $_e) {
    // silencia — logo não é crítico
}

// ── Iniciais fallback ────────────────────────────────────────
$_iniciais = 'CI';
if ($_headerEmpNome !== '') {
    $partes    = array_filter(explode(' ', trim($_headerEmpNome)));
    $_iniciais = count($partes) >= 2
        ? mb_strtoupper(mb_substr($partes[0], 0, 1) . mb_substr(end($partes), 0, 1))
        : mb_strtoupper(mb_substr($partes[0], 0, 2));
}

// ── Página atual para highlight ──────────────────────────────
$_paginaAtual = $appPaginaAtual ?? '';

// ── Itens do menu hambúrguer ─────────────────────────────────
// ATENÇÃO: 'id' deve bater exatamente com $appPaginaAtual
// definido em cada página PHP
$_menuItems = [
    ['href' => 'dashboard.php',        'icon' => '📊', 'label' => 'Dashboard', 'id' => 'dashboard'],
    ['href' => 'energia.php',          'icon' => '⚡', 'label' => 'Energia',   'id' => 'energia'],   // ✅ ADICIONADO
    ['href' => 'geracao.php',          'icon' => '🔆', 'label' => 'Geração',   'id' => 'geracao'],
    ['href' => 'historico.php',        'icon' => '📈', 'label' => 'Histórico', 'id' => 'historico'],
    ['href' => 'tarifas.php',          'icon' => '💰', 'label' => 'Tarifas',   'id' => 'tarifas'],
    ['href' => 'limites_potencia.php', 'icon' => '🔧', 'label' => 'Limites',   'id' => 'limites_potencia'],
];

// ── Itens do menu engrenagem ─────────────────────────────────
$_ehMasterHeader = in_array($_SESSION['usuario_perfil'] ?? '', ['master', 'master_operador'], true);
$_ehAdminHeader  = in_array($_SESSION['usuario_perfil'] ?? '', ['master', 'master_operador', 'administrador'], true);

$_configItems = [];
if ($_ehAdminHeader) {
    $_configItems[] = ['href' => 'usuarios.php',    'icon' => '👥', 'label' => 'Usuários',    'id' => 'usuarios'];
    $_configItems[] = ['href' => 'dispositivos.php','icon' => '📡', 'label' => 'Dispositivos','id' => 'dispositivos'];
}
if ($_ehMasterHeader) {
    $_configItems[] = ['href' => 'empresas.php',    'icon' => '🏢', 'label' => 'Empresas',    'id' => 'empresas'];
}
$_configItems[] = ['href' => 'logout.php', 'icon' => '🚪', 'label' => 'Sair', 'id' => ''];
?>


<!-- ═══════════════════════════════════════════════════════════
     HEADER FIXO
════════════════════════════════════════════════════════════ -->
<header class="app-header" id="appHeader">

    <!-- Hambúrguer -->
    <button class="header-btn" id="btnMenu" aria-label="Abrir menu">
        <span class="hamburger-icon">
            <span></span><span></span><span></span>
        </span>
    </button>

    <!-- Título central (opcional) -->
    <div class="header-title">
        <?php echo e($appTituloPagina ?? 'CIP'); ?>
    </div>

    <!-- Lado direito: logo + tema + engrenagem -->
<div class="header-right">
    <!-- Seletor Pill de Controlador -->
    <?php
    $mostrarPill = isset($controladoresAcessiveis) && count($controladoresAcessiveis) > 0 && isset($controladorAtivo);
    if (isset($estadoA) && $estadoA && count($controladoresAcessiveis) > 1) $mostrarPill = false;
    ?>
    <?php if ($mostrarPill): ?>
        <div class="ctrl-pill-wrapper" style="position: relative;">
            <?php if (count($controladoresAcessiveis) > 1): ?>
                <button type="button" class="ctrl-pill-btn" id="btnCtrlPill" aria-expanded="false" aria-controls="ctrlPillDropdown" onclick="document.getElementById('ctrlPillDropdown').hidden = !document.getElementById('ctrlPillDropdown').hidden; this.setAttribute('aria-expanded', !document.getElementById('ctrlPillDropdown').hidden);">
                    <span class="ctrl-pill-icon">📡</span>
                    <span class="ctrl-pill-name">
                        <?php echo e($controladorAtivo['codigo'] . ($controladorAtivo['apelido'] ? ' — ' . $controladorAtivo['apelido'] : '')); ?>
                    </span>
                    <span class="ctrl-pill-arrow">▼</span>
                    <?php if (isset($controladorAtivo['online'])): ?>
                        <span class="ctrl-pill-status <?php echo $controladorAtivo['online'] ? 'online' : 'offline'; ?>"></span>
                    <?php endif; ?>
                </button>
                <div class="ctrl-pill-dropdown" id="ctrlPillDropdown" hidden style="position: absolute; top: calc(100% + 10px); right: 0; background: var(--color-surface, #fff); border: 1px solid var(--color-border, #e2e8f0); border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); z-index: 1300; width: 280px; max-width: 90vw;">
                    <div style="display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; border-bottom: 1px solid var(--color-border, #e2e8f0);">
                        <span style="font-size: 13px; font-weight: 700; color: var(--color-text-muted, #64748b); text-transform: uppercase; letter-spacing: 1px;">Trocar controlador</span>
                        <button type="button" onclick="document.getElementById('ctrlPillDropdown').hidden = true; document.getElementById('btnCtrlPill').setAttribute('aria-expanded', 'false');" style="background: none; border: none; cursor: pointer; color: var(--color-text-muted, #64748b); font-size: 16px; width: 24px; height: 24px; display: flex; align-items: center; justify-content: center; border-radius: 6px;">✕</button>
                    </div>
                    <ul style="list-style: none; margin: 0; padding: 8px; max-height: 300px; overflow-y: auto;">
                        <?php foreach ($controladoresAcessiveis as $c): ?>
                            <li>
                                <a href="?ctrl=<?php echo $c['id']; ?>" style="display: block; padding: 10px 12px; text-decoration: none; color: var(--color-text, #0f172a); font-size: 14px; font-weight: 500; border-radius: 8px; <?php echo $c['id'] == $controladorAtivo['id'] ? 'background: var(--color-primary-light, #eff6ff); color: var(--color-primary, #1d4ed8);' : ''; ?>" class="ctrl-dropdown-item">
                                    <?php echo e($c['codigo'] . ($c['apelido'] ? ' — ' . $c['apelido'] : '')); ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <script>
                    document.addEventListener('click', function(e) {
                        const btn = document.getElementById('btnCtrlPill');
                        const drop = document.getElementById('ctrlPillDropdown');
                        if (btn && drop && !drop.hidden && !btn.contains(e.target) && !drop.contains(e.target)) {
                            drop.hidden = true;
                            btn.setAttribute('aria-expanded', 'false');
                        }
                    });
                    document.addEventListener('keydown', function(e) {
                        const btn = document.getElementById('btnCtrlPill');
                        const drop = document.getElementById('ctrlPillDropdown');
                        if (e.key === 'Escape' && drop && !drop.hidden) {
                            drop.hidden = true;
                            btn.setAttribute('aria-expanded', 'false');
                            btn.focus();
                        }
                    });
                </script>
            <?php else: ?>
                <div class="ctrl-pill-btn static" style="cursor: default;">
                    <span class="ctrl-pill-icon">📡</span>
                    <span class="ctrl-pill-name">
                        <?php echo e($controladorAtivo['codigo'] . ($controladorAtivo['apelido'] ? ' — ' . $controladorAtivo['apelido'] : '')); ?>
                    </span>
                    <?php if (isset($controladorAtivo['online'])): ?>
                        <span class="ctrl-pill-status <?php echo $controladorAtivo['online'] ? 'online' : 'offline'; ?>"></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Logo ou iniciais -->
    <div class="brand-avatar" title="<?php echo e($_headerEmpNome); ?>">
        <?php if (!empty($_headerLogoPath) && file_exists($_headerLogoPath)): ?>
            <img src="<?php echo e(appUrl('/' . $_headerLogoPath)); ?>"
                 alt="Logo" class="brand-logo">
        <?php else: ?>
            <span class="brand-initials"><?php echo e($_iniciais); ?></span>
        <?php endif; ?>
    </div>

    <!--
        Botao de tema global (claro/escuro)
        Icone inicial = lua; tema.js sobrescreve no DOMContentLoaded
        conforme o tema corrente (lido do data-tema do <html>).
    -->
    <button class="header-btn btn-tema"
            id="btn-tema"
            type="button"
            aria-label="Alternar tema claro/escuro"
            title="Alternar tema">
        🌙
    </button>

    <!-- Engrenagem -->
    <button class="header-btn" id="btnConfig" aria-label="Configurações">
        <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22"
             viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="3"/>
            <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06
                     a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09
                     A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83
                     l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09
                     A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83
                     l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09
                     a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83
                     l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09
                     a1.65 1.65 0 0 0-1.51 1z"/>
        </svg>
    </button>
</div>

</header>

<!-- Overlay (fecha drawers ao clicar fora) -->
<div class="drawer-overlay" id="drawerOverlay"></div>

<!-- ═══════════════════════════════════════════════════════════
     DRAWER ESQUERDO — Hambúrguer
════════════════════════════════════════════════════════════ -->
<nav class="drawer drawer-left" id="drawerMenu" aria-hidden="true">
    <div class="drawer-header">
        <span class="drawer-title">Menu</span>
        <button class="drawer-close" id="btnMenuClose" aria-label="Fechar">✕</button>
    </div>
    <ul class="drawer-nav">
        <?php foreach ($_menuItems as $item): ?>
        <li>
            <a href="<?php echo e(appUrl('/' . $item['href'])); ?>"
               class="drawer-link <?php echo $_paginaAtual === $item['id'] ? 'active' : ''; ?>">
                <span class="drawer-icon"><?php echo $item['icon']; ?></span>
                <span><?php echo e($item['label']); ?></span>
            </a>
        </li>
        <?php endforeach; ?>
    </ul>
</nav>

<!-- ═══════════════════════════════════════════════════════════
     DRAWER DIREITO — Engrenagem / Configurações
════════════════════════════════════════════════════════════ -->
<nav class="drawer drawer-right" id="drawerConfig" aria-hidden="true">
    <div class="drawer-header">
        <button class="drawer-close" id="btnConfigClose" aria-label="Fechar">✕</button>
        <span class="drawer-title">Configurações</span>
    </div>
    <ul class="drawer-nav">
        <?php foreach ($_configItems as $item): ?>
        <li>
            <a href="<?php echo e(appUrl('/' . $item['href'])); ?>"
               class="drawer-link <?php echo $_paginaAtual === ($item['id'] ?? '') ? 'active' : ''; ?>">
                <span class="drawer-icon"><?php echo $item['icon']; ?></span>
                <span><?php echo e($item['label']); ?></span>
            </a>
        </li>
        <?php endforeach; ?>
    </ul>
</nav>
