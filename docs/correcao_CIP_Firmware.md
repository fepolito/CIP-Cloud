## 📋 Pendência firmware: popular `energia_ativa_total_kwh`

### Comportamento esperado
- Integrar `potencia_consumo_total_w` ao longo do tempo
- Persistir em flash (sobrevive a reboot)
- Enviar valor em kWh com 4 casas decimais
- Valor monotônico crescente (nunca decresce, exceto em reset autorizado)

### Fórmula matemática
consumo_total(t) = ∫ P_consumo(τ) dτ  para τ de t_inicial até t
                 = consumo_total(t-Δt) + P_consumo(t) × Δt

### Considerações
- Δt típico: 5s (taxa atual de amostragem)
- Persistir em flash a cada N minutos (não a cada amostra → wear leveling)
- Em caso de power-loss, perda máxima aceitável: 5 minutos de integração
- Reset autorizado: comando explícito via Modbus/BLE com confirmação

### Validação cruzada (cloud vai monitorar)
- Cloud calcula a mesma coisa em PHP como fallback
- Se diferença > 5% em 24h → log de divergência

## 🔗 PENDÊNCIA FIRMWARE — Telemetria de FP

### Status atual (validado em 2026-06-07)
✅ Firmware LÊ corretamente FP das 3 fases do EA777
✅ App local exibe os 3 valores em tempo real
   (exemplo lido: A=0.989, B=0.871, C=0.562)
❌ Payload de telemetria enviado ao cloud NÃO inclui esses 4 campos
   (todas as linhas CSV recentes vêm com 0.000)

### Ação necessária
Adicionar ao payload de telemetria HTTP/MQTT os campos:
- fp_a       (float, -1.000 a 1.000)
- fp_b       (float, -1.000 a 1.000)
- fp_c       (float, -1.000 a 1.000)
- fp_total   (float, -1.000 a 1.000)

### Convenção de sinal a confirmar
- Positivo = indutivo (atraso)
- Negativo = capacitivo (avanço)
- OU sempre absoluto + flag separada?
→ Definir contrato e documentar em docs/CONTRATO_API.md

### Validação cruzada
Após patch firmware, comparar leitura do app local com 
SELECT no cloud das mesmas timestamps — devem coincidir.
## 🔵 P4 — Integração do inversor Solis (pendente hardware)

### Status
Inversor ainda não conectado fisicamente ao CIP. Todas as leituras 
de geração/temperatura/status do inversor estão `NULL` ou `0`.

### Colunas afetadas
- `potencia_geracao_w` → 0
- `energia_geracao_kwh` → 0
- `status_inversor` → NULL
- `temperatura_inversor_c` → NULL
- `inversor_read_errors` → 0
- `geracao_origem` → 'indisponivel'

### Comportamento esperado no cloud (temporário)
- Cards de geração exibem badge "🔌 Inversor não conectado"
- Não emitir alertas de "geração zero"
- Quando conectar: sem necessidade de mudança no cloud, fórmula 
  `MAX(energia_geracao_kwh) - MIN(...)` já vai funcionar automaticamente
