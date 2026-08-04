import re
import sys

def main():
    try:
        with open("dashboard.php", "r", encoding="utf-8") as f:
            content = f.read()

        # 1) Header Bump
        content = content.replace(" * @versao        1.14.2\n * @modificado_em 2026-07-12", " * @versao        1.15.0\n * @modificado_em 2026-07-15")
        history = """ *   2026-07-15  v1.15.0  Infografico -> background fixo; cards 2x2 (resumo_dia.php);
 *                        fix semaforo (fluxo via resumo_dia, idade via dados.php) [CIP-DEC-20260715-001]
 * ============================================================"""
        content = content.replace(" * ============================================================", history)

        # 2) CSS Bump
        css_addition = """
    /* ===== Infográfico como background decorativo ===== */
    #infografico-host {
      position: absolute;
      inset: 0;
      z-index: -1;
      opacity: 0.15;
      pointer-events: none;
      overflow: hidden;
    }
    #infografico-host svg { width: 100%; height: 100%; }

    .fluxo-wrapper { position: relative; min-height: 320px; padding: 16px; }

    /* ===== Cards 2x2 ===== */
    #cards-host {
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: 14px;
      position: relative;
      z-index: 1;
    }
    @media (max-width: 480px) {
      #cards-host { grid-template-columns: 1fr; }
    }

    .card-energia {
      background: var(--card-bg, rgba(20,24,33,0.72));
      backdrop-filter: blur(4px);
      border: 1px solid var(--card-border, rgba(255,255,255,0.08));
      border-radius: 14px;
      padding: 14px 16px;
    }
    .card-energia .ce-topo {
      display: flex; align-items: center; gap: 8px;
      font-size: .82rem; color: var(--muted, #9aa4b2);
    }
    .card-energia .ce-valor {
      font-size: 1.5rem; font-weight: 700; margin: 4px 0 10px;
      color: var(--fg, #eef2f7);
    }
    .card-energia .ce-valor small { font-size: .8rem; font-weight: 500; color: var(--muted,#9aa4b2); }

    .ce-bar-track {
      height: 8px; border-radius: 999px;
      background: rgba(255,255,255,0.08); overflow: hidden;
    }
    .ce-bar-fill {
      height: 100%; border-radius: 999px;
      width: 0%; transition: width .6s ease;
    }
    .ce-geracao   .ce-bar-fill { background: linear-gradient(90deg,#ffb020,#ffd466); }
    .ce-consumo   .ce-bar-fill { background: linear-gradient(90deg,#4a90e2,#6fb0ff); }
    .ce-injetada  .ce-bar-fill { background: linear-gradient(90deg,#2ecc71,#6ff0a5); }
    .ce-importada .ce-bar-fill { background: linear-gradient(90deg,#e05555,#ff8585); }
"""
        content = content.replace("    .infografico-wrap {", css_addition + "\n    .infografico-wrap {")

        # 3) Footer Bump
        content = content.replace("v1.14.2\n</footer>", "v1.15.0\n</footer>")

        # 4) HTML Restructure: Wrap SVG and add Cards
        # We need to replace the old cards-host which is further down, and move it up, 
        # or just put everything in the right place.
        # Right now:
        # <div id="infografico-host" class="infografico-wrap">
        #    ... SVG ...
        # </div>
        # <!-- KPIs status ... -->
        # <div class="kpi-grid" ...> ... </div>
        # <div id="cards-host"></div>
        # </div><!-- /wrap -->
        
        cards_html = """  <div id="cards-host">
    <div class="card-energia ce-geracao">
      <div class="ce-topo">☀️ <span>Geração</span></div>
      <div class="ce-valor" id="ce-val-geracao">— <small>kWh</small></div>
      <div class="ce-bar-track"><div class="ce-bar-fill" id="ce-bar-geracao"></div></div>
    </div>
    <div class="card-energia ce-consumo">
      <div class="ce-topo">🏠 <span>Consumo</span></div>
      <div class="ce-valor" id="ce-val-consumo">— <small>kWh</small></div>
      <div class="ce-bar-track"><div class="ce-bar-fill" id="ce-bar-consumo"></div></div>
    </div>
    <div class="card-energia ce-injetada">
      <div class="ce-topo">⬆️ <span>Injetada</span></div>
      <div class="ce-valor" id="ce-val-injetada">— <small>kWh</small></div>
      <div class="ce-bar-track"><div class="ce-bar-fill" id="ce-bar-injetada"></div></div>
    </div>
    <div class="card-energia ce-importada">
      <div class="ce-topo">⬇️ <span>Importada</span></div>
      <div class="ce-valor" id="ce-val-importada">— <small>kWh</small></div>
      <div class="ce-bar-track"><div class="ce-bar-fill" id="ce-bar-importada"></div></div>
    </div>
  </div>
</div><!-- /fluxo-wrapper -->
"""

        content = content.replace('<div id="infografico-host" class="infografico-wrap">', '<div class="fluxo-wrapper">\n  <div id="infografico-host" class="infografico-wrap">')
        # Now close the fluxo-wrapper after the infografico-host closes.
        # Find where infografico-host ends. It ends just before the KPI grid.
        kpi_comment = "<!-- KPIs status do controlador -->"
        if kpi_comment not in content:
            print("Error: Could not find KPI comment.")
            sys.exit(1)
        
        # Replace the `</div>` that precedes the KPI comment with the new `cards_html`.
        # Actually, let's just do a string replace on `  </div>\n\n\n\n  <!-- KPIs status do controlador -->`
        content = re.sub(r'  <\/div>\s*<!-- KPIs status do controlador -->', cards_html + '\n\n  <!-- KPIs status do controlador -->', content)

        # Now remove the old empty `<div id="cards-host"></div>` and its comment at the bottom.
        content = re.sub(r'  <!-- ════════════════════════════════════════════════════════\s*AREA RESERVADA — 4 CARDS INSTANTANEOS \(B2\.3\)[\s\S]*?<div id="cards-host"><\/div>', '', content)

        # Remove SVG text nodes containing dynamic values
        # They all have id="valGeracaoDia", class="valor-energia", etc.
        patterns = [
            r'<text[^>]*>\s*<tspan id="valGeracaoDia"[^>]*>.*?<\/tspan>\s*<\/text>',
            r'<text[^>]*>\s*<tspan id="valGeracao"[^>]*>.*?<\/tspan>\s*<\/text>',
            r'<text class="no-sub" [^>]*id="valGeracaoOrigem">.*?<\/text>',
            r'<text class="no-mini-lbl amarelo"[^>]*>.*?<\/text>',
            r'<text[^>]*>\s*<tspan id="valImportadaDia"[^>]*>.*?<\/tspan>\s*<\/text>',
            r'<text[^>]*>\s*<tspan id="valImportada"[^>]*>.*?<\/tspan>\s*<\/text>',
            r'<text class="no-mini-lbl azul"[^>]*>.*?<\/text>',
            r'<text[^>]*>\s*<tspan id="valExportadaDia"[^>]*>.*?<\/tspan>\s*<\/text>',
            r'<text[^>]*>\s*<tspan id="valExportada"[^>]*>.*?<\/tspan>\s*<\/text>',
            r'<text class="no-mini-lbl"[^>]*>CONSUMO.*?<\/text>',
            r'<text[^>]*>\s*<tspan id="valConsumoDia"[^>]*>.*?<\/tspan>\s*<\/text>',
            r'<text[^>]*>\s*<tspan id="valConsumo"[^>]*>.*?<\/tspan>\s*<\/text>',
            r'<text class="no-mini-lbl"[^>]*>SALDO.*?<\/text>',
            r'<text class="no-valor sm" id="valSaldo"[^>]*>.*?<\/text>',
            r'<text[^>]*>\s*<tspan id="valBateriaDia"[^>]*>.*?<\/tspan>\s*<\/text>',
            r'<text[^>]*>\s*<tspan id="valBateria"[^>]*>.*?<\/tspan>\s*<\/text>',
            r'<text class="no-sub"[^>]*>battery-ready.*?<\/text>',
            r'<text class="no-sub"[^>]*>\(em breve\).*?<\/text>'
        ]
        for p in patterns:
            content = re.sub(p, '', content)

        # 5) JS: Semaforo Logic Replacement
        old_semaforo = r'window\.aplicarSemaforo = function\(controlador, r, im, g, inv\) \{[\s\S]*?\n\s*\};'
        new_semaforo = """const OFFLINE_S = 900;   // 15 min sem ping = vermelho
const STALE_S   = 300;   // 5 min = amarelo

let _ultimoResumoDia = null;
function aplicarSemaforoFluxo(resumo) { _ultimoResumoDia = resumo; renderSemaforo(); }

function renderSemaforo() {
  const pingIso = window._dadosControlador?.ultimo_ping;
  const idade = pingIso ? (Date.now() - new Date(pingIso).getTime()) / 1000 : Infinity;

  const r = _ultimoResumoDia || {};
  const picG = r.potencia_pico_dia_kw?.geracao   ?? 0;
  const picE = r.potencia_pico_dia_kw?.exportada ?? 0;
  const geraKwh = r.energia_kwh?.geracao ?? 0;
  const invConn = r.inversor?.conectado === true;
  const houveFluxo = picG > 0.03 || picE > 0.03 || geraKwh > 0.01 || invConn;

  let icone, tituloTxt, classe;
  if (idade >= OFFLINE_S)      { icone='🔴'; tituloTxt='Sem comunicação'; classe='sem-off'; }
  else if (idade >= STALE_S)   { icone='🟡'; tituloTxt='Dados atrasados'; classe='sem-stale'; }
  else if (!houveFluxo)        { icone='🌙'; tituloTxt='Usina em repouso'; classe='sem-idle'; }
  else                         { icone='🟢'; tituloTxt='Usina ativa';      classe='sem-on'; }

  const luz = document.getElementById('semLuz');
  const titulo = document.getElementById('semTitulo');
  const sub = document.getElementById('semSub');

  if (luz) luz.className = `semaforo-luz ${classe}`;
  if (titulo) titulo.textContent = `${icone} ${tituloTxt}`;
  if (sub) {
    if (idade >= OFFLINE_S) sub.textContent = `Offline há mais de ${Math.floor(idade/60)} min`;
    else if (idade >= STALE_S) sub.textContent = `Atraso de ${Math.floor(idade/60)} min`;
    else if (!houveFluxo) sub.textContent = 'Sem fluxo ou geração';
    else sub.textContent = 'Operando normalmente';
  }
}"""
        content = re.sub(old_semaforo, new_semaforo, content)

        # 6) JS: atualizarCardsEnergia
        cards_js = """
async function atualizarCardsEnergia(controladorId) {
  try {
    const r = await fetch(`/api/energia/resumo_dia.php?controlador_id=${controladorId}`, { credentials: 'same-origin' });
    const j = await r.json();
    if (!j.sucesso) return;

    const e = j.energia_kwh || {};
    const metricas = {
      geracao:   e.geracao   ?? 0,
      consumo:   e.consumo   ?? 0,
      injetada:  e.exportada ?? 0,
      importada: e.importada ?? 0
    };

    const maxVal = Math.max(...Object.values(metricas), 0.001);

    for (const [k, v] of Object.entries(metricas)) {
      const val = Number(v) || 0;
      const elVal = document.getElementById(`ce-val-${k}`);
      const elBar = document.getElementById(`ce-bar-${k}`);
      if (elVal) elVal.innerHTML = `${val.toFixed(1)} <small>kWh</small>`;
      if (elBar) elBar.style.width = `${Math.min(100, (val / maxVal) * 100).toFixed(1)}%`;
    }

    aplicarSemaforoFluxo(j);
  } catch (err) {
    console.error('[cards-energia]', err);
  }
}

/**"""
        content = content.replace("/**\n * carregarKpis()", cards_js + " * carregarKpis()")

        # 7) JS: carregarKpis updates
        # Remove old aplicarSemaforo call, save window._dadosControlador, call renderSemaforo, call atualizarCardsEnergia
        old_kpi_js = """      if (typeof window.aplicarSemaforo === 'function') {
        window.aplicarSemaforo(controlador, atual?.rede, atual?.imovel, atual?.geracao, atual?.inversor);
      }"""
        new_kpi_js = """      window._dadosControlador = controlador;
      if (typeof renderSemaforo === 'function') renderSemaforo();
      if (typeof atualizarCardsEnergia === 'function') atualizarCardsEnergia(CTRL_ID);"""
        content = content.replace(old_kpi_js, new_kpi_js)

        with open("dashboard.php", "w", encoding="utf-8") as f:
            f.write(content)
        print("Success! Dashboard modified.")
    except Exception as e:
        print(f"Error: {e}")

if __name__ == "__main__":
    main()
