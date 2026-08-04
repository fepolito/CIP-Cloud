#!/usr/bin/env python3
# ============================================================
# @arquivo: import_solis_xlsx.py
# @versao: 1.5.2
# @modificado_em: 2026-06-28
# @objetivo: Lê múltiplos XLSX/XLS de um diretório, mantém buracos como NULL (sem forçar 0)
# @autor: Antigravity
# ============================================================

# @premissa: idempotente por design (apenas UPDATE por id, sem INSERT).
#            Linhas de 5min sao criadas previamente pelo upload CIP/Copilot.
#            SQL de prod casa por 'id' via reconciliacao/interpolacao dev<->prod.
#            SOLUCAO PALIATIVA ate integracao direta (API/conexao inversor).

import argparse
import pandas as pd
import numpy as np
import pymysql
from datetime import timedelta
import sys
import os
import glob

def get_db_connection():
    return pymysql.connect(
        host='localhost',
        user='root',
        password='',
        database='aeoniu71_monitor',
        charset='utf8mb4',
        cursorclass=pymysql.cursors.DictCursor
    )

def main():
    parser = argparse.ArgumentParser(description="Importador Solis XLSX para telemetria_5min")
    parser.add_argument('--file', help="Caminho de um arquivo XLSX especifico")
    parser.add_argument('--dir', help="Caminho de um diretorio contendo arquivos XLSX")
    parser.add_argument('--controlador-id', type=int, default=3, help="ID do controlador (default: 3)")
    parser.add_argument('--commit', action='store_true', help="Efetiva as alteracoes no banco. Se omitido, roda em dry-run")
    parser.add_argument('--export-sql', help="Caminho do arquivo .sql de saida para aplicar no banco de producao")
    args = parser.parse_args()

    if not args.file and not args.dir:
        print("Erro: Voce deve especificar --file ou --dir.")
        sys.exit(1)

    db = get_db_connection()
    with db.cursor() as cursor:
        cursor.execute("SELECT id, apelido as nome, timezone FROM controladores WHERE id=%s", (args.controlador_id,))
        ctrl = cursor.fetchone()
        if not ctrl:
            print(f"Erro: Controlador {args.controlador_id} nao encontrado.")
            sys.exit(1)

    # 1. Coletar arquivos a processar
    arquivos = []
    if args.file:
        arquivos.append(args.file)
    if args.dir:
        padrao_xls = os.path.join(args.dir, "*.xls*")
        arquivos_dir = glob.glob(padrao_xls)
        # Filtra apenas ext .xls e .xlsx
        arquivos.extend([f for f in arquivos_dir if f.endswith('.xls') or f.endswith('.xlsx')])
        
    if not arquivos:
        print("Nenhum arquivo XLS/XLSX encontrado.")
        sys.exit(1)

    print(f"Encontrados {len(arquivos)} arquivos para processamento.")

    # 2. Ler e concatenar todos os DataFrames
    dfs = []
    for arquivo in arquivos:
        try:
            print(f"Lendo {os.path.basename(arquivo)}...")
            # Auto-detect header row
            df_peek = pd.read_excel(arquivo, dtype=str, nrows=20, header=None)
            header_idx = None
            for idx, row in df_peek.iterrows():
                row_vals = [str(x).strip() for x in row.values if pd.notnull(x)]
                if 'Time' in row_vals and any('Total Inverter Power' in str(x) for x in row_vals):
                    header_idx = idx
                    break
            
            if header_idx is None:
                print(f"Aviso: Cabecalho nao encontrado no arquivo {os.path.basename(arquivo)}. Ignorando.")
                continue

            df_temp = pd.read_excel(arquivo, dtype=str, skiprows=header_idx)
            df_temp = df_temp[df_temp['Number'] != 'Number'].copy()
            dfs.append(df_temp)
        except Exception as e:
            print(f"Erro ao ler {arquivo}: {e}")
            continue

    if not dfs:
        print("Nenhum dado valido extraido dos arquivos.")
        sys.exit(1)

    df_xlsx = pd.concat(dfs, ignore_index=True)

    df_xlsx['Time_Parsed'] = pd.to_datetime(df_xlsx['Time'].str.replace(' (UTC-03:00)', '', regex=False), format='%d/%m/%Y %H:%M:%S', errors='coerce')
    df_xlsx.dropna(subset=['Time_Parsed'], inplace=True)
    df_xlsx['timestamp_utc'] = df_xlsx['Time_Parsed'] + timedelta(hours=3)
    df_xlsx['Power_W'] = pd.to_numeric(df_xlsx['Total Inverter Power(W)'], errors='coerce').fillna(0.0)
    
    # Sort and remove duplicated timestamps from XLSX (keep first)
    df_xlsx.sort_values('timestamp_utc', inplace=True)
    df_xlsx = df_xlsx[~df_xlsx['timestamp_utc'].duplicated(keep='first')]

    if df_xlsx.empty:
        print("Nenhum dado valido de tempo.")
        sys.exit(1)

    min_ts = df_xlsx['timestamp_utc'].min() - timedelta(minutes=10)
    max_ts = df_xlsx['timestamp_utc'].max() + timedelta(minutes=10)
    print(f"Faixa de tempo global: de {df_xlsx['timestamp_utc'].min()} a {df_xlsx['timestamp_utc'].max()} (UTC)")

    # Obter dados do DB
    with db.cursor() as cursor:
        cursor.execute("""
            SELECT id, timestamp_utc, potencia_importada_w, potencia_exportada_w
            FROM telemetria_5min
            WHERE controlador_id = %s
              AND timestamp_utc >= %s AND timestamp_utc <= %s
            ORDER BY timestamp_utc ASC
        """, (args.controlador_id, min_ts, max_ts))
        db_rows = cursor.fetchall()
        
    if not db_rows:
        print("Nenhum dado encontrado no banco de dados para o intervalo de tempo lido.")
        sys.exit(0)

    df_db = pd.DataFrame(db_rows)
    df_db['imp'] = pd.to_numeric(df_db['potencia_importada_w'], errors='coerce').fillna(0.0)
    df_db['exp'] = pd.to_numeric(df_db['potencia_exportada_w'], errors='coerce').fillna(0.0)

    # PREPARAR INTERPOLACAO
    df_db.set_index('timestamp_utc', inplace=True)
    df_xlsx.set_index('timestamp_utc', inplace=True)

    combined_idx = df_db.index.union(df_xlsx.index).sort_values()
    df_combined = pd.DataFrame(index=combined_idx)

    # Inserir Power_W do XLSX e interpolar pelo tempo
    df_combined['Power_W'] = df_xlsx['Power_W']
    
    # Limita a interpolacao a 1 hora (aprox 12 periodos de 5 min).
    # Isso impede que o script invente geracao durante toda a noite ou
    # em semanas faltando.
    df_combined['Power_W'] = df_combined['Power_W'].interpolate(method='time', limit=12)
    
    # Mantem os buracos (noites, dias sem arquivo) como NaN para gravar NULL no banco
    # Nao preenche com 0, garantindo que "sem dado = sem dado"

    # Voltar aos timestamps exatos do banco
    df_res = df_combined.loc[df_db.index].copy()
    df_res['id'] = df_db['id']
    df_res['imp'] = df_db['imp']
    df_res['exp'] = df_db['exp']
    df_res.reset_index(inplace=True)  # 'timestamp_utc' volta a ser coluna
    df_res.sort_values('timestamp_utc', inplace=True)
    
    # Calcular energia trapezoidal e consumo final
    df_res['E_kwh'] = 0.0
    
    prev_time = None
    prev_power = 0.0
    
    anomalias_null = 0
    ruidos_zerados = 0
    atualizados = 0

    update_sql = """
        UPDATE telemetria_5min 
        SET potencia_geracao_w = %s, 
            energia_geracao_kwh = %s,
            potencia_consumo_total_w = %s,
            geracao_origem = 'inversor',
            status_inversor = 'on line'
        WHERE id = %s
    """

    print(f"\n--- PROCESSAMENTO DAS LEITURAS (v1.5.1 - LOTE DIRETORIO) ---")
    if args.export_sql:
        print(f"Gerando arquivo SQL: {args.export_sql}")
        f_sql = open(args.export_sql, 'w', encoding='utf-8')
        f_sql.write("-- Script de atualizacao de dados gerado pelo import_solis_xlsx.py\n")
        f_sql.write("-- Controlador ID: {}\n".format(args.controlador_id))
        f_sql.write("BEGIN;\n\n")

    for idx, row in df_res.iterrows():
        curr_time = row['timestamp_utc']
        curr_power = row['Power_W']
        
        # Trapezoidal
        if prev_time is None or prev_time.date() != curr_time.date() or pd.isna(prev_power) or pd.isna(curr_power):
            e_kwh = 0.0
        else:
            delta_seg = (curr_time - prev_time).total_seconds()
            if delta_seg < 0: delta_seg = 0
            e_kwh = ((prev_power + curr_power) / 2) * (delta_seg / 3600.0 / 1000.0)
            
        df_res.at[idx, 'E_kwh'] = e_kwh
        prev_time = curr_time
        prev_power = curr_power

        # Consumo Total
        if pd.isna(curr_power):
            c_final = None
            sql_power_db = None
            sql_power_str = 'NULL'
        else:
            sql_power_db = curr_power
            sql_power_str = str(curr_power)
            
            c_raw = row['imp'] + curr_power - row['exp']
            c_final = c_raw
            
            # Regra de Clamp / Anomalia
            if c_raw < 0:
                if abs(c_raw) <= 50:
                    c_final = 0.0
                    ruidos_zerados += 1
                else:
                    c_final = None  # NULL no banco
                    anomalias_null += 1
                
        if args.commit and not args.export_sql:
            with db.cursor() as cursor:
                cursor.execute(update_sql, (sql_power_db, e_kwh, c_final, row['id']))
            atualizados += 1
            
        if args.export_sql:
            val_c = 'NULL' if c_final is None else str(c_final)
            f_sql.write(f"UPDATE telemetria_5min SET potencia_geracao_w = {sql_power_str}, energia_geracao_kwh = {e_kwh}, potencia_consumo_total_w = {val_c}, geracao_origem = 'inversor', status_inversor = 'on line' WHERE id = {row['id']};\n")
            atualizados += 1

    if args.export_sql:
        f_sql.write("\nCOMMIT;\n")
        f_sql.close()
        
    if args.commit and not args.export_sql:
        db.commit()
    db.close()

    print("\n--- RESUMO FINAL ---")
    if args.export_sql:
        print(f"Modo: EXPORT SQL (Gerou arquivo {args.export_sql})")
    elif args.commit:
        print("Modo: COMMIT (gravado localmente)")
    else:
        print("Modo: DRY-RUN (simulacao)")
    print(f"Updates Finais gerados: {len(df_res)}")
    print(f"Consumo Zerado (Ruido): {ruidos_zerados}")
    print(f"Consumo NULL (Anomalia): {anomalias_null}")

if __name__ == '__main__':
    main()
