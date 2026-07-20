<?php
/**
 * @arquivo       dashboard.php
 * @versao        1.19.0
 * @modificado_em 2026-07-19
 * @objetivo      Interface principal de visualização de telemetria e fluxos
 * @autor         Fernando / CIP Cloud Copilot / ATGY
 *
 * Dependências de hardware:
 *   - Servidor com MySQL/MariaDB acessível via localhost:3306
 *   - Navegador com suporte a HTML5, CSS3 e JavaScript ES6+
 *
 * Dependências de software/arquivos:
 *   - config/app.php
 *   - config/database.php   → factory getDbConnection()
 *   - app/auth.php
 *   - app/helpers/Tenant.php
 *   - includes/app_head.php
 *   - includes/app_header.php
 *   - assets/css/app.css
 *   - assets/css/header.css
 *   - assets/js/app-shell.js
 *   - api/dashboard/dados.php  (legado, alimenta KPIs do topo)
 *
 * Histórico de implementações:
 *   2026-04-07  v1.0     Implementação inicial com ApexCharts
 *   2026-04-07  v1.1     verify.php no boot + logout com invalidação
 *   2026-04-08  v1.2     Migração JWT → sessão PHP
 *   2026-04-08  v1.3     Migração para app_header.php global
 *   2026-04-08  v1.4     requireAuth() do app/auth.php
 *   2026-04-08  v1.5     Remoção app-shell.js duplicado
 *   2026-04-11  v1.6     <head> refatorado: usa app_head.php
 *   2026-04-11  v1.7     Restauração completa do <style> interno
 *   2026-04-15  v1.8     [FIX] Adicionado require database.php +
 *                        $pdo = getDbConnection() — ausência causava
 *                        Fatal Error no app_header.php (query logo)
 *                        Corrigido $appPaginaAtual 'inicio' → 'dashboard'
 *   2026-06-07  v1.9.0   [ADD] Suporte a tema claro/escuro global via
 *                        window.CipTema. Registro dos 6 ApexCharts pos-render.
 *                        Bloco CSS html[data-tema="claro"] espelhando energia.php.
 *                        Persistencia cross-page garantida via localStorage.
 *   2026-06-07  vB1.0.0  Patch B1: portado padrao multi-tenant de energia.php
 *                        (Tenant::filtroSQL, $controladorAtivo, persistencia
 *                        em $_SESSION). Removido const CTRL_ID=1 hardcoded.
 *                        Adicionado lerCoresCss() + MutationObserver para
 *                        reatividade de tema nos 6 graficos PDE.
 *   2026-06-07  v1.10.0  Patch B2.1: REMOCAO cirurgica dos 6 graficos PDE
 *                        (Potencia + Brush, Tensao, Corrente, Frequencia,
 *                        Gauge FP), botoes de periodo 1H/6H/24H/7D,
 *                        totalizadores (Tensao Dia/Pot Dia/Custo Est),
 *                        CDN ApexCharts, MutationObserver dos charts e
 *                        funcoes auxiliares (initCharts, updateSeries,
 *                        statusFP, baseCfg, setPeriodo, startTimer).
 *                        Adicionadas areas reservadas #infografico-host
 *                        (B2.2) e #cards-host (B2.3). KPIs do topo
 *                        (6 eletricos + 3 status) mantidos intactos.
 *                        Auto-refresh de 10s preservado apenas para KPIs.
 *   2026-06-07  v1.11.0  Patch B2.2 (Parte 2/3): infografico SVG animado
 *                        de fluxo energetico (FV, Rede, Imovel, Bateria).
 *                        HTML+SVG+CSS apenas; valores em placeholder ate
 *                        Parte 3/3 ativar o polling de 30s.
 *   2026-06-07  v1.12.0  Patch B2.2 (Parte 3/3): JavaScript de polling
 *                        30s + binding dinamico de valores + velocidade
 *                        adaptativa das setas (log10 sobre kW). Namespace
 *                        isolado window.CIP_Infografico. Pausa em
 *                        background, retry em erro, modo debug via ?debug=1.
 *   2026-06-07  v1.12.1  Patch B2.2.1: Hotfix UX Infografico (estado "sem
 *                        geracao" = N/D, sinais de fluxo padronizados, cores 
 *                        honestas do inversor) e limpeza de cards legados.
 *   2026-06-07  v1.13.0  Patch B2.2.2: Layout hibrido kWh+kW no infografico,
 *                        animacao independente das setas (fix bug noturno
 *                        onde tudo congelava com geracao=null), degradacao
 *                        graciosa se payload sem energia_dia.
 *   2026-07-09  v1.13.1  Fix animação: classes de estado inválidas (prefixo --) e
 *                        ausência de regra CSS de fluxo ativo. Setas agora animam.
 *   2026-07-09  v1.13.2  Fix stroke-dasharray para loop matemático perfeito no fluxo SVG.
 *   2026-07-09  v1.13.3  Reduz fator de cálculo da velocidade da animação do fluxo no JS.
 *   2026-07-09  v1.13.4  Ajuste do filtro SVG glow para userSpaceOnUse e simplificação 
 *                        da animação reversa com animation-direction.
 *   2026-07-09  v1.13.5  Reversão da animação da exportada para @keyframes explícito
 *                        (fluxo-mover-exportada) com stroke-dashoffset 36.
 *   2026-07-09  v1.13.6  Ajuste de sinal do stroke-dashoffset (para negativo) no
 *                        fluxo exportado a pedido, alinhando com a orientação real do path.
 *   2026-07-12  v1.14.1  [FIX] Remoção do card solto de Frequência (#kFreq)
 *                        redundante — dado já exibido no rodapé do
 *                        infográfico (#infoFreq). Removido HTML .kpi-grid
 *                        órfão + binding JS em carregarKpis(). CSS .kpi*
 *                        mantido (compartilhado com KPIs de status).
 *   2026-07-12  v1.14.2  [FIX] Badge de status honesto: pill nasce em
 *                        estado neutro 'verificando' (cinza pulsante) em
 *                        vez de 'online' verde falso. Evita estado inicial
 *                        mentiroso entre load e primeiro fetch de KPIs.
 *   2026-07-15  v1.15.0  Infografico -> background fixo; cards 2x2 (resumo_dia.php);
 *                        fix semaforo (fluxo via resumo_dia, idade via dados.php) [CIP-DEC-20260715-001]
 *   2026-07-15  v1.15.1  Fix stacking: cards ficavam atrás do infografico (z-index:-1 furava). isolation:isolate + z-index positivo.
 *   2026-07-17  v1.17.2  [FIX] Correção syntax error (chave órfã) + Modo TV minimalista SVG + Fix persistência seletor.
 *   2026-07-17  v1.17.3  [FIX] Restauração do card-energia (Balanço Energético) após Hipótese B.
 *   2026-07-18  v1.18.0  [ADD] Card economia (Lei 14.300) em #cards-host,
 *                        carona no ciclo 10s, badge fator_injecao. CIP-DEC-20260718-012
 *   2026-07-19  v1.18.10 [ADD] Card economia do mês e refatoração JS.
 *   2026-07-19  v1.18.12 [ADD] Badge de variação (comparativo) nos cards de economia (Fase 1).
 *   2026-07-19  v1.18.13 [FIX] Ajuste UX do badge (Fase 1).
 *   2026-07-19  v1.19.0  [ADD] Navegação temporal nos cards de economia (Fase 2).
 * ============================================================
 */

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
$stmt->execute();
$controladoresAcessiveis = $stmt->fetchAll(PDO::FETCH_ASSOC);

$ctrlSolicitado = filter_input(INPUT_GET, 'ctrl', FILTER_VALIDATE_INT) ?: null;
$ctrlPadrao     = isset($_SESSION['controlador_padrao']) ? (int) $_SESSION['controlador_padrao'] : null;

$controladorAtivo = null;

// 1) ?ctrl tem prioridade — só se pertencer ao tenant
if ($ctrlSolicitado !== null) {
    foreach ($controladoresAcessiveis as $c) {
        if ((int) $c['id'] === $ctrlSolicitado) { $controladorAtivo = $c; break; }
    }
}

// 2) fallback: controlador salvo na sessão (revalidado no tenant)
if ($controladorAtivo === null && $ctrlPadrao !== null) {
    foreach ($controladoresAcessiveis as $c) {
        if ((int) $c['id'] === $ctrlPadrao) { $controladorAtivo = $c; break; }
    }
}

// 3) fallback: assume o primeiro da lista incondicionalmente (paridade total c/ energia.php)
if ($controladorAtivo === null && !empty($controladoresAcessiveis)) {
    $controladorAtivo = $controladoresAcessiveis[0];
}

// 4) persiste SEMPRE que houver ativo válido
if ($controladorAtivo !== null) {
    $_SESSION['controlador_padrao'] = (int) $controladorAtivo['id'];
}

// 5) estadoA = precisa escolher: nenhum ativo E há mais de um para decidir
$estadoA = ($controladorAtivo === null && count($controladoresAcessiveis) > 1);

$appTituloPagina = 'Dashboard';
$appPaginaAtual  = 'dashboard';
$appUsuarioNome  = $_SESSION['usuario_nome']  ?? 'Usuário';
$appIsAdmin      = in_array($_SESSION['usuario_perfil'] ?? '', [
    'master', 'master_operador', 'administrador'
], true);
?>
<!DOCTYPE html>
<html lang="pt-BR" data-tema="escuro">
<head>
  <?php require __DIR__ . '/includes/app_head.php'; ?>
  <!-- CDN ApexCharts REMOVIDO em v1.10.0 (B2.1) -->
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
      transition: background .3s, color .3s;
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
       TOOLBAR (simplificada — sem botoes de periodo)
    ══════════════════════════════════════════════ */
    .toolbar {
      display: flex;
      align-items: center;
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

    .refresh-info {
      color: var(--txt-dim);
      font-size: 11px;
      white-space: nowrap;
      flex-shrink: 0;
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
    .kpi-status-pill.verificando  { background: rgba(122,156,196,.12); color: var(--txt-mid); }

    .pill-dot {
      width: 8px; height: 8px;
      border-radius: 50%;
      flex-shrink: 0;
    }
    .online  .pill-dot { background: var(--green); box-shadow: 0 0 6px var(--green); }
    .offline .pill-dot { background: var(--red); }
    .verificando .pill-dot { background: var(--txt-mid); animation: pulse-dot 1.2s ease-in-out infinite; }

    @keyframes pulse-dot {
      0%, 100% { opacity: 1; }
      50%      { opacity: .35; }
    }

    /* ══════════════════════════════════════════════
       AREAS RESERVADAS (B2.2 + B2.3)
    ══════════════════════════════════════════════ */
    #infografico-host,
    #cards-host {
      margin-bottom: 20px;
    }

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
       INFOGRAFICO DE FLUXO ENERGETICO (B2.2 v1.11.0)
    ══════════════════════════════════════════════ */


    /* ===== Infográfico como background decorativo ===== */
    #infografico-host {
      position: sticky;          /* fica preso no topo enquanto rola */
      top: 0;
      z-index: 0;
      opacity: 1;                /* começa VÍVIDO (era 0.15) */
      transform-origin: center top;
      will-change: opacity, transform;
      transition: opacity .15s linear;
      pointer-events: none;      /* não bloqueia cliques nos cards */
    }
    #infografico-host svg { width: 100%; height: 100%; }

    .fluxo-wrapper { position: relative; min-height: 320px; padding: 16px; isolation: isolate; }

    /* ===== CARD DE BARRAS UNIFICADO ===== */
    #cards-host {
      position: relative;
      z-index: 1;                /* cards por cima ao rolar */
      margin-top: 16px;          /* O svg é sticky e já ocupa espaço na DOM */
    }

    .card-barras {
      padding: 20px 24px;
      background: var(--card);
      backdrop-filter: blur(4px);
      border: 1px solid var(--border);
      border-radius: 14px;
    }

    /* ══════════════════════════════════════════════
       CARD ECONOMIA (CIP-DEC-20260718-012)
    ══════════════════════════════════════════════ */
    .card-economia { margin-top: 16px; }

    .card-economia .eco-fator {
      float: right; font-size: 10px; font-weight: 700;
      color: var(--green); background: rgba(0,230,118,.12);
      padding: 2px 8px; border-radius: 12px; letter-spacing: .5px;
    }

    .card-economia .ce-total {
      display: flex; flex-direction: column;
      margin-bottom: 16px; padding-bottom: 14px;
      border-bottom: 1px solid var(--border);
    }
    .card-economia .ce-total-val {
      font-size: clamp(24px, 5vw, 34px);
      font-weight: 800; line-height: 1;
      color: var(--green);
      font-variant-numeric: tabular-nums;
    }
    .card-economia .ce-total-sub {
      color: var(--txt-dim); font-size: 11px; margin-top: 6px;
      letter-spacing: .5px; text-transform: uppercase; font-weight: 700;
    }

    .card-economia .ce-linha {
      display: grid;
      grid-template-columns: 28px 1fr 110px;
      align-items: center; gap: 10px;
      margin-bottom: 12px; color: var(--txt);
    }
    .card-economia .ce-linha:last-child { margin-bottom: 0; }

    .card-economia[data-loading="true"]  .cb-valor,
    .card-economia[data-loading="true"]  .ce-total-val { opacity: .35; }
    .card-economia[data-loading="error"] .ce-total-val { color: var(--red); }

    .eco-variacao { margin-left: .4rem; }
    .eco-var-badge { font-weight: 600; }
    .eco-var-up    { color: #16a34a; }
    .eco-var-down  { color: #dc2626; }
    .eco-var-ctx   { font-size: .72rem; color: var(--txt-dim, #6b7280); margin-left: .35rem; font-weight: 400; }
    .eco-var-vazio { font-size: .72rem; color: var(--txt-dim, #9ca3af); font-style: italic; font-weight: 400; }

    .eco-nav       { font-size: .8rem; padding: 2px 6px; border-radius: 6px;
                     border: 1px solid var(--border, #d1d5db); background: transparent; color: var(--txt); margin-left: 8px; }
    .eco-nav-hoje  { font-size: .72rem; margin-left: .4rem; cursor: pointer;
                     background: none; border: none; color: var(--txt-dim, #6b7280);
                     text-decoration: underline; }

    .cb-titulo { margin: 0 0 16px; font-size: 15px; font-weight: 700; color: var(--txt); }
    .cb-titulo small { font-weight: 400; opacity: .6; }

    /* grid: ícone | label | barra (flexível) | valor */
    .cb-linha {
      display: grid;
      grid-template-columns: 28px 110px 1fr 90px;
      align-items: center;
      gap: 10px;
      margin-bottom: 14px;
      color: var(--txt);
    }
    .cb-linha:last-child { margin-bottom: 0; }

    .cb-icone { font-size: 18px; text-align: center; }
    .cb-label { font-size: 13px; font-weight: 600; white-space: nowrap; display:flex; align-items:center; gap:6px; }

    /* >>> TEMP-COBERTURA-SOLIS <<< */
    .badge-cobertura { padding: 2px 8px; border-radius: 12px; font-size: 10px; font-weight: 600; }
    .badge-ok      { background: rgba(0,230,118,.15);  color: var(--green);  }
    .badge-parcial { background: rgba(255,193,7,.15);  color: var(--yellow); }
    .badge-critico { background: rgba(255,82,82,.15);  color: var(--red);    }
    /* >>> FIM TEMP-COBERTURA-SOLIS <<< */

    .cb-track {
      height: 14px;
      background: rgba(0,0,0,.08);
      border-radius: 8px;
      overflow: hidden;
    }
    .cb-fill {
      height: 100%;
      width: 0;                       /* JS anima até o % correto */
      border-radius: 8px;
      transition: width .9s cubic-bezier(.22,1,.36,1);
    }
    .fill-geracao   { background: linear-gradient(90deg,#fbbf24,#f59e0b); }
    .fill-consumo   { background: linear-gradient(90deg,#60a5fa,#2563eb); }
    .fill-injetada  { background: linear-gradient(90deg,#34d399,#059669); }
    .fill-importada { background: linear-gradient(90deg,#f87171,#dc2626); }

    .cb-valor { font-size: 14px; font-weight: 700; text-align: right; font-variant-numeric: tabular-nums; }

    @media (max-width: 768px) {
      .cb-linha { grid-template-columns: 24px 80px 1fr 70px; gap: 6px; }
      #cards-host { margin-top: 16px; }
    }

    .infografico-wrap {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 14px;
      padding: 16px 18px 14px;
      margin-bottom: 20px;
    }

    .infografico-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 10px;
      margin-bottom: 10px;
      padding-bottom: 10px;
      border-bottom: 1px solid var(--border);
    }

    .infografico-titulo {
      color: var(--txt);
      font-size: 13px;
      font-weight: 700;
      letter-spacing: .5px;
    }

    .infografico-meta {
      display: flex;
      align-items: center;
      gap: 14px;
      color: var(--txt-dim);
      font-size: 11px;
    }

    .infografico-idade {
      letter-spacing: .5px;
    }

    .infografico-qualidade {
      display: inline-flex;
      gap: 3px;
    }
    .q-dot {
      width: 7px; height: 7px;
      border-radius: 50%;
      background: var(--green);
      box-shadow: 0 0 4px var(--green);
      transition: background .3s, box-shadow .3s;
    }
    .q-dot.off {
      background: var(--txt-dim);
      box-shadow: none;
      opacity: .4;
    }

    /* ── Palco SVG ─────────────────────────────── */
    .infografico-palco {
      width: 100%;
      position: relative;
    }

    .infografico-svg {
      width: 100%;
      height: auto;
      max-height: 460px;
      display: block;
    }

    /* ── NOS (caixas) ──────────────────────────── */
    .no-caixa {
      fill: var(--bg);
      stroke: var(--border);
      stroke-width: 1.5;
      transition: stroke .3s, fill .3s;
    }
    .no-caixa.destaque {
      stroke: var(--blue);
      stroke-width: 2;
      filter: url(#glow);
    }
    .no-grupo.standby .no-caixa {
      stroke-dasharray: 4 4;
      opacity: .55;
    }

    .no-emoji {
      font-size: 130px;
      dominant-baseline: middle;
    }
    .no-titulo {
      fill: var(--txt-dim);
      font-size: 11px;
      font-weight: 800;
      letter-spacing: 1.2px;
      font-family: 'Segoe UI', system-ui, sans-serif;
    }
    .no-valor {
      fill: var(--txt);
      font-size: 18px;
      font-weight: 800;
      font-family: 'Segoe UI', system-ui, sans-serif;
    }
    .no-valor.sm { font-size: 14px; }
    .no-valor.amarelo  { fill: #f5c542; }
    .no-valor.azul     { fill: #4a90d9; }
    .no-valor.vermelho { fill: #e05a5a; }
    .no-valor.verde    { fill: #4caf7d; }
    .no-valor.laranja  { fill: #e8944a; }
    .no-valor.cinza    { fill: #9aa0a6; }

    .valor-energia {
      font-size: 18px;
      font-weight: 800;
      font-family: 'Segoe UI', system-ui, sans-serif;
    }
    .valor-potencia {
      font-size: 13px;
      font-weight: 600;
      opacity: 0.75;
      font-family: 'Segoe UI', system-ui, sans-serif;
    }
    .valor-energia.--sem-dado,
    .valor-potencia.--sem-dado {
      opacity: 0.4;
      font-style: italic;
    }

    /* ── Modo Minimalista (TV) ─────────────────── */
    .infografico-minimal .no-valor,
    .infografico-minimal .no-mini-lbl,
    .infografico-minimal .no-sub {
      display: none;
    }

    .no-mini-lbl {
      fill: var(--txt-dim);
      font-size: 9px;
      font-weight: 700;
      letter-spacing: 1.2px;
      font-family: 'Segoe UI', system-ui, sans-serif;
    }
    .no-mini-lbl.amarelo { fill: var(--yellow); }
    .no-mini-lbl.azul    { fill: var(--blue);   }

    .no-sub {
      fill: var(--txt-dim);
      font-size: 9px;
      letter-spacing: .8px;
      font-family: 'Segoe UI', system-ui, sans-serif;
    }

    /* ── FLUXOS (caminhos) ─────────────────────── */
    .fluxo-trilho {
      fill: none;
      stroke: var(--border);
      stroke-width: 6;
      stroke-linecap: round;
      opacity: .6;
    }

    .fluxo-ativo {
      fill: none;
      stroke-width: 4;
      stroke-linecap: round;
      stroke-dasharray: 12 6;
      stroke-dashoffset: 0;
      opacity: 0;
      animation: none;
      filter: url(#glow);
    }
    .fluxo-ativo.amarelo  { stroke: #f5c542; }
    .fluxo-ativo.azul     { stroke: #4a90d9; }
    .fluxo-ativo.vermelho { stroke: #e05a5a; }
    .fluxo-ativo.verde    { stroke: #4caf7d; }
    .fluxo-ativo.laranja  { stroke: #e8944a; }
    .fluxo-ativo.cinza    { stroke: #9aa0a6; }

    .fluxo-grupo.fluxo-on .fluxo-ativo {
      opacity: 1;
      animation: fluxo-mover var(--dur, 1.25s) linear infinite;
    }
    .fluxo-grupo[data-fluxo="exportada"] .fluxo-ativo {
      animation-name: fluxo-mover-exportada;
    }
    .fluxo-grupo.fluxo-on .fluxo-ativo {
      opacity: 1;
      animation: fluxo-mover 2s linear infinite;
    }

    /* ===== Bullets Luminosos ===== */
    .bullet {
      filter: drop-shadow(0 0 3px currentColor) drop-shadow(0 0 7px currentColor);
      transition: opacity .35s ease;
    }
    .b-geracao   { fill: #fbbf24; color: #fbbf24; }
    .b-importada { fill: #ef4444; color: #ef4444; }
    .b-exportada { fill: #22c55e; color: #22c55e; }
    .bullet-off  { opacity: 0; }   /* NUNCA display:none — pararia o animateMotion */

    .no-grupo.sem-dado .no-caixa {
      stroke-dasharray: 4 4;
      opacity: .55;
    }
    .aviso-integracao {
      cursor: help;
      fill: var(--txt-dim);
      font-size: 13px;
    }

    /* Animacao: faz o tracejado "correr" */
    @keyframes fluxo-mover {
      to { stroke-dashoffset: -36; }
    }

    /* Exportada: sentido invertido (path desenhado rede->imovel) */
    @keyframes fluxo-mover-exportada {
      from { stroke-dashoffset: 0; }
      to   { stroke-dashoffset: -36; }
    }

    /* ── Rodape ─────────────────────────────────── */
    .infografico-rodape {
      display: flex;
      align-items: center;
      justify-content: center;
      flex-wrap: wrap;
      gap: 18px;
      padding-top: 10px;
      margin-top: 4px;
      border-top: 1px solid var(--border);
    }
    .info-mini {
      color: var(--txt-dim);
      font-size: 11px;
      display: inline-flex;
      align-items: center;
      gap: 6px;
    }
    .info-mini strong {
      color: var(--txt);
      font-weight: 700;
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
      .kpi-grid { grid-template-columns: repeat(3, 1fr); }
    }

    /* Mobile grande */
    @media (max-width: 768px) {
      .wrap { padding: 72px 14px 32px; }

      .toolbar { flex-direction: column; align-items: stretch; gap: 10px; }
      .ctrl-info { width: 100%; }

      .kpi-grid { grid-template-columns: repeat(2, 1fr); }
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

  <span class="refresh-info" id="refreshInfo" style="display:block; text-align:right; margin-bottom:10px;">⏳ —</span>

  <!-- ════════════════════════════════════════════════════════
       INFOGRAFICO ANIMADO DE FLUXO ENERGETICO (B2.2 v1.11.0)
       Renderiza fluxo entre FV, Rede, Imovel e Bateria.
       Setas SVG animadas via CSS stroke-dasharray, com
       velocidade controlada por CSS variable --dur (kW->seg).
       Valores e velocidades sao atualizados via JS (Parte 3/3),
       consumindo api/dashboard/infografico.php.
  ════════════════════════════════════════════════════════ -->
  <div class="fluxo-wrapper">
  <div id="infografico-host" class="infografico-wrap infografico-minimal">

    <!-- Cabecalho do bloco -->
    <div class="infografico-head">
      <div class="infografico-titulo">
        🌐 Fluxo Energetico em Tempo Real
      </div>
      <div class="infografico-meta">
        <span class="infografico-idade" id="infoIdade">— sem dados —</span>
        <span class="infografico-qualidade" id="infoQualidade" title="Qualidade do dado">
          <span class="q-dot off"></span>
          <span class="q-dot off"></span>
          <span class="q-dot off"></span>
          <span class="q-dot off"></span>
        </span>
      </div>
    </div>

    <!-- Palco SVG do infografico -->
    <div class="infografico-palco">
      <svg viewBox="0 0 1000 520" preserveAspectRatio="xMidYMid meet"
           xmlns="http://www.w3.org/2000/svg"
           class="infografico-svg" aria-label="Fluxo de energia">

        <!-- ===== DEFS: gradientes e filtros ===== -->
        <defs>
          <!-- Glow suave reutilizavel -->
          <filter id="glow" filterUnits="userSpaceOnUse" x="0" y="0" width="1000" height="480">
            <feGaussianBlur stdDeviation="3" result="b"/>
            <feMerge>
              <feMergeNode in="b"/>
              <feMergeNode in="SourceGraphic"/>
            </feMerge>
          </filter>
        </defs>

        <!-- ============================================
             CAMINHOS DE FLUXO (renderizados ANTES dos nos
             para ficarem visualmente atras das caixas)
        ============================================ -->

        <!-- Fluxo 1: FV -> Imovel (vertical, topo) -->
        <g class="fluxo-grupo" data-fluxo="geracao">
          <path class="fluxo-trilho"
                d="M 500,150 C 500,180 500,200 500,230"/>
          <path id="pathGeracao" class="fluxo-ativo amarelo"
                d="M 500,150 C 500,180 500,200 500,230"/>
        </g>
        <!-- === BULLETS GERACAO === -->
        <circle class="bullet b-geracao" data-bullet="geracao" data-idx="0" r="5"><animateMotion id="animGeracao0" dur="2s" begin="0s"     repeatCount="indefinite" rotate="auto"><mpath href="#pathGeracao"/></animateMotion></circle>
        <circle class="bullet b-geracao" data-bullet="geracao" data-idx="1" r="5"><animateMotion id="animGeracao1" dur="2s" begin="-0.66s" repeatCount="indefinite" rotate="auto"><mpath href="#pathGeracao"/></animateMotion></circle>
        <circle class="bullet b-geracao" data-bullet="geracao" data-idx="2" r="5"><animateMotion id="animGeracao2" dur="2s" begin="-1.33s" repeatCount="indefinite" rotate="auto"><mpath href="#pathGeracao"/></animateMotion></circle>

        <!-- Fluxo 2: Rede -> Imovel (horizontal, esquerda) -->
        <g class="fluxo-grupo" data-fluxo="importada">
          <path class="fluxo-trilho"
                d="M 200,335 C 280,335 340,335 400,335"/>
          <path id="pathImportada" class="fluxo-ativo vermelho"
                d="M 200,335 C 280,335 340,335 400,335"/>
        </g>
        <!-- === BULLETS IMPORTADA === -->
        <circle class="bullet b-importada" data-bullet="importada" data-idx="0" r="5"><animateMotion id="animImportada0" dur="2s" begin="0s"     repeatCount="indefinite" rotate="auto"><mpath href="#pathImportada"/></animateMotion></circle>
        <circle class="bullet b-importada" data-bullet="importada" data-idx="1" r="5"><animateMotion id="animImportada1" dur="2s" begin="-0.66s" repeatCount="indefinite" rotate="auto"><mpath href="#pathImportada"/></animateMotion></circle>
        <circle class="bullet b-importada" data-bullet="importada" data-idx="2" r="5"><animateMotion id="animImportada2" dur="2s" begin="-1.33s" repeatCount="indefinite" rotate="auto"><mpath href="#pathImportada"/></animateMotion></circle>

        <!-- Fluxo 3: Imovel -> Rede (horizontal, esquerda, abaixo) -->
        <g class="fluxo-grupo" data-fluxo="exportada">
          <path class="fluxo-trilho"
                d="M 400,335 C 340,335 280,335 200,335"/>
          <path id="pathExportada" class="fluxo-ativo verde"
                d="M 400,335 C 340,335 280,335 200,335"/>
        </g>
        <!-- === BULLETS EXPORTADA === -->
        <circle class="bullet b-exportada" data-bullet="exportada" data-idx="0" r="5"><animateMotion id="animExportada0" dur="2s" begin="0s"     repeatCount="indefinite" rotate="auto"><mpath href="#pathExportada"/></animateMotion></circle>
        <circle class="bullet b-exportada" data-bullet="exportada" data-idx="1" r="5"><animateMotion id="animExportada1" dur="2s" begin="-0.66s" repeatCount="indefinite" rotate="auto"><mpath href="#pathExportada"/></animateMotion></circle>
        <circle class="bullet b-exportada" data-bullet="exportada" data-idx="2" r="5"><animateMotion id="animExportada2" dur="2s" begin="-1.33s" repeatCount="indefinite" rotate="auto"><mpath href="#pathExportada"/></animateMotion></circle>

        <!-- Fluxo 4: Imovel <-> Bateria (horizontal, direita) -->
        <g class="fluxo-grupo fluxo-standby" data-fluxo="bateria">
          <path class="fluxo-trilho"
                d="M 600,335 C 680,335 740,335 800,335"/>
          <path class="fluxo-ativo cinza"
                d="M 600,335 C 680,335 740,335 800,335"/>
        </g>

        <!-- ============================================
             NOS (caixas dos atores)
        ============================================ -->

        <!-- No: MODULOS FOTOVOLTAICOS -->
        <g class="no-grupo" transform="translate(420, 30)">
          <text class="no-emoji" style="font-size: 90px;" x="80" y="38" text-anchor="middle">☀️</text>
          <text class="no-titulo" x="80" y="100" text-anchor="middle">MODULOS FV</text>
          <text class="no-valor amarelo" x="80" y="125" text-anchor="middle" id="valGeracao">0.00 kW</text>
          <text class="no-sub" x="80" y="145" text-anchor="middle" id="valGeracaoDia">— kWh</text>
        </g>

        <!-- No: REDE / CONCESSIONARIA -->
        <g class="no-grupo" transform="translate(40, 250)">
          <text class="no-emoji"  x="80" y="38" text-anchor="middle">⚡</text>
          <text class="no-titulo" x="80" y="120" text-anchor="middle">REDE</text>
          
          <text class="no-mini-lbl" x="80" y="145" text-anchor="middle">IMPORTAÇÃO</text>
          <text class="no-valor vermelho" x="80" y="165" text-anchor="middle" id="valImportada">— kW</text>
          <text class="no-sub" x="80" y="180" text-anchor="middle" id="valImportadaDia">— kWh hoje</text>

          <text class="no-mini-lbl" x="80" y="210" text-anchor="middle">EXPORTAÇÃO</text>
          <text class="no-valor verde" x="80" y="230" text-anchor="middle" id="valExportada">- kW</text>
          <text class="no-sub" x="80" y="245" text-anchor="middle" id="valExportadaDia">— kWh hoje</text>
        </g>

        <!-- No: IMOVEL (central) -->
        <g class="no-grupo" transform="translate(400, 230)">
          <text class="no-emoji"  x="100" y="42" text-anchor="middle">🏠</text>
          <text class="no-titulo" x="100" y="125" text-anchor="middle">IMOVEL</text>
          
          <text class="no-valor azul" x="100" y="155" text-anchor="middle" id="valConsumo">- kW</text>
          <text class="no-sub" x="100" y="175" text-anchor="middle" id="valConsumoDia">— kWh hoje</text>
          
          <text class="no-mini-lbl amarelo" x="100" y="205" text-anchor="middle">SALDO (GERAÇÃO - CONSUMO)</text>
          <text class="no-valor sm" x="100" y="225" text-anchor="middle" id="valSaldo">—</text>
        </g>

        <!-- No: BATERIA (standby) -->
        <g class="no-grupo standby" transform="translate(800, 245)">
          <text class="no-emoji" style="font-size: 90px;" x="80" y="38" text-anchor="middle">🔋</text>
          <text class="no-titulo" x="80" y="100" text-anchor="middle">BATERIA</text>
          <text class="no-valor cinza" x="80" y="125" text-anchor="middle" id="valBateria">— kW</text>
          <text class="no-sub" x="80" y="145" text-anchor="middle" id="valBateriaDia">— kWh hoje</text>
        </g>

      </svg>

      <!-- Rodape do infografico: tensao + frequencia da rede -->
      <div class="infografico-rodape">
        <span class="info-mini" title="Tensao da rede">
          🔌 <strong id="infoTensao">— V</strong>
        </span>
        <span class="info-mini" title="Frequencia da rede">
          〰️ <strong id="infoFreq">— Hz</strong>
        </span>
        <span class="info-mini" title="Status do inversor">
          ⚙️ <strong id="infoInversor">—</strong>
        </span>
        <span class="info-mini" title="Limite de exportacao ativo">
          🚦 <strong id="infoLimite">—</strong>
        </span>
      </div>
    </div>
  </div><!-- /infografico-host -->

  <div id="cards-host">
    <div class="card-energia card-barras">
      <h3 class="cb-titulo">⚡ Balanço Energético <small>(kWh)</small></h3>

      <div class="cb-linha" data-metrica="geracao">
        <span class="cb-icone">☀️</span>
        <span class="cb-label">Geração
          <!-- >>> TEMP-COBERTURA-SOLIS (remover quando SolisCloud API estiver ativa) <<< -->
          <span id="badge-cobertura" class="badge-cobertura badge-ok" style="display:none;" title="">
            📡 <span id="badge-cobertura-pct"></span>%
          </span>
          <!-- >>> FIM TEMP-COBERTURA-SOLIS <<< -->
        </span>
        <div class="cb-track"><div class="cb-fill fill-geracao" id="ce-bar-geracao"></div></div>
        <span class="cb-valor" id="ce-val-geracao">—</span>
      </div>

      <div class="cb-linha" data-metrica="consumo">
        <span class="cb-icone">💡</span>
        <span class="cb-label">Consumo</span>
        <div class="cb-track"><div class="cb-fill fill-consumo" id="ce-bar-consumo"></div></div>
        <span class="cb-valor" id="ce-val-consumo">—</span>
      </div>

      <div class="cb-linha" data-metrica="injetada">
        <span class="cb-icone">🔌</span>
        <span class="cb-label">Injetada</span>
        <div class="cb-track"><div class="cb-fill fill-injetada" id="ce-bar-injetada"></div></div>
        <span class="cb-valor" id="ce-val-injetada">—</span>
      </div>

      <div class="cb-linha" data-metrica="importada">
        <span class="cb-icone">📉</span>
        <span class="cb-label">Importada</span>
        <div class="cb-track"><div class="cb-fill fill-importada" id="ce-bar-importada"></div></div>
        <span class="cb-valor" id="ce-val-importada">—</span>
      </div>
    </div>

    <!-- ── Card Economia (Lei 14.300) — CIP-DEC-20260718-012 ── -->
    <div class="card-economia card-barras" id="cardEconomia" data-loading="true">
      <h3 class="cb-titulo">
        💰 Economia do Dia
        <small>(Lei 14.300)</small>
        <input type="date" id="eco-nav-dia" class="eco-nav" title="Escolher dia">
        <button type="button" id="eco-hoje-dia" class="eco-nav-hoje" hidden>Hoje</button>
        <span class="eco-fator" id="eco-fator" title="Fator de injeção vigente"></span>
      </h3>

      <div class="ce-total">
        <span class="ce-total-val" id="eco-total">—</span>
        <span class="ce-total-sub" id="eco-total-sub">estimativa hoje</span>
        <span class="eco-variacao" id="eco-variacao" title="vs. ontem">—</span>
      </div>

      <div class="ce-linha">
        <span class="cb-icone">☀️</span>
        <span class="cb-label">Autoconsumo</span>
        <span class="cb-valor" id="eco-autoconsumo">—</span>
      </div>
      <div class="ce-linha">
        <span class="cb-icone">💡</span>
        <span class="cb-label">Crédito injeção</span>
        <span class="cb-valor" id="eco-credito">—</span>
      </div>
    </div>

    <!-- 💰📅 Card Economia do Mês (Lei 14.300) -->
    <div class="card-economia card-barras" id="cardEconomiaMes" data-loading="true">
      <h3 class="cb-titulo">
        📅 Economia do Mês
        <small>(Lei 14.300)</small>
        <input type="month" id="eco-nav-mes" class="eco-nav" title="Escolher mês">
        <button type="button" id="eco-hoje-mes" class="eco-nav-hoje" hidden>Mês atual</button>
        <span class="eco-fator" id="eco-fator-mes" title="Fator de injeção vigente"></span>
      </h3>
      <div class="ce-total">
        <span class="ce-total-val" id="eco-total-mes">—</span>
        <span class="ce-total-sub" id="eco-total-sub-mes">acumulado no mês</span>
        <span class="eco-variacao" id="eco-variacao-mes" title="vs. mês anterior (parcial)">—</span>
      </div>
      <div class="ce-linha">
        <span class="cb-icone">☀️</span>
        <span class="cb-label">Autoconsumo</span>
        <span class="cb-valor" id="eco-autoconsumo-mes">—</span>
      </div>
      <div class="ce-linha">
        <span class="cb-icone">💡</span>
        <span class="cb-label">Crédito injeção</span>
        <span class="cb-valor" id="eco-credito-mes">—</span>
      </div>
    </div>
  </div>

</div><!-- /wrap -->

<footer class="footer">
  CIP — Controlador de Injeção de Potência Elétrica &nbsp;|&nbsp;
  Aeonium &nbsp;|&nbsp; São Paulo, BR &nbsp;|&nbsp; v1.15.1
</footer>

<script>
'use strict';

<?php if (empty($controladoresAcessiveis)): ?>
const CTRL_ID = null;
<?php else: ?>
const CTRL_ID = <?= json_encode((int) $controladorAtivo['id']) ?>;
<?php endif; ?>

const INTERVAL = 10000;
let timer = null;

function lerCoresCss() {
  const cs = getComputedStyle(document.documentElement);
  const pega = (varName, fallback) => {
    const v = cs.getPropertyValue(varName).trim();
    return v || fallback;
  };
  return {
    blue:    pega('--blue',    '#00b4ff'),
    green:   pega('--green',   '#00e676'),
    yellow:  pega('--yellow',  '#ffc107'),
    txt:     pega('--txt',     '#e0eaf8'),
  };
}

let C = lerCoresCss();

async function verificarToken() {
  try {
    const res = await fetch('/api/auth/verify.php', { method: 'GET', credentials: 'same-origin' });
    if (!res.ok) { window.location.href = '/login.php'; return false; }
    const data = await res.json();
    if (!data.success) { window.location.href = '/login.php'; return false; }
    return true;
  } catch (e) {
    return true;
  }
}

async function logout() {
  try {
    await fetch('/api/auth/logout.php', { method: 'POST', credentials: 'same-origin' });
  } catch (e) {} finally {
    sessionStorage.clear();
    window.location.href = '/login.php';
  }
}

async function atualizarCardsEnergia(controladorId) {
  try {
    const r = await fetch(`/api/energia/resumo_dia.php?controlador_id=${controladorId}`, { credentials: 'same-origin' });
    const data = await r.json();
    if (!data.sucesso) return;

    const e = data.energia_kwh || {};
    const metricas = {
      geracao:   e.geracao   ?? 0,
      consumo:   e.consumo   ?? 0,
      injetada:  e.exportada ?? 0,
      importada: e.importada ?? 0,
    };

    const max = Math.max(...Object.values(metricas), 1);
    for (const [chave, valor] of Object.entries(metricas)) {
      const pct = (valor / max) * 100;
      const bar = document.getElementById(`ce-bar-${chave}`);
      const val = document.getElementById(`ce-val-${chave}`);
      if (bar) bar.style.width = `${pct.toFixed(1)}%`;
      if (val) val.textContent = `${Number(valor).toLocaleString('pt-BR', {maximumFractionDigits:1})} kWh`;
    }

    // >>> TEMP-COBERTURA-SOLIS (remover quando SolisCloud API estiver ativa) <<<
    const cob = data.cobertura_geracao;
    if (cob) {
      const badge = document.getElementById('badge-cobertura');
      if (badge) {
        badge.style.display = 'inline-block';
        badge.className = `badge-cobertura badge-${cob.status}`;
        badge.title = cob.aviso || '';
        document.getElementById('badge-cobertura-pct').textContent = cob.pct;
      }
    }
    // >>> FIM TEMP-COBERTURA-SOLIS <<<

    // aplicarSemaforoFluxo(j); // Removido
  } catch (err) {
    console.error('[cards-energia]', err);
  }
}

const navEconomia = { dia: null, mes: null };

function limitesDatepicker() {
  const hoje = new Date();
  const min  = new Date(hoje); min.setMonth(min.getMonth() - 12);
  const iso  = d => d.toISOString().slice(0, 10);
  return { minDia: iso(min), maxDia: iso(hoje),
           minMes: iso(min).slice(0,7), maxMes: iso(hoje).slice(0,7) };
}

document.addEventListener('DOMContentLoaded', () => {
  const lim = limitesDatepicker();
  const dpDia = document.getElementById('eco-nav-dia');
  const dpMes = document.getElementById('eco-nav-mes');
  
  if (dpDia) {
    dpDia.min = lim.minDia; dpDia.max = lim.maxDia;
    dpDia.addEventListener('change', e => {
      navEconomia.dia = e.target.value || null;
      document.getElementById('eco-hoje-dia').hidden = !navEconomia.dia;
      if (window._dadosControlador) atualizarCardEconomia(window._dadosControlador.id);
    });
    document.getElementById('eco-hoje-dia').addEventListener('click', () => {
      navEconomia.dia = null; dpDia.value = '';
      document.getElementById('eco-hoje-dia').hidden = true;
      if (window._dadosControlador) atualizarCardEconomia(window._dadosControlador.id);
    });
  }
  
  if (dpMes) {
    dpMes.min = lim.minMes; dpMes.max = lim.maxMes;
    dpMes.addEventListener('change', e => {
      navEconomia.mes = e.target.value || null;
      document.getElementById('eco-hoje-mes').hidden = !navEconomia.mes;
      if (window._dadosControlador) atualizarCardEconomiaMes(window._dadosControlador.id);
    });
    document.getElementById('eco-hoje-mes').addEventListener('click', () => {
      navEconomia.mes = null; dpMes.value = '';
      document.getElementById('eco-hoje-mes').hidden = true;
      if (window._dadosControlador) atualizarCardEconomiaMes(window._dadosControlador.id);
    });
  }
});

function pintarVariacao(elId, pct, refLabel) {
  const el = document.getElementById(elId);
  if (!el) return;
  if (pct === null || pct === undefined) {
    el.innerHTML = '<span class="eco-var-vazio">— sem base de comparação</span>';
    return;
  }
  const up  = pct >= 0;
  const seta = up ? '▲' : '▼';
  const txt  = up ? 'a mais que' : 'a menos que';
  const val  = Math.abs(pct).toLocaleString('pt-BR');
  el.innerHTML =
    `<span class="eco-var-badge ${up ? 'eco-var-up' : 'eco-var-down'}">${seta} ${val}%</span>` +
    `<span class="eco-var-ctx">${txt} ${refLabel}</span>`;
}

/**
 * Card economia (Lei 14.300) — consome api/energia/economia.php.
 * Carona no ciclo de 10s via carregarKpis(). Método: simplificado_v1.
 */
async function atualizarCardEconomia(controladorId) {
  const card = document.getElementById('cardEconomia');
  if (!card) return;
  try {
    const refQ = navEconomia.dia ? `&ref=${navEconomia.dia}` : '';
    const r = await fetch(
      `/api/energia/economia.php?controlador_id=${controladorId}&comparar=1${refQ}`,
      { credentials: 'same-origin' }
    );
    const j = await r.json();
    if (!j.sucesso) throw new Error(j.erro || 'falha');

    const d = j.data;
    const brl = (v) => Number(v || 0)
      .toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });

    if (d.sem_dados) {
      document.getElementById('eco-total').textContent = 'Sem dados';
      document.getElementById('eco-autoconsumo').textContent = '—';
      document.getElementById('eco-credito').textContent = '—';
      pintarVariacao('eco-variacao', null);
    } else {
      document.getElementById('eco-total').textContent       = brl(d.total);
      document.getElementById('eco-autoconsumo').textContent = brl(d.autoconsumo_reais);
      document.getElementById('eco-credito').textContent     = brl(d.credito_reais);

      const refLabel = d.periodo_atual === false ? 'que no dia anterior' : 'que ontem';
      pintarVariacao('eco-variacao', d.variacao_pct, refLabel);
    }

    // Sub-info honesta: kWh exportados que geraram o crédito
    const sub = document.getElementById('eco-total-sub');
    if (sub && d.exportada_kwh != null) {
      const kwh = Number(d.exportada_kwh).toLocaleString('pt-BR', { maximumFractionDigits: 1 });
      sub.textContent = `estimativa hoje · ${kwh} kWh injetados`;
    }

    // Badge fator de injeção (Lei 14.300)
    const fator = document.getElementById('eco-fator');
    if (fator && d.fator_injecao != null) {
      fator.textContent = `fator ${(d.fator_injecao * 100).toFixed(0)}%`;
    }

    card.dataset.loading = 'false';
  } catch (err) {
    console.error('[card-economia]', err);
    card.dataset.loading = 'error';
    document.getElementById('eco-total').textContent = '—';
  }
}

async function atualizarCardEconomiaMes(controladorId) {
  try {
    const refQ = navEconomia.mes ? `&ref=${navEconomia.mes}` : '';
    const r = await fetch(`/api/energia/economia.php?controlador_id=${controladorId}&periodo=mes&comparar=1${refQ}`);
    const j = await r.json();
    const d = j.data;
    if (!d) return;

    const brl = (v) => Number(v || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
    
    if (d.sem_dados) {
      document.getElementById('eco-total-mes').textContent = 'Sem dados';
      document.getElementById('eco-autoconsumo-mes').textContent = '—';
      document.getElementById('eco-credito-mes').textContent = '—';
      pintarVariacao('eco-variacao-mes', null);
    } else {
      document.getElementById('eco-total-mes').textContent       = brl(d.total);
      document.getElementById('eco-autoconsumo-mes').textContent = brl(d.autoconsumo_reais);
      document.getElementById('eco-credito-mes').textContent     = brl(d.credito_reais);

      const refLabel = d.periodo_atual === false ? 'que no mês anterior' : 'que no mesmo período do mês anterior';
      pintarVariacao('eco-variacao-mes', d.variacao_pct, refLabel);
    }

    const fatorMes = document.getElementById('eco-fator-mes');
    const fatorDia = document.getElementById('eco-fator');
    if (fatorMes && fatorDia) fatorMes.textContent = fatorDia.textContent;

    document.getElementById('cardEconomiaMes').dataset.loading = 'false';
  } catch (e) {
    console.error('[economiaMes]', e);
  }
}

/**
 * carregarKpis() — versao simplificada do antigo carregar()
 * Mantem o consumo de /api/dashboard/dados.php (legado) mas
 * atualiza APENAS os 9 KPIs do topo. Series temporais, gauge
 * e totalizadores foram removidos no Patch B2.1.
 */
async function carregarKpis() {
  if (!CTRL_ID) return;
  try {
    const res = await fetch(
      `/api/dashboard/dados.php?controlador_id=${CTRL_ID}&periodo=1h`,
      { credentials: 'same-origin' }
    );
    if (res.status === 401) { logout(); return; }
    const json = await res.json();
    if (!json.success) return;

    const { atual, totais, controlador, custo_dia } = json;



    if (controlador) {
      const nomeCtrl = controlador.nome || `Controlador #${CTRL_ID}`;
      const nomeEl = document.getElementById('nomeCtrl');
      if (nomeEl) nomeEl.textContent = nomeCtrl;

      const diff     = controlador.ultimo_ping
        ? (Date.now() - new Date(controlador.ultimo_ping).getTime()) / 1000 : 999;
      const isOnline = diff < 30; // limiar offline 30s (padrao CIP)

      const pill = document.getElementById('statusPill');
      if (pill) {
        pill.className = `pill ${isOnline ? 'online' : 'offline'}`;
        const txt = document.getElementById('statusTxt');
        if (txt) txt.textContent = isOnline ? 'ONLINE' : 'OFFLINE';
      }

      if (!window._dadosControlador || window._dadosControlador.id !== controlador.id) {
        navEconomia.dia = null;
        navEconomia.mes = null;
        const dpD = document.getElementById('eco-nav-dia');
        const dpM = document.getElementById('eco-nav-mes');
        if (dpD) { dpD.value = ''; document.getElementById('eco-hoje-dia').hidden = true; }
        if (dpM) { dpM.value = ''; document.getElementById('eco-hoje-mes').hidden = true; }
      }
      window._dadosControlador = controlador;
      if (typeof atualizarCardsEnergia === 'function') atualizarCardsEnergia(CTRL_ID);
      if (typeof atualizarCardEconomia === 'function') {
        atualizarCardEconomia(CTRL_ID);
        atualizarCardEconomiaMes(CTRL_ID);
      }
    }

    document.getElementById('refreshInfo').textContent =
      '⏱ ' + new Date().toLocaleTimeString('pt-BR') + ' · auto 10s';
    document.getElementById('loading').style.display = 'none';

  } catch (err) {
    console.error('[CIP] Erro ao carregar KPIs:', err);
    document.getElementById('refreshInfo').textContent = '⚠️ Falha na última atualização';
    document.getElementById('loading').style.display   = 'none';
  }
}

function startKpiTimer() {
  if (timer) clearInterval(timer);
  timer = setInterval(carregarKpis, INTERVAL);
}

// ── Reatividade de tema (apenas atualiza cache de cores) ─────
// Charts foram removidos no Patch B2.1; o MutationObserver
// agora apenas reescreve o cache C para uso em corFP() e por
// modulos futuros (B2.2 infografico SVG, B2.3 cards).
if (window.CipTema && typeof window.CipTema.atual === 'function') {
  const observer = new MutationObserver((mutations) => {
    mutations.forEach((m) => {
      if (m.type === 'attributes' && m.attributeName === 'data-tema') {
        C = lerCoresCss();
      }
    });
  });
  observer.observe(document.documentElement, { attributes: true, attributeFilter: ['data-tema'] });
}

// ── Boot ──────────────────────────────────────────────────────
verificarToken()
  .then(ok => {
    if (!ok) return;
    if (!CTRL_ID) {
      document.getElementById('loading').style.display = 'none';
      return;
    }
    carregarKpis();
    startKpiTimer();
  })
  .catch(err => {
    console.error('[CIP] Falha na verificação de sessão:', err);
    const l = document.getElementById('loading');
    if (l) l.innerHTML = '<p>Erro ao verificar sessão. <a href="/login.php">Recarregar</a></p>';
  });
</script>

  <script>
    // Expoe o ID do controlador atual para os scripts do dashboard
    window.CIP_CTRL_ID = <?= json_encode($controladorAtivo ? (int)$controladorAtivo['id'] : 0) ?>;
  </script>

  <!-- ════════════════════════════════════════════════════════
       JAVASCRIPT DO INFOGRAFICO (B2.2 Parte 3/3 — v1.12.0)
       Polling 30s + binding de valores + velocidade das setas.
       Namespace isolado: window.CIP_Infografico
  ════════════════════════════════════════════════════════ -->
  <script>
  (function(){
    'use strict';

    /* ───────── Configuracao ───────── */
    const CFG = {
      endpoint:        '/api/dashboard/infografico.php',
      intervaloPoll:   30000,   // 30s entre fetchs
      intervaloIdade:  1000,    // 1s entre ticks de "idade"
      timeoutFetch:    8000,    // 8s timeout do fetch
      alertaIdadeS:    360,     // 6min — uma janela perdida
      criticoIdadeS:   660,     // 11min — duas janelas perdidas
      maxRetries:      3,       // tentativas em caso de erro
      debug: new URLSearchParams(location.search).get('debug') === '1'
    };

    /* ───────── Log condicional ───────── */
    const log = (...a) => CFG.debug && console.log('[CIP-Info]', ...a);
    const warn = (...a) => console.warn('[CIP-Info]', ...a);

    /* ───────── ID do controlador ───────── */
    // Tentar capturar de window.CIP_CTRL_ID (definido no PHP)
    // ou de data-attribute, ou de query string como fallback
    const controladorId =
      window.CIP_CTRL_ID ||
      document.body.dataset.controladorId ||
      new URLSearchParams(location.search).get('controlador_id') ||
      null;

    if (!controladorId) {
      warn('controlador_id nao identificado — infografico desativado');
      return;
    }
    log('controlador_id =', controladorId);

    /* ───────── Cache de refs DOM ───────── */
    const $ = (id) => document.getElementById(id);
    const refs = {
      // Valores numericos (Potencia - kW)
      geracao:        $('valGeracao'),
      geracaoOrigem:  $('valGeracaoOrigem'),
      importada:      $('valImportada'),
      exportada:      $('valExportada'),
      consumo:        $('valConsumo'),
      saldo:          $('valSaldo'),
      bateria:        $('valBateria'),
      // Valores acumulados (Energia - kWh)
      geracaoDia:     $('valGeracaoDia'),
      importadaDia:   $('valImportadaDia'),
      exportadaDia:   $('valExportadaDia'),
      consumoDia:     $('valConsumoDia'),
      bateriaDia:     $('valBateriaDia'),
      // Cabecalho
      idade:          $('infoIdade'),
      qualidade:      $('infoQualidade'),
      // Rodape
      tensao:         $('infoTensao'),
      freq:           $('infoFreq'),
      inversor:       $('infoInversor'),
      limite:         $('infoLimite'),
      // Grupos de fluxo (SVG)
      fluxoGeracao:   document.querySelector('[data-fluxo="geracao"]'),
      fluxoImportada: document.querySelector('[data-fluxo="importada"]'),
      fluxoExportada: document.querySelector('[data-fluxo="exportada"]'),
      fluxoBateria:   document.querySelector('[data-fluxo="bateria"]')
    };

    // Validacao defensiva: se algum ID faltar, log mas nao quebra
    Object.entries(refs).forEach(([k, v]) => {
      if (!v) warn(`ref DOM ausente: ${k}`);
    });

    /* ───────── Estado ───────── */
    const state = {
      ultimoTsUtc:     null,
      ultimoFetchOk:   null,
      tentativasFalha: 0,
      timerPoll:       null,
      timerIdade:      null,
      pausado:         false
    };

    /* ───────── Helpers de formatacao ───────── */
    function fmtKwOuND(watts, casas = 2) {
      if (watts === null || watts === undefined) {
        return 'N/D <tspan class="aviso-integracao"><title>Aguardando integração com inversor solar. Geração será exibida quando o firmware conectar ao Solis.</title>ⓘ</tspan>';
      }
      if (isNaN(watts)) return '— kW';
      const kw = Number(watts) / 1000;
      return kw.toFixed(casas) + ' kW';
    }

    function fmtKwComSinal(watts, modo, casas = 2) {
      if (watts == null || isNaN(watts)) return '— kW';
      const kw = Number(watts) / 1000;
      
      let prefix = '';
      if (modo === 'consumo' && kw > 0) prefix = '−';
      else if (modo === 'injecao' && kw > 0) prefix = '+';
      else if (modo === 'saldo') {
        if (kw > 0) prefix = '+';
        else if (kw < 0) prefix = '−';
      }
      
      const val = Math.abs(kw).toFixed(casas);
      return prefix + val + ' kW';
    }

    function fmtKw(watts, casas = 2) {
      if (watts == null || isNaN(watts)) return '— kW';
      const kw = Number(watts) / 1000;
      return kw.toFixed(casas) + ' kW';
    }
    function fmtNum(v, sufixo = '', casas = 2) {
      if (v == null || isNaN(v)) return '—' + (sufixo ? ' ' + sufixo : '');
      return Number(v).toFixed(casas) + (sufixo ? ' ' + sufixo : '');
    }
    function fmtIdade(segs) {
      if (segs == null) return '— sem dados —';
      if (segs < 60)    return `atualizado ha ${segs}s`;
      if (segs < 3600)  return `atualizado ha ${Math.floor(segs/60)}min`;
      const h = Math.floor(segs / 3600);
      return `atualizado ha ${h}h+`;
    }

    function fmtKwh(valor, aviso) {
      if (aviso === 'aguardando_dados_suficientes') {
        return `— <tspan style="cursor:help;"><title>aguardando dados do dia</title>ⓘ</tspan>`;
      }
      if (valor === null || valor === undefined) {
        return `N/D <tspan style="cursor:help;"><title>Dado indisponível</title>ⓘ</tspan>`;
      }
      let icon = '';
      if (aviso === 'possivel_reset_medidor') {
        icon = ` <tspan style="cursor:help;"><title>leitura instável (possível reset de medidor)</title>⚠️</tspan>`;
      }
      return valor.toFixed(2) + ' kWh' + icon;
    }

    function renderCaixaHibrida(elemEnergia, elemPotencia, kwh, kw, opts = {}) {
      if (!elemEnergia || !elemPotencia) return;
      elemEnergia.innerHTML = fmtKwh(kwh, opts.aviso);
      if (opts.modo === 'nd_if_null') {
         elemPotencia.innerHTML = fmtKwOuND(kw, 2);
      } else {
         elemPotencia.innerHTML = fmtKwComSinal(kw, opts.modo || 'neutro');
      }

      const semDadoKwh = (kwh === null || kwh === undefined);
      const semDadoKw  = (kw === null || kw === undefined);

      elemEnergia.classList.toggle('--sem-dado', semDadoKwh);
      elemPotencia.classList.toggle('--sem-dado', semDadoKw);
    }

    /* ───────── Mapeamento potencia -> velocidade da seta ───────── */
    const LIMIAR_FLUXO_W = 30;

    function calcularEstadoSeta(potenciaW) {
      if (potenciaW === null || potenciaW === undefined) {
        return { ativa: false, classe: 'fluxo-standby', velocidade: 0 };
      }
      const p = Math.abs(potenciaW);
      if (p < LIMIAR_FLUXO_W) {
        return { ativa: false, classe: null, velocidade: 0 };
      }
      // 100W -> 4s, 5000W -> 0.75s
      const velocidade = Math.max(0.75, Math.min(4, 4 - (p / 5000) * 3.25));
      return { ativa: true, classe: 'fluxo-on', velocidade };
    }

    /* ───────── Aplicacao do estado visual de UM fluxo ───────── */
    function aplicarFluxo(grupo, watts) {
      if (!grupo) return;
      const estado = calcularEstadoSeta(watts);
      
      grupo.classList.remove('fluxo-on', 'fluxo-standby');
      if (estado.classe) {
        grupo.classList.add(estado.classe);
      }
      
      if (!estado.ativa) {
        grupo.style.removeProperty('--dur');
      } else {
        grupo.style.setProperty('--dur', estado.velocidade.toFixed(2) + 's');
      }
    }

    /* ───────── Atualiza badges de qualidade (4 dots) ───────── */
    function atualizarQualidade(score) {
      if (!refs.qualidade) return;
      const dots = refs.qualidade.querySelectorAll('.q-dot');
      const s = Number(score) || 0;
      // score 0-100 mapeado para 0-4 dots acesos
      const acesos =
        s >= 90 ? 4 :
        s >= 70 ? 3 :
        s >= 40 ? 2 :
        s >  0 ? 1 : 0;
      dots.forEach((d, i) => {
        d.classList.toggle('off', i >= acesos);
      });
      refs.qualidade.title = `Qualidade do dado: ${s}/100`;
    }

    /* ───────── Atualiza cor do "idade" conforme criticidade ───────── */
    function corIdade(segs) {
      if (segs == null) return 'var(--txt-dim)';
      if (segs >= CFG.criticoIdadeS) return 'var(--red)';
      if (segs >= CFG.alertaIdadeS)  return 'var(--yellow)';
      return 'var(--txt-dim)';
    }

    /* ───────── Aplicacao principal: payload -> DOM ───────── */
    /* ===== Bullets luminosos do fluxo (velocidade + qtd ∝ potência) ===== */
    const BULLET_LIMIAR_KW = 0.03;                    // 30 W
    const BULLET_DUR_MIN = 0.6, BULLET_DUR_MAX = 4;
    const BULLET_P_MAX = 10;                           // kW
    const BULLET_MAX = 3;

    function bulletDur(kw) {
      const p = Math.min(Math.abs(kw), BULLET_P_MAX);
      return (BULLET_DUR_MIN + (BULLET_DUR_MAX - BULLET_DUR_MIN) * (1 - p / BULLET_P_MAX)).toFixed(2) + 's';
    }
    function bulletQtd(kw) {
      const p = Math.min(Math.abs(kw), BULLET_P_MAX);
      return Math.max(1, Math.ceil(BULLET_MAX * (p / BULLET_P_MAX)));
    }
    function renderBullets(fluxo, kw) {
      const circles = document.querySelectorAll(`.bullet[data-bullet="${fluxo}"]`);
      if (!circles.length) return;
      const ativo = Math.abs(kw) >= BULLET_LIMIAR_KW;
      const qtd   = ativo ? bulletQtd(kw) : 0;
      const dur   = bulletDur(kw);
      const cap   = fluxo.charAt(0).toUpperCase() + fluxo.slice(1);
      circles.forEach((c) => {
        const idx  = Number(c.dataset.idx);
        const anim = document.getElementById(`anim${cap}${idx}`);
        if (idx < qtd) {
          c.classList.remove('bullet-off');
          if (anim && anim.getAttribute('dur') !== dur) anim.setAttribute('dur', dur);
        } else {
          c.classList.add('bullet-off');
        }
      });
    }
    function atualizarBullets(kw) {
      renderBullets('geracao',   kw.geracao || 0);
      const imp = kw.importada || 0, exp = kw.exportada || 0;
      renderBullets('importada', imp > exp ? imp : 0);
      renderBullets('exportada', exp > imp ? exp : 0);
    }

    function aplicarDados(p) {
      if (!p || !p.success) return;

      // Caso "vazio" (controlador sem telemetria ainda)
      if (p.vazio) {
        log('payload vazio');
        if (refs.idade) {
          refs.idade.textContent = '— sem dados —';
          refs.idade.style.color = 'var(--txt-dim)';
        }
        aplicarFluxo(refs.fluxoGeracao,   0);
        aplicarFluxo(refs.fluxoImportada, 0);
        aplicarFluxo(refs.fluxoExportada, 0);
        return;
      }

      const f = p.fluxo  || {};
      const q = p.qualidade || {};
      const r = p.rede   || {};
      const i = p.inversor || {};
      const m = p.meta   || {};
      const energiaDia = p.energia_dia || {};

      // ── Valores numericos e Hibridos ──
      renderCaixaHibrida(refs.geracaoDia, refs.geracao, energiaDia.exportada_kwh, f.exportada_w /* TODO(inversor) */, { modo: 'nd_if_null', aviso: energiaDia.aviso });
      renderCaixaHibrida(refs.importadaDia, refs.importada, energiaDia.importada_kwh, f.importada_w, { modo: 'neutro', aviso: energiaDia.aviso });
      renderCaixaHibrida(refs.exportadaDia, refs.exportada, energiaDia.exportada_kwh, f.exportada_w, { modo: 'injecao', aviso: energiaDia.aviso });
      renderCaixaHibrida(refs.consumoDia, refs.consumo, energiaDia.consumo_total_kwh, f.consumo_total_w, { modo: 'consumo', aviso: energiaDia.aviso });

      // ✨ Bullets de fluxo (W → kW)
      atualizarBullets({
        geracao:   (f.exportada_w /* TODO(inversor): f.geracao_w */ ?? 0) / 1000,
        importada: (f.importada_w ?? 0) / 1000,
        exportada: (f.exportada_w ?? 0) / 1000,
      });

      // Saldo: exportada - importada (positivo = exportando para rede)
      const saldoWatts = (Number(f.exportada_w) || 0) - (Number(f.importada_w) || 0);
      if (refs.saldo) refs.saldo.innerHTML = fmtKwComSinal(saldoWatts, 'saldo');

      // Origem da geracao (estimado/inversor/api/indisponivel)
      if (refs.geracaoOrigem) {
        const origem = q.origem_geracao || 'aguardando';
        refs.geracaoOrigem.textContent = origem;
      }

      // Bateria sempre standby nesta versao
      if (refs.bateriaDia) refs.bateriaDia.textContent = '— kWh';
      if (refs.bateria) refs.bateria.textContent = 'STANDBY';

      // ⚡ Fluxos (velocidade + on/off independentes) ⚡
      aplicarFluxo(refs.fluxoGeracao,   f.exportada_w /* TODO(inversor) */);
      aplicarFluxo(refs.fluxoImportada, f.importada_w);
      aplicarFluxo(refs.fluxoExportada, f.exportada_w);

      if (f.exportada_w === null || f.exportada_w === undefined) { // TODO(inversor)
        if (refs.geracao) refs.geracao.closest('.no-grupo').classList.add('sem-dado');
      } else {
        if (refs.geracao) refs.geracao.closest('.no-grupo').classList.remove('sem-dado');
      }

      // ── Qualidade (dots) ──
      atualizarQualidade(q.score);

      // ── Rodape ──
      if (refs.tensao)   refs.tensao.textContent   = fmtNum(r.tensao_v, 'V', 1);
      if (refs.freq)     refs.freq.textContent     = fmtNum(r.frequencia_hz, 'Hz', 2);
      
      if (refs.inversor) {
        const status = i.status ? i.status.toLowerCase() : null;
        if (status === 'offline') {
          refs.inversor.textContent = 'Inversor offline';
          refs.inversor.style.color = 'var(--yellow)';
        } else if (status === 'online' || status === 'normal') {
          refs.inversor.textContent = i.status;
          refs.inversor.style.color = 'var(--green)';
        } else {
          refs.inversor.textContent = 'Inversor: status desconhecido';
          refs.inversor.style.color = 'var(--txt-dim)';
        }
      }

      if (refs.limite) {
        refs.limite.textContent =
          i.limite_export_w != null
            ? fmtKw(i.limite_export_w, 1)
            : '—';
      }

      // ── Guarda timestamp para tick de idade ──
      state.ultimoTsUtc = p.timestamp_utc || null;
      // idade_segundos vem do servidor — usa como base inicial
      if (p.idade_segundos != null && refs.idade) {
        refs.idade.textContent = fmtIdade(p.idade_segundos);
        refs.idade.style.color = corIdade(p.idade_segundos);
      }

      state.ultimoFetchOk   = Date.now();
      state.tentativasFalha = 0;
      log('payload aplicado', p);
    }

    /* ───────── Marca estado de erro ───────── */
    function marcaErro(motivo) {
      state.tentativasFalha++;
      warn(`erro fetch (${state.tentativasFalha}/${CFG.maxRetries}): ${motivo}`);
      // NAO zera valores — mantem ultimo bom estado
      // Apenas pinta a "idade" de vermelho se ja passou de N tentativas
      if (state.tentativasFalha >= CFG.maxRetries && refs.idade) {
        refs.idade.style.color = 'var(--red)';
        refs.idade.title = `Falha de conexao (${motivo})`;
      }
    }

    /* ───────── Fetch principal ───────── */
    async function fetchDados() {
      if (state.pausado) {
        log('pausado (aba em background) — fetch ignorado');
        return;
      }

      const url = `${CFG.endpoint}?controlador_id=${encodeURIComponent(controladorId)}&_=${Date.now()}`;
      const ctrl = new AbortController();
      const timer = setTimeout(() => ctrl.abort(), CFG.timeoutFetch);

      try {
        log('fetch ->', url);
        const r = await fetch(url, {
          credentials: 'same-origin',
          headers: { 'Accept': 'application/json' },
          signal: ctrl.signal,
          cache: 'no-store'
        });
        clearTimeout(timer);

        if (!r.ok) {
          marcaErro(`HTTP ${r.status}`);
          return;
        }

        const j = await r.json();
        if (j && j.success === false) {
          marcaErro(j.mensagem || 'success=false');
          return;
        }

        aplicarDados(j);
      } catch (e) {
        clearTimeout(timer);
        marcaErro(e.name === 'AbortError' ? 'timeout' : e.message);
      }
    }

    /* ───────── Tick de idade (local, 1s) ───────── */
    function tickIdade() {
      if (!state.ultimoTsUtc || !refs.idade) return;
      const ts = Date.parse(state.ultimoTsUtc);
      if (isNaN(ts)) return;
      const segs = Math.floor((Date.now() - ts) / 1000);
      refs.idade.textContent = fmtIdade(segs);
      refs.idade.style.color = corIdade(segs);
    }

    /* ───────── Pausa quando aba sai de foco ───────── */
    document.addEventListener('visibilitychange', () => {
      state.pausado = document.hidden;
      log(state.pausado ? 'pausado (background)' : 'retomado (foreground)');
      if (!state.pausado) {
        // Ao voltar pro foco, dispara fetch imediato (pode estar desatualizado)
        fetchDados();
      }
    });

    /* ───────── Bootstrap ───────── */
    function iniciar() {
      log('iniciando — endpoint:', CFG.endpoint, '| poll:', CFG.intervaloPoll + 'ms');
      // Primeira carga imediata
      fetchDados();
      // Polling continuo
      state.timerPoll  = setInterval(fetchDados, CFG.intervaloPoll);
      // Tick de idade
      state.timerIdade = setInterval(tickIdade, CFG.intervaloIdade);
    }

    // Expoe namespace para debug manual no console
    window.CIP_Infografico = {
      cfg: CFG,
      state,
      refs,
      fetchAgora: fetchDados,
      aplicarManual: aplicarDados
    };

    // Dispara ao DOM estar pronto
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', iniciar);
    } else {
      iniciar();
    }
  })();

  // ===== PARALLAX: diagrama some/encolhe conforme rola =====
  (function initParallaxDiagrama() {
    const host = document.getElementById('infografico-host');
    const wrapper = document.querySelector('.fluxo-wrapper');
    if (!host || !wrapper) return;

    let ticking = false;
    function onScroll() {
      if (ticking) return;
      ticking = true;
      requestAnimationFrame(() => {
        const rect = wrapper.getBoundingClientRect();
        // progresso 0 (topo) -> 1 (rolou 1 viewport)
        const progresso = Math.min(Math.max(-rect.top / (window.innerHeight * 0.6), 0), 1);
        host.style.opacity = (1 - progresso * 0.85).toFixed(2);   // 1 -> 0.15
        host.style.transform = `scale(${1 - progresso * 0.08})`;  // leve recuo
        ticking = false;
      });
    }
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
  })();
  </script>
</body>
</html>
