<?php
/**
 * @arquivo       limites.php
 * @versao        2.1.0
 * @modificado_em 2026-07-25
 * @objetivo      UI de limites de injeção (24h × 3 tipos de dia) com abas,
 *                seleção múltipla e preenchimento em massa; grava curva em kW
 *                com snapshot de potência instalada. Multi-tenant + badge sync.
 *                Troca de controlador via seletor único do cabeçalho.
 * @autor         Fernando / CIP Cloud Copilot / ATGY
 */
declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/helpers/Tenant.php';

$pdo = getDbConnection();
requireAuth();

use app\helpers\Tenant;
$ctx = Tenant::contexto();
$filtroSql = Tenant::filtroSQL('c');

$sqlCtrls = "
    SELECT c.id, c.codigo, c.apelido, c.empresa_id, c.online,
           e.nome_fantasia AS empresa_nome
      FROM controladores c
      LEFT JOIN empresa e
             ON e.id = c.empresa_id
            AND e.deleted_at IS NULL
     WHERE c.status = 'ativo'
       {$filtroSql}
     ORDER BY e.nome_fantasia IS NULL, e.nome_fantasia, c.codigo
";
$params = [];
Tenant::aplicarParam($params);
$stmt = $pdo->prepare($sqlCtrls);
$stmt->execute($params);
$controladoresAcessiveis = $stmt->fetchAll(PDO::FETCH_ASSOC);

$ctrlSolicitado = filter_input(INPUT_GET, 'ctrl', FILTER_VALIDATE_INT) ?: null;
$ctrlPadrao     = isset($_SESSION['controlador_padrao']) ? (int) $_SESSION['controlador_padrao'] : null;
$controladorAtivo = null;

if ($ctrlSolicitado !== null) {
    foreach ($controladoresAcessiveis as $c) {
        if ((int) $c['id'] === $ctrlSolicitado) { $controladorAtivo = $c; break; }
    }
}
if ($controladorAtivo === null && $ctrlPadrao !== null) {
    foreach ($controladoresAcessiveis as $c) {
        if ((int) $c['id'] === $ctrlPadrao) { $controladorAtivo = $c; break; }
    }
}
if ($controladorAtivo === null && !empty($controladoresAcessiveis)) {
    $controladorAtivo = $controladoresAcessiveis[0];
}
if ($controladorAtivo !== null) {
    $_SESSION['controlador_padrao'] = (int) $controladorAtivo['id'];
}
$estadoA = ($controladorAtivo === null && count($controladoresAcessiveis) > 1);

$appTituloPagina = 'Limites de Potência';
$appPaginaAtual  = 'limites';
$appUsuarioNome  = $_SESSION['usuario_nome']  ?? 'Usuário';
$appIsAdmin      = in_array($_SESSION['usuario_perfil'] ?? '', ['master', 'master_operador', 'administrador'], true);
?>
<!DOCTYPE html>
<html lang="pt-BR" data-tema="escuro">
<head>
  <?php require __DIR__ . '/includes/app_head.php'; ?>
  <style>
    :root {
      --bg:      #070b14;
      --card:    #0d1526;
      --border:  #1a2d4a;
      --txt:     #e0eaf8;
      --txt-dim: #3a5070;
      --txt-mut: #7a9cc4;
      --btn-bg:  #0070cc;
      --btn-hover: #005aaa;
      --amber:   #f59e0b;
      --amber-bg:#fffbeb;
      --red:     #ef4444;
      --green:   #10b981;
      --blue:    #3b82f6;
      --violet:  #8b5cf6;
      
      --aba-dias-uteis: #10b981;
      --aba-sabado: #3b82f6;
      --aba-domingo: #8b5cf6;
    }
    
    html[data-tema="claro"] {
      --bg:      #f0f4f8;
      --card:    #ffffff;
      --border:  #d0dce8;
      --txt:     #1a2d4a;
      --txt-dim: #7a96b0;
      --txt-mut: #4a6080;
    }

    body { background: var(--bg); color: var(--txt); font-family: 'Segoe UI', system-ui, sans-serif; font-size: 14px; margin: 0; padding: 0; transition: background .3s, color .3s; }
    .wrap { max-width: 1440px; margin: 0 auto; padding: 80px 24px 40px; }
    
    .card-limites { background: var(--card); border: 1px solid var(--border); border-radius: 16px; padding: 24px; margin-bottom: 20px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
    
    .card-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 24px; }
    .card-title-area h2 { font-size: 20px; font-weight: 700; margin: 0 0 8px 0; color: var(--txt); }
    .potencia-info { font-size: 13px; color: var(--txt-mut); display: flex; align-items: center; gap: 6px; }
    .potencia-info svg { width: 16px; height: 16px; color: var(--amber); }
    
    .top-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
    .ctrl-context-badge { padding: 10px 16px; border-radius: 10px; border: 1px solid var(--border); background: var(--card); color: var(--txt-mut); font-weight: 600; font-size: 14px; display: flex; align-items: center; gap: 8px; }
    .ctrl-context-badge strong { color: var(--txt); }
    
    /* Abas */
    .tabs { display: flex; gap: 8px; margin-bottom: 20px; border-bottom: 1px solid var(--border); padding-bottom: 0; }
    .tab-btn { background: transparent; border: none; padding: 12px 24px; font-size: 14px; font-weight: 600; color: var(--txt-dim); cursor: pointer; position: relative; transition: color 0.2s; }
    .tab-btn:hover { color: var(--txt); }
    .tab-btn.active { color: var(--txt); }
    .tab-btn::after { content: ''; position: absolute; bottom: -1px; left: 0; width: 100%; height: 3px; border-radius: 3px 3px 0 0; transform: scaleX(0); transition: transform 0.2s; }
    .tab-btn.active::after { transform: scaleX(1); }
    
    .tab-btn[data-tab="dias_uteis"].active::after { background: var(--aba-dias-uteis); }
    .tab-btn[data-tab="sabado"].active::after { background: var(--aba-sabado); }
    .tab-btn[data-tab="domingo_feriado"].active::after { background: var(--aba-domingo); }
    
    /* Toolbar */
    .toolbar { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 24px; align-items: center; background: rgba(0,0,0,0.1); padding: 12px 16px; border-radius: 10px; }
    .toolbar-group { display: flex; align-items: center; gap: 8px; }
    .toolbar-divider { width: 1px; height: 24px; background: var(--border); margin: 0 4px; }
    .input-mass { width: 90px; padding: 8px 12px; border-radius: 8px; border: 1px solid var(--border); background: var(--bg); color: var(--txt); text-align: right; }
    .btn-tool { background: var(--card); border: 1px solid var(--border); color: var(--txt); padding: 8px 16px; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; transition: all 0.2s; }
    .btn-tool:hover { background: var(--border); }
    .btn-tool.primary { background: var(--btn-bg); color: #fff; border-color: var(--btn-bg); }
    .btn-tool.primary:hover { background: var(--btn-hover); }
    
    /* Grid de Horários */
    .grid-horas { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 12px; margin-bottom: 24px; }
    .hora-card { background: var(--bg); border: 1px solid var(--border); border-radius: 10px; padding: 12px; cursor: pointer; transition: all 0.2s; user-select: none; position: relative; overflow: hidden; }
    .hora-card:hover { border-color: var(--txt-dim); }
    .hora-card.selected { border-color: var(--btn-bg); box-shadow: 0 0 0 1px var(--btn-bg); }
    
    .hora-card.warn { border-color: var(--amber); }
    .hora-card.warn::before { content: '!'; position: absolute; top: 8px; right: 8px; width: 18px; height: 18px; background: var(--amber); color: #fff; border-radius: 50%; font-size: 12px; font-weight: bold; display: flex; align-items: center; justify-content: center; }
    
    .hora-label { font-size: 12px; color: var(--txt-mut); font-weight: 600; margin-bottom: 8px; }
    .hora-input-wrap { position: relative; }
    .hora-input-wrap::after { content: 'kW'; position: absolute; right: 10px; top: 50%; transform: translateY(-50%); font-size: 12px; color: var(--txt-dim); pointer-events: none; }
    .hora-input { width: 100%; padding: 8px 30px 8px 12px; border-radius: 6px; border: 1px solid var(--border); background: var(--card); color: var(--txt); text-align: left; font-size: 15px; font-weight: 600; transition: border-color 0.2s; outline: none; }
    .hora-input:focus { border-color: var(--btn-bg); }
    .hora-card.warn .hora-input { border-color: var(--amber); color: var(--amber); }
    
    /* Badges */
    .badge { padding: 4px 12px; border-radius: 12px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
    .badge.sincronizada { background: rgba(16,185,129,0.15); color: var(--green); }
    .badge.pendente_ack { background: rgba(245,158,11,0.15); color: var(--amber); }
    .badge.timeout { background: rgba(239,68,68,0.15); color: var(--red); }
    .badge.divergente { background: rgba(245,158,11,0.15); color: var(--amber); }
    
    .actions-footer { display: flex; justify-content: flex-end; align-items: center; gap: 16px; border-top: 1px solid var(--border); padding-top: 20px; }
    .btn-salvar { background: var(--btn-bg); color: #fff; border: none; padding: 12px 24px; border-radius: 10px; font-weight: 600; font-size: 14px; cursor: pointer; transition: background 0.2s; }
    .btn-salvar:hover { background: var(--btn-hover); }
    .btn-salvar:disabled { opacity: 0.6; cursor: not-allowed; }
    .status-msg { font-size: 14px; font-weight: 600; }
    
    .tab-content { display: none; }
    .tab-content.active { display: block; }

    /* Tooltip */
    [data-tooltip] { position: relative; }
    [data-tooltip]:hover::after { content: attr(data-tooltip); position: absolute; bottom: 100%; left: 50%; transform: translateX(-50%); margin-bottom: 8px; padding: 6px 10px; background: #000; color: #fff; font-size: 12px; font-weight: 400; border-radius: 6px; white-space: nowrap; z-index: 10; pointer-events: none; opacity: 0.9; }
  </style>
</head>
<body>

<?php require __DIR__ . '/includes/app_header.php'; ?>

<div class="wrap">
        <div class="top-bar">
            <h2>Gestão de Limites de Potência</h2>
            <?php if ($controladorAtivo): ?>
              <div class="ctrl-context-badge" title="Para trocar de controlador, use o seletor no cabeçalho">
                📟 Editando: <strong><?= htmlspecialchars($controladorAtivo['apelido'] ?: $controladorAtivo['codigo']) ?></strong>
              </div>
            <?php endif; ?>
        </div>

        <?php if ($estadoA): ?>
            <div class="card-limites"><p>Selecione um controlador acima para continuar.</p></div>
        <?php elseif ($controladorAtivo): ?>
            <div class="card-limites">
                <div class="card-header">
                    <div class="card-title-area">
                        <h2>Curva de Injeção <span id="badge-sync" class="badge">Carregando...</span></h2>
                        <div class="potencia-info" id="potencia-info-container">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg>
                            Potência instalada: <strong id="lbl-potencia">0,00 kW</strong>
                        </div>
                    </div>
                </div>

                <div class="tabs">
                    <button class="tab-btn active" data-tab="dias_uteis">Dias Úteis</button>
                    <button class="tab-btn" data-tab="sabado">Sábado</button>
                    <button class="tab-btn" data-tab="domingo_feriado">Domingo/Feriado</button>
                </div>

                <div class="toolbar">
                    <div class="toolbar-group">
                        <input type="number" id="mass-val" class="input-mass" step="0.1" min="0" placeholder="0.0">
                        <button id="btn-aplicar-sel" class="btn-tool primary">Aplicar nos selecionados</button>
                        <button id="btn-preencher-todos" class="btn-tool">Preencher todos</button>
                    </div>
                    <div class="toolbar-divider"></div>
                    <div class="toolbar-group">
                        <button id="btn-sel-todos" class="btn-tool">Sel. todos</button>
                        <button id="btn-limpar-sel" class="btn-tool">Limpar sel.</button>
                        <button id="btn-zero" class="btn-tool">Grid zero</button>
                    </div>
                </div>

                <!-- Abas Conteúdo -->
                <div id="tab-dias_uteis" class="tab-content active">
                    <div class="grid-horas" id="grid-dias_uteis"></div>
                </div>
                <div id="tab-sabado" class="tab-content">
                    <div class="grid-horas" id="grid-sabado"></div>
                </div>
                <div id="tab-domingo_feriado" class="tab-content">
                    <div class="grid-horas" id="grid-domingo_feriado"></div>
                </div>

                <div class="actions-footer">
                    <div id="save-msg" class="status-msg"></div>
                    <button id="btn-salvar" class="btn-salvar">Salvar Configurações</button>
                </div>
            </div>
        <?php else: ?>
            <div class="card-limites"><p>Nenhum controlador disponível.</p></div>
        <?php endif; ?>
</div>

<script>
const CTRL_ID = <?= $controladorAtivo ? (int)$controladorAtivo['id'] : 'null' ?>;
const TABS = ['dias_uteis', 'sabado', 'domingo_feriado'];
let currentTab = 'dias_uteis';
let globalPotenciaMax = 7.0; // fallback inicial
let currentData = {
    dias_uteis: new Array(24).fill(0),
    sabado: new Array(24).fill(0),
    domingo_feriado: new Array(24).fill(0)
};

document.addEventListener('DOMContentLoaded', () => {
    if (!CTRL_ID) return;
    
    initUI();
    loadLimites();
    
    document.getElementById('btn-salvar').addEventListener('click', saveLimites);
    
    // Setup tabs
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            
            btn.classList.add('active');
            currentTab = btn.getAttribute('data-tab');
            document.getElementById('tab-' + currentTab).classList.add('active');
        });
    });
    
    // Setup Toolbar
    document.getElementById('btn-aplicar-sel').addEventListener('click', () => {
        const val = parseFloat(document.getElementById('mass-val').value);
        if (isNaN(val) || val < 0) return;
        
        document.querySelectorAll(`#grid-${currentTab} .hora-card.selected .hora-input`).forEach(inp => {
            inp.value = val;
            triggerInput(inp);
        });
    });
    
    document.getElementById('btn-preencher-todos').addEventListener('click', () => {
        const val = parseFloat(document.getElementById('mass-val').value);
        if (isNaN(val) || val < 0) return;
        
        document.querySelectorAll(`#grid-${currentTab} .hora-input`).forEach(inp => {
            inp.value = val;
            triggerInput(inp);
        });
    });
    
    document.getElementById('btn-zero').addEventListener('click', () => {
        document.querySelectorAll(`#grid-${currentTab} .hora-input`).forEach(inp => {
            inp.value = 0;
            triggerInput(inp);
        });
    });
    
    document.getElementById('btn-sel-todos').addEventListener('click', () => {
        document.querySelectorAll(`#grid-${currentTab} .hora-card`).forEach(card => card.classList.add('selected'));
    });
    
    document.getElementById('btn-limpar-sel').addEventListener('click', () => {
        document.querySelectorAll(`#grid-${currentTab} .hora-card`).forEach(card => card.classList.remove('selected'));
    });
});

function initUI() {
    TABS.forEach(tab => {
        const grid = document.getElementById('grid-' + tab);
        let html = '';
        for (let i = 0; i < 24; i++) {
            const hrLabel = i.toString().padStart(2, '0') + ':00';
            html += `
                <div class="hora-card" data-hr="${i}">
                    <div class="hora-label">${hrLabel}</div>
                    <div class="hora-input-wrap" data-tooltip="">
                        <input type="number" step="0.1" min="0" class="hora-input" data-col="${tab}" data-hr="${i}" value="0.0">
                    </div>
                </div>
            `;
        }
        grid.innerHTML = html;
    });
    
    // Delegar eventos para os inputs e cards
    document.querySelectorAll('.hora-card').forEach(card => {
        card.addEventListener('click', (e) => {
            if (e.target.tagName !== 'INPUT') {
                card.classList.toggle('selected');
            }
        });
    });
    
    document.querySelectorAll('.hora-input').forEach(inp => {
        inp.addEventListener('input', (e) => handleValidation(e.target));
        inp.addEventListener('change', (e) => {
            if (e.target.value === '') e.target.value = 0;
            handleValidation(e.target);
        });
    });
}

function triggerInput(el) {
    el.dispatchEvent(new Event('input'));
    el.dispatchEvent(new Event('change'));
}

function handleValidation(inp) {
    const val = parseFloat(inp.value) || 0;
    const card = inp.closest('.hora-card');
    const wrap = inp.closest('.hora-input-wrap');
    
    if (val > globalPotenciaMax) {
        card.classList.add('warn');
        wrap.setAttribute('data-tooltip', `Acima da potência instalada (${globalPotenciaMax.toLocaleString('pt-BR', {minimumFractionDigits:2})} kW) — limitado fisicamente pelo inversor`);
    } else {
        card.classList.remove('warn');
        wrap.removeAttribute('data-tooltip');
    }
}

async function loadLimites() {
    try {
        const res = await fetch(`/api/limites/tabela.php?controlador_id=${CTRL_ID}`);
        if (!res.ok) throw new Error('Erro na requisição');
        const json = await res.json();
        if (json.sucesso && json.data) {
            const p = json.data.payload;
            globalPotenciaMax = json.data.potencia_max_kw || 7.0;
            
            document.getElementById('lbl-potencia').textContent = globalPotenciaMax.toLocaleString('pt-BR', {minimumFractionDigits: 2}) + ' kW';
            
            TABS.forEach(col => {
                if (p[col]) {
                    p[col].forEach((val, i) => {
                        const input = document.querySelector(`.hora-input[data-col="${col}"][data-hr="${i}"]`);
                        if (input) {
                            input.value = val;
                            handleValidation(input);
                        }
                    });
                }
            });
            updateBadge(json.data.sync_status);
        }
    } catch (e) {
        console.error(e);
        document.getElementById('badge-sync').textContent = 'Erro ao carregar';
        document.getElementById('badge-sync').className = 'badge timeout';
    }
}

async function saveLimites() {
    const btn = document.getElementById('btn-salvar');
    const msg = document.getElementById('save-msg');
    btn.disabled = true;
    msg.textContent = 'Salvando...';
    msg.style.color = 'inherit';
    
    const payload = {
        potencia_max_kw: globalPotenciaMax,
        dias_uteis: new Array(24).fill(0),
        sabado: new Array(24).fill(0),
        domingo_feriado: new Array(24).fill(0)
    };
    
    document.querySelectorAll('.hora-input').forEach(input => {
        const col = input.getAttribute('data-col');
        const hr = parseInt(input.getAttribute('data-hr'), 10);
        payload[col][hr] = parseFloat(input.value) || 0;
    });
    
    try {
        const res = await fetch(`/api/limites/tabela.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ controlador_id: CTRL_ID, payload: payload })
        });
        const json = await res.json();
        
        if (json.sucesso) {
            msg.textContent = 'Salvo com sucesso!';
            msg.style.color = 'var(--green)';
            updateBadge(json.data.sync_status);
        } else {
            msg.textContent = 'Erro: ' + (json.erro || 'Desconhecido');
            msg.style.color = 'var(--red)';
        }
    } catch (e) {
        msg.textContent = 'Erro de rede';
        msg.style.color = 'var(--red)';
    }
    
    setTimeout(() => { btn.disabled = false; msg.textContent = ''; }, 3000);
}

function updateBadge(status) {
    const badge = document.getElementById('badge-sync');
    let label = status.replace('_', ' ').toUpperCase();
    badge.textContent = label;
    badge.className = 'badge ' + status;
}
</script>
</body>
</html>
