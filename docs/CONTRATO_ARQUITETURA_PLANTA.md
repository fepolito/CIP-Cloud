# 📐 Contrato de Arquitetura — Planta Fotovoltaica CIP
**Status:** 📥 Backlog — discutido e validado conceitualmente, implementação adiada  
**Data do levantamento:** 2026-06-07  
**Sessão:** CIP Cloud Copilot ↔ Fernando  
**Prioridade atual:** baixa (foco em dashboard primeiro)

---

## 🎯 Objetivo

Documentar o modelo conceitual da planta fotovoltaica controlada por um CIP, 
com hierarquia de equipamentos, versionamentos e fontes da verdade, para 
implementação futura quando o dashboard estiver concluído.

---

## 🧩 Modelo conceitual

Uma **planta fotovoltaica** é composta por:

- **1 Controlador CIP** (hub central, fonte da verdade operacional)
- **N Inversores Solar** (geração FV)
- **N Inversores Bateria** (futuro — armazenamento)
- **N Medidores** (bidirecional rede, geração, consumo, auxiliar)
- **1 Tabela de Limites** ativa (24h × 3 perfis de dia)

                ┌─────────────────────────┐
                │     CONTROLADOR (CIP)   │
                │  (1 por planta)         │
                └────────────┬────────────┘
                             │ 1:N
            ┌────────────────┼────────────────┐
            ▼                ▼                ▼
    ┌──────────────┐  ┌──────────────┐  ┌──────────────┐
    │  INVERSORES  │  │  INVERSORES  │  │   MEDIDORES  │
    │    SOLAR     │  │   BATERIA    │  │  (EA777 etc) │
    │              │  │   (futuro)   │  │              │
    └──────────────┘  └──────────────┘  └──────────────┘
                             │
                             ▼
                ┌─────────────────────────┐
                │   TABELA DE LIMITES     │
                │ (1 ativa por contr.)    │
                │ Hardware: só corrente   │
                │ Cloud: histórico        │
                └─────────────────────────┘


---

## 🔢 Versionamentos do sistema

| Versão | Significado | Onde fica | Quem incrementa |
|---|---|---|---|
| 🌐 **`cloud_version`** | Versão da aplicação web/API | Constante global (`app/config/Version.php`) | Deploy/Git tag |
| 🔌 **`fw_version` do CIP** | Firmware do controlador ESP32-S3 | `controladores.fw_version` ✅ já existe | OTA do firmware reporta no boot |
| 📋 **`versao` da tabela de limites** | Tabela horária ativa | `tabela_limites.versao` (a criar) | Alteração via cloud OU local |
| ⚡ **`fw_version` por inversor solar** | Firmware do Solis/outro | `inversores_solar.fw_version` (a criar) | Polling SolisCloud ou Modbus |
| 🔋 **`fw_version` por inversor bateria** | Firmware do híbrido | `inversores_bateria.fw_version` (futuro) | Idem |
| 📐 **`fw_version` por medidor** | Firmware do EA777 etc. | `medidores.fw_version` (a criar) | Modbus query |

---

## 🗄️ Tabelas a criar/ajustar (futuro)

### 1️⃣ `controladores` (já existe — ajustar)
- **Manter:** `fw_version`, `controle_versao`, `controle_origem`, `controle_atualizado_em`
- **Adicionar futuramente:**
  - `controle_sync_status` ENUM('sincronizado','pendente_ack','timeout','divergente')
  - `controle_ack_em` DATETIME
  - `controle_versao_confirmada` INT UNSIGNED
- **Repensar:** `potencia_nominal_kw` passa a ser **soma derivada** dos inversores solar
  (manter coluna cacheada via TRIGGER quando `inversores_solar` mudar)

### 2️⃣ `inversores_solar` (NOVA)
Campos núcleo:
- `controlador_id` (FK)
- `apelido`, `marca`, `modelo`, `numero_serie`
- `potencia_nominal_kw` DECIMAL(7,3)
- `fw_version`, `fw_atualizado_em`
- `endereco_modbus` (se RS485 local)
- `id_solis_cloud` (se cadastrado na SolisCloud API)
- `status` ENUM('ativo','inativo','manutencao','erro')
- timestamps padrão

### 3️⃣ `inversores_bateria` (NOVA — só reservar nome, criar quando aparecer hardware)
Campos previstos:
- `controlador_id` (FK)
- `potencia_nominal_kw`, `capacidade_kwh`
- `fw_version`
- demais a definir conforme fabricante

### 4️⃣ `medidores` (NOVA)
Campos núcleo:
- `controlador_id` (FK)
- `apelido`, `tipo` (EA777, SDM630, etc.)
- `funcao` ENUM('bidirecional_rede','geracao','consumo','auxiliar')
- `endereco_modbus`
- `fw_version` (opcional)
- timestamps padrão

### 5️⃣ `tabela_limites` (NOVA — ou consolidar existente)
**Estratégia a decidir:**
- **(a)** 1 linha por versão com `payload_json` de 72 valores ← recomendação
- **(b)** 72 linhas por versão (normalizada)

Campos previstos (opção a):
- `controlador_id`
- `versao` INT UNSIGNED
- `payload_json` JSON (24h × {dias_uteis, sabado, domingo_feriado})
- `origem` ENUM('local','cloud','app')
- `usuario_id`
- `criada_em`, `aplicada_em`, `ack_em`
- `sync_status` ENUM('sincronizada','pendente_ack','timeout','divergente')
- `ativa` BOOLEAN (UNIQUE parcial: só 1 ativa por controlador)

**Histórico:** mantém versões antigas só no cloud (firmware guarda apenas a corrente, por economia de flash).

---

## 🔄 Contrato de sync firmware ↔ cloud (handshake)

Toda comunicação cloud↔firmware deve trocar pelo menos as 3 versões críticas:

```json
{
  "controlador_id": "CIP-001",
  "versoes": {
    "firmware": "1.4.2",
    "controle": {
      "versao_cloud": 7,
      "versao_firmware": 7,
      "sync_status": "sincronizado"
    },
    "limites": {
      "versao_cloud": 23,
      "versao_firmware": 22,
      "sync_status": "pendente_ack"
    }
  },
  "inversores": [
    { "id": 1, "fw_version": "B4_HMI_C_2023_0530" },
    { "id": 2, "fw_version": "B4_HMI_C_2023_0530" }
  ],
  "medidores": [
    { "id": 1, "tipo": "EA777", "fw_version": null }
  ]
}


Regra: se qualquer versao_firmware < versao_cloud → cloud reenvia o payload daquele recurso (e só dele).

🧠 Decisões já tomadas
✅ Hardware é fonte da verdade operacional (comanda o inversor)
✅ Cloud é fonte da verdade histórica (auditoria + UI)
✅ Cada equipamento (inversor, medidor) tem fw_version própria
✅ Tabela de limites: 1 versão ativa no hardware, histórico no cloud
✅ controladores.potencia_nominal_kw = SOMA derivada dos inversores solar
✅ Cada recurso versionado (controle, limites) tem sync_status independente
❓ Decisões pendentes (resolver antes da implementação)
Potência nominal cacheada: TRIGGER, VIEW ou job manual?
Tabela de limites: JSON snapshot ou 72 linhas normalizadas?
Retenção de histórico de limites: quantas versões ou quanto tempo?
medidores: criar agora ou só quando aparecer o segundo medidor real?
cloud_version: rastrear por controlador (em cada ACK) ou só global?
▶️ Quando retomar
Pré-requisitos para destravar este backlog:

 Dashboard concluído e estável
 Decisões pendentes acima resolvidas com Fernando
 CIP Firmware Copilot acionado em paralelo para alinhar contrato
Próxima ação ao retomar: responder as 5 perguntas pendentes → gerar DDL completo + migration + rollback + TRIGGERs.

Arquivo: docs/CONTRATO_ARQUITETURA_PLANTA.md
Versão: 1.0
Status: congelado em backlog até conclusão do dashboard




---

## ✅ Pronto — backlog registrado

Salva esse arquivo em `docs/CONTRATO_ARQUITETURA_PLANTA.md` e commita. Quando o dashboard estiver fechado, retomamos exatamente daqui.

---

## ▶️ Voltando ao dashboard

Me passa onde paramos:

- 🎯 Qual página/endpoint estamos atacando agora?
- 📎 Cola o trecho atual do `.php` / `.js` afetado
- 🐛 Bug, feature ou refactor?

🚦 Foco total no dashboard. Aguardando.