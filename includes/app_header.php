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
 *   2026-08-12  v2.3.0  Patch CIP-DEC-20260812-009: adicionado faturamento.php
 *                        ao menu hamburguer (conciliacao fatura x telemetria).
 *   2026-06-07  vB1.0.0  Patch B1: portado padrao multi-tenant de energia.php
 *   2026-07-18  vB1.1.0  Patch CIP-DEC-20260718-001 (Forçar UTC no ping) e
 *                        Ajuste do seletor p/ mobile.
 *   2026-07-18  vB1.2.0  Patch CIP-DEC-20260718-002: Status derivado da
 *                        telemetria (MAX timestamp) ao invés do ping.
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
    ['href' => 'faturamento.php',      'icon' => '🧾', 'label' => 'Faturamento', 'id' => 'faturamento'], // ✅ CIP-DEC-20260812-009
    ['href' => 'limites.php', 'icon' => '🔧', 'label' => 'Limites',   'id' => 'limites'],
];

// ── Itens do menu engrenagem ─────────────────────────────────
$_ehMasterHeader = in_array($_SESSION['usuario_perfil'] ?? '', ['master', 'master_operador'], true);
$_ehAdminHeader  = in_array($_SESSION['usuario_perfil'] ?? '', ['master', 'master_operador', 'administrador'], true);
$_ehGestorHeader = in_array($_SESSION['usuario_perfil'] ?? '', ['master', 'master_operador', 'administrador', 'operador'], true);

$_configItems = [];
if ($_ehAdminHeader) {
    $_configItems[] = ['href' => 'usuarios.php',       'icon' => '👥', 'label' => 'Usuários',       'id' => 'usuarios'];
    $_configItems[] = ['href' => 'controladores.php',  'icon' => '📡', 'label' => 'Controladores',  'id' => 'controladores'];
}
if ($_ehGestorHeader) {
    $_configItems[] = ['href' => 'empresas_lista.php', 'icon' => '🏢', 'label' => 'Empresas',       'id' => 'empresas_lista'];
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
    require_once __DIR__ . '/../app/helpers/Tenant.php';
    require_once __DIR__ . '/../app/services/EmpresaService.php';

    // Fallback: se a página pai não definiu controladoresAcessíveis mas tem PDO
    if ((!isset($controladoresAcessiveis) || empty($controladoresAcessiveis)) && isset($pdo)) {
        $filtroSql = \App\Helpers\Tenant::filtroSQL('c');
        $sqlCtrls = "
            SELECT c.id, c.codigo, c.apelido, c.empresa_id, c.online
              FROM controladores c
             WHERE c.status = 'ativo'
               {$filtroSql}
             ORDER BY c.codigo
        ";
        $params = [];
        \App\Helpers\Tenant::aplicarParam($params);
        $stmt = $pdo->prepare($sqlCtrls);
        $stmt->execute();
        $controladoresAcessiveis = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Busca todas as integradoras ativas (hoje só a Aeonium; preparado para múltiplas no futuro)
    $gruposPorEmpresa = [];
    if (isset($pdo) && !empty($controladoresAcessiveis)) {
        $stmtInteg = $pdo->prepare(
            "SELECT id, nome_fantasia FROM empresa WHERE is_integradora = 1 AND ativo = 1 ORDER BY id ASC"
        );
        $stmtInteg->execute();
        $integradoras = $stmtInteg->fetchAll(PDO::FETCH_ASSOC);

        // Monta mapa empresa_id -> nome da integradora raiz, usando a subárvore de cada integradora
        $empresaIdParaIntegradora = [];
        foreach ($integradoras as $integ) {
            $idsSubarvore = \App\Services\EmpresaService::subarvoreIds((int) $integ['id'], $pdo);
            foreach ($idsSubarvore as $empId) {
                $empresaIdParaIntegradora[$empId] = $integ['nome_fantasia'];
            }
        }

        // Agrupa os controladores pela integradora raiz (via mapa); fallback para "— Sem integradora —"
        foreach ($controladoresAcessiveis as $c) {
            $chave = $empresaIdParaIntegradora[(int) ($c['empresa_id'] ?? 0)] ?? '— Sem integradora —';
            if (!isset($gruposPorEmpresa[$chave])) {
                $gruposPorEmpresa[$chave] = [];
            }
            $gruposPorEmpresa[$chave][] = $c;
        }
    }

    $mostrarPill = isset($controladoresAcessiveis) && count($controladoresAcessiveis) > 0 && isset($controladorAtivo);
    if (isset($estadoA) && $estadoA && count($controladoresAcessiveis) > 1) $mostrarPill = false;
    ?>
    <?php if ($mostrarPill): ?>
        <div class="ctrl-global-wrapper" style="margin-right: 15px; display: flex; align-items: center; gap: 8px;">
            <?php
            // --- Semáforo derivado da telemetria (fonte da verdade) ---
            $idadePing = 999999;
            if (!empty($controladorAtivo['id'])) {
                $stmtPing = $pdo->prepare(
                    "SELECT TIMESTAMPDIFF(SECOND, MAX(timestamp_utc), UTC_TIMESTAMP()) AS idade
                     FROM telemetria_5min
                     WHERE controlador_id = :id"
                );
                $stmtPing->execute([':id' => (int) $controladorAtivo['id']]);
                $idade = $stmtPing->fetchColumn();
                if ($idade !== null && $idade !== false) {
                    $idadePing = (int) $idade;
                }
            }
            $semIcon = '🔴'; $semTitle = 'Offline';
            if ($idadePing < 300) { $semIcon = '🟢'; $semTitle = 'Online'; }
            elseif ($idadePing < 900) { $semIcon = '🟡'; $semTitle = 'Atrasado'; }
            ?>
            <span title="<?php echo $semTitle; ?>" style="font-size: 14px; cursor: help;"><?php echo $semIcon; ?></span>
            
            <?php if (count($controladoresAcessiveis) > 1): ?>
                <select id="sel-controlador-global" class="sel-ctrl" onchange="trocarControlador(this.value)" style="background: var(--color-surface, #fff); border: 1px solid var(--color-border, #e2e8f0); color: var(--color-text, #0f172a); padding: 6px 12px; border-radius: 20px; font-size: 14px; font-weight: 500; outline: none; cursor: pointer; min-width: 150px;">
                    <option value="" disabled>Selecione um controlador...</option>
                    <?php foreach ($gruposPorEmpresa as $nomeEmpresa => $controladoresDoGrupo): ?>
                        <optgroup label="<?php echo e($nomeEmpresa); ?>">
                            <?php foreach ($controladoresDoGrupo as $c): ?>
                                <option value="<?php echo $c['id']; ?>" <?php echo $c['id'] == $controladorAtivo['id'] ? 'selected' : ''; ?>
                                        title="<?php echo e($c['codigo'] . ($c['apelido'] ? ' — ' . $c['apelido'] : '')); ?>">
                                    <?php echo e($c['apelido'] ?: $c['codigo']); ?>
                                </option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
                <script>
                    function trocarControlador(novoId) {
                        if (!novoId) return;
                        const url = new URL(window.location.href);
                        url.searchParams.set('ctrl', novoId);
                        window.location.href = url.toString();
                    }
                </script>
            <?php else: ?>
                <div style="background: var(--color-surface, #fff); border: 1px solid var(--color-border, #e2e8f0); color: var(--color-text, #0f172a); padding: 6px 12px; border-radius: 20px; font-size: 14px; font-weight: 500; display: flex; align-items: center; gap: 8px;">
                    <span>📡</span>
                    <?php echo e($controladorAtivo['codigo'] . ($controladorAtivo['apelido'] ? ' — ' . $controladorAtivo['apelido'] : '')); ?>
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
               class="drawer-link <?php echo ($_paginaAtual === ($item['id'] ?? '') || ($item['id'] === 'empresas_lista' && $_paginaAtual === 'empresas')) ? 'active' : ''; ?>">
                <span class="drawer-icon"><?php echo $item['icon']; ?></span>
                <span><?php echo e($item['label']); ?></span>
            </a>
        </li>
        <?php endforeach; ?>
    </ul>
</nav>

<?php
if (!empty($_SESSION['usuario']['forcar_troca_senha']) || !empty($_SESSION['forcar_troca_senha'])):
?>
<!-- ═══════════════════════════════════════════════════════════
     MODAL BLOQUEANTE — Troca Obrigatória de Senha
════════════════════════════════════════════════════════════ -->
<div id="modalTrocaSenha" style="position:fixed;inset:0;background:rgba(0,0,0,.85);z-index:99999;display:flex;align-items:center;justify-content:center;backdrop-filter:blur(3px);">
  <div style="background:#10233f;padding:24px;border-radius:10px;max-width:380px;width:90%;box-shadow:0 12px 30px rgba(0,0,0,0.6);border:1px solid #1a3a60;color:#fff;font-family:inherit;">
    <h3 style="margin-top:0;margin-bottom:10px;font-size:18px;color:#fff;">Defina sua nova senha</h3>
    <p style="color:#b0c4de;font-size:13px;line-height:1.4;margin-bottom:16px;">Por segurança, você precisa cadastrar uma nova senha antes de continuar utilizando o sistema.</p>
    <form id="formTrocaSenha">
      <div style="margin-bottom:12px;">
        <label for="novaSenha" style="display:block;font-size:12px;color:#8ba3c7;margin-bottom:4px;">Nova Senha (mínimo 6 caracteres)</label>
        <input type="password" id="novaSenha" required minlength="6" autocomplete="new-password" style="width:100%;box-sizing:border-box;padding:10px 12px;border-radius:6px;border:1px solid #2a3f5f;background:#0d1526;color:#fff;font-size:14px;outline:none;">
      </div>
      <div style="margin-bottom:16px;">
        <label for="confirmaSenha" style="display:block;font-size:12px;color:#8ba3c7;margin-bottom:4px;">Confirmar Senha</label>
        <input type="password" id="confirmaSenha" required minlength="6" autocomplete="new-password" style="width:100%;box-sizing:border-box;padding:10px 12px;border-radius:6px;border:1px solid #2a3f5f;background:#0d1526;color:#fff;font-size:14px;outline:none;">
      </div>
      <button type="submit" id="btnSalvarNovaSenha" style="width:100%;padding:10px 16px;background:#00d26a;color:#0b192c;border:none;border-radius:6px;font-weight:600;font-size:14px;cursor:pointer;">Salvar e continuar</button>
    </form>
    <div id="erroTrocaSenha" style="color:#ff6b6b;margin-top:10px;font-size:13px;min-height:18px;"></div>
  </div>
</div>
<script>
(function() {
    const form = document.getElementById('formTrocaSenha');
    if (!form) return;
    form.addEventListener('submit', async function (e) {
        e.preventDefault();
        const novaSenha = document.getElementById('novaSenha').value;
        const confirmaSenha = document.getElementById('confirmaSenha').value;
        const erroDiv = document.getElementById('erroTrocaSenha');
        const btn = document.getElementById('btnSalvarNovaSenha');

        erroDiv.textContent = '';

        if (novaSenha !== confirmaSenha) {
            erroDiv.textContent = 'As senhas não coincidem.';
            return;
        }

        if (novaSenha.length < 6) {
            erroDiv.textContent = 'A senha deve ter no mínimo 6 caracteres.';
            return;
        }

        btn.disabled = true;
        btn.textContent = 'Salvando...';

        try {
            const resp = await fetch('<?php echo e(appUrl("/api/usuarios/trocar_senha.php")); ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ senha: novaSenha })
            });
            const data = await resp.json();
            if (data && data.sucesso) {
                location.reload();
            } else {
                erroDiv.textContent = (data && data.erro) ? data.erro : 'Erro ao trocar senha.';
                btn.disabled = false;
                btn.textContent = 'Salvar e continuar';
            }
        } catch (err) {
            erroDiv.textContent = 'Erro ao se comunicar com o servidor.';
            btn.disabled = false;
            btn.textContent = 'Salvar e continuar';
        }
    });
})();
</script>
<?php endif; ?>

