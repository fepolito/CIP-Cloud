# Auditoria PROD vs LOCAL

**@arquivo:** docs/AUDITORIA_PROD_2026-06-08.md
**@versao:** 1.0
**@modificado_em:** 2026-06-08
**@objetivo:** Comparar o backup do PROD com o working tree local para decidir a estratégia de deploy.
**@autor:** Antigravity

---

## 1. Confirmação de Extração

A pasta de backup `C:\laragon\_backups_cip\PROD_2026-06-08\` foi localizada com sucesso. A listagem do nível raiz confirmou a presença da estrutura extraída:

**Arquivos e Diretórios na Raiz:**
`.htaccess`, `.htaccess_20260531`, `.user.ini`, `.well-known/`, `.well-known.zip`, `500.shtml`, `_tree.php`, `api/`, `app/`, `assets/`, `backup/`, `config/`, `controladores.php`, `dashboard.php`, `dispositivos.php`, `docs/`, `empresas.php`, `energia.php`, `energia_old_26-06-03.php`, `error_log`, `geracao.php`, `gitignore`, `historico.php`, `ht`, `includes/`, `index.php`, `limites_potencia.php`, `login.php`, `logout.php`, `php.ini`, `php_info.php`, `projeto_admin.php`, `public/`, `storage/`, `tarifas.php`, `tools/`, `uploads/`, `usuarios.php`.

---

## 2. Relatório de Divergências

### 🔴 APENAS EM PROD (25 arquivos)
*Arquivos que existem no PROD mas não no repositório Local.*

**Análise de Risco:**

🚨 **CRÍTICO** *(Configurações/Credenciais/Componentes a preservar)*
- `storage\install.lock` (Evita a reinstalação de frameworks)
- `assets\tema\tema.js` (Componente de frontend possivelmente injetado em PROD)
- `api\sync\exportar.php` (Lógica de sincronização que parece não estar versionada)

⚠️ **REVISAR** *(Pode ser artefato esquecido, log, teste ou configuração de servidor)*
- `api\config\teste.php`
- `ht` (Arquivo misterioso na raiz)
- `api\debug_500.php`
- `docs\changelog.md` (Localmente está apenas `changelog.md` na raiz)
- `includes\app_header_crack.php` (Nome suspeito, revisar integridade)
- `docs\status.md`
- `_tree.php` (Script de debug utilitário deixado no root)
- `php_info.php` (Risco de exposição de dados do servidor se acessível)
- `docs\database_inicial.sql`
- `.well-known.zip` (Backup de desafios ACME de SSL)

✅ **IGNORÁVEL** *(Claramente lixo/temporário/backups)*
- `includes\app_head_old_26-06-03.php`
- `assets\css\header_2026-04-15.css`
- `api\sync\error_log` (Log)
- `assets\js\app-shell_old.js`
- `gitignore` (Sem o ponto inicial)
- `.htaccess_20260531`
- `includes\app_header_2026-04-15.php`
- `assets\js\app-shell_2026-04-15.js`
- `error_log` (Log geral na raiz)
- `energia_old_26-06-03.php`
- `api\gitignore`
- `assets\css\header_crash.css`

---

### 🟡 DIVERGENTES (14 arquivos)
*Arquivos presentes nos dois ambientes, mas com hashes SHA256 diferentes.*

Estes arquivos foram alterados no repositório local e podem sobrescrever configurações ou comportamentos do PROD. Cuidado especial com arquivos de configuração:
- `index.php`
- `api\energia\error_log` *(Logs)*
- `config\database.php` *(Cuidado: Credenciais!)*
- `api\energia\mes.php`
- `config\app.php` *(Cuidado: Configurações do app!)*
- `docs\HISTORICO_IMPLEMENTACOES.md`
- `includes\config.php` *(Cuidado: Configurações antigas/variáveis globais!)*
- `includes\app_header.php`
- `app\helpers\Tenant.php`
- `.htaccess` *(Cuidado: Regras de roteamento!)*
- `api\error_log` *(Logs)*
- `php.ini` *(Cuidado: Configurações do PHP do servidor!)*
- `dashboard.php`
- `assets\css\header.css`

---

### 🟢 APENAS EM LOCAL (59 arquivos)
*Arquivos adicionados recentemente no Local que vão subir para PROD.*

Esses arquivos serão injetados de forma segura, pois não têm conflito:
- Dumps e logs de ferramentas: `_backups\database_inicial.sql`, `_backups\aeoniu71_monitor.sql.gz`, `_backups\aeoniu71_monitor.sql`, `tools\logs\sync_*.log`, `_backups\backup_controladores_20260607.sql`, etc.
- Ferramentas e utilitários: `tools\sync_puxar.php`, `tools\sync_config.php`, `tools\teste_tenant_path.php`, `tools\sync_auto.bat`, `tools\sync.bat`, etc.
- Docs novos: `docs\STATUS_CLOUD_*.md`, `docs\PROJETO_CIP_CLOUD.md`, `docs\CONTRATO_ARQUITETURA_PLANTA.md`, `docs\CONVENCOES.md`, etc.
- Novas APIs e Páginas: `api\energia\resumo_dia.php`, `api\energia\resumo_mes.php`, `api\energia\media_12m.php`, `api\dashboard\infografico.php`, `api\dashboard\snapshot.php`, `api\energia\instantaneo.php`.
- Arquivos de Configuração: `app\config\Constantes.php`, `config\sync.php`, `config\sync.example.php`.
- Migrações de Banco: `.sql` na pasta `database\migrations\` e `migrations\`.

---

### ⚪ IDÊNTICOS
- **78 arquivos** inspecionados via hash e com conteúdo totalmente equivalente entre LOCAL e PROD.

---

## 3. Recomendação de Estratégia de Deploy

**Estratégia A (git pull direto no doc_root): ❌ NÃO RECOMENDADO**
- Um `git pull` puro, checkout ou reset sobrescreveria/apagaria arquivos fundamentais não versionados (`assets\tema\tema.js`, `storage\install.lock`), e destruiria os arquivos `config\database.php`, `config\app.php`, e `php.ini` trazendo as configurações LOCAIS para o PROD (causando indisponibilidade do sistema).

**Estratégia B (cópia seletiva via File Manager / Deploy via CI seletivo): ✅ RECOMENDADA**
- **Sincronização Segura:** Realizar o envio dos novos arquivos de LOCAL (🟢 "Apenas em Local").
- **Merge Cuidadoso (🟡 Divergentes):** Atualizar o código (como `index.php`, `dashboard.php` e arquivos `.php` da pasta `api`), mas **não copiar** `config\database.php`, `config\app.php`, `includes\config.php`, `php.ini` e `.htaccess` sem antes mesclar as chaves e rotas da versão de PROD com eventuais novidades da LOCAL.
- **Preservação (🔴 Apenas em PROD):** Fazer download ou trazer para o versionamento os arquivos Críticos/Revisar (como `assets\tema\tema.js` e `api\sync\exportar.php`), ou apenas deixá-los como estão em PROD para não quebrar funcionalidades ativas não mapeadas.

**Ação Imediata Antes do Deploy:**
1. Trazer `assets\tema\tema.js` para o repositório Local.
2. Trazer/verificar `api\sync\exportar.php`.
3. Validar de perto o arquivo `includes\app_header_crack.php` em PROD (Pode ser um backup manual feito por um dev em "crise", mas vale investigar para segurança).
