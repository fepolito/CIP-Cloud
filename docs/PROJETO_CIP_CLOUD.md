# 📘 PROJETO CIP — CAMADA CLOUD

> **Documento-mestre de contexto técnico.**
> Leitura obrigatória no início de qualquer sessão nova (Claude, ATGY, ou qualquer
> assistente). Este arquivo é a **fonte da verdade** sobre arquitetura, ambiente,
> estrutura de arquivos e schema de banco da camada web/cloud do CIP.

- **Versão do doc:** 1.0
- **Última atualização:** 2026-06-06
- **Owner técnico:** Fernando (Aeonium Energia Sustentável — AER)
- **Agente irmão:** `CIP Firmware Copilot` (camada embarcada ESP32-S3 / C++17 — **fora do escopo deste doc**)

---

## 1. 📛 Identificação do projeto

### O que é o CIP
O **Controlador de Injeção de Potência (CIP)** é um dispositivo eletrônico
industrial desenvolvido pela **Aeonium Energia Sustentável (AER)** que controla
**quanto de energia um inversor solar injeta na rede da concessionária**.

### Função principal
Proteger o cliente de exportar energia indesejada via dois modos:

| Modo | Comportamento |
|---|---|
| 🔴 **Grid Zero** | Exportação = 0. CIP corta excedente em tempo real. |
| 🟡 **Limite Tabela** | Exportação até limite horário configurável (24 faixas × 3 perfis de dia). |

### Camada Cloud (escopo deste doc)
Dashboard web multi-tenant que **espelha** o estado do firmware:
- Monitoramento (energia, qualidade elétrica)
- Configuração remota (tabela de limites)
- Gestão multi-tenant (empresas, usuários, controladores)
- Auditoria (logs, histórico de comandos)

> ⚠️ **Regra de ouro:** o cloud é **espelho + interface remota** do firmware.
> O **firmware é a fonte da verdade operacional**. Cloud NUNCA comanda inversor
> diretamente — sempre passa pelo fluxo de sync com versionamento + ACK.

---

## 2. 🏗️ Ambientes

### 2.1 Desenvolvimento local (Laragon — Windows)

| Item | Valor |
|---|---|
| **Doc root** | `C:\laragon\www\monitor.aeonium.com.br\` |
| **URL local** | `http://monitor.aeonium.com.br.test/` |
| **Servidor web** | Apache (Laragon) |
| **PHP** | 8.3+ |
| **Banco** | MySQL/MariaDB local (Laragon) |
| **Backup do banco** | `_backups/aeoniu71_monitor.sql(.gz)` |

### 2.2 Produção (HostGator — hospedagem compartilhada)

| Item | Valor |
|---|---|
| **Doc root** | `/home1/aeoniu71/monitor.aeonium.com.br/` |
| **URL pública** | `https://monitor.aeonium.com.br/` |
| **Servidor web** | Apache (cPanel) |
| **PHP** | 8.3+ (gerenciado via cPanel) |
| **Banco** | MySQL (cPanel — `aeoniu71_monitor`) |
| **Acesso** | cPanel + File Manager + phpMyAdmin (**SEM SSH**) |
| **Deploy** | FTP / File Manager via cPanel (manual) |

> ⚠️ Na HostGator compartilhada, **a própria pasta do domínio É a doc root**.
> NÃO existe pasta `public_html/` interna do projeto.
> **Tudo dentro de `monitor.aeonium.com.br/` é potencialmente público via HTTP.**
> Diretórios sensíveis (`config/`, `app/`, `storage/`) precisam ser protegidos
> via `.htaccess` ou por arquivos que apenas fazem `require` (sem rotina executável).

### 2.3 Fluxo de trabalho

```
[ DEV LOCAL ]                         [ PRODUÇÃO ]
Laragon                               HostGator
   │                                     │
   ▼                                     ▼
Codificar + testar  ─── FTP/cPanel ──▶  Replicar arquivos
   │                                     │
   ▼                                     ▼
phpMyAdmin local    ─── SQL manual ──▶  phpMyAdmin prod
```

**Regra:** nada vai pra prod sem passar por validação local primeiro.

---

## 3. 🛠️ Stack técnica

| Camada | Tecnologia |
|---|---|
| **Backend** | PHP 8.3+ (`declare(strict_types=1)`, PSR-12) |
| **Banco** | MySQL 5.7+ / MariaDB (UTF-8 mb4, timestamps em UTC) |
| **Frontend** | JS vanilla ES6+ (sem frameworks) |
| **Gráficos** | ApexCharts 3.44.0 (via CDN) |
| **CSS** | Variáveis CSS, tema escuro fixo (token `data-tema`) |
| **Auth** | Sessão PHP nativa (não JWT) |
| **Multi-tenant** | Helper `app/helpers/Tenant.php` |
| **Integração firmware** | HMAC-SHA256 (segredo em `controladores.hmac_secret`) |
| **Integração externa** | SolisCloud API V2 (HMAC-SHA1 + Base64) — **planejado** |

---

## 4. 📂 Estrutura de arquivos

Lembrar que o doc_root é a raiz do projeto (não public_html)

### 4.1 Ambiente DEV (Laragon — `C:\laragon\www\monitor.aeonium.com.br\`)

```
monitor.aeonium.com.br/
├── 500.shtml                            # Página de erro 500
├── _backups/                            # ⚠️ APENAS LOCAL — nunca subir pra prod
│   ├── aeoniu71_monitor.sql
│   └── aeoniu71_monitor.sql.gz
├── _tree.php                            # Utilitário temporário (apagar quando não usar)
│
├── api/                                 # 🔌 API REST interna
│   ├── index.php                        # Entry point / roteador da API
│   ├── error_log                        # Log de erros PHP da API
│   │
│   ├── auth/                            # Autenticação
│   │   ├── login.php
│   │   ├── logout.php
│   │   ├── session.php
│   │   └── verify.php
│   │
│   ├── config/                          # Config específica da API
│   │   ├── database.php
│   │   └── env.php
│   │
│   ├── controllers/                     # Controllers (estilo MVC light)
│   │   ├── ControladorController.php
│   │   └── LeituraController.php
│   │
│   ├── dashboard/                       # Endpoints do dashboard
│   │   ├── dados.php                    # Snapshot de qualidade elétrica
│   │   └── snapshot.php
│   │
│   ├── energia/                         # Endpoints de energia (página energia.php)
│   │   ├── ano.php                      # Modo ANO  (kWh mensais)
│   │   ├── anos.php                     # Modo TOTAL (kWh anuais)
│   │   ├── controladores.php            # Lista de controladores do tenant
│   │   ├── dia.php                      # Modo DIA  (buckets 5min)
│   │   ├── mes.php                      # Modo MÊS  (kWh diários)
│   │   ├── resumo_dia.php               # 🆕 F1.A — Resumo agregado do dia
│   │   ├── resumo_mes.php               # 🆕 F1.A — Resumo agregado do mês
│   │   └── error_log
│   │
│   ├── helpers/                         # Helpers da API
│   │   ├── response.php
│   │   └── teste-tenant.php
│   │
│   ├── middleware/                      # Middleware (auth, etc.)
│   │   └── auth.php
│   │
│   ├── utils/                           # Utilitários
│   │   └── gerar_hash.php
│   │
│   ├── v1/                              # Versionamento futuro
│   │   └── telemetria/
│   │
│   └── preferencias-tema.php
│
├── app/                                 # 🧠 Núcleo da aplicação
│   ├── auth.php                         # Funções de autenticação
│   ├── helpers.php                      # Helpers globais
│   ├── security.php                     # Funções de segurança
│   │
│   ├── config/
│   │   └── Constantes.php
│   │
│   ├── helpers/
│   │   └── Tenant.php                   # ⚠️ Multi-tenant — escopo de controladores
│   │
│   ├── services/                        # Serviços (lógica de negócio)
│   │   ├── Database.php                 # ⚠️ NÃO usar — usar getDbConnection()
│   │   ├── HmacAuth.php                 # Auth HMAC firmware↔cloud
│   │   ├── Logger.php
│   │   ├── UsuarioConviteService.php
│   │   └── UsuarioPermissaoService.php
│   │
│   └── views/
│       └── partials/
│
├── assets/                              # 🎨 Estáticos (CSS / JS / IMG)
│   ├── css/
│   │   ├── app.css                      # Global
│   │   ├── controladores.css
│   │   ├── dashboard.css
│   │   ├── empresa.css
│   │   ├── header.css
│   │   ├── login.css
│   │   └── usuarios.css
│   ├── favicon/
│   ├── img/
│   │   ├── integradores/
│   │   ├── system/
│   │   └── logo-aeonium.png
│   └── js/
│       ├── app-shell.js                 # Bootstrap do front
│       ├── tema.js                      # Toggle tema (atualmente fixo escuro)
│       └── upload-logo.js
│
├── backup/                              # ⚠️ Arquivos legados (limpar)
│   ├── energia.php
│   ├── mes.php
│   └── error_log
│
├── config/                              # ⚙️ Config global da aplicação
│   ├── app.php                          # Constantes globais
│   ├── database.php                     # Conexão PDO (factory getDbConnection)
│   ├── database.example.php
│   ├── sync.php                         # Config de sync com firmware
│   └── sync.example.php
│
├── database/                            # 🗄️ Migrations SQL
│   └── migrations/
│       ├── 2026_06_02_add_controle_exportacao_controladores.sql
│       └── rollback/
│
├── docs/                                # 📚 Documentação
│   ├── PROJETO_CIP_CLOUD.md             # 👈 Este arquivo
│   ├── CONTRATO_API.md                  # Contrato firmware↔cloud
│   ├── CONTRATO_DASHBOARD_V2.md
│   ├── HISTORICO_IMPLEMENTACOES.md
│   ├── database_inicial.sql
│   └── STATUS CLOUD — *.md              # Status por sessão
│
├── includes/                            # 🧩 Includes PHP (layout)
│   ├── app_head.php                     # <head> global
│   ├── app_header.php                   # Header/topbar
│   ├── app_sidebar.php                  # Sidebar de navegação
│   ├── config.php                       # Carregamento de sessão + auth
│   └── usuarios_rules.php
│
├── storage/                             # 💾 Dados de runtime (gravável)
│   ├── backups/
│   │   └── pre_migration_2026-06-03_001.sql
│   ├── logs/
│   │   └── app.log
│   ├── rate_limit/
│   └── sessions/
│
├── tools/                               # 🔧 Ferramentas de manutenção (dev)
│   ├── README.md
│   ├── fix_timestamp_utc.php
│   ├── sync.bat                         # Sync local → prod (Windows)
│   ├── sync_auto.bat
│   ├── sync_puxar.php                   # Puxa dados de prod pra dev
│   ├── sync_config.php
│   ├── sync_state.json
│   ├── teste_tenant_carga.php
│   ├── teste_tenant_listar.php
│   ├── teste_tenant_path.php
│   └── logs/
│       └── sync_*.log
│
├── uploads/                             # 📤 Uploads (logos de empresas)
│   └── logo_*.png|jpg
│
├── ────── PÁGINAS PÚBLICAS (raiz) ──────
├── index.php                            # Landing / redirect
├── login.php                            # Tela de login
├── logout.php
├── dashboard.php                        # Dashboard principal (qualidade elétrica)
├── energia.php                          # Monitoramento de energia (DIA/MÊS/ANO/TOTAL)
├── controladores.php                    # Gestão de controladores
├── dispositivos.php
├── empresas.php                         # Gestão de empresas (multi-tenant)
├── usuarios.php                         # Gestão de usuários
├── geracao.php
├── historico.php
├── limites_potencia.php                 # 🚧 Tabela de limites (em desenvolvimento)
├── projeto_admin.php
├── tarifas.php                          # 🚧 Planejado (custo de energia)
│
├── ────── CONFIG / META ──────
├── changelog.md
├── php.ini                              # Config PHP local
└── limpar_repo.ps1                      # Script PowerShell de limpeza
```

### 4.2 Ambiente PROD (HostGator — `/home1/aeoniu71/monitor.aeonium.com.br/`)

Estrutura **espelha o DEV** com as seguintes diferenças naturais:

- ✅ **Existe em ambos:** núcleo da aplicação (api/, app/, assets/, includes/, config/, storage/, uploads/, docs/, páginas raiz)
- 🔄 **Sincronizado via FTP/cPanel** após validação em DEV
- 🚫 **Apenas em DEV:** `_backups/`, `database/migrations/`, `tools/` (manutenção local)
- 🚫 **NÃO replicar pra prod:** arquivos `*_old_*.php`, `_tree.php`, `_backups/`

> 📌 **Regra de sincronização:** quando um endpoint/página é validado em DEV,
> a cópia pra prod é manual via FTP. Manter o `changelog.md` atualizado em cada
> sync.

---

## 5. 🗄️ Banco de dados

### 5.1 Identificação

| Item | DEV | PROD |
|---|---|---|
| **Database name** | `aeoniu71_monitor` | `aeoniu71_monitor` |
| **Charset / Collation** | `utf8mb4` / `utf8mb4_unicode_ci` |
| **Engine** | InnoDB |
| **Timezone armazenado** | UTC (sempre) |

### 5.2 Tabelas ativas

| Tabela | Função |
|---|---|
| `controladores` | Devices CIP cadastrados (com timezone, status, controle) |
| `telemetria_5min` | Leituras agregadas a cada 5 min (schema v2) |
| `leituras_energia` | ⚠️ Legado (a verificar se ainda é usado) |
| `empresa` | Empresas (tenants) |
| `usuarios` | Usuários do sistema |
| `usuario_empresa` | Vínculo N:N usuário ↔ empresa (multi-tenant) |
| `comandos_controle` | Comandos enviados ao firmware (sync bidirecional) |
| `sync_controle` | Auditoria de sincronização firmware↔cloud |
| `logs_sistema` | Auditoria geral do sistema |

### Todas as tabelas 

Tabela:  controladores:

id (int)
codigo (varchar(20))
apelido (varchar(100))
tipo (varchar(80))
local_instalacao (varchar(200), nullable)
timezone (varchar(50))
ip_address (varchar(45), nullable)
porta (int, nullable)
empresa_id (int unsigned, nullable)
cliente_nome (varchar(150), nullable)
token_api_hash (varchar(255))
hmac_secret (varchar(128), nullable)
status (enum('ativo','inativo','manutencao','erro'), nullable)
fw_version (varchar(20), nullable)
last_seen_at (timestamp, nullable)
last_telemetry_at (timestamp, nullable)
observacoes (text, nullable)
created_at (timestamp)
updated_at (timestamp)
online (tinyint(1), nullable)
controle_exportacao_ativo (tinyint(1))
modo_controle (enum('grid_zero','limite_tabela','desativado'))
potencia_nominal_kw (decimal(7,3), nullable)
potencia_pico_90d_kw (decimal(7,3), nullable)
controle_versao (int unsigned)
controle_atualizado_em (datetime, nullable)
controle_origem (enum('local','cloud','app','firmware_boot'), nullable)
ultimo_contato (datetime, nullable)

Tabela: telemetria_5min:

id (bigint unsigned)
controlador_id (int)
timestamp_utc (timestamp)
potencia_importada_w (decimal(10,2), nullable)
potencia_exportada_w (decimal(10,2), nullable)
potencia_geracao_w (decimal(10,2), nullable)
potencia_consumo_total_w (decimal(10,2), nullable)
energia_importada_kwh (decimal(12,4), nullable)
energia_exportada_kwh (decimal(12,4), nullable)
energia_geracao_kwh (decimal(12,4), nullable)
energia_ativa_total_kwh (decimal(12,4), nullable)
energia_reativa_total_kvarh (decimal(12,4), nullable)
energia_reativa_import_kvarh (decimal(12,4), nullable)
energia_reativa_export_kvarh (decimal(12,4), nullable)
is_exporting (tinyint(1), nullable)
direction_valid (tinyint(1), nullable)
power_injected_w (decimal(10,2), nullable)
meter_read_errors (int unsigned, nullable)
meter_retry_recoveries (int unsigned, nullable)
meter_optional_failures (int unsigned, nullable)
inversor_read_errors (int unsigned, nullable)
geracao_origem (enum('inversor','estimado','api_externa','indisponivel'), nullable)
qualidade_dado (tinyint unsigned, nullable)
firmware_versao (varchar(20), nullable)
limite_exportacao_ativo_w (decimal(10,2), nullable)
status_inversor (varchar(50), nullable)
tensao_rede_v (decimal(6,2), nullable)
tensao_fase_a_v (decimal(6,2), nullable)
tensao_fase_b_v (decimal(6,2), nullable)
tensao_fase_c_v (decimal(6,2), nullable)
corrente_fase_a_a (decimal(8,3), nullable)
corrente_fase_b_a (decimal(8,3), nullable)
corrente_fase_c_a (decimal(8,3), nullable)
potencia_ativa_fase_a_w (decimal(10,2), nullable)
potencia_ativa_fase_b_w (decimal(10,2), nullable)
potencia_ativa_fase_c_w (decimal(10,2), nullable)
potencia_ativa_total_w (decimal(10,2), nullable)
potencia_reativa_fase_a_var (decimal(10,2), nullable)
potencia_reativa_fase_b_var (decimal(10,2), nullable)
potencia_reativa_fase_c_var (decimal(10,2), nullable)
potencia_reativa_total_var (decimal(10,2), nullable)
potencia_aparente_fase_a_va (decimal(10,2), nullable)
potencia_aparente_fase_b_va (decimal(10,2), nullable)
potencia_aparente_fase_c_va (decimal(10,2), nullable)
potencia_aparente_total_va (decimal(10,2), nullable)
fator_potencia_fase_a (decimal(4,3), nullable)
fator_potencia_fase_b (decimal(4,3), nullable)
fator_potencia_fase_c (decimal(4,3), nullable)
frequencia_rede_hz (decimal(5,2), nullable)
frequencia_fase_a_hz (decimal(5,2), nullable)
frequencia_fase_b_hz (decimal(5,2), nullable)
frequencia_fase_c_hz (decimal(5,2), nullable)
fator_potencia_total (decimal(4,3), nullable)
temperatura_inversor_c (decimal(5,2), nullable)



Tabela: leituras_energia
  - id (bigint unsigned)
  - controlador_id (int)
  - tipo_leitura (enum('consumo_local','importacao','geracao','injecao'))
  - fase (enum('monofasico','bifasico','trifasico'))
  - tensao_v (decimal(10,2))
  - corrente_a (decimal(10,2))
  - potencia_kw (decimal(12,3))
  - energia_kwh (decimal(14,3))
  - frequencia_hz (decimal(10,2))
  - fator_potencia (decimal(6,3))
  - timestamp_medicao (datetime)
  - criado_em (datetime)

Tabela: empresa
  - id (int unsigned)
  - nome_fantasia (varchar(120))
  - tipo (enum('cliente_real','integradora_virtual','parceiro','demo'))
  - ativo (tinyint(1))
  - observacoes (text)
  - razao_social (varchar(200))
  - cnpj (varchar(18))
  - email (varchar(150))
  - telefone (varchar(20))
  - endereco (text)
  - logo_path (varchar(255))
  - logo_updated_at (timestamp)
  - created_at (timestamp)
  - updated_at (timestamp)
  - deleted_at (datetime)
  - deleted_by (int unsigned)

Tabela: usuarios
  - id (int unsigned)
  - nome (varchar(120))
  - email (varchar(150))
  - senha_hash (varchar(255))
  - perfil (enum('master','master_operador','administrador','operador','usuario'))
  - tema (enum('claro','escuro'))
  - modo_visualizacao (enum('tudo','empresa','controlador'))
  - empresa_selecionada_id (int unsigned)
  - controlador_selecionado_id (int)
  - papel_global (enum('master','master_operador'))
  - empresa_id (int unsigned)
  - ativo (tinyint(1))
  - criado_por (int unsigned)
  - criado_em (datetime)
  - atualizado_em (datetime)
  - deleted_at (datetime)
  - deleted_by (int unsigned)

Tabela: usuario_empresa
  - id (int unsigned)
  - usuario_id (int unsigned)
  - empresa_id (int unsigned)
  - papel_empresa (enum('administrador','operador','usuario'))
  - ativo (tinyint(1))
  - criado_em (datetime)
  - criado_por (int unsigned)
  - atualizado_em (datetime)
  - deleted_at (datetime)
  - deleted_by (int unsigned)

Tabela: comandos_controle
  - id (bigint unsigned)
  - controlador_id (int)
  - usuario_id (int unsigned)
  - comando (varchar(100))
  - parametros (json)
  - status_execucao (enum('pendente','enviado','executado','falhou','cancelado'))
  - resposta_equipamento (text)
  - criado_em (datetime)
  - executado_em (datetime)

Tabela: logs_sistema
  - id (bigint unsigned)
  - nivel (enum('INFO','WARN','ERROR','DEBUG'))
  - controlador_id (int)
  - usuario_id (int unsigned)
  - ip_origem (varchar(45))
  - origem (varchar(100))
  - mensagem (text)
  - contexto (json)
  - criado_em (datetime)

Tabela: sync_controle
  - tabela (varchar(64))
  - modo_incremental (enum('por_id','por_timestamp'))
  - ultimo_id (bigint unsigned)
  - ultimo_timestamp (datetime)
  - registros_total (bigint unsigned)
  - ultima_execucao (datetime)
  - ultimo_status (enum('ok','erro','rodando'))
  - ultima_mensagem (varchar(500))
  - duracao_ms (int unsigned)
  - atualizado_em (timestamp)

[Tabelas de Backup do dia 16/05/2026]
Tabela: _bkp_empresa_20260516
Tabela: _bkp_usuarios_20260516
Tabela: _bkp_usuarios_pre_multitenant




### 5.3 Tabelas legadas (ignorar)

| Tabela | Status |
|---|---|
| `_bkp_controladores_20260516` | Backup — não usar |
| `_bkp_empresa_20260516` | Backup — não usar |
| `_bkp_usuarios_20260516` | Backup — não usar |
| `_bkp_usuarios_pre_multitenant` | Backup — não usar |

### 5.4 Tabela `controladores` (CONFIRMADO)

> Devices CIP cadastrados. Cada linha = 1 dispositivo físico instalado.

| Coluna | Tipo | Uso |
|---|---|---|
| `id` | int PK | Identificador interno |
| `codigo` | varchar(20) UNIQUE | Código do dispositivo (ex: CIP-001) |
| `apelido` | varchar(100) | Nome amigável |
| `tipo` | varchar(80) | Default `CIP-ESP32S3` |
| `local_instalacao` | varchar(200) | Localização física |
| `timezone` | varchar(50) | **CRÍTICO** — IANA tz (ex: `America/Sao_Paulo`). Usar em todo `CONVERT_TZ` |
| `ip_address` | varchar(45) | IP do dispositivo |
| `porta` | int | Porta de comunicação |
| `empresa_id` | int unsigned FK | Tenant proprietário |
| `cliente_nome` | varchar(150) | Nome do cliente final |
| `token_api_hash` | varchar(255) UNIQUE | Hash do token de API |
| `hmac_secret` | varchar(128) | ⚠️ Segredo HMAC-SHA256 compartilhado com firmware |
| `status` | enum | `ativo` / `inativo` / `manutencao` / `erro` |
| `fw_version` | varchar(20) | Versão do firmware reportada |
| `last_seen_at` | timestamp | Último contato |
| `last_telemetry_at` | timestamp | Última telemetria recebida |
| `online` | tinyint(1) | Flag binária online/offline |
| `controle_exportacao_ativo` | tinyint(1) | 1 = controle ativo |
| `modo_controle` | enum | `grid_zero` / `limite_tabela` / `desativado` |
| `controle_versao` | int unsigned | **Versão incremental** (sync firmware↔cloud) |
| `controle_atualizado_em` | datetime | Timestamp UTC da última alteração |
| `controle_origem` | enum | `local` / `cloud` / `app` / `firmware_boot` |
| `ultimo_contato` | datetime | Sinônimo de last_seen_at (a consolidar?) |
| `observacoes` | text | Notas |
| `created_at` / `updated_at` | timestamp | Auditoria |

### 5.5 Tabela `telemetria_5min` (CONFIRMADO)

> Leituras de telemetria agregadas a cada 5 minutos. **288 registros/dia
> esperados** (24h × 12 buckets).

**PK:** `id` · **UK:** `(controlador_id, timestamp_utc)` · **FK:** `controlador_id → controladores.id` (CASCADE)

#### 🟢 Família ENERGIA (kWh acumulado, monotônico)
| Coluna | Tipo | Unidade |
|---|---|---|
| `energia_importada_kwh` | decimal(12,4) | kWh |
| `energia_exportada_kwh` | decimal(12,4) | kWh |
| `energia_geracao_kwh` | decimal(12,4) | kWh |
| `energia_ativa_total_kwh` | decimal(12,4) | kWh |
| `energia_reativa_total_kvarh` | decimal(12,4) | kvarh |
| `energia_reativa_import_kvarh` | decimal(12,4) | kvarh |
| `energia_reativa_export_kvarh` | decimal(12,4) | kvarh |

> 💡 **Cálculo do dia:** `MAX(energia_*_kwh) - MIN(energia_*_kwh)` no intervalo.

#### 🟡 Família POTÊNCIA (W instantâneo)
| Coluna | Tipo | Unidade |
|---|---|---|
| `potencia_importada_w` | decimal(10,2) | W |
| `potencia_exportada_w` | decimal(10,2) | W |
| `potencia_geracao_w` | decimal(10,2) | W |
| `potencia_consumo_total_w` | decimal(10,2) | W |
| `potencia_ativa_total_w` | decimal(10,2) | W |
| `potencia_ativa_fase_[a/b/c]_w` | decimal(10,2) | W |
| `potencia_reativa_total_var` | decimal(10,2) | var |
| `potencia_reativa_fase_[a/b/c]_var` | decimal(10,2) | var |
| `potencia_aparente_total_va` | decimal(10,2) | VA |
| `potencia_aparente_fase_[a/b/c]_va` | decimal(10,2) | VA |
| `power_injected_w` | decimal(10,2) | W |
| `limite_exportacao_ativo_w` | decimal(10,2) | W |

#### 🔵 Família QUALIDADE ELÉTRICA
| Coluna | Tipo | Unidade |
|---|---|---|
| `tensao_rede_v` | decimal(6,2) | V |
| `tensao_fase_[a/b/c]_v` | decimal(6,2) | V |
| `corrente_fase_[a/b/c]_a` | decimal(8,3) | A |
| `fator_potencia_total` | decimal(4,3) | -1..1 |
| `fator_potencia_fase_[a/b/c]` | decimal(4,3) | 0..1 |
| `frequencia_rede_hz` | decimal(5,2) | Hz |
| `frequencia_fase_[a/b/c]_hz` | decimal(5,2) | Hz |
| `temperatura_inversor_c` | decimal(5,2) | °C |

#### 🟣 Família DIREÇÃO / CONTROLE
| Coluna | Tipo |
|---|---|
| `is_exporting` | tinyint(1) |
| `direction_valid` | tinyint(1) |

#### ⚫ Família QUALIDADE DO DADO / DIAGNÓSTICO
| Coluna | Tipo |
|---|---|
| `qualidade_dado` | tinyint unsigned (0-100?) |
| `geracao_origem` | enum(`inversor`, `estimado`, `api_externa`, `indisponivel`) |
| `firmware_versao` | varchar(20) |
| `status_inversor` | varchar(50) |
| `meter_read_errors` | int unsigned |
| `meter_retry_recoveries` | int unsigned |
| `meter_optional_failures` | int unsigned |
| `inversor_read_errors` | int unsigned |

#### Índices
- `uk_controlador_timestamp` (controlador_id, timestamp_utc) — UNIQUE
- `timestamp_utc`
- `idx_controlador_ts_desc` (controlador_id, timestamp_utc)
- `idx_qualidade` (qualidade_dado, timestamp_utc)

### 5.6 Outras tabelas (placeholders — completar com `SHOW CREATE TABLE`)

#### `empresa` — Empresas (tenants)
<!-- TODO: confirmar colunas com SHOW CREATE TABLE -->
Colunas esperadas: `id`, `nome`, `cnpj`, `logo`, timestamps.

#### `usuarios` — Usuários
<!-- TODO: confirmar colunas com SHOW CREATE TABLE -->
Colunas esperadas: `id`, `nome`, `email`, `senha_hash`, `tipo`, timestamps.

#### `usuario_empresa` — Vínculo N:N
<!-- TODO: confirmar colunas com SHOW CREATE TABLE -->
Colunas esperadas: `usuario_id`, `empresa_id`, `papel`, timestamps.

#### `comandos_controle` — Comandos firmware
<!-- TODO: confirmar colunas com SHOW CREATE TABLE -->
Fila de comandos pendentes a serem aplicados pelo firmware.

#### `sync_controle` — Auditoria de sync
<!-- TODO: confirmar colunas com SHOW CREATE TABLE -->
Histórico de sincronizações + ACKs entre cloud e firmware.

#### `logs_sistema` — Auditoria geral
<!-- TODO: confirmar colunas com SHOW CREATE TABLE -->
Logs de ações sensíveis (alterações, login, etc.).

#### `leituras_energia` — Legado (?)
<!-- TODO: verificar se ainda é usada ou se foi substituída por telemetria_5min -->

---

## 6. 🔌 API REST — Padrão arquitetural

### 6.1 Convenção de roteamento
- Acesso direto ao arquivo `.php` (sem router central por enquanto)
- Ex: `GET /api/energia/dia.php?controlador_id=3&data=2026-06-06`

### 6.2 Bootstrap padrão (template obrigatório)

```php
<?php
declare(strict_types=1);

$is_dev = ($_SERVER['SERVER_NAME'] ?? '') === 'localhost'
       || str_contains($_SERVER['HTTP_HOST'] ?? '', '.local')
       || str_contains($_SERVER['HTTP_HOST'] ?? '', '.test');

ini_set('display_errors', $is_dev ? '1' : '0');
ini_set('display_startup_errors', $is_dev ? '1' : '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
```

### 6.3 Conexão PDO
- ✅ **USAR:** `getDbConnection()` (factory em `config/database.php`)
- ❌ **NÃO usar:** `Database::getInstance()` (legado em `app/services/Database.php`)

### 6.4 Formato de resposta padronizado

**Sucesso:**
```json
{
  "sucesso": true,
  "data": "...",
  "...campos específicos do endpoint...": "..."
}
```

**Erro:**
```json
{
  "erro": "mensagem clara",
  "detalhe": "stack trace (APENAS em $is_dev)"
}
```
Com `http_response_code(400|404|500)` apropriado.

### 6.5 Catch obrigatório

```php
} catch (PDOException $e) {
    error_log('[arquivo.php] PDO error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'erro'    => 'Erro interno no banco de dados.',
        'detalhe' => $is_dev ? $e->getMessage() : null,
    ]);
    exit;
} catch (Throwable $e) {
    error_log('[arquivo.php] Erro: ' . $e->getMessage() . ' em ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode([
        'erro'    => 'Erro interno do servidor.',
        'detalhe' => $is_dev ? $e->getMessage() : null,
    ]);
    exit;
}
```

### 6.6 Regras de timezone (CRÍTICO)
- Tudo armazenado em **UTC** (`timestamp_utc`)
- SEMPRE usar `CONVERT_TZ(timestamp_utc, 'UTC', :tz)` com `:tz` vindo de `controladores.timezone`
- NUNCA usar `date()` / `time()` direto pra dados de controlador

### 6.7 Endpoints atuais (mapa)

| Endpoint | Função |
|---|---|
| `api/auth/login.php` | Login (POST) |
| `api/auth/logout.php` | Logout |
| `api/auth/session.php` | Estado da sessão atual |
| `api/auth/verify.php` | Verificação de auth |
| `api/dashboard/dados.php` | Snapshot de qualidade elétrica (dashboard.php) |
| `api/dashboard/snapshot.php` | (em DEV) — snapshot complementar |
| `api/energia/dia.php` | Energia modo DIA (buckets 5min) |
| `api/energia/mes.php` | Energia modo MÊS (kWh diários) |
| `api/energia/ano.php` | Energia modo ANO (kWh mensais) |
| `api/energia/anos.php` | Energia modo TOTAL (kWh anuais) |
| `api/energia/controladores.php` | Lista de controladores do tenant |
| `api/energia/resumo_dia.php` | 🆕 F1.A — Resumo agregado do dia |
| `api/energia/resumo_mes.php` | 🆕 F1.A — Resumo agregado do mês |
| `api/preferencias-tema.php` | Preferência de tema do usuário |
| `api/sync/exportar.php` | (apenas prod por ora) — exportação de dados |

---

## 7. 🎨 Frontend — Padrão

### 7.1 Layout global
- **Head:** `includes/app_head.php` (meta, CSS, favicon)
- **Header/topbar:** `includes/app_header.php`
- **Sidebar:** `includes/app_sidebar.php`
- **Bootstrap session/auth:** `includes/config.php`

### 7.2 Tema
- **Atualmente:** tema escuro fixo
- Toggle previsto via `data-tema` no `<html>` (variáveis CSS já preparadas)

### 7.3 ApexCharts
- Versão: **3.44.0** (via CDN)
- Padrão de uso: ver `energia.php` como referência

### 7.4 JS
- Vanilla ES6+ (sem frameworks)
- `const`/`let` sempre, nunca `var`
- Sem `eval`, sem dependências npm

---

## 8. 🔐 Multi-tenant + Auth

### 8.1 Modelo
- **Tenant** = `empresa`
- **Usuário** pertence a 1+ empresas via `usuario_empresa`
- **Controlador** pertence a 1 empresa via `controladores.empresa_id`

### 8.2 Helper de escopo
- `app/helpers/Tenant.php` aplica filtros automáticos por empresa nas queries
- ⚠️ **OBRIGATÓRIO** usar em qualquer query que liste controladores/leituras

### 8.3 Sessão
- PHP nativo (`$_SESSION`)
- Armazenamento em `storage/sessions/`
- NÃO usa JWT

### 8.4 Auth do firmware
- HMAC-SHA256 com segredo em `controladores.hmac_secret`
- Implementado em `app/services/HmacAuth.php`

---

## 9. 🔄 Sync firmware ↔ cloud

### 9.1 Princípios
1. **Firmware é fonte da verdade operacional** (comanda o inversor de fato)
2. **Cloud é espelho** + interface remota
3. **Toda alteração tem versão** (`controle_versao` incremental)
4. **Toda alteração tem origem** (`local` / `cloud` / `app` / `firmware_boot`)
5. **Confirmação bidirecional** via ACK

### 9.2 Infraestrutura existente
- `controladores.controle_versao` — versionamento
- `controladores.controle_origem` — quem alterou
- `controladores.controle_atualizado_em` — quando
- `comandos_controle` — fila de comandos pendentes
- `sync_controle` — auditoria de sincronizações + ACKs

### 9.3 Fluxo recomendado

```
[Cloud altera estado]
    │
    ▼
[Backend valida + versao+1 + status='pendente_ack']
    │
    ▼
[Comando inserido em comandos_controle]
    │
    ▼
[Firmware busca/recebe comando + valida + aplica]
    │
    ▼
[Firmware responde ACK com versao recebida]
    │
    ▼
[sync_controle registra ACK + status='aplicada']
```

### 9.4 Documento detalhado
Ver `docs/CONTRATO_API.md` para o contrato completo.

---

## 10. 🚫 O que NÃO existe ainda (TODOs futuros)

| Item | Status |
|---|---|
| Tabela de limites de potência (24h × 3 perfis) | 🚧 Em planejamento (`limites_potencia.php` existe vazio) |
| Integração SolisCloud API V2 | 🔮 Planejada (fallback quando firmware offline) |
| Gestão de tarifas / custos | 🔮 Planejada (`tarifas.php` existe vazio) |
| Bateria (campos no banco) | ❌ Não existe (futuro) |
| Notificações por e-mail / push | ❌ Não existe |
| Toggle de tema funcional (claro/escuro) | 🚧 Estrutura pronta, lógica pendente |
| API versionada (`v1/`) | 🚧 Diretório criado, sem endpoints ainda |

---

## 11. 🗺️ Roadmap atual

### Fase 1 — Dashboard funcional
- ✅ Login + multi-tenant
- ✅ Página `energia.php` (DIA/MÊS/ANO/TOTAL)
- ✅ Página `dashboard.php` (qualidade elétrica)
- 🔄 **F1.A em curso** — Resumos agregados (`resumo_dia.php` + `resumo_mes.php`)

### Fase 2 — Controle remoto
- 🔜 Tabela de limites de potência (CRUD + sync firmware)
- 🔜 Toggle Grid Zero / Limite Tabela / Desativado

### Fase 3 — Integrações externas
- 🔜 SolisCloud API V2 (fallback)
- 🔜 Tarifas + custo estimado

---

## 12. 📚 Documentos relacionados

| Doc | Função |
|---|---|
| `PROJETO_CIP_CLOUD.md` | 👈 Este arquivo (visão geral) |
| `CONTRATO_API.md` | Contrato firmware↔cloud |
| `CONTRATO_DASHBOARD_V2.md` | Especificação do dashboard v2 |
| `HISTORICO_IMPLEMENTACOES.md` | Histórico de features implementadas |
| `STATUS CLOUD — YYYY-MM-DD_HH-MM.md` | Status fechado por sessão |
| `changelog.md` | Mudanças por versão |
| `database_inicial.sql` | Schema inicial do banco |

---

## 13. ✍️ Manutenção deste documento

- **Quando atualizar:** sempre que uma decisão arquitetural mudar, uma tabela
  for criada/alterada, ou um novo padrão for adotado
- **Quem atualiza:** Fernando + Copilot ao final de cada sessão relevante
- **Versionamento:** incrementar versão no topo + registrar em `changelog.md`
- **Placeholders `<!-- TODO -->`:** preencher conforme info for confirmada

---



📄 Adendo — copia e cola no PROJETO_CIP_CLOUD.md
markdown


---

## 16. Ferramenta de Execução: ATGY (Antigravity)

### 16.1 O que é

**ATGY (Antigravity)** é a ferramenta de execução vinculada ao projeto CIP Cloud, 
com **acesso direto aos arquivos do repositório**. Atua como braço operacional 
do CIP Cloud Copilot: enquanto o Copilot **pensa, projeta e gera patches**, o 
ATGY **aplica, salva e testa** sem intervenção manual de Fernando.

### 16.2 Divisão de papéis

| Ator | Responsabilidade |
|------|------------------|
| 🧠 **CIP Cloud Copilot** | Análise, arquitetura, geração de patches em texto, validação de resultados |
| 🤖 **ATGY (Antigravity)** | Aplicação física dos patches em arquivos, execução de testes, retorno de output |
| 👤 **Fernando** | Aprovação de decisões técnicas, revisão de resultados, comando final |

**Fluxo padrão:**
[Copilot gera patch] → [Fernando aprova] → [Copilot escreve prompt para ATGY] ↓ [ATGY aplica + testa] → [ATGY devolve diff + output] → [Copilot valida] ↓ [Fechamento de etapa em STATUS_CLOUD.md]

nn


### 16.3 Regras obrigatórias de cabeçalho

Toda vez que o ATGY **criar** ou **modificar** um arquivo PHP/JS/CSS/SQL do 
projeto, o cabeçalho do arquivo deve seguir o padrão abaixo. **Sem exceções.**

#### 16.3.1 Criação de arquivo novo

Ao criar um arquivo, o cabeçalho **deve incluir**:

- **Caminho relativo** do arquivo no projeto
- **Finalidade**: descrição clara em 1-3 linhas do que o arquivo faz
- **Histórico**: bloco iniciado com a versão `v1.0.0` e a data de criação

```php
<?php
/**
 * Arquivo: api/energia/resumo_dia.php
 *
 * Finalidade:
 *   Endpoint REST que retorna o resumo diario de energia para um
 *   controlador especifico, agregando geracao, exportacao, importacao
 *   e consumo total em kWh, com picos de potencia e indicadores de
 *   qualidade da telemetria.
 *
 * Historico:
 *   2026-06-05  v1.0.0  Criacao do endpoint.
 */

declare(strict_types=1);
16.3.2 Modificação de arquivo existente
Ao modificar um arquivo, o ATGY deve adicionar uma nova linha no bloco Historico, sem apagar entradas anteriores. Cada entrada deve conter:

Data no formato YYYY-MM-DD
Versão semântica incrementada (vX.Y.Z):
Z (patch) — correção de bug, ajuste fino sem mudança de contrato
Y (minor) — nova funcionalidade compatível, novo campo na resposta
X (major) — quebra de contrato, mudança incompatível
Descrição curta (1-3 linhas) do que mudou e por quê
Exemplo correto:

php


/**
 * Arquivo: api/energia/resumo_dia.php
 *
 * Finalidade:
 *   Endpoint REST que retorna o resumo diario de energia [...]
 *
 * Historico:
 *   2026-06-05  v1.0.0  Criacao do endpoint.
 *   2026-06-06  v1.1.0  consumo_total agora e calculado em PHP via formula
 *                       canonica. Motivo: firmware nao popula 
 *                       energia_ativa_total_kwh (sempre 0.0000).
 *                       Adicionada flag CALCULAR_CONSUMO_NO_PHP para 
 *                       reversao futura. Campo qualidade.fonte_consumo
 *                       sinaliza origem do dado na resposta JSON.
 */
16.4 Convenções de cabeçalho (resumo)
✅ Cabeçalho em português brasileiro
✅ Texto do cabeçalho sem acentos (compatibilidade com encoding antigo)
❌ NÃO apagar entradas históricas — apenas adicionar novas linhas
❌ NÃO sobrescrever a data/versão de uma entrada já registrada
✅ Manter alinhamento visual: data  versao  descricao
✅ Quando a descrição passar de 1 linha, indentar continuação alinhada ao início da descrição (não à data)
✅ Em arquivos JS, usar /** ... */ (JSDoc); em CSS, usar /* ... */; em SQL, usar -- ... com mesmo conteúdo lógico
16.5 Checklist obrigatório do ATGY ao finalizar uma tarefa
Ao concluir qualquer aplicação de patch, o ATGY deve devolver:

 Diff das mudanças aplicadas (cada arquivo)
 Output de php -l para cada arquivo PHP modificado (sanity check de sintaxe)
 Confirmação visual de que o cabeçalho foi atualizado corretamente
 Output bruto de testes (curl, query SQL, console JS) quando solicitados
 Lista de warnings/notices do PHP error log relacionados à mudança
 Lista de arquivos tocados (caminhos relativos), para referência no STATUS
16.6 Limites do ATGY (o que ele NÃO faz)
❌ NÃO toma decisões arquiteturais (escopo do Copilot + Fernando)
❌ NÃO inventa código além do especificado no prompt
❌ NÃO apaga entradas do Historico em cabeçalhos
❌ NÃO cria arquivos novos sem ordem explícita (se arquivo alvo não existir, aborta e reporta)
❌ NÃO mexe em arquivos de firmware (escopo do CIP Firmware Copilot)
❌ NÃO executa migrations SQL destrutivas sem confirmação explícita
❌ NÃO commita/pusha em Git automaticamente — Fernando decide quando
16.7 Modelo de prompt padrão para o ATGY
Quando o Copilot delegar trabalho ao ATGY, o prompt deve seguir esta estrutura:

markdown


# 🎯 Tarefa ATGY — [resumo em 1 linha]

## Contexto
[2-4 linhas sobre o porquê da mudança]

## Arquivos alvo
1. caminho/relativo/arquivo1.php → vX.Y.Z → vX.Y+1.Z
2. caminho/relativo/arquivo2.php → criacao (v1.0.0)

## Mudanças necessárias
[Diff conceitual, blocos de código com substituições explícitas]

## Cabeçalho — regras
- Atualizar bloco Historico conforme Secao 16.3 do PROJETO_CIP_CLOUD.md
- Em arquivos novos, incluir bloco Finalidade

## Testes de validação
[Comandos curl, queries SQL, checks de browser]

## Regras de execução
[Lista de NÃOs específicos da tarefa]

## Entregar ao final
[Lista do que o ATGY deve devolver]
16.8 Rastreabilidade e auditoria
Toda alteração feita pelo ATGY deve, no fechamento de etapa, ser registrada em docs/STATUS_CLOUD.md na seção 📂 Arquivos tocados, com:

Caminho relativo
Versão antes → versão depois
Motivo da alteração (1 linha)

Tópico;	Decisão de hoje 07-06-2026 14:46;	Atualiza qual item do plano F1.B?
🎬 Infográfico SVG animado de fundo;	Aprovado, com 5 estados (export/import/noturno/bat-carga/bat-descarga);	➕ NOVO — não estava no STATUS, entra como item adicional
⭕ Range dos gauges;	Dinâmico: +20% nominal, calibra com pico 90d;	🔄 Refina Sessão 1 (cards instantâneos)
⏱️ Polling;	5min (não 10s como STATUS sugeria);	🔄 Conflito resolvido — STATUS dizia 10s, você decidiu 5min
📅 Energia Hoje/Mês;	Barras horizontais + delta vs ontem/mês passado;	🔄 Refina cards instantâneos
⭐ Média 12m;	Toggle rolante / ano calendário;	🔄 Refina Sessão 2 (responde decisão #2 do STATUS)
📐 Card cos φ trifásico;	3 ponteiros SVG vanilla + régua híbrida	;➕ NOVO — não estava no STATUS
🌐 Status offline global	;Componente compartilhado;	➕ NOVO


**FIM DO DOCUMENTO**
