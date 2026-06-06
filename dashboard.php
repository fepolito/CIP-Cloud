<?php
/**
 * ============================================================
 * Arquivo   : dashboard.php
 * Projeto   : CIP — Controlador de Injeção de Potência Elétrica
 * Objetivo  : Dashboard gráfico de monitoramento de energia
 *
 * Dependências de hardware:
 *   - Servidor com MySQL/MariaDB acessível via localhost:3306
 *   - Navegador com suporte a HTML5, CSS3 e JavaScript
 *
 * Dependências de software/arquivos:
 *   - config/app.php
 *   - config/database.php   → factory getDbConnection()
 *   - app/auth.php
 *   - includes/app_head.php
 *   - includes/app_header.php
 *   - assets/css/app.css
 *   - assets/css/header.css
 *   - assets/js/app-shell.js
 *   - api/dashboard/dados.php
 *   - CDN: ApexCharts 3.44.0
 *
 * Histórico de implementações:
 *   2026-04-07  v1.0  Implementação inicial com ApexCharts
 *   2026-04-07  v1.1  verify.php no boot + logout com invalidação
 *   2026-04-08  v1.2  Migração JWT → sessão PHP
 *   2026-04-08  v1.3  Migração para app_header.php global
 *   2026-04-08  v1.4  requireAuth() do app/auth.php
 *   2026-04-08  v1.5  Remoção app-shell.js duplicado
 *   2026-04-11  v1.6  <head> refatorado: usa app_head.php
 *   2026-04-11  v1.7  Restauração completa do <style> interno
 *   2026-04-15  v1.8  [FIX] Adicionado require database.php +
 *                     $pdo = getDbConnection() — ausência causava
 *                     Fatal Error no app_header.php (query logo)
 *                     Corrigido $appPaginaAtual 'inicio' → 'dashboard'
 * ============================================================
 */

require_once __DIR__ . '/config/app.php';       // ✅ ADICIONADO
require_once __DIR__ . '/config/database.php';  // ✅ ADICIONADO
require_once __DIR__ . '/app/auth.php';

$pdo = getDbConnection();                        // ✅ ADICIONADO

requireAuth();

$appTituloPagina = 'Dashboard';
$appPaginaAtual  = 'dashboard';                  // ✅ CORRIGIDO (era 'inicio')
$appUsuarioNome  = $_SESSION['usuario_nome']  ?? 'Usuário';
$appIsAdmin      = in_array($_SESSION['usuario_perfil'] ?? '', [
    'master', 'master_operador', 'administrador'
], true);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <?php require __DIR__ . '/includes/app_head.php'; ?>
  <!-- ApexCharts — CDN exclusivo desta página -->
  <script src="https://cdn.jsdelivr.net/npm/apexcharts@3.44.0/dist/apexcharts.min.js"></script>
  <style>
    /* ══════════════════════════════════════════════
       TOKENS
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
    }

    /* ── Reset ───────────────────────────────────── */
    *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

    html {
      scroll-behavior: smooth;
      height: 100%;
    }

    body {
      background: var(--bg);
      color: var(--txt);
      font-family: 'Segoe UI', system-ui, sans-serif;
      font-size: 14px;
      min-height: 100%;
      overflow-x: hidden;
      overflow-y: auto;
      -webkit-text-size-adjust: 100%;
    }

    body.app-sidebar-open { overflow: hidden; }

    ::-webkit-scrollbar { width: 6px; }
    ::-webkit-scrollbar-track { background: var(--bg); }
    ::-webkit-scrollbar-thumb { background: var(--border); border-radius: 3px; }

    /* ── Wrapper ─────────────────────────────────── */
    .wrap {
      max-width: 1440px;
      margin: 0 auto;
      padding: 80px 24px 40px;
    }

    /* ══════════════════════════════════════════════
       LOADING OVERLAY
    ══════════════════════════════════════════════ */
    #loading {
      position: fixed;
      inset: 0;
      background: rgba(7, 11, 20, .95);
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      z-index: 1300;
      gap: 18px;
    }
    #loading .spin {
      width: 44px; height: 44px;
      border: 3px solid var(--border);
      border-top-color: var(--blue);
      border-radius: 50%;
      animation: spin .7s linear infinite;
    }
    #loading p {
      color: var(--txt-mid);
      font-size: 12px;
      letter-spacing: 2px;
    }
    @keyframes spin { to { transform: rotate(360deg); } }

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

    .periodo-btns {
      display: flex;
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 10px;
      overflow: hidden;
    }

    .pb {
      background: transparent;
      border: none;
      color: var(--txt-mid);
      cursor: pointer;
      font-size: 12px;
      font-weight: 700;
      letter-spacing: 1px;
      padding: 10px 20px;
      transition: all .2s;
      border-right: 1px solid var(--border);
      touch-action: manipulation;
      -webkit-tap-highlight-color: transparent;
    }
    .pb:last-child { border-right: none; }
    .pb:hover      { color: var(--blue); }
    .pb.ativo      { background: var(--blue2); color: #fff; }

    .refresh-info {
      color: var(--txt-dim);
      font-size: 11px;
      white-space: nowrap;
    }

    /* ══════════════════════════════════════════════
       KPI GRID
    ══════════════════════════════════════════════ */
    .kpi-grid {
      display: grid;
      grid-template-columns: repeat(6, 1fr);
      gap: 14px;
      margin-bottom: 20px;
    }

    .kpi {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 14px;
      padding: 18px 16px;
      position: relative;
      overflow: hidden;
      transition: transform .2s, border-color .2s;
    }
    .kpi:hover {
      transform: translateY(-2px);
      border-color: var(--kc, var(--blue));
    }
    .kpi::after {
      content: '';
      position: absolute;
      bottom: 0; left: 0; right: 0;
      height: 2px;
      background: var(--kc, var(--blue));
    }

    .kpi-lbl {
      color: var(--txt-dim);
      font-size: 10px;
      font-weight: 700;
      letter-spacing: 1.5px;
      text-transform: uppercase;
      margin-bottom: 10px;
    }

    .kpi-val {
      font-size: clamp(16px, 3vw, 24px);
      font-weight: 800;
      line-height: 1;
      color: var(--txt);
    }
    .kpi-val .unit {
      font-size: 12px;
      font-weight: 400;
      color: var(--txt-mid);
      margin-left: 2px;
    }

    .kpi-sub {
      color: var(--txt-dim);
      font-size: 11px;
      margin-top: 8px;
    }

    .kpi-icon {
      position: absolute;
      top: 14px; right: 14px;
      font-size: 24px;
      opacity: .12;
    }

    /* ── Status pill (Online/Offline) ───────────── */
    .kpi-status-pill {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 6px 14px;
      border-radius: 999px;
      font-size: 13px;
      font-weight: 800;
      letter-spacing: 1px;
      margin-top: 6px;
    }
    .kpi-status-pill.online  { background: rgba(0,230,118,.12); color: var(--green); }
    .kpi-status-pill.offline { background: rgba(255,82,82,.12);  color: var(--red);  }

    .pill-dot {
      width: 8px; height: 8px;
      border-radius: 50%;
      flex-shrink: 0;
    }
    .online  .pill-dot { background: var(--green); box-shadow: 0 0 6px var(--green); }
    .offline .pill-dot { background: var(--red); }

    /* ══════════════════════════════════════════════
       CHART ROWS
    ══════════════════════════════════════════════ */
    .chart-row { margin-bottom: 16px; }

    .chart-row.two,
    .chart-row.three {
      display: grid;
      gap: 16px;
    }
    .chart-row.two   { grid-template-columns: 1fr 1fr; }
    .chart-row.three { grid-template-columns: 1fr 1fr; }

    /* ══════════════════════════════════════════════
       CHART CARD
    ══════════════════════════════════════════════ */
    .ccard {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 14px;
      padding: 18px 20px 10px;
      overflow: hidden;
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
      background: rgba(0,180,255,.1);
      border: 1px solid rgba(0,180,255,.2);
      border-radius: 6px;
      color: var(--blue);
      font-size: 10px;
      font-weight: 700;
      letter-spacing: 1px;
      padding: 2px 8px;
    }

    .hint {
      color: var(--txt-dim);
      font-size: 10px;
      letter-spacing: .5px;
    }

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
      padding: 18px 16px;
    }

    .tot-title {
      font-size: 12px;
      font-weight: 700;
      color: var(--txt-mid);
      letter-spacing: .5px;
      margin-bottom: 14px;
      padding-bottom: 10px;
      border-bottom: 1px solid var(--border);
    }

    .tot-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 6px 0;
      border-bottom: 1px solid rgba(26,45,74,.6);
      font-size: 13px;
    }
    .tot-row:last-child { border-bottom: none; }

    .tot-k { color: var(--txt-dim); }
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

    /* Tablet largo */
    @media (max-width: 1200px) {
      .kpi-grid { grid-template-columns: repeat(3, 1fr); }
    }

    /* Tablet */
    @media (max-width: 1024px) {
      .kpi-grid            { grid-template-columns: repeat(3, 1fr); }
      .chart-row.two,
      .chart-row.three     { grid-template-columns: 1fr; }
      .tot-grid            { grid-template-columns: repeat(2, 1fr); }
    }

    /* Mobile grande */
    @media (max-width: 768px) {
      .wrap { padding: 72px 14px 32px; }

      .toolbar { flex-direction: column; gap: 10px; }
      .ctrl-info,
      .periodo-group { width: 100%; }

      .kpi-grid { grid-template-columns: repeat(2, 1fr); }
      .tot-grid { grid-template-columns: 1fr; }

      .pb { padding: 12px 18px; font-size: 13px; }
    }

    /* Mobile pequeno */
    @media (max-width: 480px) {
      .kpi-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 10px;
      }

      .kpi { padding: 14px 12px; }
      .kpi-lbl  { font-size: 9px; }
      .kpi-icon { font-size: 20px; }

      /* Brush desnecessário em tela muito pequena */
      #chartBrush { display: none !important; }

      .ccard { padding: 14px 14px 8px; }
      .hint  { display: none; }

      .chart-row.two,
      .chart-row.three { grid-template-columns: 1fr; }

      .tot-grid { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>

<!-- ── Loading overlay ──────────────────────────────────────── -->
<div id="loading">
  <div class="spin"></div>
  <p>VERIFICANDO SESSÃO...</p>
</div>

<!-- ── Cabeçalho global ─────────────────────────────────────── -->
<?php require __DIR__ . '/includes/app_header.php'; ?>

<!-- ── Conteúdo principal ───────────────────────────────────── -->
<div class="wrap">

  <div class="toolbar">
    <div class="ctrl-info">
      <div class="label">Controlador Ativo</div>
      <div class="nome" id="nomeCtrl">Carregando...</div>
    </div>
    <div class="periodo-group">
      <div class="periodo-btns">
        <button class="pb ativo" onclick="setPeriodo('1h',this)">1H</button>
        <button class="pb"       onclick="setPeriodo('6h',this)">6H</button>
        <button class="pb"       onclick="setPeriodo('24h',this)">24H</button>
        <button class="pb"       onclick="setPeriodo('7d',this)">7D</button>
      </div>
      <span class="refresh-info" id="refreshInfo">⏱ —</span>
    </div>
  </div>

  <!-- KPIs principais -->
  <div class="kpi-grid">
    <div class="kpi" style="--kc:var(--blue)">
      <div class="kpi-lbl">Tensão</div>
      <div class="kpi-val" id="kTensao">—<span class="unit">V</span></div>
      <div class="kpi-sub" id="kTensaoR">min — / max —</div>
      <div class="kpi-icon">🔌</div>
    </div>
    <div class="kpi" style="--kc:var(--orange)">
      <div class="kpi-lbl">Corrente</div>
      <div class="kpi-val" id="kCorrente">—<span class="unit">A</span></div>
      <div class="kpi-sub">Leitura atual</div>
      <div class="kpi-icon">⚡</div>
    </div>
    <div class="kpi" style="--kc:var(--green)">
      <div class="kpi-lbl">Potência Ativa</div>
      <div class="kpi-val" id="kPotencia">—<span class="unit">W</span></div>
      <div class="kpi-sub" id="kPotMax">máx hoje: —</div>
      <div class="kpi-icon">📊</div>
    </div>
    <div class="kpi" style="--kc:var(--purple)">
      <div class="kpi-lbl">Fator de Potência</div>
      <div class="kpi-val" id="kFP">—<span class="unit"></span></div>
      <div class="kpi-sub" id="kFPst">—</div>
      <div class="kpi-icon">🔄</div>
    </div>
    <div class="kpi" style="--kc:var(--yellow)">
      <div class="kpi-lbl">Frequência</div>
      <div class="kpi-val" id="kFreq">—<span class="unit">Hz</span></div>
      <div class="kpi-sub">Nominal: 60 Hz</div>
      <div class="kpi-icon">〰️</div>
    </div>
    <div class="kpi" style="--kc:var(--cyan)">
      <div class="kpi-lbl">Energia Acumulada</div>
      <div class="kpi-val" id="kEnergia">—<span class="unit">kWh</span></div>
      <div class="kpi-sub" id="kCusto">Custo est.: R$ —</div>
      <div class="kpi-icon">🔋</div>
    </div>
  </div>

  <!-- KPIs status do controlador -->
  <div class="kpi-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:20px;">
    <div class="kpi" style="--kc:var(--green)">
      <div class="kpi-lbl">Status do Controlador</div>
      <div id="statusPill" class="kpi-status-pill online">
        <div class="pill-dot"></div>
        <span id="statusTxt">VERIFICANDO</span>
      </div>
      <div class="kpi-sub" id="tPing">Último ping: —</div>
      <div class="kpi-icon">📡</div>
    </div>
    <div class="kpi" style="--kc:var(--blue2)">
      <div class="kpi-lbl">Localização</div>
      <div class="kpi-val" style="font-size:14px;line-height:1.4" id="tLoc">—</div>
      <div class="kpi-icon">📍</div>
    </div>
    <div class="kpi" style="--kc:var(--txt-mid)">
      <div class="kpi-lbl">Controlador</div>
      <div class="kpi-val" style="font-size:14px;line-height:1.4" id="nomeCtrlKpi">—</div>
      <div class="kpi-icon">🖥️</div>
    </div>
  </div>

  <!-- Gráfico Potência + Brush -->
  <div class="chart-row one">
    <div class="ccard">
      <div class="ccard-head">
        <span class="ccard-title">
          ⚡ Potência Ativa
          <span class="badge" id="badgePeriodo">1H</span>
        </span>
        <span class="hint">🔍 Arraste para zoom · Duplo-clique para resetar</span>
      </div>
      <div id="chartPotencia"></div>
      <div id="chartBrush"></div>
    </div>
  </div>

  <!-- Gráficos Tensão + Corrente -->
  <div class="chart-row two">
    <div class="ccard">
      <div class="ccard-head">
        <span class="ccard-title">🔌 Tensão <span class="badge">VRMS</span></span>
        <span class="hint">Nominal: 127V / 220V</span>
      </div>
      <div id="chartTensao"></div>
    </div>
    <div class="ccard">
      <div class="ccard-head">
        <span class="ccard-title">〰️ Corrente <span class="badge">IRMS</span></span>
        <span class="hint">Corrente eficaz</span>
      </div>
      <div id="chartCorrente"></div>
    </div>
  </div>

  <!-- Gráficos Frequência + Gauge FP -->
  <div class="chart-row three">
    <div class="ccard">
      <div class="ccard-head">
        <span class="ccard-title">📡 Frequência <span class="badge">Hz</span></span>
        <span class="hint">Referência: 60 Hz ± 0.2</span>
      </div>
      <div id="chartFreq"></div>
    </div>
    <div class="ccard">
      <div class="ccard-head">
        <span class="ccard-title">🔄 Fator de Potência <span class="badge">cos φ</span></span>
        <span class="hint">Meta: ≥ 0.92</span>
      </div>
      <div id="chartGauge"></div>
      <div id="gaugeLegenda" style="text-align:center;padding-bottom:8px">
        <span style="color:var(--txt-dim);font-size:11px;letter-spacing:1px">STATUS</span>
        <div id="gaugeStatus" style="font-size:13px;font-weight:700;margin-top:4px">—</div>
      </div>
    </div>
  </div>

  <!-- Totalizadores -->
  <div class="tot-grid">
    <div class="tot-card">
      <div class="tot-title">📊 Tensão — Dia</div>
      <div class="tot-row"><span class="tot-k">Mínima</span>   <span class="tot-v" id="tTVmin">—</span></div>
      <div class="tot-row"><span class="tot-k">Máxima</span>   <span class="tot-v" id="tTVmax">—</span></div>
      <div class="tot-row"><span class="tot-k">Média</span>    <span class="tot-v" id="tTVmed">—</span></div>
      <div class="tot-row"><span class="tot-k">Leituras</span> <span class="tot-v" id="tLeit">—</span></div>
    </div>
    <div class="tot-card">
      <div class="tot-title">⚡ Potência — Dia</div>
      <div class="tot-row"><span class="tot-k">Máxima</span>   <span class="tot-v" id="tPmax">—</span></div>
      <div class="tot-row"><span class="tot-k">Média</span>    <span class="tot-v" id="tPmed">—</span></div>
      <div class="tot-row"><span class="tot-k">FP Médio</span> <span class="tot-v" id="tFPm">—</span></div>
    </div>
    <div class="tot-card">
      <div class="tot-title">💰 Custo Estimado</div>
      <div class="tot-row"><span class="tot-k">Energia Hoje</span> <span class="tot-v" id="tEkwh">—</span></div>
      <div class="tot-row"><span class="tot-k">Custo Hoje</span>   <span class="tot-v" id="tCdia">—</span></div>
      <div class="tot-row"><span class="tot-k">Projeção Mês</span> <span class="tot-v" id="tCmes">—</span></div>
    </div>
  </div>

</div><!-- /wrap -->

<footer class="footer">
  CIP — Controlador de Injeção de Potência Elétrica &nbsp;|&nbsp;
  Aeonium &nbsp;|&nbsp; São Paulo, BR &nbsp;|&nbsp; v1.3.0
</footer>

<script>
'use strict';

const CTRL_ID  = 1;
const INTERVAL = 10000;

let periodo = '1h';
let timer   = null;

const C = {
  blue:   '#00b4ff', blue2:  '#0070cc', green:  '#00e676',
  orange: '#ff9800', yellow: '#ffc107', red:    '#ff5252',
  purple: '#ce93d8', cyan:   '#18ffff', dim:    '#1a2d4a',
  bg:     '#0d1526', txt:    '#7a9cc4',
};

let apPotencia = null, apBrush = null, apTensao  = null,
    apCorrente = null, apFreq  = null, apGauge   = null;

function baseCfg(cor, unidade) {
  return {
    chart: {
      type: 'area', height: 240, background: 'transparent',
      zoom: { enabled: true, type: 'x', autoScaleYaxis: true },
      pan:  { enabled: true },
      toolbar: {
        show: true,
        tools: { download: false, selection: true, zoom: true,
                 zoomin: true, zoomout: true, pan: true, reset: true },
      },
      animations: { enabled: false },
      redrawOnWindowResize: true,
      redrawOnParentResize: true,
    },
    theme:  { mode: 'dark' },
    colors: [cor],
    stroke: { curve: 'smooth', width: 2 },
    fill: {
      type: 'gradient',
      gradient: { shadeIntensity: 1, opacityFrom: 0.35, opacityTo: 0.03, stops: [0, 95] },
    },
    dataLabels: { enabled: false },
    markers:    { size: 0, hover: { size: 4 } },
    grid: { borderColor: C.dim, strokeDashArray: 3, xaxis: { lines: { show: false } } },
    xaxis: {
      type: 'datetime',
      labels: {
        style: { colors: C.txt, fontSize: '11px' }, datetimeUTC: false,
        datetimeFormatter: { hour: 'HH:mm', minute: 'HH:mm:ss', day: 'dd/MM' },
        hideOverlappingLabels: true,
      },
      axisBorder: { color: C.dim }, axisTicks: { color: C.dim },
    },
    yaxis: {
      labels: {
        style: { colors: C.txt, fontSize: '11px' },
        formatter: v => v !== undefined ? `${v.toFixed(2)} ${unidade}` : '—',
      },
    },
    tooltip: {
      theme: 'dark', x: { format: 'dd/MM HH:mm:ss' },
      y: { formatter: v => `${v?.toFixed(3)} ${unidade}` },
      style: { fontSize: '12px' },
    },
  };
}

function initCharts() {
  apPotencia = new ApexCharts(document.getElementById('chartPotencia'), {
    ...baseCfg(C.green, 'W'),
    chart: { ...baseCfg(C.green, 'W').chart, id: 'potencia-main', height: 280 },
    series: [{ name: 'Potência W', data: [] }],
    annotations: { yaxis: [{ y: 0, borderColor: C.dim,
      label: { text: '0W', style: { color: C.txt, background: C.bg } } }] },
  });
  apPotencia.render();

  apBrush = new ApexCharts(document.getElementById('chartBrush'), {
    chart: {
      id: 'potencia-brush', height: 80, type: 'area', background: 'transparent',
      brush: { target: 'potencia-main', enabled: true },
      selection: {
        enabled: true,
        fill:   { color: C.blue, opacity: .15 },
        stroke: { color: C.blue, opacity: .4, width: 1 },
      },
      animations: { enabled: false }, toolbar: { show: false },
    },
    theme: { mode: 'dark' }, colors: [C.green],
    series: [{ name: 'Potência W', data: [] }],
    stroke: { width: 1, curve: 'smooth' }, fill: { opacity: .2 },
    dataLabels: { enabled: false }, markers: { size: 0 },
    xaxis: { type: 'datetime', tooltip: { enabled: false },
             labels: { show: false }, axisBorder: { color: C.dim } },
    yaxis: { show: false }, grid: { borderColor: C.dim },
  });
  apBrush.render();

  apTensao = new ApexCharts(document.getElementById('chartTensao'), {
    ...baseCfg(C.blue, 'V'),
    chart: { ...baseCfg(C.blue, 'V').chart, id: 'tensao', height: 230 },
    series: [{ name: 'Tensão V', data: [] }],
    annotations: { yaxis: [
      { y: 220, borderColor: '#ffc10755',
        label: { text: '220V', style: { color: C.yellow, background: C.bg } } },
      { y: 127, borderColor: '#00b4ff55',
        label: { text: '127V', style: { color: C.blue,   background: C.bg } } },
    ]},
  });
  apTensao.render();

  apCorrente = new ApexCharts(document.getElementById('chartCorrente'), {
    ...baseCfg(C.orange, 'A'),
    chart: { ...baseCfg(C.orange, 'A').chart, id: 'corrente', height: 230 },
    series: [{ name: 'Corrente A', data: [] }],
  });
  apCorrente.render();

  apFreq = new ApexCharts(document.getElementById('chartFreq'), {
    ...baseCfg(C.yellow, 'Hz'),
    chart: { ...baseCfg(C.yellow, 'Hz').chart, id: 'freq', height: 230 },
    series: [{ name: 'Frequência Hz', data: [] }],
    annotations: { yaxis: [
      { y: 60.2, borderColor: '#ff525255',
        label: { text: '+0.2Hz', style: { color: C.red,   background: C.bg } } },
      { y: 60,   borderColor: '#00e67655',
        label: { text: '60Hz',  style: { color: C.green,  background: C.bg } } },
      { y: 59.8, borderColor: '#ff525255',
        label: { text: '-0.2Hz', style: { color: C.red,   background: C.bg } } },
    ]},
    yaxis: { ...baseCfg(C.yellow, 'Hz').yaxis, min: 59.0, max: 61.0 },
  });
  apFreq.render();

  apGauge = new ApexCharts(document.getElementById('chartGauge'), {
    chart: {
      type: 'radialBar', height: 260, background: 'transparent',
      toolbar: { show: false }, animations: { enabled: true, speed: 600 },
    },
    theme: { mode: 'dark' }, series: [0], labels: ['cos φ'], colors: [C.red],
    plotOptions: {
      radialBar: {
        startAngle: -135, endAngle: 135,
        hollow:  { size: '60%', background: C.bg },
        track:   { background: C.dim, strokeWidth: '97%' },
        dataLabels: {
          name:  { offsetY: -10, color: C.txt, fontSize: '12px', fontWeight: '600' },
          value: { offsetY: 10, color: '#e0eaf8', fontSize: '28px', fontWeight: '800',
                   formatter: v => `${(v / 100).toFixed(2)}` },
        },
      },
    },
    fill: {
      type: 'gradient',
      gradient: { shade: 'dark', type: 'horizontal', shadeIntensity: .5,
                  gradientToColors: [C.green], stops: [0, 100] },
    },
    stroke: { lineCap: 'round' },
  });
  apGauge.render();
}

function updateSeries(chart, nome, dados) {
  chart.updateSeries([{ name: nome, data: dados }], false);
}

function corFP(fp) {
  if (fp >= 0.92) return C.green;
  if (fp >= 0.80) return C.yellow;
  if (fp >= 0.60) return C.orange;
  return C.red;
}

function statusFP(fp) {
  if (fp >= 0.92) return { txt: 'ÓTIMO',    cor: C.green  };
  if (fp >= 0.80) return { txt: 'BOM',      cor: C.yellow };
  if (fp >= 0.60) return { txt: 'REGULAR',  cor: C.orange };
  return                   { txt: 'CRÍTICO', cor: C.red    };
}

const fmt = (v, d, u) => v != null ? `${(+v).toFixed(d)} ${u}`.trim() : '—';

async function verificarToken() {
  try {
    const res = await fetch('/api/auth/verify.php', { method: 'GET', credentials: 'same-origin' });
    if (!res.ok) { window.location.href = '/login.php'; return false; }
    const data = await res.json();
    if (!data.success) { window.location.href = '/login.php'; return false; }
    sessionStorage.setItem('cip_usuario_nome',  data.usuario.nome);
    sessionStorage.setItem('cip_usuario_email', data.usuario.email);
    if (data.segundos_restantes < 1800)
      console.warn(`[CIP] Sessão expira em ${data.segundos_restantes}s`);
    return true;
  } catch (e) {
    console.warn('[CIP] verify.php inacessível — modo offline');
    return true;
  }
}

async function logout() {
  try {
    await fetch('/api/auth/logout.php', { method: 'POST', credentials: 'same-origin' });
  } catch (e) {
    // ignora
  } finally {
    sessionStorage.clear();
    window.location.href = '/login.php';
  }
}

async function carregar() {
  try {
    const res = await fetch(
      `/api/dashboard/dados.php?controlador_id=${CTRL_ID}&periodo=${periodo}`,
      { credentials: 'same-origin' }
    );
    if (res.status === 401) { logout(); return; }
    const json = await res.json();
    if (!json.success) return;

    const { atual, totais, controlador, custo_dia, series } = json;

    if (atual?.tensao_v !== undefined) {
      const fp = parseFloat(atual.fator_potencia);
      const st = statusFP(fp);

      document.getElementById('kTensao').innerHTML   = `${(+atual.tensao_v).toFixed(1)}<span class="unit">V</span>`;
      document.getElementById('kCorrente').innerHTML = `${(+atual.corrente_a).toFixed(3)}<span class="unit">A</span>`;
      document.getElementById('kPotencia').innerHTML = `${(+atual.potencia_w).toFixed(1)}<span class="unit">W</span>`;

      const fpEl       = document.getElementById('kFP');
      fpEl.innerHTML   = `${fp.toFixed(2)}<span class="unit"></span>`;
      fpEl.style.color = corFP(fp);

      document.getElementById('kFPst').textContent =
        fp >= 0.92 ? '✅ Ótimo (≥0.92)' : fp >= 0.80 ? '⚠️ Bom (≥0.80)' :
        fp >= 0.60 ? '🟠 Regular' : '🔴 Crítico';

      document.getElementById('kFreq').innerHTML    = `${(+atual.frequencia_hz).toFixed(2)}<span class="unit">Hz</span>`;
      document.getElementById('kEnergia').innerHTML = `${(+atual.energia_kwh).toFixed(3)}<span class="unit">kWh</span>`;
      document.getElementById('kCusto').textContent = `Custo est.: R$ ${custo_dia.toFixed(2)}`;

      apGauge.updateSeries([Math.round(fp * 100)]);
      apGauge.updateOptions({ colors: [corFP(fp)],
        fill: { gradient: { gradientToColors: [corFP(fp)] } } }, false, false);

      const stEl       = document.getElementById('gaugeStatus');
      stEl.textContent = st.txt;
      stEl.style.color = st.cor;
    }

    if (totais) {
      document.getElementById('tTVmin').textContent   = fmt(totais.tensao_min,    1, 'V');
      document.getElementById('tTVmax').textContent   = fmt(totais.tensao_max,    1, 'V');
      document.getElementById('tTVmed').textContent   = fmt(totais.tensao_media,  1, 'V');
      document.getElementById('tLeit').textContent    = totais.total_leituras ?? '—';
      document.getElementById('tPmax').textContent    = fmt(totais.potencia_max,   1, 'W');
      document.getElementById('tPmed').textContent    = fmt(totais.potencia_media, 1, 'W');
      document.getElementById('tFPm').textContent     = fmt(totais.fp_medio, 2, '');
      document.getElementById('kTensaoR').textContent =
        `min ${fmt(totais.tensao_min,1,'V')} / max ${fmt(totais.tensao_max,1,'V')}`;
      document.getElementById('kPotMax').textContent  =
        `máx hoje: ${fmt(totais.potencia_max,1,'W')}`;
    }

    if (controlador) {
      const nomeCtrl = controlador.nome || `Controlador #${CTRL_ID}`;
      document.getElementById('nomeCtrl').textContent    = nomeCtrl;
      document.getElementById('nomeCtrlKpi').textContent = nomeCtrl;
      document.getElementById('tLoc').textContent        = controlador.localizacao || '—';

      const pingStr = controlador.ultimo_ping
        ? new Date(controlador.ultimo_ping).toLocaleString('pt-BR') : '—';
      document.getElementById('tPing').textContent = `Último ping: ${pingStr}`;

      const diff     = controlador.ultimo_ping
        ? (Date.now() - new Date(controlador.ultimo_ping).getTime()) / 1000 : 999;
      const isOnline = diff <= 30;
      document.getElementById('statusPill').className  = `kpi-status-pill ${isOnline ? 'online' : 'offline'}`;
      document.getElementById('statusTxt').textContent = isOnline ? 'ONLINE' : 'OFFLINE';
    }

    const kwh = atual?.energia_kwh ? +(atual.energia_kwh) : 0;
    document.getElementById('tEkwh').textContent = `${kwh.toFixed(3)} kWh`;
    document.getElementById('tCdia').textContent = `R$ ${custo_dia.toFixed(2)}`;
    document.getElementById('tCmes').textContent = `R$ ${(custo_dia * 30).toFixed(2)}`;

    if (series) {
      updateSeries(apPotencia, 'Potência W',    series.potencia);
      updateSeries(apBrush,    'Potência W',    series.potencia);
      updateSeries(apTensao,   'Tensão V',      series.tensao);
      updateSeries(apCorrente, 'Corrente A',    series.corrente);
      updateSeries(apFreq,     'Frequência Hz', series.freq);
    }

    document.getElementById('refreshInfo').textContent =
      '⏱ ' + new Date().toLocaleTimeString('pt-BR') + ' · auto 10s';
    document.getElementById('loading').style.display = 'none';

  } catch (err) {
    console.error('[CIP] Erro ao carregar dados:', err);
    document.getElementById('refreshInfo').textContent = '⚠️ Falha na última atualização';
    document.getElementById('loading').style.display   = 'none';
  }
}

function setPeriodo(p, btn) {
  periodo = p;
  document.querySelectorAll('.pb').forEach(b => b.classList.remove('ativo'));
  btn.classList.add('ativo');
  document.getElementById('badgePeriodo').textContent = p.toUpperCase();
  carregar();
}

function startTimer() {
  if (timer) clearInterval(timer);
  timer = setInterval(carregar, INTERVAL);
}

// ── Boot ──────────────────────────────────────────────────────
verificarToken().then(ok => {
  if (!ok) return;
  initCharts();
  carregar();
  startTimer();
});
</script>
</body>
</html>
