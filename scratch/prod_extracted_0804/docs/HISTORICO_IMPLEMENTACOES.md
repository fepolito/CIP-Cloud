# HISTÓRICO DE IMPLEMENTAÇÕES

## 2026-03-21
- Subdomínio `monitor.aeonium.com.br` validado em ambiente cPanel
- Diretório web acessível criado com sucesso
- Execução de arquivos PHP confirmada no servidor
- Estrutura base da aplicação definida
- Criados arquivos iniciais de configuração da aplicação
- Criada camada inicial de conexão com banco via PDO
- Criado script de teste de conexão com banco
- Definida estrutura inicial do banco de dados do projeto
- Criado histórico formal de implementação
- Banco `aeoniu71_monitor` criado e validado com sucesso
- Usuário `aeoniu71_monitor` vinculado ao banco e autenticado com êxito
- Teste de conexão realizado com retorno positivo do servidor MySQL
## 2026-03-21
- Implementado bloqueio de reexecução do `install.php`
- Criado arquivo de trava de instalação em `storage/install.lock`
- Reforçada a política de sessão com cookies `HttpOnly` e `SameSite=Lax`
- Adicionada regeneração periódica de ID de sessão
- Associada sessão autenticada ao IP e User-Agent do cliente
- Definido tempo de expiração da sessão autenticada
- Reforçado o registro de eventos de login, logout e instalação
## 2026-03-21
- Implementada camada de proteção CSRF em formulários críticos
- Implementado controle de rate limit para tentativas de login
- Criado armazenamento local de rate limit em `storage/rate_limit`
- Adicionados cabeçalhos HTTP de segurança via aplicação e `.htaccess`
- Bloqueado acesso web a diretórios sensíveis: `config`, `app`, `docs` e `storage`
- Desabilitada listagem de diretórios no Apache
- Restringido acesso a arquivos de log, SQL, Markdown, lock e configuração
## 2026-03-21
- Corrigida estratégia de persistência de autenticação removendo validação estrita por IP
- Padronizado redirecionamento interno com função `appUrl()`
- Implementado dashboard técnico real com leitura de métricas do banco
- Adicionados cards de indicadores para usuários, dispositivos, leituras e comandos
- Adicionadas tabelas de últimas leituras, últimos comandos e últimos usuários
## 2026-03-21
- Identificada falha de persistência da sessão PHP no ambiente hospedado
- Implementado armazenamento local de sessão em `storage/sessions`
- Ajustada inicialização de sessão para maior previsibilidade em hospedagem compartilhada
- Mantidas proteções CSRF e autenticação sobre sessão persistente
# Histórico de Implementações

## 2026-03-21
- Identificado problema estrutural de persistência de sessão PHP no ambiente hospedado
- Alterado `session.save_path` para diretório dedicado: `/home1/aeoniu71/php/sessions_cipe`
- Validada criação e persistência de arquivo de sessão no servidor
- Corrigido fluxo de autenticação com sessão segura e regeneração periódica de ID
- Implementada camada de proteção CSRF com geração e validação de token em sessão
- Corrigido problema de `headers already sent` causado por warning em arquivo de dashboard
- Removido uso inadequado de `use PDOException;` fora de namespace
- Revisado `login.php`, `logout.php`, `dashboard.php`, `app/auth.php` e `app/security.php`
- Mantidos arquivos auxiliares `sessao_teste.php` e `csrf_teste.php` para diagnóstico futuro
