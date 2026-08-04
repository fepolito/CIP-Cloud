/**
 * @arquivo       assets/js/cip-pico-dia.js
 * @versao        1.0.0
 * @modificado_em 2026-07-11
 * @objetivo      Helper genérico de rastreio do pico do dia por parâmetro (seed SQL + max incremental via localStorage, reset diário automático). Consumido pelo gauge de 3 anéis.
 * @autor         Fernando / CIP Cloud Copilot / ATGY
 */
'use strict';

const CIP_PicoDia = (() => {
  const _chave = (ctrl, param, dataLocal) => `cip_pico::${ctrl}::${param}::${dataLocal}`;

  function _ler(ctrl, param, dataLocal) {
    const v = parseFloat(localStorage.getItem(_chave(ctrl, param, dataLocal)));
    return Number.isFinite(v) ? v : null;
  }

  function _gravar(ctrl, param, dataLocal, valor) {
    try { localStorage.setItem(_chave(ctrl, param, dataLocal), String(valor)); } catch (_) {}
  }

  /**
   * Semeia o piso inicial (fonte da verdade = BD). BD vence localStorage menor.
   * @param {string|number} ctrl
   * @param {string} dataLocal  YYYY-MM-DD (dia local do controlador)
   * @param {Object} picos      { geracao, exportada, importada, consumo }
   */
  function seed(ctrl, dataLocal, picos) {
    Object.entries(picos || {}).forEach(([param, valBd]) => {
      const v = parseFloat(valBd);
      if (!Number.isFinite(v)) return;
      const atual = _ler(ctrl, param, dataLocal);
      if (atual === null || v > atual) _gravar(ctrl, param, dataLocal, v);
    });
  }

  /**
   * Registra leitura em tempo real (max incremental).
   * @returns {{pico:number, ehNovoPico:boolean}}
   */
  function registrar(ctrl, param, valorKw, dataLocal) {
    const v = parseFloat(valorKw);
    const atual = _ler(ctrl, param, dataLocal) ?? 0;
    if (Number.isFinite(v) && v > atual) {
      _gravar(ctrl, param, dataLocal, v);
      return { pico: v, ehNovoPico: true };
    }
    return { pico: atual, ehNovoPico: false };
  }

  function obter(ctrl, param, dataLocal) {
    return _ler(ctrl, param, dataLocal) ?? 0;
  }

  return { seed, registrar, obter };
})();

if (typeof window !== 'undefined') window.CIP_PicoDia = CIP_PicoDia;
