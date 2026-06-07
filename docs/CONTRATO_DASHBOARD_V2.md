📜 CONTRATO — Dashboard v2.0.0
📁 Salvar como: docs/CONTRATO_DASHBOARD_V2.md 🔖 Selo: 🟡 DEV+PROD-SOFT (sem migration de schema) 🎯 Objetivo: Substituir dashboard.php v1.3.0 por experiência visual/executiva com infográfico animado + cards de potência e energia

🎯 1. Escopo e princípios



Princípio	Decisão
🎬 Modelo visual	Infográfico animado de fluxo de energia em background fixo + cards sobrepostos
🔌 Escopo de dados	UM controlador por vez (com seletor — herda lógica do energia.php)
🎨 Peso visual	Minimalista primeiro (SVG inline + animação CSS), evoluir depois
🖼️ Background	position: fixed; z-index: -1 atrás dos cards (parallax-like)
📊 Endpoints	Novos endpoints dedicados — não reusar api/energia/*
📁 Arquivo	Rewrite por cima de dashboard.php + backup .bkp_2026-06-06
🌗 Tema	CSS variáveis com variantes [data-tema="claro"] e [data-tema="escuro"]
♻️ Refresh	Respeita TELEMETRIA_CICLO_SEGUNDOS (DEV=900s / PROD=60s)
🔒 Auth/Tenant	requireAuth() + Tenant::listarControladores() v1.2.1
🎨 2. Layout final (ASCII consolidado)


┌─────────────────────────────────────────────────────────────────┐
│ [HEADER GLOBAL — app_header.php — com toggle tema funcional]    │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  🎬 BACKGROUND FIXO (z-index: -1, opacity: 0.18~0.25)           │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │   ☀️  ───→  🏠  ───→  🔌                                │   │
│  │  Painéis    Carga    Rede                               │   │
│  │  (setas animadas — velocidade ∝ potência)               │   │
│  └─────────────────────────────────────────────────────────┘   │
│                                                                 │
│  ┌─────────── TOOLBAR ───────────────────────────────────┐     │
│  │  [Controlador: ▼ Polito ───────] [⏱ atualizado 12:24]│     │
│  └───────────────────────────────────────────────────────┘     │
│                                                                 │
│  ┌─────────── CARD "POTÊNCIA AGORA" ────────────────────┐      │
│  │  ⭕ Gauges concêntricos (radialBar multiple)         │      │
│  │  ⚪ Geração   X.XX kW                                │      │
│  │  ⚪ Consumo   X.XX kW                                │      │
│  │  ⚪ Injetada  X.XX kW                                │      │
│  │  ⚪ Total     X.XX kW                                │      │
│  └──────────────────────────────────────────────────────┘      │
│                                                                 │
│  ┌────── CARD "ENERGIA HOJE" ──────┐ ┌───── "MÊS" ─────┐       │
│  │  Geração   ████████  18.4 kWh   │ │ Geração ███ 412 │       │
│  │  Consumo   ██████    14.2 kWh   │ │ Consumo ██  328 │       │
│  │  Injetada  ███        5.1 kWh   │ │ Injetad ██  118 │       │
│  │  Importada █          0.9 kWh   │ │ Import. █    34 │       │
│  └─────────────────────────────────┘ └─────────────────┘       │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
🧩 3. Backend — Endpoints novos
3.1 api/dashboard/snapshot.php v1.0.0 (já existe ✅)
Reaproveitar. Não modificar.
Retorna potências instantâneas: geracao_w, consumo_w, injetada_w, total_w, timestamp, fonte_dado.
3.2 api/dashboard/resumo_dia.php (🆕 a criar)
Selo: 🟢 DEV-ONLY (endpoint novo)

json


// Request: ?controlador_id=3
// Response:
{
  "status": "ok",
  "dados": {
    "controlador_id": 3,
    "data_referencia": "2026-06-06",
    "timezone": "America/Sao_Paulo",
    "geracao_kwh":  18.4,
    "consumo_kwh":  14.2,
    "injetada_kwh":  5.1,
    "importada_kwh": 0.9,
    "atualizado_em": "2026-06-06T12:24:50-03:00"
  }
}
3.3 api/dashboard/resumo_mes.php (🆕 a criar)
Selo: 🟢 DEV-ONLY (endpoint novo)

json


// Request: ?controlador_id=3&mes=2026-06  (mes opcional, default=atual)
// Response:
{
  "status": "ok",
  "dados": {
    "controlador_id": 3,
    "mes_referencia": "2026-06",
    "timezone": "America/Sao_Paulo",
    "geracao_kwh":   412.7,
    "consumo_kwh":   328.4,
    "injetada_kwh":  118.2,
    "importada_kwh":  34.1,
    "dias_com_dado": 6,
    "atualizado_em": "2026-06-06T12:24:50-03:00"
  }
}
Regras comuns dos dois endpoints:

declare(strict_types=1)
requireAuth() no topo
Validar controlador_id via Tenant::listarControladores() (bloqueia se não pertence ao escopo do usuário)
Prepared statements PDO
CONVERT_TZ usando controladores.timezone
Schema v2 (colunas dedicadas da energia)
Resposta padrão {status, dados, mensagem}
Content-Type: application/json; charset=utf-8
🎨 4. Frontend — Componentes
4.1 Infográfico SVG animado
html


<div class="bg-flow">
  <svg viewBox="0 0 1200 400">
    <!-- ☀️ Painéis (esquerda) -->
    <!-- 🏠 Carga (centro) -->
    <!-- 🔌 Rede (direita) -->
    <!-- Setas: <path> com stroke-dasharray + animação CSS -->
  </svg>
</div>
Regras:

position: fixed; inset: 0; z-index: -1; opacity: 0.18 (escuro) / 0.12 (claro)
Classe no <body>: .fluxo-exportando (setas painel→rede) ou .fluxo-importando (rede→casa)
Velocidade da animação proporcional a |injetada_w| (mais kW = animation-duration menor)
Sem libs externas — SVG + CSS puro
4.2 Card "Potência Agora"
ApexCharts radialBar com 4 séries concêntricas (geração/consumo/injetada/total)
Valores numéricos ao lado em coluna
Cor por série definida em :root (compatível tema claro/escuro)
4.3 Cards "Energia Hoje" e "Energia Mês"
ApexCharts bar horizontal — 4 categorias (Geração / Consumo / Injetada / Importada)
Labels no eixo Y, valor à direita da barra (dataLabels: { enabled: true })
🌗 5. Tema claro/escuro — fix do bug
css


:root,
[data-tema="escuro"] {
  --bg: #070b14;
  --card: #0d1526;
  --border: #1a2d4a;
  --txt: #e0eaf8;
  /* ... */
}

[data-tema="claro"] {
  --bg: #f5f7fb;
  --card: #ffffff;
  --border: #d8e0ec;
  --txt: #1a2d4a;
  /* ... */
}
E nos ApexCharts, ao invés de theme: { mode: 'dark' } fixo, ler:

js


const tema = document.documentElement.dataset.tema || 'escuro';
theme: { mode: tema === 'claro' ? 'light' : 'dark' }
listener para re-renderizar gráficos no tema:mudou (event que app-shell.js provavelmente já dispara).
⏱️ 6. Auto-refresh
js


// Lê do PHP via data-attribute no <body>
const CICLO_S = parseInt(document.body.dataset.cicloSeg, 10) || 60;
const INTERVAL = (CICLO_S / 2) * 1000;  // metade do ciclo do firmware
No PHP:

php


$cicloSeg = (int)($_ENV['TELEMETRIA_CICLO_SEGUNDOS'] ?? 60);
?>
<body data-ciclo-seg="<?= $cicloSeg ?>" data-tema="escuro">
📁 7. Arquivos impactados



Arquivo	Ação	Selo
public_html/dashboard.php	🔧 Rewrite total (backup .bkp prévio)	🟡 SOFT
api/dashboard/snapshot.php	✅ Manter (já existe)	—
api/dashboard/resumo_dia.php	🆕 Criar	🟢 DEV-ONLY
api/dashboard/resumo_mes.php	🆕 Criar	🟢 DEV-ONLY
api/dashboard/dados.php	⚰️ Deprecar (mover para _legado/)	🟡 SOFT
app/helpers/Tenant.php	✅ Sem mudança (v1.2.1 OK)	—
🚦 8. Plano de entrega (subfases F1.A → F1.D)



Fase	Entregável	Tempo	Selo
F1.A	resumo_dia.php + resumo_mes.php + testes curl	~45min	🟢
F1.B	Rewrite dashboard.php — estrutura HTML + CSS (tema funcional)	~1h	🟡
F1.C	SVG do infográfico + animações CSS	~45min	🟢
F1.D	JS de carga (snapshot + resumos) + gráficos + auto-refresh	~1h	🟢
Total estimado: 3h-3h30 distribuídas em 4 prompts cirúrgicos para o ATGY.

✅ 9. Critérios de aceite (checklist final)
 Dashboard carrega após login (é landing page)
 Cabeçalho global presente e com toggle tema funcional
 Seletor de controlador respeita Tenant (master vê todos)
 Infográfico anima em background, setas mudam com fluxo real
 Card "Agora" mostra 4 gauges + valores numéricos
 Cards "Hoje" e "Mês" mostram barras horizontais com 4 categorias
 Auto-refresh respeita TELEMETRIA_CICLO_SEGUNDOS
 Tema claro/escuro alterna sem reload e re-renderiza gráficos
 Mobile (≤480px): infográfico simplifica, cards empilham
 Sem erros no console
 Endpoint dados.php legado movido para _legado/
❓ Antes de eu pedir os prompts pro ATGY
Preciso confirmar 3 detalhes finos:

🔧 Header: o energia.php tem o cabeçalho funcionando com tema. Quer que eu peça pro ATGY copiar o head do energia.php (incluindo a estrutura de CSS variables que dá suporte ao tema) como referência, ou prefere mandar você anexar o energia.php na próxima mensagem pra eu inspecionar antes?
📊 Dado de "Consumo": o snapshot atual retorna geracao_w e injetada_w (sei). E consumo? Vem como consumo_w = geracao_w - injetada_w (calculado no backend) ou existe sensor dedicado? Se você não souber de cabeça, eu desenho o resumo_dia.php com a fórmula e a gente ajusta depois.
🎬 Setas do infográfico: 3 ícones (Painéis / Casa / Rede) ou prefere 4 incluindo "Bateria"? (Pelo escopo do CIP, hoje não tem bateria — confirmo que ficamos com 3?)
▶️ Próxima ação
Você responde os 3 pontos acima e o OK no contrato, eu transformo isso em docs/CONTRATO_DASHBOARD_V2.md pronto pra commit + o primeiro prompt cirúrgico (F1.A — endpoints) pro ATGY. 🚀