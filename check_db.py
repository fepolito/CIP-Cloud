import pymysql

def main():
    db = pymysql.connect(
        host='localhost',
        user='root',
        password='',
        database='aeoniu71_monitor',
        charset='utf8mb4',
        cursorclass=pymysql.cursors.DictCursor
    )
    
    query = """
    SELECT 
        DATE(CONVERT_TZ(timestamp_utc, 'UTC', 'America/Sao_Paulo')) as dia, 
        COUNT(potencia_geracao_w) as lidas_geracao,
        SUM(CASE WHEN potencia_geracao_w IS NULL THEN 1 ELSE 0 END) as nulas_geracao,
        SUM(CASE WHEN potencia_geracao_w = 0 THEN 1 ELSE 0 END) as zero_geracao,
        SUM(energia_geracao_kwh) as total_geracao_kwh
    FROM telemetria_5min 
    WHERE controlador_id = 3 
      AND timestamp_utc >= '2026-05-30'
    GROUP BY dia 
    ORDER BY dia;
    """
    
    with db.cursor() as cursor:
        cursor.execute(query)
        rows = cursor.fetchall()
        
    print("RESUMO DIARIO DO BANCO DE DADOS (Local):")
    print(f"{'Data':<12} | {'Leituras (Não Nulas)':<22} | {'Nulas (NULL)':<15} | {'Zeradas (0W)':<15} | {'Energia (kWh)':<15}")
    print("-" * 85)
    for r in rows:
        dia = str(r['dia'])
        lidas = r['lidas_geracao']
        nulas = r['nulas_geracao']
        zeros = r['zero_geracao']
        kwh = r['total_geracao_kwh'] if r['total_geracao_kwh'] else 0.0
        print(f"{dia:<12} | {lidas:<22} | {nulas:<15} | {zeros:<15} | {kwh:.2f}")

    db.close()

if __name__ == '__main__':
    main()
