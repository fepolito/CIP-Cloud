import re

with open('C:/laragon/www/monitor.aeonium.com.br/dashboard_corrupted.php', 'r', encoding='utf-8') as f:
    text = f.read()

# Fix double-encoding
try:
    text = text.encode('latin-1').decode('utf-8')
except Exception as e:
    print('Encoding fix failed:', e)

# Fix missing backticks in aplicarSemaforo
text = text.replace('subTxt = Usina offline hǭ mais de  minutos. Verifique a internet no local.;', 'subTxt = Usina offline há mais de  minutos. Verifique a internet no local.;')
text = text.replace('subTxt = Usina offline há mais de  minutos. Verifique a internet no local.;', 'subTxt = Usina offline há mais de  minutos. Verifique a internet no local.;')
text = text.replace('subTxt = Último dado recebido há  min. Atraso na transmissão.;', 'subTxt = Último dado recebido há  min. Atraso na transmissão.;')
text = text.replace('subTxt = ǟltimo dado recebido hǟ  min. Atraso na transmissǟo.;', 'subTxt = Último dado recebido há  min. Atraso na transmissão.;')
text = text.replace('subTxt = Online, mas sem geraǟǟo no momento (noite ou baixa luz).;', 'subTxt = Online, mas sem geração no momento (noite ou baixa luz).;')
text = text.replace('subTxt = Online, mas sem geração no momento (noite ou baixa luz).;', 'subTxt = Online, mas sem geração no momento (noite ou baixa luz).;')
text = text.replace('subTxt = Usina online e ativa ǽ''?o dado de  min atrǟs.;', 'subTxt = Usina online e ativa – dado de  min atrás.;')
text = text.replace('subTxt = Usina online e ativa – dado de  min atrás.;', 'subTxt = Usina online e ativa – dado de  min atrás.;')

text = text.replace('luz.className = semaforo-luz ;', 'luz.className = semaforo-luz ;')
text = text.replace('titulo.textContent =  ;', 'titulo.textContent = ${icone} ;')

with open('C:/laragon/www/monitor.aeonium.com.br/dashboard.php', 'w', encoding='utf-8') as f:
    f.write(text)

print('Fixed!')