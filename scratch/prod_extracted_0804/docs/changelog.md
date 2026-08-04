# CHANGELOG — CIP Controlador de Injeção de Potência Elétrica

## [1.2.0] — 2026-04-15
### Corrigido
- `usuarios.php`: Adicionado `$pdo = getDbConnection()` após require
  de `config/database.php`. A ausência desta linha causava HTTP 500
  pois `$pdo` nunca era instanciado no escopo global do arquivo.

## [1.1.0] — 2026-04-15
### Alterado
- `usuarios.php`: Removido `<style>` inline — migrado para
  `assets/css/usuarios.css` (CSP compliance).
- Substituído `asset()` por `appUrl()`.

## [1.0.0] — 2026-04-11
### Adicionado
- CRUD completo de usuários com controle de acesso por perfil:
  master / master_operador / administrador / operador / usuario
- Listagem paginada, filtros, criação, edição inline e toggle ativo
