## 📌 STATUS CLOUD — 2026-06-07 11:53 (Patch A):

### ✅ Concluído nesta sessão
- `public_html/dashboard.php` v1.8 → v1.9.0
  - Habilitado tema claro/escuro global via window.CipTema
  - Bloco CSS html[data-tema="claro"] espelhando energia.php
  - 6 ApexCharts registrados pós-render para sincronia automática
  - theme.mode dinâmico em baseCfg(), tooltip e apGauge
  - Transição suave background/color (.3s) no body
  - Persistência cross-page validada (dashboard ↔ energia)

### 🧠 Decisões técnicas
- Patch cirúrgico (4 pontos): NÃO tocar em const C, NÃO refatorar
  carregar(), NÃO mexer em annotations — fica para Patch B
- Padronizar data-tema="escuro" como fallback no <html> mesmo
  sabendo que o anti-FOUC sobrescreve (defensivo/cosmético)
- Manter consistência visual 1:1 com energia.php (tokens idênticos)

### 📂 Arquivos tocados
- `public_html/dashboard.php` — toggle de tema funcional

### 🔄 Impactos / dependências afetadas
- Nenhum impacto em outros arquivos
- tema.js, app_head.php, app_header.php, energia.php intactos

### 🔗 Impacto no firmware
- Nenhum (tema é puramente cosmético/cloud)

### ⚠️ Débitos técnicos conhecidos (alvo do Patch B)
- const C com cores hex hardcoded (grid/border/tooltip ficam
  visualmente inconsistentes no tema claro)
- CTRL_ID = 1 hardcoded (sem multi-tenant)
- annotations com cores fixas (#ffc10755, #00b4ff55, etc)
- Sem flag fonte_dado (cip | solis_api | cache)
- Sem badge de status online/offline real
- Sem integração resumo_dia.php

### ▶️ Próxima ação
- Patch B: refactor completo do dashboard.php
  → multi-tenant + cards instantâneos + badge geração
  → status online real + fonte_dado + cores dinâmicas


Estado A — Nenhum controlador selecionado (primeira visita)

 Arquitetura do Seletor Colapsável (B1) STATUS_CLOUD_2026-06-07 12:09
Antes de codar, alinha comigo o desenho:

┌─────────────────────────────────────────────────────────┐
│  HEADER GLOBAL (app_header.php)                          │
│  [☰] CIP Cloud                            [🌙] [👤 user] │
├─────────────────────────────────────────────────────────┤
│                                                          │
│  ⚡ Selecione um Controlador                             │
│  ┌─────────────────────────────────────────────┐        │
│  │ [▼ Escolha o controlador...           ]     │        │
│  └─────────────────────────────────────────────┘        │
│                                                          │
└─────────────────────────────────────────────────────────┘
Estado B — Controlador selecionado (uso normal)


┌─────────────────────────────────────────────────────────┐
│  HEADER GLOBAL                                           │
│  [☰] CIP Cloud  [📡 CIP-001 ▼] 🟢      [🌙] [👤 user]   │
│                  └─ botão clicável ─┘                    │
├─────────────────────────────────────────────────────────┤
│                                                          │
│  [Cards de telemetria, gráficos, etc...]                │
│                                                          │
└─────────────────────────────────────────────────────────┘
Estado C — Botão clicado (troca de controlador)


┌─────────────────────────────────────────────────────────┐
│  HEADER GLOBAL                                           │
│  [☰] CIP Cloud  [📡 CIP-001 ▼] 🟢      [🌙] [👤 user]   │
├─────────────────────────────────────────────────────────┤
│  ┌─────────────────────────────────────────────┐ ✕      │
│  │ Trocar controlador:                          │        │
│  │ [▼ CIP-001 — Galpão Industrial         ]     │        │
│  │  • CIP-001 — Galpão Industrial 🟢            │        │
│  │  • CIP-002 — Sede Administrativa 🔴          │        │
│  │  • CIP-003 — Estacionamento 🟢               │        │
│  └─────────────────────────────────────────────┘        │
│                                                          │
│  [conteúdo do dashboard logo abaixo]                    │
└─────────────────────────────────────────────────────────┘

## 📌 STATUS CLOUD — 2026-06-07 13:37

### ✅ Concluído nesta sessão

- **Patch B1 — Dashboard: Multi-tenant + Pill Selector + Cores Reativas**
  - `dashboard.php`: portado padrão multi-tenant do `energia.php` (Tenant::filtroSQL,
    `$controladorAtivo`, persistência em `$_SESSION['controlador_padrao']`).
    Removido `const CTRL_ID = 1` hardcoded.
  - `dashboard.php`: implementado `lerCoresCss()` + MutationObserver pra reatividade
    de tema nos 6 gráficos PDE via `updateOptions()`.
  - `includes/app_header.php`: pill button colapsável no header (à esquerda do logo
    do integrador). Modo estático com 1 controlador, dropdown com 2+. Acessibilidade
    com ESC + clique fora. Opção 1 escolhida (header lê variáveis injetadas pela view).
  - `assets/css/header.css`: estilos do `.ctrl-pill-btn`, bolinhas `.online`/`.offline`,
    hover e breakpoints @media 600px/400px.

### 🧠 Decisões técnicas

- **Opção 1 (DRY)** adotada no header: cada view define `$controladorAtivo` +
  `$controladoresAcessiveis` **antes** do `require app_header.php`. Header só lê.
- **Persistência via sessão** (`$_SESSION['controlador_padrao']`) compartilhada
  entre `dashboard.php` e `energia.php` — UX consistente entre páginas.
- **Reatividade de tema via MutationObserver** no atributo `data-tema` do `<html>`,
  com fallback de cores via `getPropertyValue()` quando var CSS não definida.
- **Fonte do "online" no pill** = coluna `controladores.online` (server-side, page
  load). Pode divergir do card "Status do Controlador" que calcula em runtime via
  `last_telemetry_at`. Ver "Pontos de atenção" abaixo.

### 📂 Arquivos tocados

| Arquivo | Versão antes → depois | Motivo |
|---|---|---|
| `dashboard.php` | vX.Y.Z → vB1.0.0 | Multi-tenant + cores reativas |
| `includes/app_header.php` | vX.Y.Z → vB1.0.0 | Pill button colapsável |
| `assets/css/header.css` | vX.Y.Z → (sem bump) | Estilos do pill + responsivo |

### 🔄 Impactos / dependências afetadas

- ✅ `energia.php` compartilha sessão `controlador_padrao` → navegação coerente
- ⚠️ Toda nova página que usar `app_header.php` precisa definir `$controladorAtivo`
  e `$controladoresAcessiveis` antes do include (padrão Opção 1)
- ⚠️ Variáveis CSS `--blue`, `--green`, etc devem existir no tema escuro atual
  (usa fallbacks hardcoded se faltarem — degradação suave)

### 🔗 Impacto no firmware

- ❌ Nenhum. Patch 100% web/cloud. Não toca em contrato firmware↔cloud.

### ▶️ Próxima ação (a decidir com Fernando)

Opções abertas:
- 🅰️ **Patch B2** — próxima frente no dashboard (definir escopo)
- 🅱️ **Replicar pill** em outras páginas que usam `app_header.php` mas ainda não
  injetam `$controladorAtivo`
- 🅲️ **Sincronizador online** — cron PHP que atualiza `controladores.online`
  baseado em `last_telemetry_at` (resolve a inconsistência relatada)
- 🅳️ **Avançar pra Fase 2** — tabela de limites de potência (`limites_potencia.php`)

### ⚠️ Pontos de atenção (débitos / observações)

- 🐛 **Inconsistência de "online"**: coluna `controladores.online` pode estar
  desatualizada (caso real: controlador "Simulador Python" id=4 com `online=1`
  mas sem telemetria recente). Pill mostra valor da coluna; card calcula em
  runtime. Não é bug de código — é bug de dado. Considerar consolidação futura
  (cron, VIEW ou helper único).
- 📋 Ao replicar pill em outras páginas, lembrar de seguir Opção 1 (injetar
  variáveis antes do include) — caso contrário, pill some sem erro visível.
- 🎨 Reatividade de tema testada via console (`data-tema='claro'`). Tema claro
  funcional completo ainda é débito do projeto (Seção 10 do PROJETO_CIP_CLOUD.md).
////////////********************:******************************************/////////////////////////
## 📌 STATUS CLOUD — 2026-06-07 18:53

### ✅ Concluído nesta sessão
- `api/energia/instantaneo.php` v1.0.1 — revisado e aprovado (já estava em prod)
- `api/energia/resumo_dia.php` v1.0.0 — agregado do dia corrente (energia MAX-MIN, potência AVG, consumo recalculado, completude por amostras)
- `api/energia/resumo_mes.php` v1.0.0 — agregado do mês corrente + projeção linear simples
- `api/energia/media_12m.php` v1.0.0 — média dos 12 meses fechados + delta vs projeção do mês corrente (tendência acima/abaixo/estavel)

### 🧠 Decisões técnicas
- Energia cumulativa → `MAX(col) - MIN(col)` por janela
- Potência instantânea → `AVG(col)` por janela
- Consumo SEMPRE recalculado: `importada - exportada` (firmware reporta `potencia_consumo_total_w`, mas não confiamos por divergência de sinal do EA777)
- Janela temporal calculada no TZ do controlador → convertida pra UTC no WHERE
- `inversor.conectado` derivado de `geracao_origem` + amostras válidas
- `resumo_mes` usa projeção linear simples (consumo_parcial / dia_atual * dias_no_mes)
- `media_12m` exclui mês corrente (12 meses FECHADOS) para servir de baseline limpo
- Tendência ±5% como limiar de "estavel" (hardcoded por ora — futuro: config)
- `historico_suficiente = false` se < 3 meses fechados

### 📂 Arquivos tocados
- `api/energia/resumo_dia.php` — novo
- `api/energia/resumo_mes.php` — novo
- `api/energia/media_12m.php` — novo + correção GROUP BY (alias mes_ref)

### 🐛 Bug encontrado e corrigido
- `media_12m.php` quebrava com `ONLY_FULL_GROUP_BY` quando se usava 2 placeholders distintos (`:tz_str` / `:tz_str2`) em expressões logicamente idênticas. MySQL compara por AST, não por valor. Correção: `GROUP BY mes_ref` (alias da projeção). Mais limpo e elimina o parâmetro duplicado.

### 🔄 Impactos / dependências afetadas
- Frontend (`energia.php` ou dashboard novo) agora pode consumir os 4 endpoints (`instantaneo`, `resumo_dia`, `resumo_mes`, `media_12m`)
- Nenhuma migration de schema necessária
- Nenhum impacto em outros endpoints existentes

### 🔗 Impacto no firmware
- Nenhum nesta etapa (apenas leitura de telemetria já gravada)
- ⚠️ Reforça pendência P0 do firmware: enviar `fator_potencia_total` real (hoje vem zero/null), pra `instantaneo.php` exibir FP corretamente

### ▶️ Próxima ação
- **F1.B**: integrar os endpoints no frontend do dashboard (cards "Agora", "Hoje", "Este Mês", "Comparativo 12m")
- OU **F1.C**: criar gráfico de série temporal (séries diárias do mês corrente, mensais do ano)
- Fernando decide a ordem

### ⚠️ Pontos de atenção
- Projeção linear simples ignora sazonalidade (fins de semana, feriados, clima). Aceitável pra MVP; evoluir pra média móvel ponderada se ficar impreciso na prática
- Limiar de ±5% pra "estavel" é arbitrário — calibrar com dados reais após semanas de operação
- `media_12m` precisa de pelo menos 1 mês fechado pra retornar algo útil — em controladores novos, frontend deve tratar `historico_suficiente = false` com mensagem "Aguardando histórico"
- Inversor ainda offline: todos os campos `geracao` virão zerados/nulos. JSON está estruturado pra isso, mas o frontend precisa renderizar "—" ou badge neutro em vez de "0,00 kWh" (que pode confundir)

### 📋 Lição aprendida (pra próximos prompts ATGY)
- ❌ Evitar duplicar placeholder PDO pra contornar reuso
- ✅ Usar `GROUP BY alias` quando expressão se repete entre SELECT e GROUP BY
- ✅ Confiar nas extensões do MySQL quando elas simplificam (alias em GROUP BY é uma delas)
////////////////////////*******************************************************************///////////////////////////////
📌 STATUS CLOUD — 2026-06-07 19:43
✅ Concluído nesta sessão
public_html/dashboard.php — vB1.0.0 → v1.10.0 (Patch B2.1)
Removidos: CDN ApexCharts, 6 gráficos PDE, botões de período, totalizadores, MutationObserver dos charts, funções initCharts/updateSeries/statusFP/baseCfg/setPeriodo/startTimer
Adicionados: #infografico-host (B2.2) e #cards-host (B2.3) como áreas reservadas
Mantidos intactos: 9 KPIs do topo (6 elétricos + 3 status), multi-tenant B1, tema claro/escuro
carregar() refatorado em carregarKpis() consumindo o mesmo endpoint legado
Auto-refresh de 10s preservado apenas para KPIs
🧠 Decisões técnicas
Endpoint legado api/dashboard/dados.php mantido como está — refactor fica para Fase B3 (não bloqueia B2.2/B2.3)
MutationObserver enxuto — agora só recolore o KPI de FP (único elemento JS-driven que sobrou); pronto pra ser estendido por B2.2/B2.3
Áreas reservadas vazias — zero impacto visual até as próximas ondas popularem
📂 Arquivos tocados
public_html/dashboard.php — patch cirúrgico B2.1
🔄 Impactos / dependências afetadas
❌ Nenhuma página externa afetada
⚠️ api/dashboard/dados.php continua sendo consumido (campos series, gauge, totalizadores extras viraram payload "morto" — ignorados pelo frontend, mas ainda trafegam). Otimização fica para B3.
🔗 Impacto no firmware
✅ Nenhum. Patch puramente cosmético/estrutural na camada cloud.
▶️ Próxima ação
🎨 Patch B2.2 — Infográfico animado SVG no #infografico-host
Definir: fluxo de energia (rede ↔ CIP ↔ inversor ↔ cargas) com setas animadas e valores em tempo real
Stack: SVG inline + CSS animations + JS para atualização de valores
Reusar carregarKpis() ou criar carregarInfografico() dedicado?

/////////////////////////******************************************************///////////////////////////////

## 📌 STATUS CLOUD — 2026-06-07 (Sessão noturna) 22:14

### ✅ Concluído nesta sessão

#### 🔧 Patch B2.2.2 — v1.13.0 (entregue e validado)

**Backend — `api/dashboard/infografico.php`:**
- Adicionada query de cálculo de energia acumulada do dia via delta de hodômetro (`MAX - MIN`)
- Cálculo timezone-aware usando `CONVERT_TZ` com `controladores.timezone` (JOIN otimizado O(1) com escopo de tenant)
- Novo bloco `energia_dia` no JSON de resposta com: `geracao_kwh`, `importada_kwh`, `exportada_kwh`, `consumo_total_kwh`, `amostras`, `geracao_dia_parcial`, timestamps de janela
- Fórmula de consumo total: `importada + COALESCE(geracao, 0) - exportada`
- Validações de borda implementadas (status "aparentemente sim" — pendente confirmação visual no código)

**Frontend — `dashboard.php`:**
- Layout híbrido nas caixas do infográfico SVG: kWh do dia em destaque + kW instantâneo secundário
- Implementação via `<tspan>` no SVG (escala junto com o vetor)
- CSS dedicado: `.valor-energia` (18px, 800) e `.valor-potencia` (13px, 600, opacity 0.75)
- Estados `--sem-dado` com opacity 0.4 + itálico

**JavaScript — fix do bug noturno das setas:**
- Helper centralizado `calcularEstadoSeta(potenciaW)` com limiar `LIMIAR_FLUXO_W = 30W`
- Helper wrapper `aplicarFluxo(grupo, watts)` — gerencia classes CSS + propriedade `--dur`
- **Cada seta agora calcula seu estado independentemente** (FV→Casa, Rede→Casa, Casa→Rede, Bateria↔Casa)
- Velocidade da animação proporcional à potência: `Math.max(1.5, Math.min(8, 8 - (p/5000)*6.5))`
- Limpeza defensiva de classes legadas (`inativo`, `standby`, `--standby` etc.)
- Degradação graciosa se backend não enviar `energia_dia` (fallback v1.12.1)

### 🧠 Decisões técnicas

1. **Tabela `telemetria_5min` armazena hodômetro absoluto**, não delta — validado com dump real do EA777 (1084.84 → 1085.54 kWh em 45min). Fórmula correta: `MAX - MIN` na janela do dia local.
2. **Card do Imóvel mostra valor calculado novo** (`consumo_total = importada + geração - exportada`) — não existe em `energia.php`. É uma feature semântica nova: "quanto a casa consumiu fisicamente", diferente de "quanto entrou da rede".
3. **`aplicarFluxo()` como wrapper** — não estava no ATGY original, foi adição elegante para encapsular manipulação de classes + custom property CSS.
4. **`grupo.style.removeProperty('--dur')`** quando inativo — evita "fantasma" de animação anterior.
5. **Validação cruzada confirmada**: dashboard (`energia_dia`) bate com `energia.php` para os mesmos períodos → não há divergência de cálculo entre as duas vias.
6. **B2.2.3 absorvido pelo B2.2.4**: como não há divergência, auditoria documental dos 4 endpoints `api/energia/*` será feita dentro do `docs/CONVENCOES.md` (economiza 1 sessão sem perder rigor).

### 📂 Arquivos tocados

- `api/dashboard/infografico.php` — bloco `energia_dia` + query de hodômetro timezone-aware (v1.13.0)
- `dashboard.php` — layout híbrido SVG + helpers JS centralizados + fix animação independente (v1.13.0)

### 🔄 Impactos / dependências afetadas

- **Payload do endpoint `infografico.php` cresceu** — novo bloco `energia_dia`. Compatibilidade preservada (frontend degrada gracioso se ausente).
- **Nenhum impacto em `energia.php`** — validação cruzada confirmou que valores batem.
- **Polling 30s mantido**, pausa em background mantida.
- **Tema claro/escuro** respeitado nos novos elementos.

### 🔗 Impacto no firmware

❌ **Zero impacto.** Patch 100% camada cloud. Firmware continua enviando os mesmos hodômetros via `telemetria_5min`.

---

### 📁 Arquitetura de deploy — Subdomínio em servidor compartilhado

⚠️ **CORREÇÃO ARQUITETURAL REGISTRADA EM 2026-06-07** (eu havia assumido errado em mensagem anterior — informação retificada por Fernando):

O projeto CIP **roda em servidor compartilhado** sob o subdomínio 
`monitor.aeonium.com.br`. A pasta do projeto chama-se **`monitor.aeonium.com.br`** 
(mesmo nome do subdomínio) e é o **doc_root do subdomínio**.

**Importante:** a estrutura é **idêntica em DEV e em PROD** — mesma nomenclatura 
de pasta, mesma topologia. Isso simplifica deploy (rsync/FTP espelhado) e elimina 
divergência de paths entre ambientes.

**Topologia real:**
```
~/public_html/                       → doc_root de www.aeonium.com.br
│                                      (domínio principal — NÃO é do projeto CIP)
│
└── monitor.aeonium.com.br/          → doc_root de monitor.aeonium.com.br
    │                                  (PROJETO CIP COMPLETO — PROD e DEV)
    │
    ├── api/                         → acessível via HTTP
    │   ├── dashboard/
    │   │   └── infografico.php
    │   └── energia/
    │       ├── dia.php
    │       ├── mes.php
    │       ├── ano.php
    │       └── anos.php
    ├── app/                         → DEVE ter .htaccess Deny + guard CIP_BOOT
    │   └── helpers/
    │       └── Tenant.php
    ├── docs/                        → DEVE ter .htaccess Deny
    │   ├── STATUS_CLOUD.md
    │   └── CONVENCOES.md (a criar)
    ├── dashboard.php                → ponto de entrada legítimo
    ├── energia.php                  → ponto de entrada legítimo
    └── limites.php                  → ponto de entrada legítimo (a confirmar)
```

**Implicações técnicas:**

1. **Não há separação física** entre código público e privado — tudo está sob o doc_root
2. Não usar `__DIR__ . '/../../app/...'` assumindo nível acima do doc_root — paths são todos relativos à raiz do subdomínio
3. Toda pasta sensível precisa de `.htaccess` com `Deny from all`
4. Helpers em `app/` devem ter guard no topo:
   ```php
   if (!defined('CIP_BOOT')) { http_response_code(403); exit; }
   ```
5. Pontos de entrada (`dashboard.php`, `energia.php`, APIs) devem definir 
   `CIP_BOOT` antes do primeiro `require`
6. Credenciais sensíveis: investigar se a hospedagem permite arquivo um nível 
   acima de `~/public_html/` para `.env` ou `config_secret.php`

**TODO de auditoria de segurança (backlog):**
- [ ] Verificar existência de `.htaccess` em `app/` e `docs/`
- [ ] Verificar uso de guard `CIP_BOOT` (ou equivalente) nos helpers
- [ ] Mapear onde estão as credenciais sensíveis (DB, SolisCloud HMAC)
- [ ] Teste de sanidade: tentar acessar `https://monitor.aeonium.com.br/app/helpers/Tenant.php` e `https://monitor.aeonium.com.br/docs/STATUS_CLOUD.md` — devem retornar 403

---

### ⚠️ Pontos de atenção / dívida técnica

1. **Validações de borda do backend** — Fernando respondeu "aparentemente sim" para as 3 bordas (`amostras < 2`, delta negativo por reset de medidor, `geracao_dia_parcial`). **Confirmar visualmente no código** na próxima sessão. Risco real quando:
   - Sistema instalado e primeiro dia tem < 2 amostras (Borda 1)
   - Medidor EA777 trocado/resetado em campo (Borda 2 — vai acontecer)
   - Integração com inversor ativada no meio do dia (Borda 3)

2. **TODO cosmético — Tooltip no card do Imóvel** — adicionar `<title>` ou ⓘ explicando que o valor é `importada + geração - exportada` (consumo físico da casa, não da rede). Adiado para depois do ciclo B2.2.

3. **Validação manual com cenário diurno pendente** — todos os testes foram com cenário noturno (geração=null, importação ativa). Quando o sol estiver bom, conferir se o híbrido se comporta corretamente com FV gerando + exportação ativa.

4. **Auditoria de segurança do doc_root** — itens listados na seção de arquitetura acima.

---

### ▶️ Próxima ação

🎯 **Sessão seguinte:** Patch B2.2.4 — criação de `docs/CONVENCOES.md` consolidando:
- Convenção de sinais (B2.2.1)
- Cálculo de energia via hodômetro `MAX-MIN` (B2.2.2)
- Listagem explícita dos 5 arquivos que tocam no cálculo: `api/dashboard/infografico.php` + `api/energia/{dia,mes,ano,anos}.php`
- Fluxo de sincronização firmware ↔ cloud (tabela de limites)
- Hierarquia de fonte de dados (`fonte_dado`: cip / solis_api / cache)
- `geracao_origem` semântica
- Topologia de deploy (subdomínio em hospedagem compartilhada)

---

### 📦 Versionamento

```
v1.12.0 → v1.12.1 (B2.2.1 — hotfix UX)
v1.12.1 → v1.13.0 (B2.2.2 — híbrido + fix setas) ← VERSÃO ATUAL
```

Próximo bump: `v1.13.1` (B2.2.4 — apenas documentação, patch level).

---

### 🚢 Deploy em produção (monitor.aeonium.com.br)

**Caminhos de upload (PROD e DEV usam a mesma estrutura):**

| Arquivo local | Destino no servidor |
|---------------|---------------------|
| `api/dashboard/infografico.php` | `~/monitor.aeonium.com.br/api/dashboard/infografico.php` |
| `dashboard.php` | `~/monitor.aeonium.com.br/dashboard.php` |
| `docs/STATUS_CLOUD.md` | `~/monitor.aeonium.com.br/docs/STATUS_CLOUD.md` |

> 📌 Ajustar o caminho raiz conforme estrutura da hospedagem (pode ser 
> `~/public_html/monitor.aeonium.com.br/`, `~/monitor.aeonium.com.br/`, 
> `/home/usuario/monitor.aeonium.com.br/` etc.)

**Permissões em hospedagem compartilhada:**
- Arquivos PHP: `644`
- Pastas: `755`
- Nunca usar `777`

**Pós-deploy:**
- [ ] Acessar `https://monitor.aeonium.com.br/dashboard.php` (ou rota equivalente)
- [ ] Ctrl+Shift+R no primeiro acesso (CSS/JS embedados mudaram)
- [ ] Verificar Network tab → `api/dashboard/infografico.php` retorna bloco `energia_dia`
- [ ] Console JS sem erros
- [ ] OPcache: hospedagem compartilhada geralmente recicla automático em poucos minutos
- [ ] Verificar `error_log` (geralmente em raiz do subdomínio ou painel da hospedagem)

**Teste de segurança rápido pós-deploy:**
- [ ] Tentar acessar `https://monitor.aeonium.com.br/app/helpers/Tenant.php` → deve retornar 403
- [ ] Tentar acessar `https://monitor.aeonium.com.br/docs/STATUS_CLOUD.md` → deve retornar 403
- [ ] Se algum dos dois retornar o conteúdo, **PARAR** e adicionar `.htaccess` Deny antes de continuar

---

### 📝 Sobre o ATGY (lembrete pra próxima sessão)

**ATGY** = padrão de prompt estruturado usado para abrir cada patch. Formato consolidado:

```
1. Contexto fixo (stack, arquivos alvo, versão atual → nova)
2. Objetivo (o que entrega, com bullets)
3. Premissas críticas (regras que não podem ser violadas)
4. Alterações requeridas (backend / frontend / CSS / JS, com pseudocódigo)
5. Checklist de validação pós-patch
6. Formato da entrega esperada
7. Inputs que devem ser fornecidos antes (código atual, helper Tenant etc.)
8. Continuidade (como esse patch encaixa no ciclo maior)
```

🎯 **Lição aprendida nesta sessão:** o ATGY funciona melhor quando é entregue 
**em um único bloco markdown contínuo** (como Fernando corretamente exigiu no 
meio da sessão). Evita fragmentação e facilita copiar/colar entre sessões.

Para B2.2.4: gerar o ATGY do `docs/CONVENCOES.md` já em bloco único desde o início.

---

### 🗺️ Roadmap consolidado do ciclo B2.2

```
✅ B2.2.1 (v1.12.1) — Hotfix UX + limpeza de cards legados
✅ B2.2.2 (v1.13.0) — Híbrido kWh+kW + fix animação independente
⏭️ B2.2.4 (v1.13.1) — docs/CONVENCOES.md consolidado [PRÓXIMA SESSÃO]
   └── absorve auditoria que seria do B2.2.3 (não houve divergência)
⏳ B2.2.2.1 (cosmético) — Tooltip explicativo do card Imóvel [BACKLOG]
⏳ Auditoria de segurança do doc_root (.htaccess + guards) [BACKLOG]
⏳ Fechamento — STATUS_CLOUD.md consolidado de todo ciclo B2.2
🚀 B2.3 — Novos cartões / features (escopo a definir)
```

---

### 🎯 Resumo executivo da sessão

| Métrica | Valor |
|---------|-------|
| Patches entregues | 1 (B2.2.2 v1.13.0) |
| Bugs corrigidos | 1 (animação noturna das setas) |
| Features novas | 2 (kWh do dia + cálculo consumo do imóvel) |
| Arquivos modificados | 2 |
| Validação cruzada | ✅ Confirmada com `energia.php` |
| Correções arquiteturais | 1 (topologia do doc_root retificada) |
| Dívida técnica gerada | Baixa (1 confirmação + 1 tooltip + 1 auditoria de segurança) |
| Próxima sessão | B2.2.4 — `docs/CONVENCOES.md` |


📋 Sobre o ATGY (lembrete pra futura sessão)
ATGY = padrão de prompt estruturado que usamos pra abrir cada patch. Formato:



1. Contexto fixo (stack, arquivos alvo, versão atual → nova)
2. Objetivo (o que entrega, com bullets)
3. Premissas críticas (regras que não podem ser violadas)
4. Alterações requeridas (backend / frontend / CSS / JS, com pseudocódigo)
5. Checklist de validação pós-patch
6. Formato da entrega esperada
7. Inputs que devem ser fornecidos antes (código atual, helper Tenant etc.)
8. Continuidade (como esse patch encaixa no ciclo maior)
🎯 Lição aprendida nesta sessão: o ATGY funciona melhor quando é entregue em um único bloco markdown contínuo (como você corretamente exigiu no meio da sessão). Evita fragmentação, facilita copiar/colar entre sessões.

Para a próxima sessão (B2.2.4): vou gerar o ATGY do docs/CONVENCOES.md já em bloco único desde o início.


**Time de trabalho:**
- 👷 **Fernando** — engenheiro responsável, decisões de arquitetura e produto
- 🧠 **CIP Cloud Copilot** (este agente) — co-piloto técnico de planejamento, 
  revisão, arquitetura e definição de patches
- 🤖 **ATGY (Antigravity)** — agente executor: aplica os patches no código, 
  encontra trechos a alterar e roda testes

**Divisão de papéis:**
1. Fernando + Cloud Copilot **planejam e engenheiram** a solução 
   (arquitetura, contrato, schema, lógica, validações)
2. Cloud Copilot entrega **patch pronto + instruções claras** 
   (mesmo que pequeno — ATGY trabalha melhor com escopo cirúrgico, 
   pois tem mais facilidade em localizar o trecho exato a alterar)
3. **ATGY codifica e testa** no repositório/ambiente

**📋 Instrução obrigatória para repassar ao ATGY em TODA entrega:**
> ⚠️ "Atualizar o cabeçalho do arquivo com:
> - `@versao` incrementada (semver: MAJOR.MINOR.PATCH)
> - `@modificado_em` com a data da alteração
> - `@objetivo` descrevendo o que esta alteração/página faz
> 
> Este cabeçalho é parte do patch — não é opcional."

**Exemplo de cabeçalho-padrão para o ATGY seguir:**
```php
<?php
/**
 * @arquivo      api/energia/dia.php
 * @versao       2.3.1
 * @modificado_em 2026-06-04
 * @objetivo     Endpoint do modo DIA: retorna buckets de 5min para 
 *               o dashboard de energia. Schema v2 com colunas dedicadas.
 * @autor        Fernando / CIP Cloud Copilot / ATGY
 */
declare(strict_types=1);
