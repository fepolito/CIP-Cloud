import pandas as pd
import glob
import json
import warnings
warnings.filterwarnings('ignore', category=UserWarning, module='openpyxl')

f = glob.glob('C:/Users/fernando.polito/OneDrive/pessoal/casa/cpfl/SOLIS/Polito/inversor/*.xls*')[0]

df1 = pd.read_excel(f, nrows=15)
df2 = pd.read_excel(f, skiprows=7, nrows=5)

with open('peek_solis.txt', 'w', encoding='utf-8') as out:
    out.write(f"Arquivo: {f}\n")
    out.write("--- SEM SKIPROWS ---\n")
    out.write(df1.to_string())
    out.write("\n\n--- COM SKIPROWS=7 ---\n")
    out.write(df2.to_string())
    out.write("\n\nCOLUMNS:\n")
    out.write(", ".join([str(c) for c in df2.columns]))
