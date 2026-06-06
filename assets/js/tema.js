/**
 * =============================================================================
 * Projeto    : CIP - Controlador de Injecao de Potencia Eletrica
 * Arquivo    : assets/js/tema.js
 * Objetivo   : Gestao centralizada do tema visual (claro/escuro) da aplicacao.
 *              Aplica o atributo data-tema no <html>, persiste a escolha em
 *              localStorage, sincroniza com instancia ApexCharts (se houver)
 *              e expoe API global (window.CipTema) para outras paginas.
 *
 * Dependencias de hardware:
 *   - Navegador com suporte a HTML5, localStorage e CSS Custom Properties
 *
 * Dependencias de software/arquivos:
 *   - includes/app_head.php   (snippet anti-FOUC que define data-tema inicial)
 *   - includes/app_header.php (botao #btn-tema)
 *   - assets/css/app.css      (tokens :root + html[data-tema="claro"])
 *
 * API publica (window.CipTema):
 *   - aplicar(tema)    -> aplica 'escuro' | 'claro'
 *   - alternar()       -> inverte o tema atual
 *   - atual()          -> retorna o tema corrente
 *   - registrarChart(chart) -> registra instancia ApexCharts para sync
 *
 * Historico de implementacoes:
 *   2026-05-18  v1.0.0  Criacao - extracao da logica que vivia em energia.php
 *                       Centralizacao em modulo global reutilizavel em todas
 *                       as paginas do sistema (dashboard, energia, empresas,
 *                       usuarios, etc).
 * =============================================================================
 */
(function () {
  'use strict';

  const CHAVE_STORAGE = 'cip-tema';
  const TEMA_PADRAO   = 'escuro';
  const TEMAS_VALIDOS = ['claro', 'escuro'];

  // Charts ApexCharts registrados para sincronia de tema
  const chartsRegistrados = new Set();

  /**
   * Retorna o tema corrente lido do <html data-tema="...">.
   */
  function temaAtual() {
    const t = document.documentElement.getAttribute('data-tema');
    return TEMAS_VALIDOS.includes(t) ? t : TEMA_PADRAO;
  }

  /**
   * Aplica o tema: atualiza atributo HTML, persiste em localStorage,
   * sincroniza botao e charts registrados.
   */
  function aplicarTema(tema) {
    if (!TEMAS_VALIDOS.includes(tema)) tema = TEMA_PADRAO;

    document.documentElement.setAttribute('data-tema', tema);

    try {
      localStorage.setItem(CHAVE_STORAGE, tema);
    } catch (e) {
      // localStorage pode estar bloqueado (modo privado) - ignora silenciosamente
    }

    // Atualiza icone do botao no header
    const btn = document.getElementById('btn-tema');
    if (btn) {
      btn.textContent = tema === 'escuro' ? '🌙' : '☀️';
      btn.setAttribute('aria-label',
        tema === 'escuro' ? 'Mudar para tema claro' : 'Mudar para tema escuro');
    }

    // Sincroniza ApexCharts registrados
    chartsRegistrados.forEach((chart) => {
      if (chart && typeof chart.updateOptions === 'function') {
        chart.updateOptions({
          theme: { mode: tema === 'escuro' ? 'dark' : 'light' },
          chart: { background: 'transparent' }
        }, false, true);
      }
    });
  }

  /**
   * Inverte o tema atual.
   */
  function alternarTema() {
    aplicarTema(temaAtual() === 'escuro' ? 'claro' : 'escuro');
  }

  /**
   * Registra uma instancia ApexCharts para sincronia automatica de tema.
   */
  function registrarChart(chart) {
    if (chart) chartsRegistrados.add(chart);
  }

  // Expoe API global
  window.CipTema = {
    aplicar:        aplicarTema,
    alternar:       alternarTema,
    atual:          temaAtual,
    registrarChart: registrarChart
  };

  // Liga o botao do header quando o DOM estiver pronto
  document.addEventListener('DOMContentLoaded', function () {
    const btn = document.getElementById('btn-tema');
    if (btn) {
      btn.addEventListener('click', alternarTema);
      // Sincroniza icone inicial (caso anti-FOUC ja tenha aplicado o tema)
      const t = temaAtual();
      btn.textContent = t === 'escuro' ? '🌙' : '☀️';
    }
  });
})();
