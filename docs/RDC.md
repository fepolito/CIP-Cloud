# RDC — Registro de Decisões Cloud
# Projeto CIP Cloud (Aeonium)

**@arquivo:** docs/RDC.md
**@versao:** 1.0.0
**@modificado_em:** 2026-06-08
**@objetivo:** Registro vivo de decisoes tecnicas do projeto CIP Cloud,
              com rastreabilidade (PQRS), status e referencia.
**@autor:** Fernando / CIP Cloud Copilot

---

## Estados possiveis
- 🟡 Proposta    — sugerida, aguarda confirmacao
- 🟢 Confirmada  — aprovada por Fernando
- 🔴 Revogada    — superada por decisao posterior

---

## CIP-DEC-20260607-001
**Titulo:** Doc_root real do subdominio sem public_html/ interno
**Contexto:** Confusao sobre estrutura de pastas em PROD (HostGator).
**Decisao:** Raiz do subdominio = doc_root direto. Nao existe
             public_html/ interno. Toda pasta dentro do doc_root e
             potencialmente publica e exige .htaccess Deny.
**Alternativas:** Mover projeto pra subpasta (descartado: quebra paths).
**Impacto:** Pastas sensiveis (app/, config/, includes/, storage/,
             docs/) DEVEM ter .htaccess Deny from all.
**Riscos:** Esquecer protecao em nova pasta = exposicao publica.
**Owner:** Fernando
**Status:** 🟢 Confirmada (STATUS_CLOUD 2026-06-07)
**Referencia:** docs/PROJETO_CIP_CLOUD.md

---

## CIP-DEC-20260607-002
**Titulo:** Energia por janela calculada via MAX - MIN (timezone-aware)
**Contexto:** Energia em kWh dentro de uma janela temporal.
**Decisao:** Usar MAX(energia) - MIN(energia) no periodo, com
             CONVERT_TZ(timestamp_utc, 'UTC', :tz) onde :tz vem de
             controladores.timezone.
**Alternativas:** SUM de deltas (descartado: erros acumulados).
**Impacto:** Endpoints api/energia/* todos seguem esse padrao.
**Riscos:** Reset de contador no firmware pode causar valor negativo.
**Owner:** Fernando
**Status:** 🟢 Confirmada

---

## CIP-DEC-20260608-001
**Titulo:** Arquivo novo exige cabecalho com @objetivo claro e honesto
**Contexto:** Cabecalhos vagos dificultam manutencao.
**Decisao:** Todo arquivo novo obrigatoriamente leva @arquivo, @versao,
             @modificado_em, @objetivo (1-2 linhas factuais), @autor.
             Edicao = bump semver + atualizar @modificado_em.
**Alternativas:** Cabecalho livre (descartado: inconsistencia).
**Impacto:** Padrao aplicado a 100% dos arquivos PHP novos.
**Riscos:** Atrito leve na criacao (compensado por clareza futura).
**Owner:** Fernando
**Status:** 🟢 Confirmada

---

## CIP-DEC-20260608-002
**Titulo:** Deploy v1.13.0 sera feito via Estrategia B (copia seletiva)
**Contexto:** Auditoria PROD vs LOCAL revelou 25 arquivos so em PROD,
              14 divergentes (incluindo configs criticas), 59 so em
              LOCAL (incluindo dumps SQL e ferramentas dev).
**Decisao:** Deploy NAO sera via git pull. Sera via copia seletiva
             via File Manager do cPanel, arquivo por arquivo, sem
             tocar em configs sensiveis (database.php, app.php,
             php.ini, .htaccess raiz).
**Alternativas:**
  - Estrategia A (git pull no doc_root) — descartada (sobrescreveria
    configs e apagaria arquivos legitimos so em PROD).
**Impacto:** Deploy mais lento, mas zero risco de quebrar PROD.
**Riscos:** Erro humano na copia. Mitigacao: ordem cirurgica
             documentada na Fase 6 do plano.
**Owner:** Fernando
**Status:** 🟢 Confirmada
**Referencia:** docs/AUDITORIA_PROD_2026-06-08.md

---

## CIP-DEC-20260608-003
**Titulo:** Configs de ambiente NUNCA sao sobrescritos em deploy
**Contexto:** Arquivos como config/database.php, config/app.php,
              php.ini e .htaccess raiz tem valores especificos por
              ambiente (PROD vs DEV).
**Decisao:** Esses arquivos permanecem no .gitignore (quando
             contem segredos) ou sao IGNORADOS no deploy (quando
             versionados mas com valores PROD).
**Lista intocavel em deploy:**
  - config/database.php (credenciais PROD)
  - config/app.php (env-specific)
  - config/sync.php (token sensivel)
  - includes/config.php (legado, variaveis globais)
  - php.ini (servidor PROD)
  - .htaccess (raiz, regras HostGator)
**Alternativas:** Versionar com .example (parcialmente adotado).
**Impacto:** Deploy preserva configuracao operacional do PROD.
**Riscos:** Drift de config entre ambientes. Mitigacao: documentar
             diffs intencionais.
**Owner:** Fernando
**Status:** 🟢 Confirmada

---

## CIP-DEC-20260608-004
**Titulo:** Tarefas operacionais de filesystem/scripting = ATGY
**Contexto:** Copilot tentou gerar script PowerShell para auditoria,
              quebrando o protocolo de divisao de responsabilidades.
**Decisao:** Copilot define ESCOPO + entregaveis. ATGY EXECUTA
             (filesystem, scripts, scans, diffs). Copilot consolida
             resultado.
**Alternativas:** Copilot gerar tudo (descartado: viola secao 12).
**Impacto:** Fluxo de trabalho mais limpo e rastreavel.
**Riscos:** Nenhum significativo.
**Owner:** Fernando
**Status:** 🟢 Confirmada
**Referencia:** Prompt v3.1 secao 12 (ATGY protocolo)

---

## CIP-DEC-20260608-005
**Titulo:** Limpeza de seguranca em PROD antes de deploy funcional
**Contexto:** Auditoria identificou arquivos de exposicao/debug
              ativos em PROD (php_info.php, _tree.php, debug_500.php,
              api/config/teste.php, .well-known.zip, app_header_crack.php
              e variantes _old / _2026-04-15 / _crash).
**Decisao:** Remover esses arquivos de PROD ANTES do deploy do
             dashboard v1.13.0.
**Alternativas:** Deixar pra remover junto com deploy (descartado:
                  risco de seguranca ativo).
**Impacto:** PROD limpo, sem vetores de info disclosure.
**Riscos:** Algum arquivo "lixo" pode ser referenciado por codigo
             ativo. Mitigacao: smoke test apos remocao.
**Owner:** Fernando
**Status:** 🟢 Confirmada (limpeza executada em 2026-06-08)

---

## CIP-DEC-20260608-006
**Titulo:** Dumps SQL e ferramentas dev nunca sobem pra PROD
**Contexto:** Working tree local contem _backups/*.sql, tools/*.bat,
              tools/logs/*.log que se acessiveis via HTTP em PROD
              causariam vazamento total do banco.
**Decisao:** .gitignore deve ignorar:
  - /_backups/
  - tools/sync_state.json
  - tools/logs/
  - *.sql.gz, *.zip, *.tar.gz
              Deploy seletivo NUNCA copia essas pastas/arquivos.
**Alternativas:** Manter mas com .htaccess Deny (descartado:
                  defesa em profundidade, nao subir e melhor).
**Impacto:** Risco de vazamento eliminado.
**Riscos:** Esquecer de incluir nova pasta sensivel no gitignore.
**Owner:** Fernando
**Status:** 🟢 Confirmada
**Referencia:** .gitignore (raiz do projeto)

---

## CIP-DEC-20260608-007 (PENDENTE)
**Titulo:** Investigar assets/tema/tema.js — confirmar se e arquivo
            ativo do header refatorado ou lixo
**Contexto:** Arquivo existe em PROD mas nao em LOCAL. Pode ser
              parte do header limpo (legitimo) ou residuo (lixo).
**Decisao:** Pendente — ATGY deve verificar se includes/app_header.php
             ou assets/js/app-shell.js referenciam assets/tema/tema.js.
**Alternativas:** N/A
**Impacto:** Define se arquivo entra no Git ou e removido.
**Riscos:** Remover arquivo ativo quebra o header em PROD.
**Owner:** Fernando
**Status:** 🟡 Proposta (investigacao pendente)

---

## CIP-DEC-20260608-008 (PENDENTE)
**Titulo:** Investigar api/sync/exportar.php — endpoint nao versionado
**Contexto:** Endpoint existe em PROD, sem nenhuma referencia no Git.
**Decisao:** Pendente — ATGY deve baixar conteudo, analisar e decidir:
             (a) versionar se for legitimo, (b) remover se for lixo,
             (c) marcar como deprecated se for legado.
**Alternativas:** N/A
**Impacto:** Endpoint pode estar sendo consumido por outro sistema.
**Riscos:** Remover endpoint ativo quebra integracao externa.
**Owner:** Fernando
**Status:** 🟡 Proposta (investigacao pendente)

---

## CIP-DEC-20260608-009 (PENDENTE)
**Titulo:** Aplicar migration add_potencia_nominal_pico_controladores
**Contexto:** Endpoint api/energia/instantaneo.php precisa das colunas
              potencia_nominal_kw e potencia_pico_90d_kw em controladores.
**Decisao:** Pendente — aplicar via phpMyAdmin no banco aeoniu71_monitor
             apos confirmacao final.
**Alternativas:** Aplicar via Adminer (mesmo resultado).
**Impacto:** Habilita cards de potencia no dashboard. Nao quebra
             nada existente (colunas NULL default).
**Riscos:** Migration nao idempotente — rodar 2x da erro de coluna
             duplicada.
**Owner:** Fernando
**Status:** 🟡 Proposta (aguarda janela de deploy)
**Referencia:** migrations/2026_06_07_add_potencia_nominal_pico_controladores.sql

---

## PROXIMAS DECISOES EM ABERTO
- Implementacao do job de calculo de potencia_pico_90d_kw
- Estrategia de monitoramento de drift entre LOCAL e PROD
- Padronizacao de logs (formato + retencao)
- Implementacao da Fase 2 (Limite Tabela: 24 faixas x 3 perfis)

## CIP-DEC-20260608-007
**Titulo:** Investigar assets/tema/tema.js — confirmar se e arquivo
            ativo do header refatorado ou lixo
**Contexto:** Arquivo existe em PROD mas nao em LOCAL. Pode ser
              parte do header limpo (legitimo) ou residuo (lixo).
**Investigacao (2026-06-08, ATGY):**
  - Nenhuma referencia em includes/app_header.php
  - Nenhuma referencia em includes/app_sidebar.php
  - Nenhuma referencia em assets/js/app-shell.js
  - Nenhuma referencia em assets/js/app-shell_2026-04-15.js (PROD)
  - Nenhuma referencia em .php da raiz ou includes/
  - Nenhuma referencia em .js de assets/
**Decisao:** Arquivo orfao. Reclassificado de CRITICO para IGNORAVEL.
             Acao: remover de PROD na proxima janela de limpeza.
**Alternativas:** Manter por seguranca (descartado: arquivo morto
                  polui o codebase e gera duvida em auditorias futuras).
**Impacto:** Codebase mais limpo. Zero impacto funcional confirmado.
**Riscos:** Carregamento dinamico via banco (improvavel — sem CMS
             ativo no projeto). Mitigacao: smoke test pos-remocao.
**Owner:** Fernando
**Status:** 🟢 Confirmada (encerrada 2026-06-08)



🗄️ RDC — Registros de Decisão
CIP-DEC-20260609-001 — Estratégia de deploy via cPanel Git Version Control
Contexto: PROD em HostGator compartilhado sem SSH; cPanel oferece Git Version Control nativo; repo CIP-Cloud público já existe.
Decisão: Adotar cPanel Git como mecanismo oficial de deploy. Topologia (B1 vs B2) a confirmar.
Alternativas: Manter deploy via FTP/File Manager (descartado — sem rastreabilidade).
Impacto: Deploy passa a ser git push → cPanel pull. Edições diretas em PROD ficam proibidas (exceto hotfix documentado).
Riscos: Repo público — mitigados pela auditoria completa desta sessão.
Owner: Fernando.
Status: 🟡 Proposta (topologia a confirmar).
CIP-DEC-20260609-002 — Repositório CIP-Cloud permanecerá público
Contexto: Repo já público; auditoria confirmou ausência de segredos no HEAD e no histórico.
Decisão: Manter público.
Impacto: Workflow precisa de disciplina permanente — nenhum segredo, dump ou log pode entrar em commits futuros.
Owner: Fernando.
Status: 🟢 Confirmada (tacitamente).
CIP-DEC-20260609-003 — Repo Git a


CIP-DEC-20260609-004  Adicionar config/soliscloud.php e config/sync.php
                      ao --exclude do .cpanel.yml (defesa em profundidade)
                      Status: Proposta (M3)

CIP-DEC-20260609-005  Mover api/config/env.php para fora de api/
                      (pasta pública). Status: Proposta (M3)

CIP-DEC-20260609-008  Origem do commit 9430ded identificada:
                      edição via GitHub Web Interface (provavelmente
                      sugestão Copilot aceita em 09/06/2026 ~21:50 BRT).
                      Conteúdo: refatoração equivocada do .gitignore
                      removendo 93 linhas e inserindo 72 (perda líquida
                      de regras críticas).
                      Mitigação: rebase + force push da versão local
                      higienizada (commit 52e3659).
                      Status: 🟢 RESOLVIDA


15-06-2026


CIP-DEC-20260615-001  Deploy DEV→PROD: Seletor + endpoints resumo
                      Status: 🟢 LIBERADA para deploy
                      Nota: patch aditivo, sem lacuna de endpoint.

CIP-DEC-20260615-002  Migration de potência NÃO é pré-requisito
                      Status: 🟢 Confirmada (instantaneo.php ausente no PROD)

CIP-DEC-20260615-003  Arquitetura: dashboard consome endpoint consolidado
                      /api/dashboard/dados.php (não consome api/energia/*
                      diretamente). resumo_dia/mes destinados a energia.php.
                      Status: 🟡 Proposta (confirmar consumidor real)

## D�vida T�cnica: TEMP-COBERTURA-SOLIS
- **Data**: 2026-07-15
- **Descri��o**: O c�lculo de cobertura de gera��o (porcentagem de dados presentes no dia) est� sendo injetado temporariamente na API de resumo di�rio usando a tag TEMP-COBERTURA-SOLIS. Essa l�gica dever� ser removida e substitu�da assim que a integra��o direta com a API da SolisCloud for ativada.
- **Status**: Pendente de integra��o futura.


## CIP-DEC-20260725-001 — Modelo de curva de limites: histórico versionado
- **Contexto:** tabela_limites tem versao, ativa e ativa_uk (VIRTUAL UNIQUE).
- **Decisão:** múltiplas linhas por controlador, uma ativa garantida pelo banco. Push = desativa-antiga -> insere-nova-ativa em transação. Proibido ON DUPLICATE KEY UPDATE.
- **Impacto:** limites_push.php, LimitesSync.
- **Status:** ✅ Confirmada (schema aplicado DEV+PROD)

## CIP-DEC-20260725-002 — Colunas LWW adicionadas
- **Contexto/Decisão:** atualizado_em, hash_payload, editado_em_local criadas em DEV+PROD.
- **Status:** ✅ Confirmada

## CIP-DEC-20260725-003 — sync_status inclui timeout
- **Contexto/Decisão:** ENUM real: ('sincronizada','pendente_ack','timeout','divergente'). Código de sync deve tratar timeout.
- **Status:** 🟡 Proposta (confirmar ao implementar o watcher de ACK)

## CIP-DEC-20260725-023
- **Contexto/Decisão:** Auth device = reuso X-CIP-Serial + X-CIP-Token (reuso telemetria).
- **Status:** 🟢 Confirmada

## CIP-DEC-20260725-025 (REVOGADA)
- **Contexto/Decisão:** Hash SHA-256, potencias como STRING '0.00' (2 casas).
- **Status:** 🔴 Revogada pela CIP-DEC-20260608-004.

## CIP-DEC-20260725-026
- **Contexto/Decisão:** HTTPS setInsecure() no firmware = risco MITM aceito.
- **Status:** 🟡 Débito (futuro: cert pin)

## CIP-DEC-20260608-002
- **Contexto/Decisão:** Chaves de payload confirmadas: dias_uteis / domingo_feriado / sabado. (antiga RDC-004 obsoleta).
- **Status:** 🟢 Confirmada

## CIP-DEC-20260608-004
- **Contexto/Decisão:** Unidade canônica = Watts inteiros. kW é só apresentação (W/1000). Conversão proibida na fronteira do hash.
- **Status:** 🟢 Confirmada

## CIP-DEC-20260608-005
- **Contexto/Decisão:** normalizarPotencias deixa de usar number_format(...,2) e passa a usar (int) round($v). Canônico = inteiros nus no JSON. Requer espelhamento no firmware (hash sobre int, não string).
- **Status:** 🟢 Confirmada
