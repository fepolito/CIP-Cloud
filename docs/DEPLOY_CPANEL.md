# Deploy CIP Cloud — cPanel ↔ GitHub

> **@arquivo** docs/DEPLOY_CPANEL.md
> **@versao** 1.0.0
> **@modificado_em** 2026-06-09
> **@objetivo** Manual operacional para configurar e operar o deploy
> automatizado do CIP Cloud via Git Version Control do cPanel HostGator,
> integrado ao repositorio privado github.com/fepolito/CIP-Cloud.
> **@autor** Fernando / CIP Cloud Copilot / ATGY

---

## 1. Arquitetura do Deploy
DEV (Laragon) ──git push──▶ GitHub (fepolito/CIP-Cloud) ──pull──▶ cPanel HostGator │ ▼ rsync (.cpanel.yml) │ ▼ /home1/aeoniu71/monitor.aeonium.com.br/




## 2. Pré-requisitos (uma vez só)

### 2.1. Gerar Personal Access Token (PAT) no GitHub

1. Acesse: https://github.com/settings/tokens?type=beta
2. Clique em **Generate new token** → **Fine-grained personal access token**
3. Preencha:
   - **Token name**: `cpanel-hostgator-cip-cloud`
   - **Expiration**: `1 year` (anote a data de renovação!)
   - **Resource owner**: `fepolito`
   - **Repository access**: `Only select repositories` → escolha `CIP-Cloud`
   - **Permissions** → Repository permissions:
     - `Contents`: **Read-only**
     - `Metadata`: **Read-only** (auto)
4. Clique **Generate token**
5. **COPIE O TOKEN** (formato `github_pat_xxxxxx...`) — só aparece UMA vez!
6. Guarde temporariamente num gerenciador de senhas

⚠️ **Nunca commite o token no repo. Nunca cole em screenshots públicos.**

### 2.2. Configurar Git Version Control no cPanel

1. Acesse cPanel HostGator → seção **Files** → **Git™ Version Control**
2. Clique **Create**
3. Preencha:
   - **Clone URL**: `https://fepolito:GITHUB_PAT_AQUI@github.com/fepolito/CIP-Cloud.git`
     - Substitua `GITHUB_PAT_AQUI` pelo token copiado em 2.1
   - **Repository Path**: `/home1/aeoniu71/repos/cip-cloud`
     - ⚠️ NÃO use o doc_root aqui! Esta é a pasta de trabalho do Git.
   - **Repository Name**: `cip-cloud`
4. Marque ✅ **Clone a Repository**
5. Clique **Create**

✅ O cPanel vai clonar o repo em `/home1/aeoniu71/repos/cip-cloud`. O `.cpanel.yml` já está versionado, então o cPanel detecta automaticamente.

## 3. Primeiro Deploy

1. Em cPanel → **Git Version Control** → clique no repo `cip-cloud`
2. Aba **Pull or Deploy**
3. Clique **Update from Remote** (faz `git pull`)
4. Clique **Deploy HEAD Commit** (executa `.cpanel.yml`)
5. Aguarde o log aparecer — deve terminar com:
Deployment task completed successfully.




## 4. Validação Pós-Deploy

### 4.1. Verificar arquivo de auditoria

Via File Manager do cPanel, abrir:
`/home1/aeoniu71/monitor.aeonium.com.br/.last_deploy`

Deve conter a data/hora do deploy.

### 4.2. Smoke test HTTP

```bash
# HTTPS forçado (deve retornar 200 ou 302 para login)
curl -I https://monitor.aeonium.com.br/

# HTTP deve redirecionar para HTTPS (301)
curl -I http://monitor.aeonium.com.br/

# HSTS deve aparecer no header
curl -I https://monitor.aeonium.com.br/ | grep -i strict-transport

# Pastas sensíveis devem retornar 403
curl -I https://monitor.aeonium.com.br/config/
curl -I https://monitor.aeonium.com.br/storage/
curl -I https://monitor.aeonium.com.br/docs/

# Arquivos sensíveis devem retornar 403
curl -I https://monitor.aeonium.com.br/.env
curl -I https://monitor.aeonium.com.br/.cpanel.yml
```

### 4.3. Verificar que config/database.php do PROD foi preservado
Via File Manager do cPanel, abrir `/home1/aeoniu71/monitor.aeonium.com.br/config/database.php` e confirmar que ainda tem as credenciais de PROD (não foi sobrescrito).

## 5. Fluxo de Trabalho Diário
```bash
# No Laragon (DEV)
git add .
git commit -m "feat: descrição do que mudou"
git push origin main

# No cPanel (Git Version Control)
# 1. Update from Remote
# 2. Deploy HEAD Commit
```

## 6. Rollback de Emergência
Caso um deploy quebre o PROD:

1. cPanel → Git Version Control → repo cip-cloud
2. Aba Pull or Deploy
3. Em Checkout Branch, mudar para o commit anterior
4. Clique Deploy HEAD Commit

## 7. Renovação do PAT
📅 Antes da data de expiração (anotada em 2.1):

1. Gerar novo PAT (passo 2.1)
2. cPanel → Git Version Control → repo cip-cloud → Manage
3. Atualizar Clone URL com novo token
4. Salvar

## 8. Troubleshooting

| Sintoma | Causa provável | Solução |
|---|---|---|
| Repository not found ao clonar | PAT inválido/expirado ou repo errado | Regerar PAT, verificar URL |
| Deploy executa mas site não atualiza | DEPLOYPATH errado no .cpanel.yml | Confirmar caminho absoluto do doc_root |
| rsync: command not found | Caminho do rsync diferente | Trocar /bin/rsync por rsync no .cpanel.yml |
| Arquivos sensíveis aparecem no site | .htaccess não foi aplicado | Verificar se mod_rewrite ativo |
| Permission denied no deploy | Permissões erradas no doc_root | Rodar manualmente chmod 755 nas pastas |

## 9. RDC Aplicadas
- CIP-DEC-20260609-001 Deploy automatizado via cPanel Git + .cpanel.yml — Proposta
- CIP-DEC-20260609-002 Autenticação por PAT (fine-grained, read-only) — Proposta
- CIP-DEC-20260609-003 HSTS 1 ano com includeSubDomains — Confirmada
