📌 STATUS CLOUD — 2026-06-03 (sessão consolidada)
Salvar como: docs/STATUS_CLOUD.md (sobrescrever o anterior) ou versionar como docs/STATUS_CLOUD_2026-06-03.md se preferir histórico

markdown


# 📌 STATUS CLOUD — Sessão 2026-06-03

**Projeto:** CIP — Controlador de Injeção de Potência (AER)  
**Responsável:** Fernando  
**Co-piloto:** CIP Cloud Copilot (Claude Opus 4.7)  
**Duração da sessão:** Investigação arquitetural + planejamento de fases  
**Status geral:** ⏸️ Pausado antes da Fase 0 — aguardando Ação Zero.3

---

## 🎯 OBJETIVO DA SESSÃO

Planejar a evolução do dashboard do CIP em 3 fases (B+):
1. Card "Agora" no `energia.php`
2. Dashboard da Empresa (rewrite)
3. Dashboard Global (AER)

E estabelecer **disciplina de migração DEV → PROD** antes de tocar em qualquer
linha de código, dado que a base já tem dados reais e ambiente produtivo.

---

## 🔍 DESCOBERTAS ARQUITETURAIS (não perder isso)

### Modelo multi-tenant real do projeto
👑 GLOBAL (papel_global) ├─ master → AER └─ master_operador → AER ops │ ▼ 🏢 empresa (tipo) ├─ 'cliente_real' ← empresa real (instaladora/integradora) ├─ 'integradora_virtual' ← agrupamento lógico ├─ 'parceiro' ← parceiro comercial └─ 'demo' ← ambiente de demonstração │ ├──► 🔌 controladores (empresa_id, 1:N) │ └─ cliente_nome (texto livre — "cliente final") │ └──► 👥 usuarios ├─ empresa_id (vínculo "home" — primário) └─ usuario_empresa (M:N — VAZIA hoje, mas existe!) └─ papel_empresa: admin/operador/usuario




### Achados-chave

- ✅ Schema multi-tenant é **mais maduro** que a memória registrava
- ✅ Tabela `usuario_empresa` (M:N) **existe** mas está **vazia** — disponível pra uso
- ✅ `empresa.tipo` já contempla 4 categorias (`cliente_real`, `integradora_virtual`, `parceiro`, `demo`)
- ✅ `Tenant.php` v1.0.0 (2026-05-17) tem fail-safe `AND 1=0` e está sólido
- ❌ **Falta:** tabela `usuario_controlador` (M:N usuário↔device) para clientes C&I com acesso granular
- ⚠️ **Divergência terminológica:** schema usa `'usuario'`, prompt antigo do `usuarios.php` usa `'viewer'` → padronizar em `'usuario'`

### Dados reais em PROD hoje
usuarios: #1 Administrador Inicial perfil=administrador empresa_id=1 ATIVO=0 (soft deleted) #2 Fernando Cezar Leal Polito perfil=master papel_global=master (você) #3 Rafael Rahd Polito perfil=operador papel_global=master_operador #4 Ivone M Rahd Polito perfil=usuario empresa_id=1 #5 Theo Moital perfil=usuario empresa_id=1

empresa: #1 Aeonium Energia tipo=cliente_real ATIVO=1

usuario_empresa: VAZIA controladores: até id=4 (AUTO_INCREMENT=5)




---

## 🗺️ PLANO DE FASES APROVADO (Caminho B+)

### 🔵 FASE 0 — Preparação (não iniciada)
**Selo:** 🟢 DEV-ONLY  
**Tempo estimado:** ~30 min  
**Entregáveis:**
- [ ] `docs/ARQUITETURA_HIERARQUIA.md` — congelar a descoberta
- [ ] `docs/migrations/README.md` — convenção de migrations
- [ ] `docs/CHANGELOG_CLOUD.md` — esqueleto inicial
- [ ] `docs/STATUS_CLOUD.md` — este arquivo
- [ ] Prompt cirúrgico pro ATGY: correção do bug HTML `<d\tiv>` no `energia.php` + cabeçalho v2.5

### 🟡 FASE 1 — Card "Agora" no energia.php (não iniciada)
**Selo:** 🟡 DEV+PROD-SOFT  
**Tempo estimado:** ~1-2h  
**Entregáveis:**
- [ ] Componente visual "Agora" no topo do `energia.php`
- [ ] Reuso do `api/dashboard/snapshot.php` v1.0.0 (já operacional)
- [ ] Refresh automático respeitando `TELEMETRIA_CICLO_SEGUNDOS` (DEV=900s, PROD=60s)
- [ ] Suporte a tema claro/escuro via CSS variables
- [ ] Fallback gracioso quando snapshot vier vazio

### 🟠 FASE 2 — Dashboard da Empresa (não iniciada)
**Selo:** 🟠 DEV+PROD-SCHEMA  
**Tempo estimado:** ~3-5h  
**Entregáveis:**
- [ ] Migration `usuario_controlador` (com rollback)
- [ ] Rewrite do `dashboard.php` (ou arquivo novo — decidir)
- [ ] Novo endpoint `api/dashboard/empresa.php`
- [ ] Método `Tenant::controladoresPermitidos()` no helper
- [ ] Backfill opcional de `usuario_controlador` para usuários existentes

### 🟡 FASE 3 — Dashboard Global AER (não iniciada)
**Selo:** 🟡 DEV+PROD-SOFT  
**Tempo estimado:** ~2-3h  
**Entregáveis:**
- [ ] Novo `dashboard_global.php` (gate por `papel_global`)
- [ ] Endpoint `api/dashboard/global.php` com agregação por empresa
- [ ] KPIs: total empresas, controladores, online/offline, geração 24h
- [ ] Lista clicável de empresas → drilldown pra Fase 2

---

## 🛡️ DISCIPLINA DEV→PROD ESTABELECIDA

### Princípios
1. 🔒 DEV nunca toca PROD direto
2. 📦 Toda mudança de schema = migration versionada em `docs/migrations/`
3. ↩️ Toda migration tem rollback gêmeo
4. 🧪 Backup ANTES de aplicar em PROD (manual via phpMyAdmin)
5. 🚦 Feature flag onde possível
6. 📝 Changelog único em `docs/CHANGELOG_CLOUD.md`
7. ⏸️ Janela de manutenção declarada em PROD

### Sistema de selos
| Selo | Significado |
|---|---|
| 🟢 DEV-ONLY | Aplica direto em DEV, só código |
| 🟡 DEV+PROD-SOFT | DEV livre, PROD precisa janela mas sem risco (novo arquivo PHP, frontend) |
| 🟠 DEV+PROD-SCHEMA | Tem migration SQL — backup + janela + rollback |
| 🔴 PROD-CRÍTICO | UPDATE em massa, DROP, ALTER de coluna usada — aprovação explícita |

### Fluxo de deploy adotado (sem Git por enquanto)
Desenvolve em DEV (.test)
Valida em DEV
Backup de PROD (banco + arquivos)
Upload em PROD via cPanel/FTP
Validação em PROD
Registro em CHANGELOG_CLOUD.md



---

## 🌐 AMBIENTES CONFIRMADOS

| Item | DEV | PROD |
|---|---|---|
| URL | `http://monitor.aeonium.com.br.test` | `https://monitor.aeonium.com.br` |
| Banco | `aeoniu71_monitor` (cópia local) | `aeoniu71_monitor` (compartilhado cPanel) |
| Usuário DB | `root` | (não exposto) |
| Senha DB | (vazia) | (no `config/db.php` do servidor) |
| Acesso arquivos | Filesystem local | cPanel File Manager / FTP |
| Backup | Não automático | Não automático |
| Versionamento | Git: **não adotado** ainda | Git: **não adotado** ainda |

---

## 🆘 AÇÃO ZERO — Status de execução

| Item | Status | Observação |
|---|---|---|
| **Zero.1** — Backup banco PROD via phpMyAdmin | ✅ Feito | Arquivo `.sql` baseline salvo no PC |
| **Zero.2** — Backup arquivos PROD via cPanel | ✅ Feito | ZIP baseline salvo no PC |
| **Zero.3** — Espelhar DEV ← PROD | ⛔ **PENDENTE — NÃO EXECUTAR ainda** | ⚠️ Risco: DEV pode ter implementações não migradas para PROD. Inventariar antes de sobrescrever |

### ⚠️ ATENÇÃO CRÍTICA — Antes de retomar

> **NÃO executar Zero.3 (espelhar DEV ← PROD) sem antes inventariar  
> o que está em DEV e ainda não foi para PROD.**  
> 
> Sobrescrever DEV agora pode **destruir trabalho já feito**.

---

## 📋 CHECKLIST OBRIGATÓRIO PARA RETOMADA DA SESSÃO

Quando voltar, executar nesta ordem:

### 🔍 Passo 1 — Inventário do DEV (descobrir o que está adiantado)

**1.1 — Comparar bancos (DEV vs PROD)**

Rodar em DEV (local) e em PROD (phpMyAdmin), comparar resultados:

```sql
-- Lista de tabelas
SHOW TABLES;

-- Versão do Tenant.php e arquivos críticos
-- (não tem como via SQL, comparar arquivos via diff)

-- Schemas das tabelas-chave (comparar manualmente)
SHOW CREATE TABLE usuarios;
SHOW CREATE TABLE empresa;
SHOW CREATE TABLE controladores;
SHOW CREATE TABLE usuario_empresa;
SHOW CREATE TABLE energia;
SHOW CREATE TABLE tabela_limites;
SHOW CREATE TABLE tabela_limites_historico;
SHOW CREATE TABLE integracoes_solis;
1.2 — Comparar arquivos (DEV vs PROD)




Arquivo	DEV	PROD	Diferenças?
public_html/energia.php	?	?	?
public_html/usuarios.php	?	?	?
public_html/dashboard.php	?	?	?
app/helpers/Tenant.php	?	?	?
api/dashboard/snapshot.php	?	?	?
api/energia/*.php	?	?	?
includes/app_head.php	?	?	?
includes/app_header.php	?	?	?
config/db.php	?	?	?
app/auth.php	?	?	?
Ferramenta sugerida: WinMerge / Beyond Compare / VS Code diff entre pastas

1.3 — Resultado esperado

Produzir documento docs/INVENTARIO_DEV_VS_PROD_2026-XX-XX.md com:

Tabelas que existem em DEV mas não em PROD
Tabelas com schema diferente
Arquivos modificados em DEV não publicados
Decisão: o que migrar pra PROD antes da Fase 0
🔄 Passo 2 — Reconciliação
Para cada divergência DEV>PROD encontrada:

Avaliar se a alteração está finalizada e testada
Se SIM → criar migration + plano de deploy pra publicar em PROD
Se NÃO → documentar como "trabalho em andamento" e isolar branch
✅ Passo 3 — Só então, executar Ação Zero.3
Com DEV e PROD reconciliados (ou divergências documentadas)
Aí sim espelhar PROD em DEV pra começar limpo
🚀 Passo 4 — Iniciar Fase 0
Documentação consolidada
Correção do bug HTML <d\tiv> no energia.php
Cabeçalho v2.5
🧠 DECISÕES TÉCNICAS DESTA SESSÃO



#	Decisão	Justificativa
1	Caminho B+ (3 fases)	Schema atual suporta com mínima evolução
2	Padronizar terminologia em 'usuario' (não viewer)	Coerência com schema existente em 2 lugares
3	Criar usuario_controlador na Fase 2	Necessário para clientes C&I com acesso granular
4	NÃO criar tabela integradores separada	empresa.tipo já resolve com integradora_virtual
5	Adiar uso prático de empresa.tipo='integradora_virtual'	Esperar caso de uso real, não construir spec especulativa
6	Sem Git por enquanto	Fernando não tem conhecimento; trilha paralela opcional futura
7	Sistema de selos 🟢🟡🟠🔴 oficial	Identifica risco de cada alteração antes do código
8	Migrations versionadas em docs/migrations/	Pasta dedicada com rollback gêmeo
9	Backup manual obrigatório antes de cada deploy PROD	Compensa ausência de Git + sem rotina automática
📂 ARQUIVOS TOCADOS NESTA SESSÃO
Nenhum arquivo de código foi alterado.
Sessão foi 100% de investigação e planejamento.

Arquivos consultados (somente leitura):

app/helpers/Tenant.php v1.0.0
Schema da tabela usuarios
Schema da tabela empresa
Schema da tabela controladores
Schema da tabela usuario_empresa
Schema da tabela _bkp_usuarios_pre_multitenant
docs/prompt_2026-04-29_19-12.txt (retomada do módulo usuários)
🔄 IMPACTOS / DEPENDÊNCIAS
Fase 0
Sem impacto em código produtivo
Só cria docs + corrige typo HTML
Fase 1
Depende de: api/dashboard/snapshot.php v1.0.0 estar funcional em PROD
Impacta: public_html/energia.php (adição de bloco no topo)
Fase 2
Depende de: usuario_controlador criado
Impacta: Tenant.php (novo método), dashboard.php, possivelmente usuarios.php (gestão de vínculos device)
Fase 3
Depende de: Fase 2 concluída e estável
Impacta: criação de página nova, sem mexer em existentes
🔗 IMPACTO NO FIRMWARE
Nenhum impacto previsto nas Fases 0, 1, 2 e 3.

Todas as fases são camada web/cloud. O firmware continua sua rotina normal.

Sinalizar para o CIP Firmware Copilot apenas se:

Decidirmos espelhar a tabela usuario_controlador no firmware (improvável, pois device não precisa saber quem o vê)
Implementarmos no futuro o sync da tabela de limites (já documentado como tema separado)
⚠️ PONTOS DE ATENÇÃO PARA NÃO ESQUECER
🛑 NÃO espelhar PROD em DEV sem inventariar DEV primeiro
🐛 Bug HTML conhecido no energia.php: <d\tiv> (tab no meio da tag) — corrigir na Fase 0
🌙 Ideia do sono do Fernando — ainda não foi compartilhada! Pode mudar a Fase 3 ou abrir Fase 4
📝 Divergência terminológica 'usuario' vs 'viewer' no prompt antigo do usuarios.php — resolver antes de implementar o módulo
📦 Tabela usuario_empresa está vazia mas pronta — semântica de uso ainda não foi definida (vínculo extra além de empresa_id?)
⏰ TELEMETRIA_CICLO_SEGUNDOS: DEV=900s / PROD=60s — não confundir ao validar refresh
🔐 controladores.hmac_secret é texto puro — não expor em nenhum endpoint que vá pro frontend
🇧🇷 Timezone do controlador via CONVERT_TZ usando controladores.timezone, nunca date() direto
🎯 PRÓXIMA AÇÃO (quando retomar)
Ação imediata: Executar Passo 1 do checklist (Inventário DEV vs PROD)

Ordem sugerida:

Abrir phpMyAdmin local (DEV) e remoto (PROD) lado a lado
Rodar SHOW TABLES nos dois e listar diferenças
Comparar SHOW CREATE TABLE das tabelas-chave
Inventariar arquivos via diff de pastas (WinMerge ou similar)
Produzir docs/INVENTARIO_DEV_VS_PROD_2026-XX-XX.md
Decidir caminho de reconciliação
Aí sim, prosseguir com Ação Zero.3 e Fase 0
Tempo estimado do inventário: 30-60 min (depende de quantos arquivos foram tocados em DEV)

📞 PROMPT DE RETOMADA (para próxima sessão)
Quando voltar, abrir nova conversa com o CIP Cloud Copilot e colar:



Estou retomando o projeto CIP a partir do STATUS CLOUD de 2026-06-03.
[colar o arquivo docs/STATUS_CLOUD.md inteiro aqui]

Próxima ação: inventário DEV vs PROD antes da Ação Zero.3 e Fase 0.

Já executei:
- [ ] Comparação de tabelas DEV vs PROD
- [ ] Comparação de arquivos DEV vs PROD
- [ ] Documento de inventário gerado

[opcional] A ideia do sono que mencionei era: ...
📊 RESUMO EXECUTIVO



Métrica	Valor
Fases planejadas	4 (0, 1, 2, 3)
Fases iniciadas	0
Migrations escritas	0
Arquivos modificados	0
Backups feitos	2 de 3 (Zero.1 ✅, Zero.2 ✅, Zero.3 ⛔)
Bloqueador atual	Inventário DEV vs PROD pendente
Risco de retomar sem inventário	🔴 Alto (perder trabalho em DEV)
Fim do STATUS — 2026-06-03




---

## 🎒 Resumo da minha cabeça pra você sair tranquilo

✅ **O que está garantido:**
- Backups de PROD (banco + arquivos) feitos e salvos no seu PC
- Plano arquitetural detalhado em 4 fases
- Disciplina DEV→PROD desenhada
- Descobertas-chave sobre o schema documentadas

⚠️ **O que NÃO foi feito (intencionalmente):**
- Zero.3 (espelhar DEV ← PROD) — você acertou em pausar
- Qualquer alteração de código
- Qualquer migration

🎯 **Quando voltar, primeira ação:**
- Inventariar DEV vs PROD antes de qualquer coisa

🌙 **Ainda devendo:**
- A famosa **ideia do sono** 😄 — guarda anotada num post-it pra próxima sessão

---

Pode sair tranquilo, Fernando. Tudo registrado, nada esquecido, sem nenhuma bomba relógio armada. Bom trabalho nas outras frentes! ☕🚀

Quando voltar, é só colar o `STATUS_CLOUD.md` e a gente retoma exatamente daqui.