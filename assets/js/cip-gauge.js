/**
 * @arquivo       assets/js/cip-gauge.js
 * @versao        1.0.0
 * @modificado_em 2026-07-11
 * @objetivo      Renderiza gauge SVG de 3 anéis concêntricos (geração/export-import/consumo), escala compartilhada, com marca de pico do dia por anel. Consome CIP_PicoDia.
 * @autor         Fernando / CIP Cloud Copilot / ATGY
 */
'use strict';

const CIP_Gauge = (() => {
  const NS = 'http://www.w3.org/2000/svg';
  const CX = 100, CY = 100;
  const ARCO = 270;               // graus de arco útil
  const START = 135;              // ângulo inicial (canto inf-esq)
  const RAIOS = { geracao: 82, meio: 62, consumo: 42 };
  const LARGURA = 14;
  const COR = {
    geracao:   '#f97316',              // laranja
    exportada: '#22c55e',              // verde
    importada: '#ef4444',              // vermelho
    consumo:   '#3b82f6',              // azul
    fundo:     'rgba(255,255,255,0.08)',
    tick:      '#e5e7eb'
  };

  const _rad = (g) => (g - 90) * Math.PI / 180;
  const _ponto = (r, ang) => ({ x: CX + r * Math.cos(_rad(ang)), y: CY + r * Math.sin(_rad(ang)) });

  function _arco(r, frac, cor, larg) {
    frac = Math.max(0, Math.min(1, frac));
    const fim = START + ARCO * frac;
    const p0 = _ponto(r, START), p1 = _ponto(r, fim);
    const largeArc = (ARCO * frac) > 180 ? 1 : 0;
    const el = document.createElementNS(NS, 'path');
    el.setAttribute('d', `M ${p0.x} ${p0.y} A ${r} ${r} 0 ${largeArc} 1 ${p1.x} ${p1.y}`);
    el.setAttribute('fill', 'none');
    el.setAttribute('stroke', cor);
    el.setAttribute('stroke-width', larg);
    el.setAttribute('stroke-linecap', 'round');
    return el;
  }

  function _tick(r, frac, cor, larg) {
    frac = Math.max(0, Math.min(1, frac));
    const ang = START + ARCO * frac;
    const pi = _ponto(r - larg / 2 - 2, ang);
    const po = _ponto(r + larg / 2 + 2, ang);
    const el = document.createElementNS(NS, 'line');
    el.setAttribute('x1', pi.x); el.setAttribute('y1', pi.y);
    el.setAttribute('x2', po.x); el.setAttribute('y2', po.y);
    el.setAttribute('stroke', cor);
    el.setAttribute('stroke-width', 2.5);
    el.setAttribute('stroke-linecap', 'round');
    return el;
  }

  /**
   * @param {HTMLElement} container
   * @param {Object} d  { escala_kw, atual:{geracao,exportada,importada,consumo,is_exporting},
   *                       picos:{geracao,exportada,importada,consumo} }
   */
  function render(container, d) {
    if (!container) return;
    const esc = parseFloat(d.escala_kw) || 1;
    const a = d.atual || {}, p = d.picos || {};
    const f = (v) => (parseFloat(v) || 0) / esc;

    const svg = document.createElementNS(NS, 'svg');
    svg.setAttribute('viewBox', '0 0 200 200');
    svg.setAttribute('class', 'cip-gauge');

    // fundos
    [RAIOS.geracao, RAIOS.meio, RAIOS.consumo].forEach(r =>
      svg.appendChild(_arco(r, 1, COR.fundo, LARGURA)));

    // anel 1 — geração (externo)
    svg.appendChild(_arco(RAIOS.geracao, f(a.geracao), COR.geracao, LARGURA));
    svg.appendChild(_tick(RAIOS.geracao, f(p.geracao), COR.tick, LARGURA));

    // anel 2 — export/import (bidirecional) + 2 ticks
    const exportando = a.is_exporting === true;
    const importando = a.is_exporting === false;
    const valMeio = exportando ? a.exportada : (importando ? a.importada : 0);
    const corMeio = exportando ? COR.exportada : (importando ? COR.importada : COR.fundo);
    svg.appendChild(_arco(RAIOS.meio, f(valMeio), corMeio, LARGURA));
    svg.appendChild(_tick(RAIOS.meio, f(p.exportada), COR.exportada, LARGURA));
    svg.appendChild(_tick(RAIOS.meio, f(p.importada), COR.importada, LARGURA));

    // anel 3 — consumo (interno)
    svg.appendChild(_arco(RAIOS.consumo, f(a.consumo), COR.consumo, LARGURA));
    svg.appendChild(_tick(RAIOS.consumo, f(p.consumo), COR.tick, LARGURA));

    container.innerHTML = '';
    container.appendChild(svg);
  }

  return { render, COR };
})();

if (typeof window !== 'undefined') window.CIP_Gauge = CIP_Gauge;
