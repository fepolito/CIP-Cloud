<?php
/**
 * @arquivo       dashboard.php
 * @versao        1.15.0
 * @modificado_em 2026-07-12
 * @objetivo      Dashboard de monitoramento de energia (KPIs + Ã¡reas reservadas para infogrÃ¡fico SVG e cards instantÃ¢neos)
 * @autor         Fernando / CIP Cloud Copilot / ATGY
 *
 * DependÃªncias de hardware:
 *   - Servidor com MySQL/MariaDB acessÃ­vel via localhost:3306
 *   - Navegador com suporte a HTML5, CSS3 e JavaScript ES6+
 *
 * DependÃªncias de software/arquivos:
 *   - config/app.php
 *   - config/database.php   â†’ factory getDbConnection()
 *   - app/auth.php
 *   - app/helpers/Tenant.php
 *   - includes/app_head.php
 *   - includes/app_header.php
 *   - assets/css/app.css
 *   - assets/css/header.css
 *   - assets/js/app-shell.js
 *   - api/dashboard/dados.php  (legado, alimenta KPIs do topo)
 *
 * HistÃ³rico de implementaÃ§Ãµes:
 *   2026-04-07  v1.0     ImplementaÃ§Ã£o inicial com ApexCharts
 *   2026-04-07  v1.1     verify.php no boot + logout com invalidaÃ§Ã£o
 *   2026-04-08  v1.2     MigraÃ§Ã£o JWT â†’ sessÃ£o PHP
 *   2026-04-08  v1.3     MigraÃ§Ã£o para app_header.php global
 *   2026-04-08  v1.4     requireAuth() do app/auth.php
 *   2026-04-08  v1.5     RemoÃ§Ã£o app-shell.js duplicado
 *   2026-04-11  v1.6     <head> refatorado: usa app_head.php
 *   2026-04-11  v1.7     RestauraÃ§Ã£o completa do <style> interno
 *   2026-04-15  v1.8     [FIX] Adicionado require database.php +
 *                        $pdo = getDbConnection() â€” ausÃªncia causava
 *                        Fatal Error no app_header.php (query logo)
 *                        Corrigido $appPaginaAtual 'inicio' â†’ 'dashboard'
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
 *   2026-07-09  v1.13.1  Fix animaÃ§Ã£o: classes de estado invÃ¡lidas (prefixo --) e
 *                        ausÃªncia de regra CSS de fluxo ativo. Setas agora animam.
 *   2026-07-09  v1.13.2  Fix stroke-dasharray para loop matemÃ¡tico perfeito no fluxo SVG.
 *   2026-07-09  v1.13.3  Reduz fator de cÃ¡lculo da velocidade da animaÃ§Ã£o do fluxo no JS.
 *   2026-07-09  v1.13.4  Ajuste do filtro SVG glow para userSpaceOnUse e simplificaÃ§Ã£o 
 *                        da animaÃ§Ã£o reversa com animation-direction.
 *   2026-07-09  v1.13.5  ReversÃ£o da animaÃ§Ã£o da exportada para @keyframes explÃ­cito
 *                        (fluxo-mover-exportada) com stroke-dashoffset 36.
 *   2026-07-09  v1.13.6  Ajuste de sinal do stroke-dashoffset (para negativo) no
 *                        fluxo exportado a pedido, alinhando com a orientaÃ§Ã£o real do path.
 * ============================================================
 */

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/helpers/Tenant.php';

// ForÃ§a UTF-8
header('Content-Type: text/html; charset=UTF-8');

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

$ctrlSolicitado = isset($_GET['ctrl']) ? (int)$_GET['ctrl'] : null;
$ctrlPadrao     = $_SESSION['controlador_padrao'] ?? null;

$estadoA = (!$ctrlSolicitado && !$ctrlPadrao);

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

if ($ctrlSolicitado && $controladorAtivo) {
    $_SESSION['controlador_padrao'] = (int) $controladorAtivo['id'];
    $estadoA = false;
}

$appTituloPagina = 'Dashboard';
$appPaginaAtual  = 'dashboard';
$appUsuarioNome  = $_SESSION['usuario_nome']  ?? 'UsuÃ¡rio';
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
    /* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
       TOKENS
    â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
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

    /* â”€â”€ TEMA CLARO â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
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

    /* â”€â”€ Reset â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
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

    /* â”€â”€ Wrapper â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
    .wrap {
      max-width: 1440px;
      margin: 0 auto;
      padding: 80px 24px 40px;
    }

    /* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
       LOADING OVERLAY
    â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
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

    /* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
       TOOLBAR (simplificada â€” sem botoes de periodo)
    â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
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

    /* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
       KPI GRID
    â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
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

    /* â”€â”€ Status pill (Online/Offline) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
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

    /* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
       AREAS RESERVADAS (B2.2 + B2.3)
    â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
    #infografico-host,
    #cards-host {
      margin-bottom: 20px;
    }

    /* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
       FOOTER
    â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
    .footer {
      text-align: center;
      color: var(--txt-dim);
      font-size: 11px;
      padding-top: 20px;
      border-top: 1px solid var(--border);
    }

    /* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
       INFOGRAFICO DE FLUXO ENERGETICO (B2.2 v1.11.0)
    â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */

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

    /* â”€â”€ Palco SVG â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
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

    /* â”€â”€ NOS (caixas) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
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
      font-size: 26px;
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
    .no-valor.verde   { fill: var(--green);  }
    .no-valor.amarelo { fill: var(--yellow); }
    .no-valor.azul    { fill: var(--blue);   }
    .no-valor.cinza   { fill: var(--txt-dim); letter-spacing: 1.5px; font-size: 13px; }

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

    /* â”€â”€ FLUXOS (caminhos) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
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
    .fluxo-ativo.verde   { stroke: var(--green);   }
    .fluxo-ativo.amarelo { stroke: var(--yellow);  }
    .fluxo-ativo.azul    { stroke: var(--blue);    }
    .fluxo-ativo.cinza   { stroke: var(--txt-dim); }

    .fluxo-grupo.fluxo-on .fluxo-ativo {
      opacity: 1;
      animation: fluxo-mover var(--dur, 1.25s) linear infinite;
    }
    .fluxo-grupo[data-fluxo="exportada"] .fluxo-ativo {
      animation-name: fluxo-mover-exportada;
    }
    .fluxo-grupo.fluxo-standby .fluxo-ativo {
      opacity: .25;
      animation: fluxo-mover 2s linear infinite;
    }

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

    /* â”€â”€ Rodape â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
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

    /* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
       RESPONSIVO
    â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */

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

<!-- â”€â”€ Loading overlay â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
<div id="loading">
  <div class="spin"></div>
  <p>VERIFICANDO SESSÃƒO...</p>
</div>

<!-- â”€â”€ CabeÃ§alho global â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
<?php require __DIR__ . '/includes/app_header.php'; ?>

<!-- â”€â”€ ConteÃºdo principal â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
<div class="wrap">

  <div class="toolbar">
    <div class="ctrl-info">
      <div class="label">Controlador</div>
      <?php if (empty($controladoresAcessiveis)): ?>
        <div class="nome">Nenhum controlador disponÃ­vel, contate o administrador</div>
      <?php elseif ($estadoA && count($controladoresAcessiveis) > 1): ?>
        <select id="sel-controlador-main" class="sel-ctrl" onchange="trocarControlador(this.value)" style="background: transparent; border: 1px solid var(--border); color: var(--txt); padding: 8px 12px; border-radius: 8px; font-size: 14px; width: 100%; margin-top: 6px; outline: none; appearance: auto;">
          <option value="" disabled selected>Selecione um controlador...</option>
          <?php foreach ($controladoresAcessiveis as $c): ?>
            <option value="<?= $c['id'] ?>" style="background: var(--card); color: var(--txt);">
              <?= htmlspecialchars($c['codigo']) ?>
              <?= $c['apelido'] ? ' â€” ' . htmlspecialchars($c['apelido']) : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      <?php else: ?>
        <div class="nome" id="nomeCtrl">Carregando...</div>
      <?php endif; ?>
    </div>
    <span class="refresh-info" id="refreshInfo">â± â€”</span>
  </div>

  <!-- â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
       INFOGRAFICO ANIMADO DE FLUXO ENERGETICO (B2.2 v1.11.0)
       Renderiza fluxo entre FV, Rede, Imovel e Bateria.
       Setas SVG animadas via CSS stroke-dasharray, com
       velocidade controlada por CSS variable --dur (kW->seg).
       Valores e velocidades sao atualizados via JS (Parte 3/3),
       consumindo api/dashboard/infografico.php.
  â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• -->
  <div id="infografico-host" class="infografico-wrap">

    <!-- Cabecalho do bloco -->
    <div class="infografico-head">
      <div class="infografico-titulo">
        ðŸŒ Fluxo Energetico em Tempo Real
      </div>
      <div class="infografico-meta">
        <span class="infografico-idade" id="infoIdade">â€” sem dados â€”</span>
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
      <svg viewBox="0 0 1000 480" preserveAspectRatio="xMidYMid meet"
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
          <path class="fluxo-ativo verde"
                d="M 500,150 C 500,180 500,200 500,230"/>
        </g>

        <!-- Fluxo 2: Rede -> Imovel (horizontal, esquerda) -->
        <g class="fluxo-grupo" data-fluxo="importada">
          <path class="fluxo-trilho"
                d="M 200,310 C 280,310 340,310 400,310"/>
          <path class="fluxo-ativo amarelo"
                d="M 200,310 C 280,310 340,310 400,310"/>
        </g>

        <!-- Fluxo 3: Imovel -> Rede (horizontal, esquerda, abaixo) -->
        <g class="fluxo-grupo" data-fluxo="exportada">
          <path class="fluxo-trilho"
                d="M 400,360 C 340,360 280,360 200,360"/>
          <path class="fluxo-ativo azul"
                d="M 400,360 C 340,360 280,360 200,360"/>
        </g>

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
          <rect class="no-caixa" x="0" y="0" width="160" height="120" rx="14"/>
          <text class="no-emoji"  x="80" y="38" text-anchor="middle">â˜€ï¸</text>
          <text class="no-titulo" x="80" y="58" text-anchor="middle">MODULOS FV</text>
          <text x="80" y="86" text-anchor="middle">
             <tspan id="valGeracaoDia" class="valor-energia verde">â€” kWh</tspan>
          </text>
          <text x="80" y="104" text-anchor="middle">
             <tspan id="valGeracao" class="valor-potencia verde">â€” kW</tspan>
          </text>
          <text class="no-sub" x="80" y="116" text-anchor="middle" id="valGeracaoOrigem">aguardando</text>
        </g>

        <!-- No: REDE / CONCESSIONARIA -->
        <g class="no-grupo" transform="translate(40, 250)">
          <rect class="no-caixa" x="0" y="0" width="160" height="180" rx="14"/>
          <text class="no-emoji"  x="80" y="38" text-anchor="middle">âš¡</text>
          <text class="no-titulo" x="80" y="58" text-anchor="middle">REDE</text>

          <!-- Linha importada (amarelo) -->
          <text class="no-mini-lbl amarelo" x="80" y="80" text-anchor="middle">â–¼ IMPORTADA</text>
          <text x="80" y="100" text-anchor="middle">
             <tspan id="valImportadaDia" class="valor-energia amarelo">â€” kWh</tspan>
          </text>
          <text x="80" y="118" text-anchor="middle">
             <tspan id="valImportada" class="valor-potencia amarelo">â€” kW</tspan>
          </text>

          <!-- Linha exportada (azul) -->
          <text class="no-mini-lbl azul" x="80" y="140" text-anchor="middle">â–² EXPORTADA</text>
          <text x="80" y="160" text-anchor="middle">
             <tspan id="valExportadaDia" class="valor-energia azul">â€” kWh</tspan>
          </text>
          <text x="80" y="178" text-anchor="middle">
             <tspan id="valExportada" class="valor-potencia azul">â€” kW</tspan>
          </text>
        </g>

        <!-- No: IMOVEL (central) -->
        <g class="no-grupo" transform="translate(400, 230)">
          <rect class="no-caixa destaque" x="0" y="0" width="200" height="180" rx="16"/>
          <text class="no-emoji"  x="100" y="42" text-anchor="middle">ðŸ </text>
          <text class="no-titulo" x="100" y="66" text-anchor="middle">IMOVEL</text>

          <text class="no-mini-lbl" x="100" y="90" text-anchor="middle">CONSUMO</text>
          <text x="100" y="112" text-anchor="middle">
             <tspan id="valConsumoDia" class="valor-energia">â€” kWh</tspan>
          </text>
          <text x="100" y="132" text-anchor="middle">
             <tspan id="valConsumo" class="valor-potencia">â€” kW</tspan>
          </text>

          <text class="no-mini-lbl" x="100" y="156" text-anchor="middle">SALDO REDE</text>
          <text class="no-valor sm" id="valSaldo" x="100" y="174" text-anchor="middle">â€” kW</text>
        </g>

        <!-- No: BATERIA (standby) -->
        <g class="no-grupo standby" transform="translate(800, 245)">
          <rect class="no-caixa" x="0" y="0" width="160" height="150" rx="14"/>
          <text class="no-emoji"  x="80" y="38" text-anchor="middle">ðŸ”‹</text>
          <text class="no-titulo" x="80" y="58" text-anchor="middle">BATERIA</text>
          <text x="80" y="86" text-anchor="middle">
             <tspan id="valBateriaDia" class="valor-energia cinza">â€” kWh</tspan>
          </text>
          <text x="80" y="104" text-anchor="middle">
             <tspan id="valBateria" class="valor-potencia cinza">STANDBY</tspan>
          </text>
          <text class="no-sub" x="80" y="124" text-anchor="middle">battery-ready</text>
          <text class="no-sub" x="80" y="138" text-anchor="middle">(em breve)</text>
        </g>

      </svg>

      <!-- Rodape do infografico: tensao + frequencia da rede -->
      <div class="infografico-rodape">
        <span class="info-mini" title="Tensao da rede">
          ðŸ”Œ <strong id="infoTensao">â€” V</strong>
        </span>
        <span class="info-mini" title="Frequencia da rede">
          ã€°ï¸ <strong id="infoFreq">â€” Hz</strong>
        </span>
        <span class="info-mini" title="Status do inversor">
          âš™ï¸ <strong id="infoInversor">â€”</strong>
        </span>
        <span class="info-mini" title="Limite de exportacao ativo">
          ðŸš¦ <strong id="infoLimite">â€”</strong>
        </span>
      </div>
    </div>
  </div>

  <!-- KPIs principais -->
  <div class="kpi-grid">
    <div class="kpi" style="--kc:var(--yellow)">
      <div class="kpi-lbl">FrequÃªncia</div>
      <div class="kpi-val" id="kFreq">â€”<span class="unit">Hz</span></div>
      <div class="kpi-sub">Nominal: 60 Hz</div>
      <div class="kpi-icon">ã€°ï¸</div>
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
      <div class="kpi-sub" id="tPing">Ãšltimo ping: â€”</div>
      <div class="kpi-icon">ðŸ“¡</div>
    </div>
    <div class="kpi" style="--kc:var(--blue2)">
      <div class="kpi-lbl">LocalizaÃ§Ã£o</div>
      <div class="kpi-val" style="font-size:14px;line-height:1.4" id="tLoc">â€”</div>
      <div class="kpi-icon">ðŸ“</div>
    </div>
    <div class="kpi" style="--kc:var(--txt-mid)">
      <div class="kpi-lbl">Controlador</div>
      <div class="kpi-val" style="font-size:14px;line-height:1.4" id="nomeCtrlKpi">â€”</div>
      <div class="kpi-icon">ðŸ–¥ï¸</div>
    </div>
  </div>

  <!-- â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
       AREA RESERVADA â€” 4 CARDS INSTANTANEOS (B2.3)
       Substituira os antigos graficos PDE com cartoes de
       leitura instantanea. Sera populada no Patch B2.3.
  â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• -->
  <div id="cards-host"></div>

</div><!-- /wrap -->

<footer class="footer">
  CIP â€” Controlador de InjeÃ§Ã£o de PotÃªncia ElÃ©trica &nbsp;|&nbsp;
  Aeonium &nbsp;|&nbsp; SÃ£o Paulo, BR &nbsp;|&nbsp; v1.15.0
</footer>

<script>
'use strict';

<?php if (empty($controladoresAcessiveis)): ?>
const CTRL_ID = null;
const CTRL_NOME = null;
const CTRL_CODIGO = null;
<?php else: ?>
const CTRL_ID = <?= json_encode((int) $controladorAtivo['id']) ?>;
const CTRL_NOME = <?= json_encode($controladorAtivo['apelido'] ?? null) ?>;
const CTRL_CODIGO = <?= json_encode($controladorAtivo['codigo'] ?? null) ?>;
<?php endif; ?>

const INTERVAL = 10000;
let timer = null;

function trocarControlador(novoId) {
  if (!novoId) return;
  const url = new URL(window.location.href);
  url.searchParams.set('ctrl', novoId);
  window.location.href = url.toString();
}

function lerCoresCss() {
  const cs = getComputedStyle(document.documentElement);
  const pega = (varName, fallback) => {
    const v = cs.getPropertyValue(varName).trim();
    return v || fallback;
  };
  return {
    blue:    pega('--blue',    '#00b4ff'),
    blue2:   pega('--blue2',   '#0070cc'),
    green:   pega('--green',   '#00e676'),
    yellow:  pega('--yellow',  '#ffc107'),
    orange:  pega('--orange',  '#ff9800'),
    red:     pega('--red',     '#ff5252'),
    purple:  pega('--purple',  '#ce93d8'),
    cyan:    pega('--cyan',    '#18ffff'),
    txt:     pega('--txt',     '#e0eaf8'),
    dim:     pega('--border',  '#1a2d4a'),
    bg:      pega('--card',    '#0d1526'),
  };
}

let C = lerCoresCss();

const fmt = (v, d, u) => v != null ? `${(+v).toFixed(d)} ${u}`.trim() : 'â€”';

async function verificarToken() {
  try {
    const res = await fetch('/api/auth/verify.php', { method: 'GET', credentials: 'same-origin' });
    if (!res.ok) { console.error('Redirect impedido pelo debug'); /* window.location.href = '/login.php'; */ return false; }
    const data = await res.json();
    if (!data.success) { console.error('Redirect impedido pelo debug'); /* window.location.href = '/login.php'; */ return false; }
    sessionStorage.setItem('cip_usuario_nome',  data.usuario.nome);
    sessionStorage.setItem('cip_usuario_email', data.usuario.email);
    if (data.segundos_restantes < 1800)
      console.warn(`[CIP] SessÃ£o expira em ${data.segundos_restantes}s`);
    return true;
  } catch (e) {
    console.warn('[CIP] verify.php inacessÃ­vel â€” modo offline');
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
    console.error('Redirect impedido pelo debug'); /* window.location.href = '/login.php'; */
  }
}

/**
 * carregarKpis() â€” versao simplificada do antigo carregar()
 * Mantem o consumo de /api/dashboard/dados.php (legado) mas
 * atualiza APENAS os 9 KPIs do topo. Series temporais, gauge
 * e totalizadores foram removidos no Patch B2.1.
 */
const SEM = { STALE_S: 1200, OFFLINE_S: 2400 };

window.aplicarSemaforo = function(controlador, r, im, g, inv) {
  const el = document.getElementById('semaforo');
  if (!el) return;
  el.style.display = 'flex';

  const luz = document.getElementById('semLuz');
  const titulo = document.getElementById('semTitulo');
  const sub = document.getElementById('semSub');

  const idade = controlador.ultimo_ping
    ? (Date.now() - new Date(controlador.ultimo_ping).getTime()) / 1000 : 99999;
  
  const kwGeracao = parseFloat(g?.kw || g?.w || 0);
  const invStatus = inv?.status || 'offline';

  let estado = 'cinza', icone = 'âšª', tituloTxt = '', subTxt = '';

  if (idade >= SEM.OFFLINE_S) {
    estado = 'vermelho'; icone = 'ðŸ”´';
    tituloTxt = 'Sem comunicaÃ§Ã£o';
    subTxt = Usina offline hÃ¡ mais de  minutos. Verifique a internet no local.;
  } else if (idade >= SEM.STALE_S) {
    estado = 'amarelo'; icone = 'ðŸŸ¡';
    tituloTxt = 'ComunicaÃ§Ã£o atrasada';
    subTxt = Ãšltimo dado recebido hÃ¡  min. Atraso na transmissÃ£o.;
  } else if (kwGeracao <= 0 && invStatus !== 'online') {
    estado = 'amarelo'; icone = 'ðŸŸ¡';
    tituloTxt = 'Usina em repouso';
    subTxt = Online, mas sem geraÃ§Ã£o no momento (noite ou baixa luz).;
  } else {
    estado = 'verde'; icone = 'ðŸŸ¢';
    tituloTxt = 'Tudo funcionando';
    subTxt = Usina online e ativa â€“ dado de  min atrÃ¡s.;
  }

  luz.className = semaforo-luz ;
  titulo.textContent = ${icone} ;
  sub.textContent = subTxt;
};

function setBadge(pillId, txtId, isOnline) {
  const p = document.getElementById(pillId);
  const t = document.getElementById(txtId);
  if (p) p.className = 'kpi-status-pill ' + (isOnline ? 'online' : 'offline');
  if (t) t.textContent = isOnline ? 'ONLINE' : 'OFFLINE';
}

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

    if (atual?.frequencia_hz !== undefined) {
      document.getElementById('kFreq').innerHTML = `${(+atual.frequencia_hz).toFixed(2)}<span class="unit">Hz</span>`;
    }

    if (controlador) {
      const nomeCtrl = controlador.nome || `Controlador #${CTRL_ID}`;
      const nomeEl = document.getElementById('nomeCtrl');
      if (nomeEl) nomeEl.textContent = nomeCtrl;
      document.getElementById('nomeCtrlKpi').textContent = nomeCtrl;
      document.getElementById('tLoc').textContent        = controlador.localizacao || 'â€”';

      const pingStr = controlador.ultimo_ping
        ? new Date(controlador.ultimo_ping).toLocaleString('pt-BR') : 'â€”';
      document.getElementById('tPing').textContent = `Ãšltimo ping: ${pingStr}`;

      const diff     = controlador.ultimo_ping
        ? (Date.now() - new Date(controlador.ultimo_ping).getTime()) / 1000 : 999;
      const isOnline = diff <= 30;
      document.getElementById('statusPill').className  = `kpi-status-pill ${isOnline ? 'online' : 'offline'}`;
      document.getElementById('statusTxt').textContent = isOnline ? 'ONLINE' : 'OFFLINE';
    }

    document.getElementById('refreshInfo').textContent =
      'â± ' + new Date().toLocaleTimeString('pt-BR') + ' Â· auto 10s';
    document.getElementById('loading').style.display = 'none';

  } catch (err) {
    console.error('[CIP] Erro ao carregar KPIs:', err);
    document.getElementById('refreshInfo').textContent = 'âš ï¸ Falha na Ãºltima atualizaÃ§Ã£o';
    document.getElementById('loading').style.display   = 'none';
  }
}

function startKpiTimer() {
  if (timer) clearInterval(timer);
  timer = setInterval(carregarKpis, INTERVAL);
}

// â”€â”€ Reatividade de tema (apenas atualiza cache de cores) â”€â”€â”€â”€â”€
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

// â”€â”€ Boot â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
verificarToken().then(ok => {
  if (!ok) return;
  if (!CTRL_ID) {
    document.getElementById('loading').style.display = 'none';
    return;
  }
  carregarKpis();
  startKpiTimer();
}).catch(err => {
  console.error('[CIP] Falha na verificaÃ§Ã£o de sessÃ£o:', err);
  const l = document.getElementById('loading');
  if (l) l.innerHTML = '<p>Erro ao verificar sessÃ£o. <a href="/login.php">Recarregar</a></p>';
});
</script>

  <script>
    // Expoe o ID do controlador atual para os scripts do dashboard
    window.CIP_CTRL_ID = <?= json_encode($controladorAtivo ? (int)$controladorAtivo['id'] : 0) ?>;
  </script>

  <!-- â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
       JAVASCRIPT DO INFOGRAFICO (B2.2 Parte 3/3 â€” v1.12.0)
       Polling 30s + binding de valores + velocidade das setas.
       Namespace isolado: window.CIP_Infografico
  â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• -->
  <script>
  (function(){
    'use strict';

    /* â”€â”€â”€â”€â”€â”€â”€â”€â”€ Configuracao â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
    const CFG = {
      endpoint:        '/api/dashboard/infografico.php',
      intervaloPoll:   30000,   // 30s entre fetchs
      intervaloIdade:  1000,    // 1s entre ticks de "idade"
      timeoutFetch:    8000,    // 8s timeout do fetch
      alertaIdadeS:    360,     // 6min â€” uma janela perdida
      criticoIdadeS:   660,     // 11min â€” duas janelas perdidas
      maxRetries:      3,       // tentativas em caso de erro
      debug: new URLSearchParams(location.search).get('debug') === '1'
    };

    /* â”€â”€â”€â”€â”€â”€â”€â”€â”€ Log condicional â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
    const log = (...a) => CFG.debug && console.log('[CIP-Info]', ...a);
    const warn = (...a) => console.warn('[CIP-Info]', ...a);

    /* â”€â”€â”€â”€â”€â”€â”€â”€â”€ ID do controlador â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
    // Tentar capturar de window.CIP_CTRL_ID (definido no PHP)
    // ou de data-attribute, ou de query string como fallback
    const controladorId =
      window.CIP_CTRL_ID ||
      document.body.dataset.controladorId ||
      new URLSearchParams(location.search).get('controlador_id') ||
      null;

    if (!controladorId) {
      warn('controlador_id nao identificado â€” infografico desativado');
      return;
    }
    log('controlador_id =', controladorId);

    /* â”€â”€â”€â”€â”€â”€â”€â”€â”€ Cache de refs DOM â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
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

    /* â”€â”€â”€â”€â”€â”€â”€â”€â”€ Estado â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
    const state = {
      ultimoTsUtc:     null,
      ultimoFetchOk:   null,
      tentativasFalha: 0,
      timerPoll:       null,
      timerIdade:      null,
      pausado:         false
    };

    /* â”€â”€â”€â”€â”€â”€â”€â”€â”€ Helpers de formatacao â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
    function fmtKwOuND(watts, casas = 2) {
      if (watts === null || watts === undefined) {
        return 'N/D <tspan class="aviso-integracao"><title>Aguardando integraÃ§Ã£o com inversor solar. GeraÃ§Ã£o serÃ¡ exibida quando o firmware conectar ao Solis.</title>â“˜</tspan>';
      }
      if (isNaN(watts)) return 'â€” kW';
      const kw = Number(watts) / 1000;
      return kw.toFixed(casas) + ' kW';
    }

    function fmtKwComSinal(watts, modo, casas = 2) {
      if (watts == null || isNaN(watts)) return 'â€” kW';
      const kw = Number(watts) / 1000;
      
      let prefix = '';
      if (modo === 'consumo' && kw > 0) prefix = 'âˆ’';
      else if (modo === 'injecao' && kw > 0) prefix = '+';
      else if (modo === 'saldo') {
        if (kw > 0) prefix = '+';
        else if (kw < 0) prefix = 'âˆ’';
      }
      
      const val = Math.abs(kw).toFixed(casas);
      return prefix + val + ' kW';
    }

    function fmtKw(watts, casas = 2) {
      if (watts == null || isNaN(watts)) return 'â€” kW';
      const kw = Number(watts) / 1000;
      return kw.toFixed(casas) + ' kW';
    }
    function fmtNum(v, sufixo = '', casas = 2) {
      if (v == null || isNaN(v)) return 'â€”' + (sufixo ? ' ' + sufixo : '');
      return Number(v).toFixed(casas) + (sufixo ? ' ' + sufixo : '');
    }
    function fmtIdade(segs) {
      if (segs == null) return 'â€” sem dados â€”';
      if (segs < 60)    return `atualizado ha ${segs}s`;
      if (segs < 3600)  return `atualizado ha ${Math.floor(segs/60)}min`;
      const h = Math.floor(segs / 3600);
      return `atualizado ha ${h}h+`;
    }

    function fmtKwh(valor, aviso) {
      if (aviso === 'aguardando_dados_suficientes') {
        return `â€” <tspan style="cursor:help;"><title>aguardando dados do dia</title>â“˜</tspan>`;
      }
      if (valor === null || valor === undefined) {
        return `N/D <tspan style="cursor:help;"><title>Dado indisponÃ­vel</title>â“˜</tspan>`;
      }
      let icon = '';
      if (aviso === 'possivel_reset_medidor') {
        icon = ` <tspan style="cursor:help;"><title>leitura instÃ¡vel (possÃ­vel reset de medidor)</title>âš ï¸</tspan>`;
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

    /* â”€â”€â”€â”€â”€â”€â”€â”€â”€ Mapeamento potencia -> velocidade da seta â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
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

    /* â”€â”€â”€â”€â”€â”€â”€â”€â”€ Aplicacao do estado visual de UM fluxo â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
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

    /* â”€â”€â”€â”€â”€â”€â”€â”€â”€ Atualiza badges de qualidade (4 dots) â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
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

    /* â”€â”€â”€â”€â”€â”€â”€â”€â”€ Atualiza cor do "idade" conforme criticidade â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
    function corIdade(segs) {
      if (segs == null) return 'var(--txt-dim)';
      if (segs >= CFG.criticoIdadeS) return 'var(--red)';
      if (segs >= CFG.alertaIdadeS)  return 'var(--yellow)';
      return 'var(--txt-dim)';
    }

    /* â”€â”€â”€â”€â”€â”€â”€â”€â”€ Aplicacao principal: payload -> DOM â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
    function aplicarDados(p) {
      if (!p || !p.success) return;

      // Caso "vazio" (controlador sem telemetria ainda)
      if (p.vazio) {
        log('payload vazio');
        if (refs.idade) {
          refs.idade.textContent = 'â€” sem dados â€”';
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

      // â”€â”€ Valores numericos e Hibridos â”€â”€
      renderCaixaHibrida(refs.geracaoDia, refs.geracao, energiaDia.geracao_kwh, f.geracao_w, { modo: 'nd_if_null', aviso: energiaDia.aviso });
      renderCaixaHibrida(refs.importadaDia, refs.importada, energiaDia.importada_kwh, f.importada_w, { modo: 'neutro', aviso: energiaDia.aviso });
      renderCaixaHibrida(refs.exportadaDia, refs.exportada, energiaDia.exportada_kwh, f.exportada_w, { modo: 'injecao', aviso: energiaDia.aviso });
      renderCaixaHibrida(refs.consumoDia, refs.consumo, energiaDia.consumo_total_kwh, f.consumo_total_w, { modo: 'consumo', aviso: energiaDia.aviso });

      // Saldo: exportada - importada (positivo = exportando para rede)
      const saldoWatts = (Number(f.exportada_w) || 0) - (Number(f.importada_w) || 0);
      if (refs.saldo) refs.saldo.innerHTML = fmtKwComSinal(saldoWatts, 'saldo');

      // Origem da geracao (estimado/inversor/api/indisponivel)
      if (refs.geracaoOrigem) {
        const origem = q.origem_geracao || 'aguardando';
        refs.geracaoOrigem.textContent = origem;
      }

      // Bateria sempre standby nesta versao
      if (refs.bateriaDia) refs.bateriaDia.textContent = 'â€” kWh';
      if (refs.bateria) refs.bateria.textContent = 'STANDBY';

      // â”€â”€ Fluxos (velocidade + on/off independentes) â”€â”€
      aplicarFluxo(refs.fluxoGeracao,   f.geracao_w);
      aplicarFluxo(refs.fluxoImportada, f.importada_w);
      aplicarFluxo(refs.fluxoExportada, f.exportada_w);

      if (f.geracao_w === null || f.geracao_w === undefined) {
        if (refs.geracao) refs.geracao.closest('.no-grupo').classList.add('sem-dado');
      } else {
        if (refs.geracao) refs.geracao.closest('.no-grupo').classList.remove('sem-dado');
      }

      // â”€â”€ Qualidade (dots) â”€â”€
      atualizarQualidade(q.score);

      // â”€â”€ Rodape â”€â”€
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
            : 'â€”';
      }

      // â”€â”€ Guarda timestamp para tick de idade â”€â”€
      state.ultimoTsUtc = p.timestamp_utc || null;
      // idade_segundos vem do servidor â€” usa como base inicial
      if (p.idade_segundos != null && refs.idade) {
        refs.idade.textContent = fmtIdade(p.idade_segundos);
        refs.idade.style.color = corIdade(p.idade_segundos);
      }

      state.ultimoFetchOk   = Date.now();
      state.tentativasFalha = 0;
      log('payload aplicado', p);
    }

    /* â”€â”€â”€â”€â”€â”€â”€â”€â”€ Marca estado de erro â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
    function marcaErro(motivo) {
      state.tentativasFalha++;
      warn(`erro fetch (${state.tentativasFalha}/${CFG.maxRetries}): ${motivo}`);
      // NAO zera valores â€” mantem ultimo bom estado
      // Apenas pinta a "idade" de vermelho se ja passou de N tentativas
      if (state.tentativasFalha >= CFG.maxRetries && refs.idade) {
        refs.idade.style.color = 'var(--red)';
        refs.idade.title = `Falha de conexao (${motivo})`;
      }
    }

    /* â”€â”€â”€â”€â”€â”€â”€â”€â”€ Fetch principal â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
    async function fetchDados() {
      if (state.pausado) {
        log('pausado (aba em background) â€” fetch ignorado');
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

    /* â”€â”€â”€â”€â”€â”€â”€â”€â”€ Tick de idade (local, 1s) â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
    function tickIdade() {
      if (!state.ultimoTsUtc || !refs.idade) return;
      const ts = Date.parse(state.ultimoTsUtc);
      if (isNaN(ts)) return;
      const segs = Math.floor((Date.now() - ts) / 1000);
      refs.idade.textContent = fmtIdade(segs);
      refs.idade.style.color = corIdade(segs);
    }

    /* â”€â”€â”€â”€â”€â”€â”€â”€â”€ Pausa quando aba sai de foco â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
    document.addEventListener('visibilitychange', () => {
      state.pausado = document.hidden;
      log(state.pausado ? 'pausado (background)' : 'retomado (foreground)');
      if (!state.pausado) {
        // Ao voltar pro foco, dispara fetch imediato (pode estar desatualizado)
        fetchDados();
      }
    });

    /* â”€â”€â”€â”€â”€â”€â”€â”€â”€ Bootstrap â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
    function iniciar() {
      log('iniciando â€” endpoint:', CFG.endpoint, '| poll:', CFG.intervaloPoll + 'ms');
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
  </script>
</body>
</html>
