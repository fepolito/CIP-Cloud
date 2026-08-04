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
        SELECT COUNT(*) AS linhas_alvo,
               SUM(CASE WHEN (COALESCE(potencia_importada_w,0)
                            + COALESCE(potencia_geracao_w,0)
                            - COALESCE(potencia_exportada_w,0)) < 0
                        THEN 1 ELSE 0 END) AS negativos_restantes
        FROM telemetria_5min
        WHERE controlador_id = 3
          AND id <= 21192
          AND (potencia_importada_w IS NOT NULL
               OR potencia_geracao_w IS NOT NULL
               OR potencia_exportada_w IS NOT NULL);
        """
        cursor.execute(q)
        res = cursor.fetchone()
        
        with open('scratch_query_result2.txt', 'w', encoding='utf-8') as f:
            f.write("=== RESULTADO DA QUERY DE TESTE ===\n")
            f.write(f"linhas_alvo: {res['linhas_alvo']}\n")
            f.write(f"negativos_restantes: {res['negativos_restantes']}\n")

    db.close()

if __name__ == '__main__':
    main()
