import pymysql

db = pymysql.connect(
    host='localhost',
    user='root',
    password='',
    database='aeoniu71_monitor',
    charset='utf8mb4',
    cursorclass=pymysql.cursors.DictCursor
)

queries = [
    ('18/05/2026', '2026-05-18 00:00:00', '2026-05-18 23:59:59'),
    ('28/05/2026', '2026-05-28 00:00:00', '2026-05-28 23:59:59')
]

with db.cursor() as c:
    for data_pt, start, end in queries:
        c.execute('''
            SELECT COUNT(*) as qtd, SUM(energia_geracao_kwh) as geracao_total
            FROM telemetria_5min
            WHERE controlador_id = 3
              AND timestamp_utc >= %s AND timestamp_utc <= %s
              AND geracao_origem = %s
        ''', (start, end, 'inversor'))
        row = c.fetchone()
        ger = float(row['geracao_total']) if row['geracao_total'] is not None else 0.0
        print(f"Dia {data_pt}: {row['qtd']} registros do inversor, Geracao Total: {ger:.2f} kWh")

db.close()
