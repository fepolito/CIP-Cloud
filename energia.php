<?php
/**
 * =============================================================================
 * Projeto    : CIP - Controlador de Injecao de Potencia Eletrica
 * Arquivo    : public_html/energia.php
 * Objetivo   : Monitoramento de energia — importacao, geracao, consumo e
 *              exportacao com graficos interativos ApexCharts em 4 modos:
 *              DIA (potencia — linhas continuas) | MES (kWh diarios) |
 *              ANO (kWh mensais)                 | TOTAL (kWh anuais historico)
 * Dependencias de hardware:
 *   - Servidor com MySQL/MariaDB acessivel via localhost:3306
 *   - Navegador com suporte a HTML5, CSS3 e JavaScript
 *   - Controlador CIP-ESP32S3
 * Dependencias de software:
 *   - PHP 7.4+
 *   - ApexCharts 3.44.0 (CDN — cdn.jsdelivr.net)
 *   - includes/app_head.php       (meta, links, shell CSS/JS)
 *   - includes/app_header.php     (nav, sidebar, loading overlay)
 *   - app/auth.php                (autenticacao de sessao)
 *   - api/energia/dia.php         (dados horarios  — modo DIA)
 *   - api/energia/mes.php         (dados diarios   — modo MES)
 *   - api/energia/ano.php         (dados mensais   — modo ANO)
 *   - api/energia/anos.php        (dados anuais    — modo TOTAL)
 * Historico de implementacoes:
 *   - 2026-04-11 | v1.0 | Criacao inicial
 *   - 2026-04-11 | v1.1 | Integracao app_header.php
 *   - 2026-04-11 | v1.2 | Renomeacao compensacao->geracao
 *   - 2026-04-11 | v1.3 | Calculo consumo total no frontend
 *   - 2026-04-11 | v2.0 | Multimodo DIA|MES|ANO|TOTAL
 *   - 2026-04-12 | v2.1 | Navegacao setas, tema claro/escuro, cards abaixo
 *   - 2026-04-12 | v2.2 | Legenda no rodape, sem botao atualizar,
 *                          eixo X fixo MES(30d) e ANO(12m), zoom toolbar
 *                          reposicionado, datepicker no toque da data (DIA)
 *   - 2026-04-14 | v2.3 | Modo DIA alterado para 4 linhas continuas
 *                          (suporte a qualquer resolucao: 5min, 15min, 1h)
 *                          Eixo X DIA permanece categorias fixas (LABELS_24H)
 *                          para manter navegacao de datas funcional.
 *                          Barras mantidas apenas em MES/ANO/TOTAL.
 *   - 2026-05-18 | v2.4 | [REFACTOR] Removidos botao #btn-tema local,
 *                          funcoes aplicarTema/toggleTema e estilos do tema
 *                          local. Tema agora e GLOBAL via includes/app_header.php
 *                          + assets/js/tema.js. Chart registrado em window.CipTema
 *                          para sincronia automatica de modo dark/light.
 * =============================================================================
 */
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/helpers/Tenant.php';
//require_once __DIR__ . '/app/auth.php';
//require_once __DIR__ . '/app/helpers/Tenant.php';   // 👈 NOVO
requireAuth();
use app\helpers\Tenant;


// ── Conexao unica ────────────────────────────────────────────────────────────
$pdo = getDbConnection();

// 🆕 Busca controladores que o usuário pode ver (FASE 1 validada)
$ctx       = Tenant::contexto();
$filtroSql = Tenant::filtroSQL('c');




// 👑 Permissoes administrativas
$appIsMaster        = Tenant::ehGlobal();
$appIsAdminLocal    = ($ctx['perfil'] === 'administrador');
$appPodeAdministrar = $appIsMaster || $appIsAdminLocal;

// ── Lista controladores acessiveis (respeitando tenant) ──────────────────────
// Master ve todos; usuario comum ve so da sua empresa.
$filtro = Tenant::filtroSQL('c');

$sqlCtrls = "
    SELECT c.id, c.codigo, c.apelido, c.empresa_id,
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
$stmt->execute();
$controladoresAcessiveis = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 🎯 Define o controlador "ativo" da página:
//    prioridade: ?ctrl=X na URL → controlador_padrao da sessão → primeiro da lista
$ctrlSolicitado = isset($_GET['ctrl']) ? (int)$_GET['ctrl'] : null;
$ctrlPadrao     = $_SESSION['controlador_padrao'] ?? null;

$controladorAtivo = null;
foreach ($controladoresAcessiveis as $c) {
    if ($ctrlSolicitado && $c['id'] == $ctrlSolicitado) { $controladorAtivo = $c; break; }
}
if (!$controladorAtivo && $ctrlPadrao) {
    foreach ($controladoresAcessiveis as $c) {
        if ($c['id'] == $ctrlPadrao) { $controladorAtivo = $c; break; }
    }
}
if (!$controladorAtivo && !empty($controladoresAcessiveis)) {
    $controladorAtivo = $controladoresAcessiveis[0];
}

// 🛡️ Sem acesso a nenhum controlador? Trata o caso.
if (!$controladorAtivo) {
    $semControlador = true;
}
// 🕐 Timezone do controlador (para uso no frontend)
// Prioridade: campo 'timezone' do controlador → 'America/Sao_Paulo' default
$timezoneControlador = $controladorAtivo['timezone'] ?? 'America/Sao_Paulo';

$appTituloPagina     = 'Energia';
$appPaginaAtual      = 'energia';
$appUsuarioNome      = $_SESSION['usuario_nome']   ?? 'Usuário';
$appEmpresaNome      = 'Controlador de Injeção de Potência Elétrica';
$appEmpresaLogoTexto = 'CI';
$appIsAdmin = in_array($_SESSION['usuario_perfil'] ?? '', [
    'master', 'master_operador', 'administrador'
], true);
?>
<!DOCTYPE html>
<html lang="pt-BR" data-tema="escuro">
<head>
  <?php require __DIR__ . '/includes/app_head.php'; ?>
  <script src="https://cdn.jsdelivr.net/npm/apexcharts@3.44.0/dist/apexcharts.min.js"></script>
  <style>
    /* ══════════════════════════════════════════════
       TOKENS — TEMA ESCURO (padrão)
    ══════════════════════════════════════════════ */
    :root {
      --bg:      #070b14;
      --card:    #0d1526;
      --border:  #1a2d4a;
      --blue:    #00b4ff;
      --blue2:   #0070cc;
      --green:   #00e676;
      --yellow:  #ffc107;
      --orange:  #ff9800;
      --red:     #ff5252;
      --purple:  #ce93d8;
      --cyan:    #18ffff;
      --txt:     #e0eaf8;
      --txt-mid: #7a9cc4;
      --txt-dim: #3a5070;
      --shadow:  rgba(0,0,0,.4);
    }

    /* ── TEMA CLARO ──────────────────────────────── */
    html[data-tema="claro"] {
      --bg:      #f0f4f8;
      --card:    #ffffff;
      --border:  #d0dce8;
      --blue:    #0070cc;
      --blue2:   #005aaa;
      --green:   #00a854;
      --yellow:  #e6a800;
      --orange:  #e07000;
      --red:     #d63030;
      --purple:  #8e44ad;
      --cyan:    #0097a7;
      --txt:     #1a2d4a;
      --txt-mid: #4a6080;
      --txt-dim: #7a96b0;
      --shadow:  rgba(0,0,0,.12);
    }

    *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
    html { scroll-behavior: smooth; height: 100%; }
    body {
      background: var(--bg);
      color: var(--txt);
      font-family: 'Segoe UI', system-ui, sans-serif;
      font-size: 14px;
      min-height: 100%;
      overflow-x: hidden;
      overflow-y: auto;
      -webkit-text-size-adjust: 100%;
      transition: background .3s, color .3s;
    }
    body.app-sidebar-open { overflow: hidden; }
    ::-webkit-scrollbar       { width: 6px; }
    ::-webkit-scrollbar-track { background: var(--bg); }
    ::-webkit-scrollbar-thumb { background: var(--border); border-radius: 3px; }

    .wrap {
      max-width: 1440px;
      margin: 0 auto;
      padding: 80px 24px 40px;
    }

    /* ══════════════════════════════════════════════
       TOOLBAR
    ══════════════════════════════════════════════ */
    .toolbar {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 12px;
      margin-bottom: 20px;
    }
    .ctrl-info {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 10px;
      padding: 10px 16px;
      flex: 1 1 auto;
      min-width: 0;
      box-shadow: 0 2px 8px var(--shadow);
    }
    .ctrl-info .label {
      color: var(--txt-dim);
      font-size: 10px;
      font-weight: 700;
      letter-spacing: 1.5px;
      text-transform: uppercase;
      margin-bottom: 4px;
    }
    .ctrl-info .nome {
      color: var(--txt);
      font-size: 14px;
      font-weight: 700;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .periodo-group {
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
      flex-shrink: 0;
    }

    /* ── Seletor de modo ─────────────────────────── */
    .modo-btns {
      display: flex;
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 10px;
      overflow: hidden;
      box-shadow: 0 2px 8px var(--shadow);
    }
    .mb {
      background: transparent;
      border: none;
      border-right: 1px solid var(--border);
      color: var(--txt-mid);
      cursor: pointer;
      font-size: 11px;
      font-weight: 700;
      letter-spacing: 1px;
      padding: 10px 14px;
      transition: all .2s;
      touch-action: manipulation;
      -webkit-tap-highlight-color: transparent;
    }
    .mb:last-child { border-right: none; }
    .mb:hover      { color: var(--blue); }
    .mb.ativo      { background: var(--blue2); color: #fff; }

    /* ── Toggle tema ──────────────────────────────── */
    .btn-tema {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 10px;
      color: var(--txt-mid);
      cursor: pointer;
      font-size: 18px;
      padding: 8px 12px;
      line-height: 1;
      transition: all .2s;
      box-shadow: 0 2px 8px var(--shadow);
    }
    .btn-tema:hover { border-color: var(--blue); color: var(--blue); }

    /* ── Input month ──────────────────────────────── */
    .inp-data {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 10px;
      color: var(--txt);
      font-size: 13px;
      font-weight: 700;
      padding: 10px 14px;
      cursor: pointer;
      transition: border-color .2s;
      outline: none;
      box-shadow: 0 2px 8px var(--shadow);
    }
    .inp-data:focus { border-color: var(--blue); }
    .inp-data::-webkit-calendar-picker-indicator { filter: invert(.5); cursor: pointer; }

    /* ── Select de ano ────────────────────────────── */
    .sel-ano {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 10px;
      color: var(--txt);
      font-size: 13px;
      font-weight: 700;
      padding: 10px 32px 10px 14px;
      cursor: pointer;
      outline: none;
      transition: border-color .2s;
      appearance: none;
      -webkit-appearance: none;
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%237a9cc4' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
      background-repeat: no-repeat;
      background-position: right 12px center;
      box-shadow: 0 2px 8px var(--shadow);
    }
    .sel-ano:focus { border-color: var(--blue); }

    /* ══════════════════════════════════════════════
       NAVEGAÇÃO DIA (◀ DATA ▶)
    ══════════════════════════════════════════════ */
    .nav-dia {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      margin-bottom: 12px;
    }
    .nav-dia-seta {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 8px;
      color: var(--txt-mid);
      cursor: pointer;
      font-size: 20px;
      width: 38px;
      height: 38px;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: all .2s;
      box-shadow: 0 2px 6px var(--shadow);
      touch-action: manipulation;
      -webkit-tap-highlight-color: transparent;
      flex-shrink: 0;
      line-height: 1;
      padding: 0;
    }
    .nav-dia-seta:hover:not(:disabled) {
      border-color: var(--blue);
      color: var(--blue);
      background: rgba(0,112,204,.08);
    }
    .nav-dia-seta:disabled { opacity: .3; cursor: not-allowed; }

    .nav-dia-data-wrap {
      position: relative;
      display: inline-block;
    }
    .nav-dia-data {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 8px;
      color: var(--txt);
      font-size: 14px;
      font-weight: 700;
      padding: 8px 16px;
      min-width: 130px;
      text-align: center;
      box-shadow: 0 2px 6px var(--shadow);
      letter-spacing: .5px;
      cursor: pointer;
      user-select: none;
      transition: border-color .2s;
    }
    .nav-dia-data:hover { border-color: var(--blue); }

    #inp-dia {
      position: absolute;
      inset: 0;
      opacity: 0;
      width: 100%;
      height: 100%;
      cursor: pointer;
      border: none;
      padding: 0;
    }
    #inp-dia::-webkit-calendar-picker-indicator {
      position: absolute;
      inset: 0;
      width: 100%;
      height: 100%;
      opacity: 0;
      cursor: pointer;
    }

    /* ══════════════════════════════════════════════
       CHART CARD
    ══════════════════════════════════════════════ */
    .ccard {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 14px;
      padding: 18px 20px 10px;
      overflow: hidden;
      margin-bottom: 16px;
      box-shadow: 0 2px 12px var(--shadow);
    }
    .ccard-head {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 6px;
      margin-bottom: 4px;
    }
    .ccard-title {
      color: var(--txt);
      font-size: 13px;
      font-weight: 700;
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
    }
    .badge {
      background: rgba(0,112,204,.1);
      border: 1px solid rgba(0,112,204,.2);
      border-radius: 6px;
      color: var(--blue);
      font-size: 10px;
      font-weight: 700;
      letter-spacing: 1px;
      padding: 2px 8px;
    }
    .badge.green { background: rgba(0,168,84,.1); border-color: rgba(0,168,84,.2); color: var(--green); }
    .hint {
      color: var(--txt-dim);
      font-size: 10px;
      letter-spacing: .5px;
    }
    .sem-dados {
      display: none;
      text-align: center;
      padding: 40px 20px;
      color: var(--txt-dim);
      font-size: 13px;
    }
    .sem-dados.visivel { display: block; }

    /* ══════════════════════════════════════════════
       KPI GRID
    ══════════════════════════════════════════════ */
    .kpi-grid {
      display: grid;
      grid-template-columns: repeat(5, 1fr);
      gap: 10px;
      margin-bottom: 14px;
    }
    .kpi {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 10px;
      padding: 12px 12px 10px;
      position: relative;
      overflow: hidden;
      transition: transform .2s, border-color .2s;
      box-shadow: 0 2px 8px var(--shadow);
    }
    .kpi:hover { transform: translateY(-2px); border-color: var(--kc, var(--blue)); }
    .kpi::after {
      content: '';
      position: absolute;
      bottom: 0; left: 0; right: 0;
      height: 2px;
      background: var(--kc, var(--blue));
    }
    .kpi-lbl {
      color: var(--txt-mid);
      font-size: 9px;
      font-weight: 700;
      letter-spacing: 1.2px;
      text-transform: uppercase;
      margin-bottom: 6px;
    }
    .kpi-val {
      font-size: clamp(14px, 2.5vw, 20px);
      font-weight: 800;
      line-height: 1;
      color: var(--txt);
    }
    .kpi-val .unit {
      font-size: 11px;
      font-weight: 400;
      color: var(--txt-mid);
      margin-left: 2px;
    }
    .kpi-sub { color: var(--txt-dim); font-size: 10px; margin-top: 6px; }
    .kpi-icon { position: absolute; top: 10px; right: 10px; font-size: 18px; opacity: .10; }

    /* ══════════════════════════════════════════════
       TOTALIZADORES
    ══════════════════════════════════════════════ */
    .tot-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 14px;
      margin-bottom: 20px;
    }
    .tot-card {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 14px;
      padding: 16px;
      box-shadow: 0 2px 8px var(--shadow);
    }
    .tot-title {
      font-size: 12px;
      font-weight: 700;
      color: var(--txt-mid);
      letter-spacing: .5px;
      margin-bottom: 12px;
      padding-bottom: 10px;
      border-bottom: 1px solid var(--border);
    }
    .tot-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 5px 0;
      border-bottom: 1px solid rgba(26,45,74,.3);
      font-size: 12px;
    }
    html[data-tema="claro"] .tot-row { border-bottom-color: rgba(208,220,232,.6); }
    .tot-row:last-child { border-bottom: none; }
    .tot-k { color: var(--txt-mid); }
    .tot-v { color: var(--txt); font-weight: 700; }

    /* ══════════════════════════════════════════════
       FOOTER
    ══════════════════════════════════════════════ */
    .footer {
      text-align: center;
      color: var(--txt-dim);
      font-size: 11px;
      padding-top: 20px;
      border-top: 1px solid var(--border);
    }

    /* ══════════════════════════════════════════════
       RESPONSIVO
    ══════════════════════════════════════════════ */
    @media (max-width: 1200px) { .kpi-grid { grid-template-columns: repeat(3, 1fr); } }
    @media (max-width: 1024px) {
      .kpi-grid { grid-template-columns: repeat(3, 1fr); }
      .tot-grid { grid-template-columns: repeat(2, 1fr); }
    }
    @media (max-width: 768px) {
      .wrap { padding: 72px 14px 32px; }
      .toolbar { flex-direction: column; gap: 10px; }
      .ctrl-info, .periodo-group { width: 100%; }
      .kpi-grid { grid-template-columns: repeat(3, 1fr); gap: 8px; }
      .tot-grid { grid-template-columns: 1fr; }
      .mb { padding: 10px; font-size: 11px; }
    }
    @media (max-width: 480px) {
      .kpi-grid  { grid-template-columns: repeat(3, 1fr); gap: 6px; }
      .kpi       { padding: 10px 8px 8px; }
      .kpi-lbl   { font-size: 8px; letter-spacing: .8px; }
      .kpi-val   { font-size: 13px; }
      .kpi-val .unit { font-size: 10px; }
      .kpi-sub   { font-size: 9px; margin-top: 4px; }
      .kpi-icon  { display: none; }
      .ccard     { padding: 14px 12px 8px; }
      .hint      { display: none; }
      .tot-grid  { grid-template-columns: 1fr; }
      .nav-dia-data { font-size: 13px; min-width: 110px; padding: 8px 10px; }
    }
	.sel-ctrl {
	  background: transparent;
	  border: none;
	  color: var(--txt);
	  font-size: 14px;
	  font-weight: 700;
	  width: 100%;
	  cursor: pointer;
	  outline: none;
	  appearance: none;
	  -webkit-appearance: none;
	  padding-right: 20px;
	  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%237a9cc4' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
	  background-repeat: no-repeat;
	  background-position: right 0 center;
	}
.sel-ctrl option { background: var(--card); color: var(--txt); }
    /* ══════════════════════════════════════════════
       TOAST DE ERRO (erros manuais — não auto-refresh)
    ══════════════════════════════════════════════ */
    .toast-erro {
      position: fixed;
      top: 80px;
      right: 20px;
      background: var(--card);
      border: 1px solid var(--red);
      border-left: 4px solid var(--red);
      border-radius: 8px;
      padding: 12px 16px;
      color: var(--txt);
      font-size: 13px;
      box-shadow: 0 4px 16px var(--shadow);
      z-index: 9999;
      display: flex;
      align-items: center;
      gap: 12px;
      max-width: 360px;
      animation: toast-in .25s ease-out;
    }
    .toast-erro .toast-x {
      cursor: pointer;
      color: var(--txt-mid);
      font-size: 16px;
      background: none;
      border: none;
      padding: 0 4px;
      line-height: 1;
    }
    .toast-erro .toast-x:hover { color: var(--red); }
    @keyframes toast-in {
      from { opacity: 0; transform: translateX(20px); }
      to   { opacity: 1; transform: translateX(0); }
    }
    @media (max-width: 480px) {
      .toast-erro { top: 70px; right: 10px; left: 10px; max-width: none; }
    }
  </style>
</head>
<body>

<div id="loading">
  <div class="spin"></div>
  <p>VERIFICANDO SESSÃO...</p>
</div>

<?php require __DIR__ . '/includes/app_header.php'; ?>

<div class="wrap">

  <!-- ══════════════════════════════════════════ TOOLBAR -->
  <div class="toolbar">
    <d	iv class="ctrl-info">
	  <div class="label">Controlador</div>
	  <?php if (count($controladoresAcessiveis) > 1): ?>
		<select id="sel-controlador" class="sel-ctrl" onchange="trocarControlador(this.value)">
		  <?php foreach ($controladoresAcessiveis as $c): ?>
			<option value="<?= (int)$c['id'] ?>"
					<?= $c['id'] == $controladorAtivo['id'] ? 'selected' : '' ?>>
			  <?= htmlspecialchars($c['codigo']) ?>
			  <?= $c['apelido'] ? ' — ' . htmlspecialchars($c['apelido']) : '' ?>
			  <?= $c['empresa_nome'] ? ' · ' . htmlspecialchars($c['empresa_nome']) : '' ?>
			</option>
		  <?php endforeach; ?>
		</select>
	  <?php else: ?>
		<div class="nome" id="ctrl-nome">
		  <?= htmlspecialchars($controladorAtivo['codigo'] ?? 'Sem controlador') ?>
		  <?= !empty($controladorAtivo['apelido']) ? ' — ' . htmlspecialchars($controladorAtivo['apelido']) : '' ?>
		</div>
	  <?php endif; ?>
	</div>

    <div class="periodo-group">
      <div class="modo-btns">
        <button class="mb ativo" id="mb-dia"  onclick="setModo('dia')">DIA</button>
        <button class="mb"       id="mb-mes"  onclick="setModo('mes')">MÊS</button>
        <button class="mb"       id="mb-ano"  onclick="setModo('ano')">ANO</button>
        <button class="mb"       id="mb-anos" onclick="setModo('anos')">TOTAL</button>
      </div>

      <input type="month" class="inp-data" id="inp-mes"
             style="display:none" onchange="carregarDados()">

      <select class="sel-ano" id="sel-ano"
              style="display:none" onchange="carregarDados()"></select>

      
    </div>
  </div>

  <!-- ══════════════════════════════════════════ CHART CARD -->
  <div class="ccard">
    <div class="ccard-head">
      <div class="ccard-title">
        <span id="chart-titulo">⚡ Potência ao longo do dia</span>
        <span class="badge"       id="badge-periodo">—</span>
        <span class="badge green" id="badge-registros" style="display:none"></span>
      </div>
      <span class="hint" id="chart-hint"></span>
    </div>

    <!-- Navegação DIA -->
    <div class="nav-dia" id="nav-dia-wrap">
      <button class="nav-dia-seta" id="btn-ant"  onclick="navegarDia(-1)" title="Dia anterior">&#8249;</button>
      <div class="nav-dia-data-wrap">
        <div class="nav-dia-data" id="nav-dia-label">—</div>
        <input type="date" id="inp-dia" onchange="onChangeDia()">
      </div>
      <button class="nav-dia-seta" id="btn-prox" onclick="navegarDia(1)" title="Próximo dia">&#8250;</button>
    </div>

    <div class="sem-dados" id="sem-dados-msg">
      📭 Nenhum dado encontrado para este período.
    </div>
    <div id="chart-potencia"></div>
  </div>

  <!-- ══════════════════════════════════════════ KPIs -->
  <div class="kpi-grid">
    <div class="kpi" style="--kc: var(--red)">
      <div class="kpi-icon">📥</div>
      <div class="kpi-lbl" id="kpi-lbl-imp">Pico Importada</div>
      <div class="kpi-val"><span id="kpi-imp">—</span><span class="unit" id="kpi-unit-imp">kW</span></div>
      <div class="kpi-sub" id="kpi-sub-imp">—</div>
    </div>
    <div class="kpi" style="--kc: var(--green)">
      <div class="kpi-icon">📤</div>
      <div class="kpi-lbl" id="kpi-lbl-exp">Pico Exportada</div>
      <div class="kpi-val"><span id="kpi-exp">—</span><span class="unit" id="kpi-unit-exp">kW</span></div>
      <div class="kpi-sub" id="kpi-sub-exp">—</div>
    </div>
    <div class="kpi" style="--kc: var(--orange)">
      <div class="kpi-icon">☀️</div>
      <div class="kpi-lbl" id="kpi-lbl-ger">Pico Geração</div>
      <div class="kpi-val"><span id="kpi-ger">—</span><span class="unit" id="kpi-unit-ger">kW</span></div>
      <div class="kpi-sub" id="kpi-sub-ger">—</div>
    </div>
    <div class="kpi" style="--kc: var(--blue)">
      <div class="kpi-icon">📊</div>
      <div class="kpi-lbl" id="kpi-lbl-cons">Média Consumo</div>
      <div class="kpi-val"><span id="kpi-cons">—</span><span class="unit" id="kpi-unit-cons">kW</span></div>
      <div class="kpi-sub" id="kpi-sub-cons">no período</div>
    </div>
    <div class="kpi" style="--kc: var(--purple)">
      <div class="kpi-icon">🗂️</div>
      <div class="kpi-lbl" id="kpi-lbl-reg">Registros</div>
      <div class="kpi-val"><span id="kpi-reg">—</span></div>
      <div class="kpi-sub" id="kpi-sub-reg">no período</div>
    </div>
  </div>

  <!-- ══════════════════════════════════════════ TOTALIZADORES -->
  <div class="tot-grid">
    <div class="tot-card">
      <div class="tot-title" id="tot-titulo-energia">⚡ Energia do Dia (kWh estimado)</div>
      <div class="tot-row"><span class="tot-k">Importada</span>        <span class="tot-v" id="tot-imp">—</span></div>
      <div class="tot-row"><span class="tot-k">Exportada</span>        <span class="tot-v" id="tot-exp">—</span></div>
      <div class="tot-row"><span class="tot-k">Geração</span>          <span class="tot-v" id="tot-ger">—</span></div>
      <div class="tot-row"><span class="tot-k">Consumo Total</span>    <span class="tot-v" id="tot-cons">—</span></div>
      <div class="tot-row"><span class="tot-k">Saldo (exp − imp)</span><span class="tot-v" id="tot-saldo">—</span></div>
    </div>
    <div class="tot-card">
      <div class="tot-title">📈 Estatísticas</div>
      <div class="tot-row"><span class="tot-k" id="stat-lbl-imp">Máx. Importada</span> <span class="tot-v" id="stat-imp">—</span></div>
      <div class="tot-row"><span class="tot-k" id="stat-lbl-exp">Máx. Exportada</span> <span class="tot-v" id="stat-exp">—</span></div>
      <div class="tot-row"><span class="tot-k" id="stat-lbl-ger">Máx. Geração</span>   <span class="tot-v" id="stat-ger">—</span></div>
      <div class="tot-row"><span class="tot-k" id="stat-lbl-cons">Máx. Consumo</span>  <span class="tot-v" id="stat-cons">—</span></div>
    </div>
    <div class="tot-card">
      <div class="tot-title">🕐 Informações do Período</div>
      <div class="tot-row"><span class="tot-k" id="info-lbl-a">Primeiro registro</span><span class="tot-v" id="info-a">—</span></div>
      <div class="tot-row"><span class="tot-k" id="info-lbl-b">Último registro</span>  <span class="tot-v" id="info-b">—</span></div>
      <div class="tot-row"><span class="tot-k">Total registros</span>                   <span class="tot-v" id="info-total">—</span></div>
    </div>
  </div>

  <div class="footer">
    CIP · Controlador de Injeção de Potência Elétrica &nbsp;·&nbsp;
    Monitoramento de Energia &nbsp;·&nbsp;
    <span id="footer-atualizacao">—</span>
  </div>

</div><!-- /wrap -->

<script>
/**
 * =============================================================================
 * Projeto   : CIP - Controlador de Injecao de Potencia Eletrica
 * Arquivo   : public_html/energia.php [bloco JS]
 * Objetivo  : Orquestrador multimode DIA|MES|ANO|TOTAL
 *             v2.3: modo DIA usa 4 linhas continuas; navegacao preservada.
 * =============================================================================
 */

/* ════════════════════════════════════════
   CONSTANTES
════════════════════════════════════════ */

const API = {
  dia : '/api/energia/dia.php',
  mes : '/api/energia/mes.php',
  ano : '/api/energia/ano.php',
  anos: '/api/energia/anos.php',
};
const COR_IMP  = '#ff5252';
const COR_EXP  = '#00e676';
const COR_GER  = '#ff9800';
const COR_CONS = '#00b4ff';
/* ════════════════════════════════════════
   CONSTANTES (injetadas pelo PHP via tenant)
════════════════════════════════════════ */
const CONTROLADOR_ID  = <?= (int)($controladorAtivo['id'] ?? 0) ?>;
const CONTROLADOR_COD = <?= json_encode($controladorAtivo['codigo'] ?? '') ?>;
const TIMEZONE_CTRL   = <?= json_encode($timezoneControlador ?? 'America/Sao_Paulo') ?>;
/*
 * LABELS_24H — eixo X fixo do modo DIA.
 * Mantido pois expandir24h() mapeia os dados reais da API
 * (qualquer resolução: 5min, 15min, 1h) para as 24 categorias.
 * A navegação ◀▶ e o datepicker dependem de dataAtual (YYYY-MM-DD),
 * não do eixo X — por isso continuam funcionando normalmente.
 *
 * NOTA FUTURA: quando quisermos eixo X com resolução real (ex: HH:MM a cada
 * 5 min), será necessário mudar xaxis para type:'datetime' e passar timestamps.
 * Isso exigirá revisar atualizarNavDia() para não depender de LABELS_24H.
 */
const LABELS_24H = Array.from({ length: 24 }, (_, i) =>
  `${String(i).padStart(2,'0')}:00`
);

const NOMES_MESES = ['Jan','Fev','Mar','Abr','Mai','Jun',
                     'Jul','Ago','Set','Out','Nov','Dez'];
/* ════════════════════════════════════════
   ESTADO
════════════════════════════════════════ */
let chart     = null;
let modoAtual = 'dia';
let dataAtual = dataHoje();
let mesAtual  = dataHoje().substring(0, 7);
let anoAtual  = new Date().getFullYear();
let anosDisp  = [];

// Flag: true quando o carregamento vier do auto-refresh.
// Lida por buildChartOptions() para desabilitar animações durante refresh silencioso.
let carregamentoSilencioso = false;

// 2026-05-18 [REMOVIDO] let temaAtual = localStorage.getItem('cip-tema') || 'escuro';
// Motivo: tema agora e gerenciado globalmente por assets/js/tema.js
// Para ler o tema atual em qualquer ponto: window.CipTema.atual()

function navegarDia(offset) {
  const nova = deslocarData(dataAtual, offset);
  if (nova > dataHoje()) return;
  dataAtual = nova;
  atualizarNavDia();
  carregarDados();
  AutoRefresh.reavaliar();
}
/* ════════════════════════════════════════
   UTILITÁRIOS DE DATA
════════════════════════════════════════ */
/* ════════════════════════════════════════
   UTILITÁRIOS DE DATA
   v2.4: agora respeitam TIMEZONE_CTRL para evitar
   o bug de "dia seguinte" após 21h BRT (UTC-3).
════════════════════════════════════════ */

/**
 * Retorna a data "hoje" (YYYY-MM-DD) no timezone do controlador.
 * Corrige bug onde, após 21h BRT, toISOString() retornava UTC do dia seguinte.
 */
function dataHoje() {
  const fmt = new Intl.DateTimeFormat('en-CA', {
    timeZone: TIMEZONE_CTRL,
    year: 'numeric', month: '2-digit', day: '2-digit'
  });
  return fmt.format(new Date()); // "YYYY-MM-DD"
}

/**
 * Desloca uma data YYYY-MM-DD em N dias.
 * Usa meio-dia UTC como âncora para evitar pulos de dia em fronteiras de TZ.
 */
function deslocarData(base, dias) {
  // âncora ao meio-dia UTC: imune a saltos de timezone/DST
  const d = new Date(base + 'T12:00:00Z');
  d.setUTCDate(d.getUTCDate() + dias);
  const y = d.getUTCFullYear();
  const m = String(d.getUTCMonth() + 1).padStart(2, '0');
  const dd = String(d.getUTCDate()).padStart(2, '0');
  return `${y}-${m}-${dd}`;
}

function formatarDataBR(iso) {
  const [y, m, d] = iso.split('-');
  return `${d}/${m}/${y}`;
}

/**
 * Exibe um card flutuante de erro no topo-direito.
 * Auto-dismiss em 5s ou clique no X.
 * Substitui o uso de alert() para erros de operação manual.
 * @versao  v1.0
 * @autor   ATGY
 * @data    2026-06-02
 */
function mostrarToastErro(mensagem) {
  // Remove toast anterior se existir
  document.querySelector('.toast-erro')?.remove();
  const toast = document.createElement('div');
  toast.className = 'toast-erro';
  toast.innerHTML = `
    <span>⚠️ ${mensagem}</span>
    <button class="toast-x" aria-label="Fechar">✕</button>
  `;
  document.body.appendChild(toast);
  const fechar = () => toast.remove();
  toast.querySelector('.toast-x').addEventListener('click', fechar);
  setTimeout(fechar, 5000);
}
function nomeMes(num) { return NOMES_MESES[(num - 1)] ?? ''; }


function labelPeriodo() {
  switch (modoAtual) {
    case 'dia' : return formatarDataBR(dataAtual);
    case 'mes' : { const [y,m] = mesAtual.split('-'); return `${nomeMes(+m)}/${y}`; }
    case 'ano' : return `${anoAtual}`;
    case 'anos': return 'Histórico Total';
  }
}

/* ════════════════════════════════════════
   NAVEGAÇÃO DIA — intacta v2.2
════════════════════════════════════════ */
function atualizarNavDia() {
  document.getElementById('nav-dia-label').textContent = formatarDataBR(dataAtual);
  document.getElementById('inp-dia').value             = dataAtual;
  document.getElementById('btn-prox').disabled         = (dataAtual >= dataHoje());
}



function onChangeDia() {
  const val = document.getElementById('inp-dia').value;
  if (!val || val > dataHoje()) return;
  dataAtual = val;
  atualizarNavDia();
  carregarDados();
  AutoRefresh.reavaliar();
}

/* ════════════════════════════════════════
   TOOLBAR
════════════════════════════════════════ */
function atualizarToolbar() {
  ['dia','mes','ano','anos'].forEach(m =>
    document.getElementById(`mb-${m}`).classList.toggle('ativo', m === modoAtual)
  );
  const show = (id, vis) => {
    const el = document.getElementById(id);
    if (el) el.style.display = vis ? '' : 'none';
  };
  show('nav-dia-wrap', modoAtual === 'dia');
  show('inp-mes',      modoAtual === 'mes');
  show('sel-ano',      modoAtual === 'ano');

  const titulos = {
    dia : '⚡ Potência ao longo do dia',
    mes : '📅 Energia diária do mês',
    ano : '📆 Energia mensal do ano',
    anos: '📊 Energia Total — Histórico',
  };
  document.getElementById('chart-titulo').textContent = titulos[modoAtual];
  document.getElementById('tot-titulo-energia').textContent = {
    dia : '⚡ Energia do Dia (kWh estimado)',
    mes : '⚡ Energia do Mês (kWh)',
    ano : '⚡ Energia do Ano (kWh)',
    anos: '⚡ Energia Total Histórica (kWh)',
  }[modoAtual];

  const isKwh = modoAtual !== 'dia';
  const un    = isKwh ? 'kWh' : 'kW';
  document.getElementById('kpi-lbl-imp').textContent  = isKwh ? 'Total Importada' : 'Pico Importada';
  document.getElementById('kpi-lbl-exp').textContent  = isKwh ? 'Total Exportada' : 'Pico Exportada';
  document.getElementById('kpi-lbl-ger').textContent  = isKwh ? 'Total Geração'   : 'Pico Geração';
  document.getElementById('kpi-lbl-cons').textContent = isKwh ? 'Total Consumo'   : 'Média Consumo';
  document.getElementById('kpi-lbl-reg').textContent  = isKwh ? 'Períodos'        : 'Registros';
  document.getElementById('kpi-sub-reg').textContent  = isKwh ? 'com dados'       : 'no dia';
  ['imp','exp','ger','cons'].forEach(k =>
    document.getElementById(`kpi-unit-${k}`).textContent = un
  );

  const p = isKwh ? 'Pico' : 'Máx.';
  document.getElementById('stat-lbl-imp').textContent  = `${p} Importada`;
  document.getElementById('stat-lbl-exp').textContent  = `${p} Exportada`;
  document.getElementById('stat-lbl-ger').textContent  = `${p} Geração`;
  document.getElementById('stat-lbl-cons').textContent = `${p} Consumo`;

  const li = {
    dia : ['Primeiro registro','Último registro'],
    mes : ['Primeiro dia','Último dia'],
    ano : ['Primeiro mês','Último mês'],
    anos: ['Primeiro ano','Último ano'],
  };
  document.getElementById('info-lbl-a').textContent = li[modoAtual][0];
  document.getElementById('info-lbl-b').textContent = li[modoAtual][1];

  if (modoAtual === 'dia') atualizarNavDia();
}

/* ════════════════════════════════════════
   CHART — buildChartOptions v2.3
   MUDANÇA PRINCIPAL: modo DIA agora usa
   4 linhas contínuas em vez de barras.
   Eixo X permanece categorias LABELS_24H.
════════════════════════════════════════ */
/* ════════════════════════════════════════
   CHART — inicialização
   Cria já no modo DIA (line), não bar.
════════════════════════════════════════ */
function inicializarChart() {
  const el = document.getElementById('chart-potencia');
  if (chart) { chart.destroy(); chart = null; }
  chart = new ApexCharts(
    el,
    buildChartOptions('dia', LABELS_24H, [], [], [], [])
  );
  chart.render();
  
  // Registra o chart no modulo de tema para sincronia automatica
    if (window.CipTema && typeof window.CipTema.registrarChart === 'function') {
    window.CipTema.registrarChart(chart);
}
}

// ============================================================
// ARQUIVO: dashboard.js
// FUNÇÃO : buildChartOptions
// PROJETO: Controlador de Injeção de Potência Elétrica
// AUTOR  : Claude Sonnet 4.6 / Anthropic
// DATA   : 2026-04-15
// VERSÃO : 2.2.0
// -------------------------------------------------------
// DEPENDÊNCIAS DE HARDWARE/SOFTWARE:
//   - ApexCharts >= 3.x (CDN ou npm)
//   - Variáveis globais: temaAtual, COR_IMP, COR_EXP,
//                        COR_GER, COR_CONS, NOMES_MESES
// -------------------------------------------------------
// OBJETIVOS:
//   - Gerar configuração unificada do ApexCharts
//     para os modos: dia, mes, ano, total
//   - Renderiza linhas visíveis no modo DIA
//   - Mantém mixed chart (bar+line) nos demais modos
// -------------------------------------------------------
// HISTÓRICO:
//   v1.0.0 - Implementação inicial
//   v2.0.0 - Separação stroke/fill/markers por modo
//   v2.1.0 - [FIX] Remove 'type' por série no modo DIA
//   v2.2.0 - [FIX] fill.opacity zerava as linhas no modo
//            DIA. Corrigido: type='solid' + opacity=1 para
//            todas as séries do modo DIA.
// ============================================================

function buildChartOptions(modo, cats, vImp, vExp, vGer, vCons) {
  const isDia = modo === 'dia';
  // 2026-05-18 [REFACTOR] tema lido do modulo global window.CipTema
  const tMode = window.CipTema.atual() === 'escuro' ? 'dark' : 'light';
  const un    = isDia ? 'kW' : 'kWh';

  /* ── Séries ──────────────────────────────────────────────── */
  const series = isDia
    ? [
        { name: 'Importada',     data: vImp        },
        { name: 'Exportada',     data: vExp        },
        { name: 'Geração',       data: vGer        },
        { name: 'Consumo Total', data: vCons ?? [] },
      ]
    : [
        { name: 'Importada', type: 'bar',  data: vImp        },
        { name: 'Exportada', type: 'bar',  data: vExp        },
        { name: 'Geração',   type: 'line', data: vGer        },
        { name: 'Consumo',   type: 'line', data: vCons ?? [] },
      ];

  /* ── xaxis ───────────────────────────────────────────────── 
  let xaxis;
  if (isDia) {
    xaxis = {
      categories: cats,
      tickAmount: 24,
      labels: {
        rotate: -45,
        style : { colors: '#7a9cc4', fontSize: '11px' },
      },
      axisBorder: { color: '#1a2d4a' },
      axisTicks : { color: '#1a2d4a' },
    };
    */
    
       if (isDia) {
      xaxis = {
        categories: cats,
        tickAmount: 12,                                    // ✅ ~1 label a cada 2h (limpo)
        labels: {
          rotate: -45,
          rotateAlways: false,
          hideOverlappingLabels: true,                     // ✅ esconde sobrepostos
          showDuplicates: false,
          style: { colors: '#7a9cc4', fontSize: '11px' },
        },
        axisBorder: { color: '#1a2d4a' },
        axisTicks : { color: '#1a2d4a' },
      };
    
    
  } else if (modo === 'mes') {
    xaxis = {
      categories: cats,
      tickAmount: 30,
      labels: {
        style    : { colors: '#7a9cc4', fontSize: '11px' },
        formatter: v => v,
      },
      title     : { text: 'Dia do mês', style: { color: '#7a9cc4', fontSize: '11px' } },
      axisBorder: { color: '#1a2d4a' },
      axisTicks : { color: '#1a2d4a' },
    };
  } else if (modo === 'ano') {
    xaxis = {
      categories: NOMES_MESES,
      tickAmount: 12,
      labels    : { style: { colors: '#7a9cc4', fontSize: '11px' } },
      axisBorder: { color: '#1a2d4a' },
      axisTicks : { color: '#1a2d4a' },
    };
  } else {
    xaxis = {
      type  : 'datetime',
      labels: {
        style      : { colors: '#7a9cc4', fontSize: '11px' },
        datetimeUTC: false,
        formatter  : val => `${new Date(val).getFullYear()}`,
      },
      axisBorder: { color: '#1a2d4a' },
      axisTicks : { color: '#1a2d4a' },
    };
  }

  /* ── yaxis ───────────────────────────────────────────────── */
  const yaxis = {
    min   : 0,
    labels: {
      style    : { colors: '#7a9cc4' },
      formatter: v => v.toFixed(isDia ? 2 : 1),
    },
    title: {
      text : un,
      style: { color: '#7a9cc4', fontSize: '12px', fontWeight: 700 },
    },
  };

  /* ── stroke ──────────────────────────────────────────────── */
  const strokeCfg = isDia
    ? {
        show     : true,
        width    : [2.5, 2.5, 2.5, 2.0],
        curve    : 'smooth',
        dashArray: [0, 6, 0, 4],
        lineCap  : 'round',
      }
    : {
        show     : true,
        width    : [0, 0, 2.5, 2.5],
        curve    : 'smooth',
        dashArray: [0, 0, 0, 5],
      };

  /* ── markers ─────────────────────────────────────────────── */
  const markersCfg = isDia
    ? { size: 0 }
    : {
        size        : [0, 0, 4, 4],
        strokeColors: '#0d1526',
        strokeWidth : 2,
        hover       : { size: 6 },
      };

  /* ── fill ────────────────────────────────────────────────── */
  // ✅ FIX v2.2.0: modo DIA usa type='solid' + opacity=1
  // opacity=0 afetava a linha inteira, tornando-a invisível.
  // A área sob a linha já é controlada pelo stroke (sem area chart).
  const fillCfg = isDia
    ? {
        type   : 'solid',
        opacity: 1,
      }
    : {
        opacity : [0.85, 0.85, 0.2, 0],
        type    : ['solid', 'solid', 'gradient', 'solid'],
        gradient: {
          shade         : 'dark',
          type          : 'vertical',
          shadeIntensity: 0.3,
          opacityFrom   : 0.5,
          opacityTo     : 0.05,
        },
      };

  return {
    chart: {
      type      : isDia ? 'line' : 'bar',
      height    : 400,
      background: 'transparent',
      fontFamily: "'Segoe UI', system-ui, sans-serif",
      toolbar: {
        show   : true,
        offsetY: -10,
        tools  : {
          download : true,
          selection: true,
          zoom     : true,
          zoomin   : true,
          zoomout  : true,
          pan      : true,
          reset    : true,
        },
      },
      zoom      : { enabled: true },
      animations: {
        enabled: !carregamentoSilencioso,
        easing: 'easeinout',
        speed: 500,
        animateGradually: {
          enabled: !carregamentoSilencioso,
          delay: 80
        },
        dynamicAnimation: {
          enabled: !carregamentoSilencioso,
          speed: 350
        }
      },
    },
    theme    : { mode: tMode },
    colors   : [COR_IMP, COR_EXP, COR_GER, COR_CONS],
    series,
    xaxis,
    yaxis,
    legend: {
      position       : 'bottom',
      horizontalAlign: 'center',
      labels         : { colors: '#7a9cc4' },
      markers        : { radius: 3 },
      itemMargin     : { horizontal: 12, vertical: 4 },
    },
    tooltip: {
      theme    : tMode,
      shared   : true,
      intersect: false,
      y        : { formatter: v => (v ?? 0).toFixed(3) + ` ${un}` },
    },
    grid: {
      borderColor   : '#1a2d4a',
      strokeDashArray: 3,
      padding       : { top: 0, right: 10, bottom: 0, left: 10 },
    },
    stroke     : strokeCfg,
    markers    : markersCfg,
    fill       : fillCfg,
    plotOptions: { bar: { columnWidth: '60%', borderRadius: 2 } },
    dataLabels : { enabled: false },
    noData     : { text: 'Sem dados', style: { color: '#7a9cc4', fontSize: '14px' } },
  };
}




/* ════════════════════════════════════════
   HELPERS — MODO DIA
════════════════════════════════════════ */
function expandir24h(labels, valores) {
  const mapa = {};
  (labels ?? []).forEach((lbl, i) => {
    const v = (valores ?? [])[i];
    mapa[lbl] = (v != null) ? parseFloat(v) || 0 : 0;
  });
  return LABELS_24H.map(h =>
    mapa[h] !== undefined ? parseFloat(mapa[h].toFixed(3)) : 0
  );
}
/**
 * Mapeia labels reais da API (resolução 5min) para os 288 slots fixos do dia.
 * IMPORTANTE: slots sem dado ficam como null (não 0) para criar gap visual
 * em vez de "puxar" a linha pra baixo.
 *
 * @param {string[]} labels   - labels da API, ex: ["00:00","00:05",...]
 * @param {number[]} valores  - valores correspondentes
 * @returns {(number|null)[]} - array de 288 posições
 */
function expandir288(labels, valores) {
  const mapa = {};
  (labels ?? []).forEach((lbl, i) => {
    const v = (valores ?? [])[i];
    if (v != null && !isNaN(v)) {
      mapa[lbl] = parseFloat(parseFloat(v).toFixed(3));
    }
  });

  // Determina até onde preencher com 0 (para não desenhar linha no futuro de hoje)
  let maxValidLabel = "23:55";
  if (typeof dataAtual !== 'undefined' && typeof dataHoje === 'function' && dataAtual === dataHoje()) {
    const now = new Date();
    const h = String(now.getHours()).padStart(2, '0');
    const m = String(now.getMinutes()).padStart(2, '0');
    maxValidLabel = `${h}:${m}`;
  }

  return LABELS_288.map(h => {
    if (h in mapa) return mapa[h];
    // Se for gap no passado/presente, joga pra 0. Se for no futuro (hoje), deixa null.
    if (h <= maxValidLabel) return 0;
    return null;
  });
}


function calcularConsumoTotal(imp, ger, exp) {
  return imp.map((v, i) =>
    parseFloat(Math.max(0, v + (ger[i] ?? 0) - (exp[i] ?? 0)).toFixed(3))
  );
}

/**
 * Integração trapezoidal para estimar energia (kWh) a partir de potência (kW).
 * - Ignora pares com null (gaps no gráfico).
 * - Considera intervalo de 5 minutos entre amostras (1/12 hora).
 * - Resultado em kWh.
 */
function estimarEnergia(vals) {
  if (!vals || vals.length < 2) return 0;
  const DT_HORAS = 5 / 60; // 5min = 1/12 h
  let s = 0;
  for (let i = 0; i < vals.length - 1; i++) {
    const a = vals[i], b = vals[i + 1];
    if (a == null || b == null) continue; // pula gaps
    s += ((a + b) / 2) * DT_HORAS;
  }
  return s;
}


/* ════════════════════════════════════════
   HELPERS — MÊS / ANO
════════════════════════════════════════ */
function extrairSeries(json) {
  const buscar = nome => {
    const s = (json.series ?? []).find(s =>
      s.name.toLowerCase().includes(nome.toLowerCase())
    );
    return s ? s.data : [];
  };
  return {
    imp : buscar('importada'),
    exp : buscar('exportada'),
    ger : buscar('geração') || buscar('geracao'),
    cons: buscar('consumo'),
  };
}

/* ════════════════════════════════════════
   ATUALIZAÇÃO UI — intacta v2.2
════════════════════════════════════════ */
function atualizarUI(json, modo) {
  const isDia  = modo === 'dia';
  const r      = json.resumo ?? {};
  const fmt2   = v => parseFloat(v ?? 0).toFixed(2);
  const fmt3   = v => parseFloat(v ?? 0).toFixed(3);
  const fmtKwh = v => fmt2(v) + ' kWh';

  document.getElementById('badge-periodo').textContent = labelPeriodo();

  const total = json.total_registros ?? r.total_registros ?? 0;
  const bdReg = document.getElementById('badge-registros');
  bdReg.textContent   = total + ' registros';
  bdReg.style.display = total > 0 ? '' : 'none';

  if (isDia) {
    document.getElementById('kpi-imp').textContent  = fmt3(r.pico_importada_kw   ?? 0);
    document.getElementById('kpi-exp').textContent  = fmt3(r.pico_exportada_kw   ?? 0);
    document.getElementById('kpi-ger').textContent  = fmt3(r.pico_compensacao_kw ?? 0);
    document.getElementById('kpi-cons').textContent = fmt3(r.media_consumo_kw    ?? 0);
    document.getElementById('kpi-sub-imp').textContent  = '';
    document.getElementById('kpi-sub-exp').textContent  = '';
    document.getElementById('kpi-sub-ger').textContent  = '';
    document.getElementById('kpi-sub-cons').textContent = 'no período';
  } else {
    document.getElementById('kpi-imp').textContent  = fmt2(r.total_importada_kwh ?? 0);
    document.getElementById('kpi-exp').textContent  = fmt2(r.total_exportada_kwh ?? 0);
    document.getElementById('kpi-ger').textContent  = fmt2(r.total_geracao_kwh   ?? 0);
    document.getElementById('kpi-cons').textContent = fmt2(r.total_consumo_kwh   ?? 0);
    ['imp','exp','ger','cons'].forEach(k =>
      document.getElementById(`kpi-sub-${k}`).textContent = 'no período'
    );
  }
  document.getElementById('kpi-reg').textContent = total || '—';

  if (isDia) {
    const s    = json._sc;
    const eImp = estimarEnergia(s.imp);
    const eExp = estimarEnergia(s.exp);
    const eGer = estimarEnergia(s.ger);
    const eCon = estimarEnergia(s.cons);
    const eSal = eExp - eImp;
    document.getElementById('tot-imp').textContent   = fmtKwh(eImp);
    document.getElementById('tot-exp').textContent   = fmtKwh(eExp);
    document.getElementById('tot-ger').textContent   = fmtKwh(eGer);
    document.getElementById('tot-cons').textContent  = fmtKwh(eCon);
    document.getElementById('tot-saldo').textContent = (eSal >= 0 ? '+' : '') + fmt2(eSal) + ' kWh';

    const maxV = arr => arr.length ? Math.max(...arr).toFixed(3) : '0.000';
    document.getElementById('stat-imp').textContent  = maxV(s.imp)  + ' kW';
    document.getElementById('stat-exp').textContent  = maxV(s.exp)  + ' kW';
    document.getElementById('stat-ger').textContent  = maxV(s.ger)  + ' kW';
    document.getElementById('stat-cons').textContent = maxV(s.cons) + ' kW';

    document.getElementById('info-a').textContent     = r.primeiro_registro ?? '—';
    document.getElementById('info-b').textContent     = r.ultimo_registro    ?? '—';
    document.getElementById('info-total').textContent = r.total_registros    ?? '—';

  } else {
    document.getElementById('tot-imp').textContent   = fmtKwh(r.total_importada_kwh);
    document.getElementById('tot-exp').textContent   = fmtKwh(r.total_exportada_kwh);
    document.getElementById('tot-ger').textContent   = fmtKwh(r.total_geracao_kwh);
    document.getElementById('tot-cons').textContent  = fmtKwh(r.total_consumo_kwh);
    const saldo = parseFloat(r.saldo_kwh ?? 0);
    document.getElementById('tot-saldo').textContent = (saldo >= 0 ? '+' : '') + fmt2(saldo) + ' kWh';

    document.getElementById('stat-imp').textContent  = fmtKwh(r.pico_importada_kwh);
    document.getElementById('stat-exp').textContent  = fmtKwh(r.pico_exportada_kwh);
    document.getElementById('stat-ger').textContent  = fmtKwh(r.pico_geracao_kwh);
    document.getElementById('stat-cons').textContent = fmtKwh(r.pico_consumo_kwh);

    const raw = json._raw ?? [];
    if (raw.length > 0) {
      const primeiro = raw[0];
      const ultimo   = raw[raw.length - 1];
      const fmtPer   = obj => {
        if (obj.dia)  return formatarDataBR(obj.dia);
        if (obj.mes)  return `${nomeMes(+obj.mes.split('-')[1])}/${obj.mes.split('-')[0]}`;
        if (obj.ano)  return `${obj.ano}`;
        return '—';
      };
      document.getElementById('info-a').textContent = fmtPer(primeiro);
      document.getElementById('info-b').textContent = fmtPer(ultimo);
    } else {
      document.getElementById('info-a').textContent = '—';
      document.getElementById('info-b').textContent = '—';
    }
    document.getElementById('info-total').textContent = total || '—';
  }

    const nc = [json.controlador_codigo, json.controlador_apelido].filter(Boolean).join(' — ');
    const elCtrlNome = document.getElementById('ctrl-nome');
    if (nc && elCtrlNome) elCtrlNome.textContent = nc;

  document.getElementById('footer-atualizacao').textContent =
    'Atualizado em ' + new Date().toLocaleTimeString('pt-BR');
}


// 🔄 Troca de controlador via seletor
function trocarControlador(novoId) {
  const url = new URL(window.location.href);
  url.searchParams.set('ctrl', novoId);
  window.location.href = url.toString();
}
/* ════════════════════════════════════════
   CARREGAMENTO PRINCIPAL
════════════════════════════════════════ */
async function carregarDados() {
  document.getElementById('sem-dados-msg').classList.remove('visivel');
  try {
    switch (modoAtual) {
      case 'dia' : await carregarDia();  break;
      case 'mes' : await carregarMes();  break;
      case 'ano' : await carregarAno();  break;
      case 'anos': await carregarAnos(); break;
    }
  } catch (err) {
    // 🐛 DEBUG TEMPORÁRIO — remover depois!
    console.error('[CIP:energia] modo:', modoAtual);
    console.error('[CIP:energia] erro completo:', err);
    console.error('[CIP:energia] mensagem:', err?.message);
    console.error('[CIP:energia] stack:', err?.stack);

    mostrarToastErro('Falha ao atualizar dados. Tente novamente.');
  }
}

/**
 * Versão SILENCIOSA de carregarDados() — para uso do auto-refresh.
 * Diferença: erros vão para console.warn, sem alert/toast.
 * @versao  v1.0
 * @autor   ATGY
 * @data    2026-06-02
 */
async function carregarDadosSilencioso() {
  carregamentoSilencioso = true;  // desliga animação durante o refresh
  try {
    switch (modoAtual) {
      case 'dia' : await carregarDia();  break;
      case 'mes' : await carregarMes();  break;
      case 'ano' : await carregarAno();  break;
      case 'anos': await carregarAnos(); break;
    }
  } catch (err) {
    console.warn('[carregarDadosSilencioso] erro:', err?.message || err);
    throw err; // re-throw para AutoRefresh logar também
  } finally {
    carregamentoSilencioso = false; // sempre restaura, mesmo em caso de erro
  }
}

/* ════════════════════════════════════════
   MODO DIA — v2.3
   Mantém expandir24h() + LABELS_24H.
   Navegação ◀▶ e datepicker preservados.
════════════════════════════════════════ */
/* ════════════════════════════════════════
   MODO DIA — destrói e recria (fix line)
════════════════════════════════════════ */
// ============================================================
// ARQUIVO: dashboard.js
// FUNÇÃO : carregarDia
// PROJETO: Controlador de Injeção de Potência Elétrica
// AUTOR  : Claude Sonnet 4.6 / Anthropic
// DATA   : 2026-04-14
// VERSÃO : 2.1.0
// -------------------------------------------------------
// DEPENDÊNCIAS DE HARDWARE/SOFTWARE:
//   - ApexCharts >= 3.x
//   - API REST: endpoint API.dia
//   - Variáveis globais: CONTROLADOR_ID, dataAtual,
//                        LABELS_24H, chart
//   - Funções: expandir24h(), calcularConsumoTotal(),
//              buildChartOptions(), atualizarUI(),
//              atualizarNavDia()
// -------------------------------------------------------
// OBJETIVOS:
//   - Buscar dados de potência do dia atual via API
//   - Expandir vetores para 24h (1 ponto por hora)
//   - Renderizar gráfico ApexCharts modo linha
//   - Atualizar painel de resumo e navegação de data
// -------------------------------------------------------
// HISTÓRICO:
//   v1.0.0 - Implementação inicial
//   v2.0.0 - Separação do buildChartOptions
//   v2.1.0 - [FIX] Usa buildChartOptions corrigido (sem
//            type por série no modo DIA). Diagnóstico
//            detalhado no catch. destroy() garantido.
// ============================================================

/*
 * LABELS_288 — eixo X fixo de 24h com resolução de 5 minutos.
 * Total: 288 slots (24h × 12 buckets/h) → "00:00", "00:05", ..., "23:55".
 *
 * Usado pelo modo DIA para garantir escala completa do dia,
 * independente da hora atual. Horários sem dados ficam como null
 * (não 0!) para criar GAP visual no ApexCharts em vez de zerar.
 */
const LABELS_288 = Array.from({ length: 288 }, (_, i) => {
  const h = Math.floor(i / 12);
  const m = (i % 12) * 5;
  return `${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')}`;
});



async function carregarDia() {
  try {
    /* ── 1. Monta URL e dispara requisição ─────────────────────────────── */
    const url = `${API.dia}?controlador_id=${CONTROLADOR_ID}&data=${dataAtual}`;
    console.log('[carregarDia] URL:', url);

    const res = await fetch(url);
    console.log('[carregarDia] HTTP status:', res.status, res.statusText);

    if (!res.ok) {
      const texto = await res.text();
      console.error('[carregarDia] Resposta não-OK:', texto);
      throw new Error(`HTTP ${res.status}: ${texto}`);
    }

    /* ── 2. Parse do JSON ──────────────────────────────────────────────── */
    const json = await res.json();
    console.log('[carregarDia] JSON recebido:', json);

    if (!json.sucesso) {
      console.error('[carregarDia] API retornou sucesso=false:', json.erro);
      throw new Error(json.erro ?? 'Erro desconhecido da API');
    }

    /* ── 3. Aviso de sem dados ─────────────────────────────────────────── */
    const semDados = document.getElementById('sem-dados-msg');
    if (semDados) {
      if ((json.resumo?.total_registros ?? 0) === 0) {
        semDados.classList.add('visivel');
      } else {
        semDados.classList.remove('visivel');
      }
    }

    /* ── 4. Expande para 288 slots fixos (24h × 5min) ──────────────────── */
    // Mapeia labels reais da API ("HH:MM") → valor; ausentes ficam null (gap).
    const vImp  = expandir288(json.labels, json.importada);
    const vExp  = expandir288(json.labels, json.exportada);
    const vGer  = expandir288(json.labels, json.compensacao);
    const vCons = expandir288(json.labels, json.consumo_total);

    /* ── 5. Armazena séries calculadas no JSON para uso posterior ──────── */
    json._sc = { imp: vImp, exp: vExp, ger: vGer, cons: vCons };

    /* ── 6. Renderiza gráfico ──────────────────────────────────────────── */
    if (chart) { chart.destroy(); chart = null; }
    const el = document.getElementById('chart-potencia');
    chart = new ApexCharts(
      el,
      buildChartOptions('dia', LABELS_288, vImp, vExp, vGer, vCons)
    );
    chart.render();

    // 🔧 FIX: re-registra no módulo de tema após recriar o chart
    if (window.CipTema?.registrarChart) {
      window.CipTema.registrarChart(chart);
    }

    /* ── 7. Atualiza UI ────────────────────────────────────────────────── */
    atualizarUI(json, 'dia');
    atualizarNavDia();

  } catch (err) {
    console.error('[carregarDia] erro:', err);
    throw err;
  }
}


/* ════════════════════════════════════════
   MODO MÊS — intacto v2.2
════════════════════════════════════════ */
async function carregarMes() {
  const mes = document.getElementById('inp-mes').value;
  if (!mes) return;
  mesAtual = mes;

  const res  = await fetch(`${API.mes}?controlador_id=${CONTROLADOR_ID}&mes=${mes}`);
  const json = await res.json();
  if (!json.sucesso) { mostrarToastErro('API mês: ' + (json.erro ?? 'Erro')); return; }

  const sRaw = extrairSeries(json);
  const vImp  = new Array(30).fill(0);
  const vExp  = new Array(30).fill(0);
  const vGer  = new Array(30).fill(0);
  const vCons = new Array(30).fill(0);
  const hoje  = dataHoje();

  const preencherVetor = (serie, vetor) => {
    serie.forEach(([ts, val]) => {
      const d   = new Date(ts);
      const dia = d.getDate();
      const iso = d.toISOString().split('T')[0];
      if (dia >= 1 && dia <= 30 && iso <= hoje) {
        vetor[dia - 1] = parseFloat((val ?? 0).toFixed(3));
      }
    });
  };

  preencherVetor(sRaw.imp,  vImp);
  preencherVetor(sRaw.exp,  vExp);
  preencherVetor(sRaw.ger,  vGer);
  preencherVetor(sRaw.cons, vCons);

  const cats = Array.from({ length: 30 }, (_, i) => i + 1);

  await chart.updateOptions(
    buildChartOptions('mes', cats, vImp, vExp, vGer, vCons),
    true, true, true
  );

  json._raw = sRaw.imp.map(([ts]) => ({
    dia: new Date(ts).toISOString().split('T')[0]
  }));
  atualizarUI(json, 'mes');
}

/* ════════════════════════════════════════
   MODO ANO — intacto v2.2
════════════════════════════════════════ */

async function carregarAno() {
  const ano = parseInt(document.getElementById('sel-ano').value, 10);
  if (!ano) return;
  anoAtual = ano;

  const res  = await fetch(`${API.ano}?controlador_id=${CONTROLADOR_ID}&ano=${ano}`);
  const json = await res.json();
  if (!json.sucesso) { mostrarToastErro('API ano: ' + (json.erro ?? 'Erro')); return; }

  const sRaw    = extrairSeries(json);
  const agora   = new Date();
  const anoHoje = agora.getFullYear();
  const mesHoje = agora.getMonth() + 1;

  const vImp  = new Array(12).fill(0);
  const vExp  = new Array(12).fill(0);
  const vGer  = new Array(12).fill(0);
  const vCons = new Array(12).fill(0);

  const preencherAno = (serie, vetor) => {
    serie.forEach(([ts, val]) => {
      const d     = new Date(ts);
      const mes   = d.getMonth() + 1;
      const anoTs = d.getFullYear();
      if (anoTs < anoHoje || (anoTs === anoHoje && mes <= mesHoje)) {
        vetor[mes - 1] = parseFloat((val ?? 0).toFixed(3));
      }
    });
  };

  preencherAno(sRaw.imp,  vImp);
  preencherAno(sRaw.exp,  vExp);
  preencherAno(sRaw.ger,  vGer);
  preencherAno(sRaw.cons, vCons);

  await chart.updateOptions(
    buildChartOptions('ano', NOMES_MESES, vImp, vExp, vGer, vCons),
    true, true, true
  );

  json._raw = sRaw.imp.map(([ts]) => {
    const d = new Date(ts);
    return { mes: `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}` };
  });
  atualizarUI(json, 'ano');
}

// ============================================================
// ARQUIVO: dashboard.js
// FUNÇÃO : carregarAnos
// PROJETO: Controlador de Injeção de Potência Elétrica
// AUTOR  : Claude Sonnet 4.6 / Anthropic
// DATA   : 2026-04-15
// VERSÃO : 2.4.0
// -------------------------------------------------------
// DEPENDÊNCIAS DE HARDWARE/SOFTWARE:
//   - ApexCharts >= 3.x
//   - API REST: endpoint API.anos
//   - Variáveis globais: CONTROLADOR_ID, chart, anosDisp
//   - Funções: extrairSeries(), buildChartOptions(),
//              atualizarUI()
// -------------------------------------------------------
// OBJETIVOS:
//   - Buscar histórico total de anos via API
//   - Exibir sempre os últimos 5 anos no eixo X
//   - Anos sem dados aparecem com valor null (gap no gráfico)
//   - Manter anosDisp com todos os anos disponíveis
// -------------------------------------------------------
// HISTÓRICO:
//   v1.0.0 - Implementação inicial
//   v2.0.0 - Integração com buildChartOptions
//   v2.2.0 - Estabilização modo total
//   v2.3.0 - [FIX] Eixo X limitado aos últimos 5 anos
//   v2.4.0 - [FIX] Eixo X sempre exibe 5 anos fixos,
//            mesmo sem dados. Anos ausentes preenchidos
//            com null via merge por timestamp do ano.
// ============================================================

async function carregarAnos() {
  const res  = await fetch(`${API.anos}?controlador_id=${CONTROLADOR_ID}`);
  const json = await res.json();
  if (!json.sucesso) { mostrarToastErro('API anos: ' + (json.erro ?? 'Erro')); return; }

  /* ── Lista completa de anos (preservada para navegação) ── */
  anosDisp = (json.series?.[0]?.data ?? [])
    .map(p => new Date(p[0]).getFullYear());

  if ((json.total_anos ?? 0) === 0)
    document.getElementById('sem-dados-msg').classList.add('visivel');

  /* ── Extrai séries completas da API ───────────────────── */
  const s = extrairSeries(json);

  /* ── Gera range fixo dos últimos 5 anos ───────────────── */
  const anoAtual = new Date().getFullYear();
  const anos5    = Array.from({ length: 5 }, (_, i) => anoAtual - 4 + i);
  // ex: [2022, 2023, 2024, 2025, 2026]

  /* ── Merge: garante 1 ponto por ano, null se ausente ──── */
  const merge = arr => {
    // Monta mapa  ano → valor
    const mapa = new Map(
      (arr ?? []).map(([ts, val]) => [new Date(ts).getFullYear(), val])
    );
    // Retorna array [timestamp_jan1, valor|null] para cada ano do range
    return anos5.map(ano => [
      new Date(`${ano}-01-01T00:00:00`).getTime(),
      mapa.has(ano) ? mapa.get(ano) : null,
    ]);
  };

  const sImp  = merge(s.imp);
  const sExp  = merge(s.exp);
  const sGer  = merge(s.ger);
  const sCons = merge(s.cons);

  /* ── Renderiza gráfico com range fixo de 5 anos ──────── */
  await chart.updateOptions(
    buildChartOptions('anos', null, sImp, sExp, sGer, sCons),
    true, true, true
  );

  /* ── Metadados para atualizarUI ───────────────────────── */
  
  json._raw = anos5.map(ano => ({ ano }));
  atualizarUI(json, 'anos');
  

  
}


/* ════════════════════════════════════════
   TROCA DE MODO
════════════════════════════════════════ */
/* ════════════════════════════════════════
   TROCA DE MODO — destrói chart ao trocar
════════════════════════════════════════ */
function setModo(modo) {
  if (modoAtual === modo) return;
  modoAtual = modo;
  atualizarToolbar();

  /* Destrói sempre ao trocar — evita conflito bar↔line */
  if (chart) { chart.destroy(); chart = null; }
  const el = document.getElementById('chart-potencia');
  chart = new ApexCharts(
    el,
    buildChartOptions(modo, [], [], [], [], [])
  );
  chart.render();

  if (modo === 'ano') {
    const sel   = document.getElementById('sel-ano');
    const anoC  = new Date().getFullYear();
    const lista = anosDisp.length
      ? anosDisp
      : Array.from({ length: 6 }, (_, i) => anoC - i);
    sel.innerHTML = lista
      .map(a => `<option value="${a}" ${a == anoAtual ? 'selected' : ''}>${a}</option>`)
      .join('');
  }

  carregarDados();
  AutoRefresh.reavaliar();
}
/* ════════════════════════════════════════
   FIX MOBILE — re-render após layout estabilizar
   Alguns navegadores mobile (Android Chrome, Safari iOS)
   renderizam o chart com width=0 no primeiro paint.
   Solução: forçar resize após window.load + pequeno delay.
════════════════════════════════════════ */
window.addEventListener('load', () => {
  // Aguarda o layout final (fonts, CSS, drawer, etc.)
  setTimeout(() => {
    if (chart && typeof chart.updateOptions === 'function') {
      // Trigger de reflow do ApexCharts
      chart.updateOptions({}, false, false);
    }
    // Dispara também um resize sintético (pega edge cases)
    window.dispatchEvent(new Event('resize'));
  }, 300);
});

/* ════════════════════════════════════════
   AUTO-REFRESH (modo DIA + data == hoje)
   v1.0 — silencioso, pausa em aba inativa
   @autor   ATGY
   @data    2026-06-02
════════════════════════════════════════ */
const AutoRefresh = (function() {
  const INTERVALO_MS = 5 * 60 * 1000; // 5min — produção
 //  const INTERVALO_MS = 10 * 1000;  // ← descomentar APENAS para testes manuais
  let timerId = null;

  function deveRodar() {
    return modoAtual === 'dia' && dataAtual === dataHoje() && !document.hidden;
  }

  function start() {
    if (timerId !== null) return; // já está rodando
    timerId = setInterval(tick, INTERVALO_MS);
    console.log('[AutoRefresh] iniciado (intervalo:', INTERVALO_MS, 'ms)');
  }

  function stop() {
    if (timerId === null) return;
    clearInterval(timerId);
    timerId = null;
    console.log('[AutoRefresh] parado');
  }

  async function tick() {
    if (!deveRodar()) { stop(); return; }
    console.log('[AutoRefresh] tick @', new Date().toLocaleTimeString('pt-BR'));
    try {
      await carregarDadosSilencioso();
    } catch (err) {
      console.warn('[AutoRefresh] erro silencioso:', err?.message || err);
    }
  }

  function reavaliar() {
    if (deveRodar()) start();
    else stop();
  }

  // Pausa/resume conforme visibilidade da aba
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
      stop();
    } else if (modoAtual === 'dia' && dataAtual === dataHoje()) {
      tick();   // refresh imediato ao voltar
      start();
    }
  });

  return { start, stop, reavaliar, tick };
})();

/* ════════════════════════════════════════
   INICIALIZAÇÃO
════════════════════════════════════════ */
(function init() {
  // 2026-05-18 [REMOVIDO] aplicarTema(temaAtual)
  // Motivo: tema agora e aplicado pelo snippet anti-FOUC do app_head.php
  // ANTES do primeiro paint, eliminando flash. tema.js cuida do resto.

  document.getElementById('inp-mes').value = mesAtual;
  atualizarToolbar();
  inicializarChart();

  // 2026-05-18 [ADD] Registra o chart no modulo global de tema para que
  // ele troque automaticamente entre dark/light quando o usuario alternar
  // o tema no botao do header. Protegido com optional chaining.
  if (window.CipTema?.registrarChart && chart) {
    window.CipTema.registrarChart(chart);
  }

  carregarDados().finally(() => {
    document.getElementById('loading').style.display = 'none';
    AutoRefresh.reavaliar();
  });
})();
</script>
</body>
</html>
