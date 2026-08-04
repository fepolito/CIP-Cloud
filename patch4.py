import sys

def patch_dashboard():
    with open('C:/laragon/www/monitor.aeonium.com.br/dashboard.php', 'r', encoding='utf-8') as f:
        content = f.read()

    # 1. Update version
    content = content.replace('@versao        1.14.0', '@versao        1.15.0')
    content = content.replace('@modificado_em 2026-07-09', '@modificado_em 2026-07-12')
    content = content.replace('v1.14.0', 'v1.15.0')

    # 2. Force UTF-8 HTTP header
    if "header('Content-Type: text/html; charset=UTF-8');" not in content:
        content = content.replace(
            "require_once __DIR__ . '/app/helpers/Tenant.php';",
            "require_once __DIR__ . '/app/helpers/Tenant.php';\n\n// Força UTF-8\nheader('Content-Type: text/html; charset=UTF-8');"
        )

    # 3. Add setBadge and applySemaforo to global scope
    if 'window.aplicarSemaforo = function' not in content:
        content = content.replace(
            'async function carregarKpis() {',
            '''const SEM = { STALE_S: 1200, OFFLINE_S: 2400 };

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

  let estado = 'cinza', icone = '⚪', tituloTxt = '', subTxt = '';

  if (idade >= SEM.OFFLINE_S) {
    estado = 'vermelho'; icone = '🔴';
    tituloTxt = 'Sem comunicação';
    subTxt = Usina offline há mais de  minutos. Verifique a internet no local.;
  } else if (idade >= SEM.STALE_S) {
    estado = 'amarelo'; icone = '🟡';
    tituloTxt = 'Comunicação atrasada';
    subTxt = Último dado recebido há  min. Atraso na transmissão.;
  } else if (kwGeracao <= 0 && invStatus !== 'online') {
    estado = 'amarelo'; icone = '🟡';
    tituloTxt = 'Usina em repouso';
    subTxt = Online, mas sem geração no momento (noite ou baixa luz).;
  } else {
    estado = 'verde'; icone = '🟢';
    tituloTxt = 'Tudo funcionando';
    subTxt = Usina online e ativa – dado de  min atrás.;
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

async function carregarKpis() {'''
        )

    # 4. Remove local aplicarSemaforo
    import re
    content = re.sub(r'function aplicarSemaforo\(controlador, r, im, g, inv\) \{.*?\n    \}\n', '', content, flags=re.DOTALL)

    # 5. Fix aplicarDados inside infografico
    old_aplicar = r'''      const f = p\.fluxo  \|\| \{\};
      const q = p\.qualidade \|\| \{\};
      const r = p\.rede   \|\| \{\};
      const i = p\.inversor \|\| \{\};
      const m = p\.meta   \|\| \{\};
      const energiaDia = p\.energia_dia \|\| \{\};

      // ⚡ Valores numericos e Hibridos ⚡
      renderCaixaHibrida\(refs\.geracaoDia, refs\.geracao, energiaDia\.geracao_kwh, f\.geracao_w, \{ modo: 'nd_if_null', aviso: energiaDia\.aviso \}\);
      renderCaixaHibrida\(refs\.importadaDia, refs\.importada, energiaDia\.importada_kwh, f\.importada_w, \{ modo: 'neutro', aviso: energiaDia\.aviso \}\);
      renderCaixaHibrida\(refs\.exportadaDia, refs\.exportada, energiaDia\.exportada_kwh, f\.exportada_w, \{ modo: 'injecao', aviso: energiaDia\.aviso \}\);
      renderCaixaHibrida\(refs\.consumoDia, refs\.consumo, energiaDia\.consumo_total_kwh, f\.consumo_total_w, \{ modo: 'consumo', aviso: energiaDia\.aviso \}\);

      // Saldo: exportada - importada \(positivo = exportando para rede\)
      const saldoWatts = \(Number\(f\.exportada_w\) \|\| 0\) - \(Number\(f\.importada_w\) \|\| 0\);
      if \(refs\.saldo\) refs\.saldo\.innerHTML = fmtKwComSinal\(saldoWatts, 'saldo'\);

      // Origem da geracao \(estimado/inversor/api/indisponivel\)
      if \(refs\.geracaoOrigem\) \{
        const origem = q\.origem_geracao \|\| 'aguardando';
        refs\.geracaoOrigem\.textContent = origem;
      \}

      // Bateria sempre standby nesta versao
      if \(refs\.bateriaDia\) refs\.bateriaDia\.textContent = '— kWh';
      if \(refs\.bateria\) refs\.bateria\.textContent = 'STANDBY';'''

    new_aplicar = '''      const im = p.imovel || {};
      const st = p.status || {};
      const g = p.geracao || {};
      const r_novo = p.rede   || {};

      if (st.controlador && typeof setBadge === 'function') {
        setBadge('statusPill', 'statusTxt', st.controlador.online);
      }

      // Valores numericos e Hibridos (Infográfico v2)
      renderCaixaHibrida(refs.geracaoDia, refs.geracao, g.kwh, g.w, { modo: 'nd_if_null', aviso: null });
      renderCaixaHibrida(refs.importadaDia, refs.importada, r_novo.importada?.kwh, r_novo.importada?.w, { modo: 'neutro', aviso: null });
      renderCaixaHibrida(refs.exportadaDia, refs.exportada, r_novo.exportada?.kwh, r_novo.exportada?.w, { modo: 'injecao', aviso: null });
      renderCaixaHibrida(refs.consumoDia, refs.consumo, im.consumo_kwh, im.consumo_w, { modo: 'consumo', aviso: null });

      const exportadaW = Number(r_novo.exportada?.w) || 0;
      const importadaW = Number(r_novo.importada?.w) || 0;
      const saldoWatts = exportadaW - importadaW;
      
      if (refs.saldo) refs.saldo.innerHTML = fmtKwComSinal(saldoWatts, 'saldo');

      if (refs.geracaoOrigem) {
        refs.geracaoOrigem.textContent = g.origem || 'aguardando';
      }

      if (refs.bateriaDia) refs.bateriaDia.textContent = '— kWh';
      if (refs.bateria) refs.bateria.textContent = 'STANDBY';'''
    
    content = re.sub(old_aplicar, new_aplicar, content)

    # 6. carregarKpis old status pill logic
    old_kpi_status = r'''      const diff     = controlador\.ultimo_ping
        \? \(Date\.now\(\) - new Date\(controlador\.ultimo_ping\)\.getTime\(\)\) / 1000 : 999;
      const isOnline = diff <= 30;
      document\.getElementById\('statusPill'\)\.className  = kpi-status-pill \$\{isOnline \? 'online' : 'offline'\};
      document\.getElementById\('statusTxt'\)\.textContent = isOnline \? 'ONLINE' : 'OFFLINE';'''
    content = re.sub(old_kpi_status, '// O status agora é resolvido via infografico payload.', content)

    # 7. Add .catch to verificarToken
    content = content.replace(
        '''verificarToken().then(ok => {
      if (ok) {
        carregarKpis();
        startKpiTimer();
      }
    });''',
        '''verificarToken()
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
      });'''
    )

    # 8. Remove gauge HTML
    content = re.sub(r'  <!-- \n       GAUGE 3 ANÉIS INSTANTÂNEO.*?</div>\n  </div>', '', content, flags=re.DOTALL)
    
    # 9. Remove gauge scripts
    content = re.sub(r'  <!-- Dependências do Gauge -->\n  <script src="/assets/js/cip-pico-dia.js"></script>\n  <script src="/assets/js/cip-gauge.js"></script>', '', content)

    # 10. Remove initGauge
    content = re.sub(r'  /\* ⚡\n     GAUGE INSTANTÂNEO \(30s\)\n  ⚡ \*/\n  \(function initGauge\(\) \{.*?  \}\)\(\);\n', '', content, flags=re.DOTALL)

    # 11. Fix aplicarSemaforo call parameters
    content = content.replace('window.aplicarSemaforo(controlador, {}, {}, {}, {});', 'window.aplicarSemaforo(controlador, null, null, null, null);')

    with open('C:/laragon/www/monitor.aeonium.com.br/dashboard.php', 'w', encoding='utf-8') as f:
        f.write(content)

patch_dashboard()