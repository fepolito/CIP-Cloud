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
    
    with db.cursor() as cursor:
        # Query 1
        q1 = """
        SELECT COUNT(*) AS linhas_alvo
        FROM telemetria_5min
        WHERE controlador_id = 3
          AND (potencia_importada_w IS NOT NULL
               OR potencia_geracao_w IS NOT NULL
               OR potencia_exportada_w IS NOT NULL);
        """
        cursor.execute(q1)
        res1 = cursor.fetchone()
        
        # Query 2
        q2 = """
        SELECT id,
               CONVERT_TZ(timestamp_utc,'UTC','America/Sao_Paulo') AS ts_local,
               COALESCE(potencia_importada_w,0) AS imp,
               COALESCE(potencia_geracao_w,0)   AS ger,
               COALESCE(potencia_exportada_w,0) AS exp,
               (COALESCE(potencia_importada_w,0)
                + COALESCE(potencia_geracao_w,0)
                - COALESCE(potencia_exportada_w,0)) AS consumo_calc
        FROM telemetria_5min
        WHERE controlador_id = 3
        ORDER BY id DESC
        LIMIT 200;
        """
        cursor.execute(q2)
        res2 = cursor.fetchall()
        
        with open('scratch_query_result.txt', 'w', encoding='utf-8') as f:
            f.write("=== RESULTADO DA QUERY 1 ===\n")
            f.write(f"linhas_alvo: {res1['linhas_alvo']}\n\n")
            
            f.write("=== RESULTADO DA QUERY 2 (Amostra 200) ===\n")
            f.write(f"{'id':<8} | {'ts_local':<20} | {'imp':<8} | {'ger':<8} | {'exp':<8} | {'consumo_calc':<12}\n")
            f.write("-" * 75 + "\n")
            for row in res2:
                f.write(f"{row['id']:<8} | {str(row['ts_local']):<20} | {row['imp']:<8.1f} | {row['ger']:<8.1f} | {row['exp']:<8.1f} | {row['consumo_calc']:<12.1f}\n")

    db.close()

if __name__ == '__main__':
    main()
