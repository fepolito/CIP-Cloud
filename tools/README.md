# tools/

Scripts de diagnostico e teste do projeto CIP Cloud.

**NAO** sao usados em producao. Servem para debug local, validacao
de helpers e smoke tests apos deploy.

## Inventario

| Arquivo | Funcao |
|---------|--------|
| `teste_tenant_carga.php`  | Valida carga da classe App\Helpers\Tenant (autoload manual) |
| `teste_tenant_path.php`   | Valida path do arquivo Tenant.php (case-sensitivity Windows vs Linux) |
| `teste_tenant_listar.php` | Smoke test do metodo Tenant::listarControladores() retornando JSON |

## Como usar

Acesse via browser autenticado:

- https://monitor.aeonium.com.br/tools/teste_tenant_carga.php
- https://monitor.aeonium.com.br/tools/teste_tenant_path.php
- https://monitor.aeonium.com.br/tools/teste_tenant_listar.php

## Seguranca

> **ATENCAO:** estes scripts expoem dados internos.
> Em producao, proteger via .htaccess ou middleware de papel_global=master.
