<?php
/**
 * =============================================================================
 * Projeto    : CIP - Controlador de Injecao de Potencia Eletrica
 * Arquivo    : includes/app_head.php
 * Objetivo   : Emitir tags <meta>, <link>, <title> e scripts base dentro do
 *              <head> de cada pagina. Inclui snippet anti-FOUC que aplica o
 *              tema (claro/escuro) ANTES do primeiro paint, eliminando o
 *              flash branco em transicoes para tema escuro.
 *
 * Dependencias de hardware:
 *   - Servidor web com suporte a PHP 7.4+
 *
 * Dependencias de software/arquivos:
 *   - includes/config.php       (asset(), APP_URL, APP_BASE_PATH)
 *   - assets/css/app.css        (tokens de tema :root + [data-tema="claro"])
 *   - assets/css/header.css     (estilos do header global)
 *   - assets/js/app-shell.js    (controla drawers - CRITICO)
 *   - assets/js/tema.js         (gestao centralizada de tema claro/escuro)
 *
 * Historico de implementacoes:
 *   2026-04-11  v1.0  Criacao - extracao das tags <head>
 *                     Cache-busting via filemtime()
 *   2026-04-15  v1.1  [FIX] Adicionado app-shell.js via jsVersion()
 *                     Ausencia impedia drawers de funcionar em paginas
 *                     que usam app_head.php sem carregar manualmente
 *   2026-05-18  v1.2  [ADD] Snippet anti-FOUC de tema inline no <head>
 *                     [ADD] Carga global de tema.js
 *                     Motivo: centralizar tema (claro/escuro) como
 *                     escolha GLOBAL aplicada antes do primeiro paint,
 *                     eliminando flash branco e duplicacao de codigo
 *                     entre paginas.
 *   2026-05-18  v1.3  [FIX CRITICO] Removido defer do tema.js + removida
 *                     linha duplicada.
 *                     Motivo: scripts inline de paginas (energia.php)
 *                     usam window.CipTema.atual() durante init(). Com
 *                     defer, CipTema so existia depois do DOM pronto,
 *                     causando crash em buildChartOptions e impedindo
 *                     carregamento de dados/grafico. Tema funcionava
 *                     visualmente (via snippet anti-FOUC) mas API JS
 *                     ainda nao estava montada.
 * =============================================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

// ── Cache-busting CSS ────────────────────────────────────────────────────────
if (!function_exists('cssVersion')) {
    function cssVersion(string $relativePath): string
    {
        $fullPath = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/'
                  . ltrim($relativePath, '/');
        $ts = file_exists($fullPath) ? filemtime($fullPath) : time();
        return asset($relativePath) . '?v=' . $ts;
    }
}

// ── Cache-busting JS ─────────────────────────────────────────────────────────
if (!function_exists('jsVersion')) {
    function jsVersion(string $relativePath): string
    {
        $fullPath = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/'
                  . ltrim($relativePath, '/');
        $ts = file_exists($fullPath) ? filemtime($fullPath) : time();
        return asset($relativePath) . '?v=' . $ts;
    }
}

$appTituloPagina = $appTituloPagina ?? 'CIP';
?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="theme-color" content="#0f172a">
<title>CIP — <?= htmlspecialchars($appTituloPagina, ENT_QUOTES, 'UTF-8') ?></title>

<!--
    Anti-FOUC de tema — CRITICO: precisa rodar ANTES do CSS para que o
    atributo data-tema esteja correto no primeiro paint. Sem isso, paginas
    com tema claro salvo no localStorage piscam em escuro antes de trocar.
    Codigo inline (sem src) para zero latencia.
-->
<script>
(function () {
  try {
    var t = localStorage.getItem('cip-tema');
    if (t !== 'claro' && t !== 'escuro') t = 'escuro';
    document.documentElement.setAttribute('data-tema', t);
  } catch (e) {
    document.documentElement.setAttribute('data-tema', 'escuro');
  }
})();
</script>

<!-- CSS com cache-busting automatico baseado em filemtime() -->
<link rel="stylesheet" href="<?= htmlspecialchars(cssVersion('assets/css/app.css'),    ENT_QUOTES) ?>">
<link rel="stylesheet" href="<?= htmlspecialchars(cssVersion('assets/css/header.css'), ENT_QUOTES) ?>">
<!--
  Favicon Aeonium Energia Sustentavel
  @versao 1.0.0
  @modificado_em 2026-05-31
  Pacote multi-resolucao gerado via RealFaviconGenerator
-->

<link rel="icon" type="image/png" href="/assets/favicon/favicon-96x96.png" sizes="96x96" />
<link rel="icon" type="image/svg+xml" href="/assets/favicon/favicon.svg" />
<link rel="shortcut icon" href="/assets/favicon/favicon.ico" />
<link rel="apple-touch-icon" sizes="180x180" href="/assets/favicon/apple-touch-icon.png" />
<meta name="apple-mobile-web-app-title" content="MyWebSite" />
<link rel="manifest" href="/assets/favicon/site.webmanifest" />

<!--
    app-shell.js — CRITICO: controla os dois drawers (hamburguer e
    engrenagem), overlay e acessibilidade. Deve estar no <head> com
    defer para garantir execucao apos o DOM estar pronto, sem bloquear
    o render da pagina.
-->
<script defer src="<?= htmlspecialchars(jsVersion('assets/js/app-shell.js'), ENT_QUOTES) ?>"></script>

<!--
    tema.js — Gestao centralizada de tema claro/escuro.
    Expoe window.CipTema com aplicar/alternar/atual/registrarChart.
-->
<script src="<?= htmlspecialchars(jsVersion('assets/js/tema.js'), ENT_QUOTES) ?>"></script>


