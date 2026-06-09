## 📌 STATUS CLOUD — 2026-06-06 23:55 (encerramento F1.A)

### ✅ Concluído nesta sessão
- 🐛 Diagnóstico do bug "Energia hoje = 0 kWh" (escopo firmware repassado)
- 📋 docs/CONTRATO_DASHBOARD_V2.md (formalização do schema v2)
- 📋 docs/correcao_CIP_Firmware.md (handoff para CIP Firmware Copilot)
- 🧹 .gitattributes com eol=lf (renormalização completa)
- 🧹 Remoção de public_html/_tree.php
- 🔧 api/energia/dia.php v1.1.x — consumo_total calculado em PHP
- 🥊 Eliminação total de em-dashes/acentos/espaços em docs/
- 📜 docs/CONVENCOES.md (regra ASCII formalizada)

### 🧠 Decisões técnicas
- Cloud calcula consumo_total em PHP (firmware envia apenas raws)
- Nomes de arquivos: 100% ASCII (a-z, A-Z, 0-9, _, -, .)
- Status snapshots: STATUS_CLOUD_YYYY-MM-DD_HH-MM-SS.md
- EOL: LF universal via .gitattributes
- Rename detection do Git preservou histórico integralmente

### 📂 Arquivos finais em docs/ (14)
✅ Todos ASCII, todos .md (exceto .htaccess, .sql)

### 🔗 Impacto no firmware
- docs/correcao_CIP_Firmware.md pendente de processamento
  pelo CIP Firmware Copilot na próxima sessão dele
- Issue: energia_total_kwh zerada em reboot do ESP32-S3

### ▶️ Próxima ação (F1.B)
- Frontend Dashboard v2 — integração com api/energia/dia.php v1.1.x
- ApexCharts: ajustar consumo_total renderizado pelo backend

### ⚠️ Pontos de atenção
- CONVENCOES.md aguardando revisão + possível pre-commit hook
- Próximo STATUS_CLOUD usa padrão STATUS_CLOUD_2026-06-XX_HH-MM-SS.md


📌 STATUS CLOUD — 2026-06-06 23:58 (planejamento F1.B)
🎯 Objetivo desta sessão de planejamento
Consolidar a visão arquitetural e funcional do Dashboard v2 (public_html/energia.php) para retomar com precisão na próxima sessão (F1.B).

🖼️ VISÃO GERAL DO DASHBOARD V2
O dashboard é a interface de monitoramento principal do CIP, exibindo energia/potência em quatro escalas temporais com hierarquia de fontes de dado e tema claro/escuro.

Princípios norteadores
🥇 Cloud é espelho do firmware, não fonte da verdade operacional
🎨 Tema claro/escuro via data-tema no <html>
📊 ApexCharts 3.44.0 como única lib de gráficos
🔄 Refresh automático sem piscar tela (modo DIA)
🚦 Badges de fonte sempre visíveis (cip / solis_api / cache)
📱 Responsivo mobile-first
🧩 ESTRUTURA DE BLOCOS DA PÁGINA


┌─────────────────────────────────────────────────────────┐
│  HEADER                                                  │
│  ├─ Nome do controlador + status online/offline         │
│  ├─ Badge fonte_dado (🟢 CIP / 🟡 SOLIS / ⚪ CACHE)     │
│  ├─ Última atualização (HH:MM:SS local)                 │
│  └─ Toggle tema claro/escuro                            │
├─────────────────────────────────────────────────────────┤
│  CARDS DE RESUMO (4 cards instantâneos)                 │
│  ├─ ⚡ Potência geração agora (kW)                      │
│  ├─ 🏠 Potência consumo agora (kW)                      │
│  ├─ 🔌 Potência rede agora (kW, +injeta / -consome)     │
│  └─ 🎯 Limite ativo no momento (kW, da tabela horária)  │
├─────────────────────────────────────────────────────────┤
│  ⭐ NOVO — CARD MÉDIA HISTÓRICA (proposta Fernando)     │
│  ├─ Período: últimos 12 meses                           │
│  ├─ Filtro: excluir meses com kWh = 0                   │
│  ├─ 4 sub-métricas:                                     │
│  │   ├─ Geração média (kWh/mês)                         │
│  │   ├─ Consumo médio (kWh/mês)                         │
│  │   ├─ Injeção média (kWh/mês)                         │
│  │   └─ Consumo da rede médio (kWh/mês)                 │
│  └─ Tooltip: quantos meses entraram no cálculo          │
├─────────────────────────────────────────────────────────┤
│  SELETOR DE MODO                                        │
│  ├─ 📅 DIA   (buckets 5min, 288 pontos)                │
│  ├─ 📆 MÊS   (kWh diários, ~30 pontos)                  │
│  ├─ 🗓️ ANO   (kWh mensais, 12 pontos)                  │
│  └─ ♾️ TOTAL (kWh anuais, N pontos)                     │
├─────────────────────────────────────────────────────────┤
│  SELETOR DE DATA / PERÍODO                              │
│  └─ Datepicker contextualizado ao modo                  │
├─────────────────────────────────────────────────────────┤
│  GRÁFICO PRINCIPAL (ApexCharts)                         │
│  ├─ 4 séries: geração, consumo, injeção, rede           │
│  ├─ Tipo: area (DIA) / bar (MÊS, ANO, TOTAL)            │
│  ├─ Tooltip rico (kW + kWh acumulado)                   │
│  ├─ Sombreamento de faixas tarifárias (futuro)          │
│  └─ Linha horizontal do limite ativo (modo DIA)         │
├─────────────────────────────────────────────────────────┤
│  TOTAIS DO PERÍODO SELECIONADO                          │
│  ├─ Geração total (kWh)                                 │
│  ├─ Consumo total (kWh)                                 │
│  ├─ Injeção total (kWh)                                 │
│  └─ Consumo da rede total (kWh)                         │
└─────────────────────────────────────────────────────────┘
⭐ NOVA FEATURE — Card de Média Histórica (12 meses)
💡 Ideia do Fernando (2026-06-06) — anotada para discussão e implementação na sessão F1.B.

Conceito
Card destacado exibindo a média mensal dos últimos 12 meses dos 4 parâmetros principais, excluindo meses com leitura zerada (para não distorcer a média com falhas de coleta ou períodos antes da instalação).

Os 4 parâmetros



Métrica	Símbolo	Origem	Unidade
Geração média	☀️	Inversor (CIP/Solis)	kWh/mês
Consumo médio	🏠	Calculado (PHP)	kWh/mês
Injeção média	⬆️	Medidor EA777	kWh/mês
Consumo da rede médio	⬇️	Medidor EA777	kWh/mês
Lógica de cálculo


Para cada parâmetro:
  1. Buscar últimos 12 meses (mes_referencia)
  2. Filtrar onde kwh_total > 0
  3. Calcular: SUM(kwh_total) / COUNT(meses_validos)
  4. Retornar: { media_kwh, meses_considerados, meses_excluidos }
Decisões a discutir na retomada
❓ "12 meses" = últimos 12 calendar months ou últimos 12 meses com dado válido?
Proposta inicial: últimos 12 calendar months, mas exibir aviso se < 12 válidos
❓ Threshold de "mês zerado" — kwh = 0 exato ou kwh < 1 (margem de ruído)?
Proposta inicial: kwh_total <= 0 (estrito)
❓ Comportamento se < 3 meses válidos — exibir card ou ocultar com placeholder?
Proposta inicial: exibir com badge "⚠️ amostra reduzida (X meses)"
❓ Tooltip detalhado — mostrar lista dos meses incluídos/excluídos ao hover?
Proposta inicial: SIM, popover com tabela mês | incluído | kWh
❓ Comparação visual — mostrar mês atual vs. média (% acima/abaixo)?
Proposta inicial: SIM, indicador delta colorido (verde acima da média de geração, vermelho acima da média de consumo, etc.)
Visual proposto (rascunho ASCII)


┌──────────────────────────────────────────────────────────┐
│ 📊 MÉDIA DOS ÚLTIMOS 12 MESES                            │
│                              (11 de 12 meses considerados)│
├──────────────────────────────────────────────────────────┤
│  ☀️ Geração         🏠 Consumo                           │
│   542,3 kWh/mês      318,7 kWh/mês                       │
│   ▲ +8% vs atual     ▼ -3% vs atual                      │
│                                                           │
│  ⬆️ Injeção         ⬇️ Consumo da rede                   │
│   245,1 kWh/mês      21,5 kWh/mês                        │
│   ▲ +12% vs atual    ▬ estável                           │
└──────────────────────────────────────────────────────────┘
Implementação técnica prevista
Backend:

📡 Novo endpoint: api/energia/media_12m.php
🗄️ Query única com GROUP BY mes_referencia + HAVING SUM(kwh) > 0
⏱️ Cache de 1h (dado mensal não muda no minuto)
🌐 Timezone via controladores.timezone + CONVERT_TZ
Frontend:

🎨 Componente <div id="card-media-12m"> no topo
🔄 Carrega uma vez ao abrir página (não precisa polling)
📐 Grid 2×2 responsivo (vira 1×4 em mobile)
🎨 Cores das setas vinculadas a var(--cor-positivo) / var(--cor-negativo)
Schema sugerido de resposta:

json


{
  "status": "ok",
  "dados": {
    "periodo": { "inicio": "2025-06", "fim": "2026-05" },
    "meses_considerados": 11,
    "meses_excluidos": ["2025-08"],
    "metricas": {
      "geracao":      { "media_kwh": 542.3, "atual_mes_kwh": 587.2, "delta_pct": 8.3 },
      "consumo":      { "media_kwh": 318.7, "atual_mes_kwh": 309.1, "delta_pct": -3.0 },
      "injecao":      { "media_kwh": 245.1, "atual_mes_kwh": 274.5, "delta_pct": 12.0 },
      "consumo_rede": { "media_kwh":  21.5, "atual_mes_kwh":  21.8, "delta_pct":  1.4 }
    }
  },
  "mensagem": null
}
🔗 DEPENDÊNCIAS E IMPACTOS
Endpoints existentes (a manter)
✅ api/energia/dia.php v1.1.x — schema v2, consumo_total calculado em PHP
✅ api/energia/mes.php — kWh diários
✅ api/energia/ano.php — kWh mensais
✅ api/energia/anos.php — kWh anuais
Endpoints a criar
🆕 api/energia/media_12m.php ⭐ (card média histórica)
🆕 api/energia/instantaneo.php (cards de potência agora — talvez já exista?)
Endpoints futuros (F1.C, F2)
🔮 api/limites/atual.php (limite ativo no momento, exibido no header)
🔮 api/limites/tabela.php (CRUD da tabela 3×24)
🔮 api/solis/sync.php (fallback SolisCloud)
Tabelas envolvidas
controladores (tenant + timezone)
energia (schema v2)
tabela_limites (futuro)
🎯 ESCOPO PROPOSTO PARA F1.B (sessão de retomada)
Cenário recomendado: 🟡 Realista + Card Média 12m
Tempo estimado: 12-16h (3 sessões focadas)

Sessão 1 (~5h):

Refactor energia.php para consumir schema v2
ApexCharts dos 4 modos funcionando com dados reais
Cards instantâneos (4 cards de potência)
Badge fonte_dado no header
Sessão 2 (~5h):

⭐ Endpoint api/energia/media_12m.php
⭐ Card de Média Histórica (frontend + lógica delta)
Tema claro/escuro polido
Loading states + tratamento de erros
Sessão 3 (~4h):

Responsivo mobile
Tooltips ricos no ApexCharts
Refresh automático (modo DIA, polling 10s)
QA visual + ajustes finos
🧠 DECISÕES TÉCNICAS A VALIDAR NA RETOMADA



#	Decisão	Proposta	Status
1	Polling vs. WebSocket no modo DIA	Polling 10s (simples)	🟡 a discutir
2	Cálculo da média: calendar vs. dado válido	Calendar (12 fixos)	🟡 a discutir
3	Threshold "mês zerado"	kwh_total <= 0	🟡 a discutir
4	Tooltip com lista de meses excluídos	SIM, popover	🟡 a discutir
5	Delta vs. mês atual visível no card	SIM, cores semânticas	🟡 a discutir
6	Cache do media_12m	1h (dado mensal)	🟡 a discutir
7	Mobile: card 2×2 ou empilhado	Grid responsivo	🟡 a discutir
📂 ARQUIVOS PREVISTOS PARA F1.B
A modificar:

public_html/energia.php — refactor maior
app/helpers/Tenant.php — possíveis ajustes (se necessário)
A criar:

api/energia/media_12m.php ⭐
api/energia/instantaneo.php (se não existir)
public_html/assets/js/dashboard.js (extrair JS inline?)
public_html/assets/css/dashboard.css (extrair CSS inline?)
A consultar (read-only):

api/energia/dia.php (v1.1.x referência)
api/energia/mes.php
api/energia/ano.php
api/energia/anos.php
docs/CONTRATO_DASHBOARD_V2.md
⚠️ PONTOS DE ATENÇÃO
🌐 Timezone: TODA query envolvendo data precisa CONVERT_TZ(coluna, 'UTC', timezone_controlador)
🔒 Multi-tenant: TODA query passa por app/helpers/Tenant.php
📊 ApexCharts: evitar recriar gráfico inteiro no refresh — usar chart.updateSeries()
🎨 Tema: variáveis CSS são fonte da verdade — sem cor hardcoded em JS
♿ Acessibilidade: badges com aria-label, gráficos com fallback textual
📉 Sem dados: estado vazio bem desenhado para cada gráfico/card
⏱️ Performance: card de média 12m com cache server-side (Redis futuro? por ora cache de arquivo ou MySQL query cache)
▶️ PRÓXIMA AÇÃO
Na retomada da sessão F1.B, Fernando deve:

📄 Colar o estado atual de public_html/energia.php
📄 Colar o estado atual de api/energia/dia.php
🎯 Validar/refinar as 7 decisões técnicas listadas acima
🟡 Confirmar escopo Realista + Card 12m (ou ajustar)
Em resposta, o Copilot entrega:

Diagnóstico do gap atual
Roadmap detalhado das 3 sessões
Patch da Sessão 1 (Refactor + Cards instantâneos)
💾 PARA SALVAR
Recomendação: salvar este conteúdo em:



docs/STATUS_CLOUD_2026-06-06_23-58_planejamento_F1B.md
Assim ele entra na cadeia oficial de status snapshots e pode ser referenciado na próxima sessão com:

"Cola o STATUS_CLOUD_2026-06-06_23-58_planejamento_F1B.md que a gente fechou no sábado."

🎬 FECHAMENTO DA SESSÃO


F1.A — Housekeeping + Bug firmware     ✅ FECHADA
F1.B — Dashboard v2 + Card Média 12m   📋 PLANEJADA  ← próxima
F1.C — Tabela de Limites               🔮 PENDENTE
F2.x — Integração SolisCloud           🔮 PENDENTE