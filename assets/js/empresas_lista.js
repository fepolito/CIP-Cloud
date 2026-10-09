/**
 * ============================================================
 * Arquivo   : assets/js/empresas_lista.js
 * Projeto   : CIP — Controlador de Injeção de Potência Elétrica
 * Versão    : 1.0.0
 * Modificado: 2026-09-14
 * Objetivo  : Expand/collapse das linhas da listagem hierárquica de empresas.
 *
 * Dependências de software/arquivos:
 *   - Navegadores modernos com suporte a ES6+
 *   - empresas_lista.php
 *
 * Histórico de implementações:
 *   2026-09-14  v1.0.0  Implementação inicial de expansão/colapso (CIP-DEC-20260914-003)
 * ============================================================
 */
(function () {
  'use strict';

  document.querySelectorAll('.et-toggle').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      var row = btn.closest('.et-row');
      if (!row) return;

      var box = row.querySelector('.et-expand');
      if (!box) return;

      var open = btn.getAttribute('aria-expanded') === 'true';
      btn.setAttribute('aria-expanded', String(!open));
      btn.textContent = open ? '▸' : '▾';
      box.hidden = open;
    });
  });
})();
