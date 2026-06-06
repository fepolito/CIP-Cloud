# CONTRATO DE API: Sincronização de Limites de Potência (Firmware ↔ Cloud)

## Objetivo
Definir o fluxo, o formato e a política de sincronização da tabela horária de limites de potência entre a Nuvem (Cloud) e o Dispositivo (Firmware ESP32-S3).

A Tabela de Limites no dispositivo determina a máxima potência exportada (em kW) por hora (24 faixas) para três perfis distintos de dias: 
1. Dias úteis
2. Sábados
3. Domingos/Feriados

---

## 1. Estrutura do Payload de Sincronização

Independente do canal de comunicação escolhido (ver seção 2), o payload trafegado para sincronizar os limites terá a seguinte estrutura **JSON normalizada**:

```json
{
  "controlador_id": 1024,
  "versao": 5,
  "timestamp_utc": "2026-05-30T15:30:00Z",
  "origem": "cloud",
  "limites": {
    "dias_uteis": [ /* 24 valores em kW, índice 0 = 00:00, 23 = 23:00 */
      0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 5.0, 10.0, 15.0, 20.0, 25.0, 25.0,
      25.0, 25.0, 20.0, 15.0, 10.0, 5.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0
    ],
    "sabado": [ /* 24 valores em kW */
      0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 5.0, 5.0, 10.0, 10.0, 10.0, 10.0,
      10.0, 10.0, 10.0, 10.0, 5.0, 5.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0
    ],
    "domingo_feriado": [ /* 24 valores em kW */
      0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0,
      0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0
    ]
  }
}
```

### Ack de Confirmação (Resposta do Firmware)
O firmware deve responder com o status de aplicação da versão enviada.

```json
{
  "controlador_id": 1024,
  "versao": 5,
  "status_aplicacao": "sucesso", // ou "erro_validacao"
  "mensagem": "OK"
}
```

---

## 2. Opções de Canal de Comunicação

Ainda a ser definido com a equipe de firmware. Abaixo estão as opções:

### Opção A: Pull / Polling REST (Firmware busca ativamente)
O firmware faz uma requisição `GET /api/limites/sync.php?ctrl=1024&versao_atual=4` a cada X minutos. 
Se a cloud responder com uma versão maior, o firmware baixa, aplica e envia `POST /api/limites/ack.php`.
- **Prós:** Mais fácil de implementar no backend; firmware tem total controle do fluxo e reconexão; dispensa infraestrutura adicional (broker).
- **Contras:** Maior overhead HTTP se polling for frequente; tempo de latência para aplicação de mudança de limite (pode demorar até o próximo ciclo).

### Opção B: MQTT (Canal Bidirecional Constante)
O firmware mantém conexão aberta via TCP num Broker MQTT (ex: Mosquitto). Cloud publica nova versão num tópico `cip/1024/limites/rx`. Firmware aplica e publica ACK no tópico `cip/1024/limites/tx`.
- **Prós:** Latência quase zero (real-time); baixíssimo overhead de banda.
- **Contras:** Exige subir, escalar e manter um Broker MQTT na infraestrutura da Aeonium; complexidade de manter a conexão persistente no ESP32 lidando com picos/quedas de sinal.

### Opção C: WebSocket (Canal Bidirecional Web)
Semelhante ao MQTT, mas nativo com tecnologias Web.
- **Prós:** Não exige Broker dedicado se a infraestrutura PHP (via Swoole ou ReactPHP) suportar.
- **Contras:** Difícil implementar com PHP tradicional (fpm/apache) — exigiria servidor em loop. Manter WebSockets abertos em redes de celular IoT (se for o caso) pode ser instável.

### Opção D: Push via API Local (Cloud chama o IP do Firmware)
Cloud faz uma chamada REST direta pro IP da rede do inversor.
- **Prós:** Simples e imedia.
- **Contras:** Praticamente inviável pois o CIP operará atrás de NAT/CGNAT em redes de clientes.

**Recomendação Preliminar:** Se infraestrutura MQTT já existe no projeto ou está nos planos, é o melhor caminho. Caso o ambiente seja unicamente LAMP stack focado em APIs HTTPS, a **Opção A (Polling REST)** é a mais segura e previsível.

---

## 3. Fluxo de Estado na Cloud (Conforme modelo normalizado)

1. Usuário altera interface e salva.
2. Backend insere nova versão nas tabelas `tabela_limites` e `tabela_limites_faixas`, com `status = 'pendente_envio'`. E loga em `tabela_limites_historico`.
3. Disparo (MQTT) ou espera do firmware (Polling).
4. No momento que a mensagem é despachada (ou baixada pelo firmware no polling), altera status para `'enviada'`.
5. Cloud inicia contagem de **Janela de Timeout (Ex: 2 minutos)**.
6. Firmware processa payload. Se ok, responde `sucesso`. Se falhar limites físicos, rejeita.
7. Cloud recebe ACK e altera para `'aplicada'` (ou `'rejeitada'`).
8. Se Timeout expirar sem ACK, altera status para `'timeout'`.

---

## 4. Política de Retry

- Se o envio expirar com `timeout`, a Cloud não tentará forçar reenvio ativo (evitar loop infinito).
- Se estivermos usando MQTT: mensagem pode ser QoS 1 (delivered at least once).
- Se estivermos usando Polling: o firmware, no próximo ciclo, continuará reportando estar na versão 4, e a Cloud devolverá a versão 5 pendente. Logo, o "retry" é passivo e dependente do próximo check-in do firmware.
