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
        q1 = """
        SELECT COUNT(*) AS linhas_alvo,
               SUM(CASE WHEN (COALESCE(potencia_importada_w,0)
                            + COALESCE(potencia_geracao_w,0)
                            - COALESCE(potencia_exportada_w,0)) < 0
                        THEN 1 ELSE 0 END) AS negativos_restantes
        FROM telemetria_5min
        WHERE controlador_id = 3
          AND timestamp_utc >= '2026-06-22 03:00:00'
          AND (potencia_importada_w IS NOT NULL
               OR potencia_geracao_w IS NOT NULL
               OR potencia_exportada_w IS NOT NULL);
        """
        cursor.execute(q1)
        res1 = cursor.fetchone()
        
        q2 = """
        SELECT COUNT(*) as nulos_consumo
        FROM telemetria_5min
        WHERE controlador_id = 3
          AND timestamp_utc >= '2026-06-22 03:00:00'
          AND potencia_consumo_total_w IS NULL
          AND potencia_geracao_w IS NOT NULL;
        """
        cursor.execute(q2)
        res2 = cursor.fetchone()
        
        with open('scratch_query_result5.txt', 'w', encoding='utf-8') as f:
            f.write("=== RESULTADO DA QUERY DE VALIDACAO ===\n")
            f.write(f"linhas_alvo (pos 22/06): {res1['linhas_alvo']}\n")
            f.write(f"negativos_restantes (pos 22/06): {res1['negativos_restantes']}\n")
            f.write(f"registros_com_consumo_null: {res2['nulos_consumo']}\n")

    db.close()

if __name__ == '__main__':
    main()
