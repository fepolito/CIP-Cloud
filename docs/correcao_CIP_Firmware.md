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
