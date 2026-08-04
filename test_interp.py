import pandas as pd
import numpy as np
from datetime import datetime, timedelta

# Create some mock data
times_db = pd.date_range("2026-06-01 10:00", "2026-06-03 10:00", freq="5min")
df_db = pd.DataFrame(index=times_db)
df_db['db_val'] = 1

# Create xlsx data with a huge gap (missing day 2)
times_xlsx = pd.date_range("2026-06-01 10:00", "2026-06-01 12:00", freq="5min").append(
             pd.date_range("2026-06-03 08:00", "2026-06-03 10:00", freq="5min"))
df_xlsx = pd.DataFrame(index=times_xlsx)
df_xlsx['Power_W'] = 100.0

combined_idx = df_db.index.union(df_xlsx.index).sort_values()
df_combined = pd.DataFrame(index=combined_idx)

df_combined['Power_W'] = df_xlsx['Power_W']
df_combined['Power_W_interp'] = df_combined['Power_W'].interpolate(method='time', limit=6)
df_combined['Power_W_interp'] = df_combined['Power_W_interp'].fillna(0)

# Check a gap
print(df_combined.loc["2026-06-01 11:50":"2026-06-01 12:45"])
