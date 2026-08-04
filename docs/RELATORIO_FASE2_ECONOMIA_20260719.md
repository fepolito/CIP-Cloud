# 📊 Relatório de Desenvolvimento — Dashboard de Economia (Fase 2)

**Projeto:** CIP Cloud (Aeonium)
**Módulo:** Dashboard de Energia — Cards de Economia
**Data:** 2026-07-19
**Autor:** Fernando / CIP Cloud Copilot / ATGY
**Status:** ✅ Concluído e operacional em produção

---

## 1. Objetivo

Implementar navegação temporal nos cards de economia do dashboard, permitindo
consultar dias e meses passados com comparativo correto, respeitando as regras
de período parcial vs. fechado, dentro de uma janela de 12 meses.

---

## 2. Escopo Entregue

### 2.1 Backend — `api/energia/economia.php`
- Aceita parâmetro opcional `ref`:
  - Modo dia: `YYYY-MM-DD`
  - Modo mês: `YYYY-MM`
- Resolução de janela timezone-aware (via `controladores.timezone`).
- Detecção **período atual vs. passado** para decidir a lógica de comparação.
- Janela anterior calculada conforme o caso:
  - **Período atual:** parcial vs. parcial (mesmo nº de dias/período decorrido).
  - **Período fechado:** cheio vs. cheio.
- Guarda-corpo de 12 meses (rejeita `ref` fora da janela → erro tratado, sem 500).
- Flag `sem_dados: true` quando a janela não possui telemetria (via `COUNT(*)`).
- Retrocompatibilidade total: sem `ref`, comportamento idêntico à Fase 1.

### 2.2 Frontend — `dashboard.php`
- Datepicker independente por card (dia e mês).
- Limites `min`/`max` calculados no front (janela deslizante de 12 meses).
- Botão "Hoje / Mês atual" (aparece só ao navegar; reseta para o período corrente).
- Tratamento de `sem_dados` → card exibe "Sem dados".
- `refLabel` dinâmico (texto do comparativo muda conforme período atual/passado).
- Reset de navegação ao trocar de controlador (integridade multi-tenant).

### 2.3 Estilos
- Classes `.eco-nav` e `.eco-nav-hoje` (respeitando variáveis de tema).

---

## 3. Regras de Negócio (matriz de rótulos)

| Card | Período | refLabel |
|------|---------|----------|
| Dia  | Atual   | "que ontem" |
| Dia  | Passado | "que no dia anterior" |
| Mês  | Atual   | "que no mesmo período do mês anterior" (parcial) |
| Mês  | Passado | "que no mês anterior" (cheio) |

---

## 4. Decisões Técnicas (RDC)

| ID | Título | Status |
|----|--------|--------|
| CIP-DEC-20260719-011 | Navegação via datepicker, por card (dia e mês independentes) | ✅ Confirmada |
| CIP-DEC-20260719-012 | Período passado/fechado = comparação cheio vs. cheio | ✅ Confirmada |
| CIP-DEC-20260719-013 | Limite do datepicker = últimos 12 meses (janela deslizante) | ✅ Confirmada |
| CIP-DEC-20260719-014 | Janela sem telemetria → flag `sem_dados: true` | ✅ Confirmada |

> Nota RDC-013: por ser janela fixa (hoje − 12 meses), dispensou-se o
> endpoint `periodo_disponivel.php` — front calcula limites sozinho.
> Menos código, menor superfície de ataque.

---

## 5. Conformidade com a Arquitetura

- ✅ **Multi-tenant:** todas as queries filtram por controlador/tenant; navegação
  reseta ao trocar de controlador.
- ✅ **Timezone:** janelas atual e anterior via `CONVERT_TZ` com timezone do controlador.
- ✅ **Segurança:** prepared statements, sem concatenação de SQL, erros tratados
  sem vazar stack trace em produção.
- ✅ **Cálculo de energia:** MAX−MIN timezone-aware (RDC-20260607-002).

---

## 6. Validação Realizada

- [x] Sem `ref` → idêntico à Fase 1 (zero regressão).
- [x] Mês passado → comparação cheio vs. cheio (RDC-012).
- [x] Mês atual → parcial vs. parcial (RDC-010).
- [x] `ref` > 12 meses → erro tratado (não 500) (RDC-013).
- [x] Janela vazia → `sem_dados: true` → card "Sem dados" (RDC-014).
- [x] Botão "Hoje/Mês atual" com visibilidade correta.
- [x] `refLabel` dinâmico conforme período.
- [x] Reset de navegação na troca de controlador.
- [x] Timezone respeitado nas duas janelas.

**Resultado:** todos os cenários aprovados. Operacional em produção.

---

## 7. Arquivos Alterados

| Arquivo | Tipo | Observação |
|---------|------|------------|
| `api/energia/economia.php` | Editado | Bump minor · `@modificado_em` atualizado |
| `dashboard.php` | Editado | Bump minor · datepicker, listeners, refLabel |
| (CSS do dashboard) | Editado | Classes `.eco-nav`, `.eco-nav-hoje` |

---

## 8. Débitos Técnicos / Pendências

- [ ] Confirmar bump de versão registrado em `dashboard.php`.
- [ ] Atualizar `STATUS_CLOUD.md` com a conclusão da Fase 2.
- [ ] (Herdados) Auditoria `.htaccess`, tema claro residual hardcoded,
      badge online/offline consistente.

---

## 9. Próximos Passos Sugeridos

1. `limites.php` — Limite Tabela (24 faixas × 3 perfis + `sync_controle`).
2. Datepicker no dashboard de energia (reaproveitar lógica de 12 meses).
3. Limpeza dos débitos técnicos herdados.
