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
        q = """
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
          AND id <= 21192
          AND timestamp_utc >= '2026-06-22 03:00:00' /* 2026-06-22 00:00:00 local time */
          AND (COALESCE(potencia_importada_w,0)
             + COALESCE(potencia_geracao_w,0)
             - COALESCE(potencia_exportada_w,0)) < 0
        ORDER BY id DESC
        LIMIT 100;
        """
        cursor.execute(q)
        res = cursor.fetchall()
        
        with open('scratch_query_result4.txt', 'w', encoding='utf-8') as f:
            f.write("=== AMOSTRA DE DADOS COM CONSUMO NEGATIVO (A partir de 22/06) ===\n")
            f.write(f"Total Encontrado (limit 100): {len(res)}\n")
            f.write(f"{'id':<8} | {'ts_local':<20} | {'imp':<8} | {'ger':<8} | {'exp':<8} | {'consumo_calc':<12}\n")
            f.write("-" * 75 + "\n")
            for row in res:
                f.write(f"{row['id']:<8} | {str(row['ts_local']):<20} | {row['imp']:<8.1f} | {row['ger']:<8.1f} | {row['exp']:<8.1f} | {row['consumo_calc']:<12.1f}\n")

    db.close()

if __name__ == '__main__':
    main()
