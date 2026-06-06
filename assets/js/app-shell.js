// ============================================================
// Arquivo   : assets/js/app-shell.js
// Projeto   : CIP — Controlador de Injeção de Potência Elétrica
// Objetivo  : Controle dos drawers (hambúrguer e engrenagem),
//             overlay e comportamento do header fixo.
//
// Dependências de hardware:
//   - Dispositivos móveis, tablets e desktops
//
// Dependências de software/arquivos:
//   - includes/app_header.php
//   - assets/css/header.css
//
// Histórico de implementações:
//   2026-03-27  v1.0.0  Criação inicial
//   2026-04-15  v2.0.0  Reescrita completa — controle robusto
//                        dos dois drawers com overlay,
//                        acessibilidade aria-hidden e
//                        fechamento por ESC/overlay/botão
//   2026-04-15  v2.1.0  [FIX] aria-hidden bloqueado quando
//                        descendente retinha foco — substituído
//                        por inert attribute + blur() antes
//                        de setar aria-hidden
// ============================================================

(function () {
  'use strict';

  // ── Elementos ─────────────────────────────────────────────
  const btnMenu        = document.getElementById('btnMenu');
  const btnConfig      = document.getElementById('btnConfig');
  const drawerMenu     = document.getElementById('drawerMenu');
  const drawerConfig   = document.getElementById('drawerConfig');
  const overlay        = document.getElementById('drawerOverlay');
  const btnMenuClose   = document.getElementById('btnMenuClose');
  const btnConfigClose = document.getElementById('btnConfigClose');

  if (!btnMenu || !btnConfig || !drawerMenu || !drawerConfig || !overlay) {
    console.warn('[CIP] app-shell.js: elementos do header não encontrados.');
    return;
  }

  // ── Estado ────────────────────────────────────────────────
  let drawerAberto = null; // 'menu' | 'config' | null

  // ── Helpers ───────────────────────────────────────────────

  /**
   * Remove o foco de qualquer elemento dentro do drawer
   * ANTES de setar aria-hidden — evita o warning do WAI-ARIA.
   */
  function blurDentro(drawer) {
    const focused = drawer.querySelector(':focus');
    if (focused) focused.blur();
  }

  /**
   * Desativa drawer acessivelmente:
   * usa `inert` (moderno) com fallback para aria-hidden.
   */
  function desativarDrawer(drawer) {
    blurDentro(drawer);
    drawer.classList.remove('open');
    if ('inert' in HTMLElement.prototype) {
      drawer.inert = true;
    } else {
      drawer.setAttribute('aria-hidden', 'true');
    }
  }

  /**
   * Ativa drawer acessivelmente.
   */
  function ativarDrawer(drawer) {
    drawer.classList.add('open');
    if ('inert' in HTMLElement.prototype) {
      drawer.inert = false;
    } else {
      drawer.setAttribute('aria-hidden', 'false');
    }
  }

  // ── Funções principais ────────────────────────────────────
  function fecharTodos() {
    desativarDrawer(drawerMenu);
    desativarDrawer(drawerConfig);
    overlay.classList.remove('active');
    document.body.classList.remove('drawer-open');
    drawerAberto = null;
  }

  function abrirDrawer(qual) {
    fecharTodos();
    drawerAberto = qual;

    if (qual === 'menu') {
      ativarDrawer(drawerMenu);
    } else {
      ativarDrawer(drawerConfig);
    }

    overlay.classList.add('active');
    document.body.classList.add('drawer-open');
  }

  function toggleDrawer(qual) {
    drawerAberto === qual ? fecharTodos() : abrirDrawer(qual);
  }

  // ── Estado inicial — garantir inert nos drawers fechados ──
  desativarDrawer(drawerMenu);
  desativarDrawer(drawerConfig);

  // ── Eventos ───────────────────────────────────────────────
  btnMenu.addEventListener('click',        () => toggleDrawer('menu'));
  btnConfig.addEventListener('click',      () => toggleDrawer('config'));
  overlay.addEventListener('click',        fecharTodos);
  btnMenuClose.addEventListener('click',   fecharTodos);
  btnConfigClose.addEventListener('click', fecharTodos);

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && drawerAberto) fecharTodos();
  });

})();
