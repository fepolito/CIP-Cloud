
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
      if (refs.bateriaDia) refs.bateriaDia.textContent = '— kWh';
      if (refs.bateria) refs.bateria.textContent = 'STANDBY';

      // ── Fluxos (velocidade + on/off independentes) ──
      aplicarFluxo(refs.fluxoGeracao,   f.geracao_w);
      aplicarFluxo(refs.fluxoImportada, f.importada_w);
      aplicarFluxo(refs.fluxoExportada, f.exportada_w);

      if (f.geracao_w === null || f.geracao_w === undefined) {
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
  
