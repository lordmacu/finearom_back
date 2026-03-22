#!/usr/bin/env python3
"""
Importa pronósticos manuales desde el Excel BD COMERCIAL a sales_forecasts.
modelo = 'manual', confianza = 'alta', lower_bound = NULL, upper_bound = NULL.

Uso: python3 import_manual_forecasts.py <ruta_archivo.xlsx>

Estructura esperada del Excel:
  - Fila 4 (índice 4): headers reales — NIT, CÓDIGO, P Enero, P Febrero, ...
  - Filas 5+: datos por cliente+producto
  - Columnas P [Mes] 2025: índices 8,13,19,24,29,34,39,46,51,56,61,66
  - Columnas P [Mes] 2026: índices 71,75,79,83,87,91
"""

import sys
import os
import re
import pandas as pd
import pymysql
from datetime import datetime

# ── Argumentos ────────────────────────────────────────────────────────────────
if len(sys.argv) < 2:
    print("ERROR: Falta la ruta del archivo", file=sys.stderr)
    sys.exit(1)

XLSX_PATH = sys.argv[1]
if not os.path.exists(XLSX_PATH):
    print(f"ERROR: Archivo no encontrado: {XLSX_PATH}", file=sys.stderr)
    sys.exit(1)

# ── Leer .env ─────────────────────────────────────────────────────────────────
SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
ENV_PATH   = os.path.join(SCRIPT_DIR, "..", ".env")

def parse_env(path):
    env = {}
    with open(path) as f:
        for line in f:
            line = line.strip()
            if not line or line.startswith("#"):
                continue
            match = re.match(r'^([A-Z0-9_]+)=(.*)$', line)
            if match:
                key, val = match.groups()
                env[key] = val.strip('"').strip("'")
    return env

env     = parse_env(ENV_PATH)
DB_HOST = os.environ.get("DB_HOST") or env.get("DB_HOST", "127.0.0.1")
DB_PORT = int(os.environ.get("DB_PORT") or env.get("DB_PORT", 3306))
DB_NAME = os.environ.get("DB_DATABASE") or env.get("DB_DATABASE", "finearom")
DB_USER = os.environ.get("DB_USERNAME") or env.get("DB_USERNAME", "root")
DB_PASS = os.environ.get("DB_PASSWORD") or env.get("DB_PASSWORD", "")

NOW = datetime.now().strftime("%Y-%m-%d %H:%M:%S")

# ── Mapa de columnas de pronóstico por año/mes ────────────────────────────────
# col_idx → (año, mes)
FORECAST_COLS = {
    8:  ("2025", "ENERO"),
    13: ("2025", "FEBRERO"),
    19: ("2025", "MARZO"),
    24: ("2025", "ABRIL"),
    29: ("2025", "MAYO"),
    34: ("2025", "JUNIO"),
    39: ("2025", "JULIO"),
    46: ("2025", "AGOSTO"),
    51: ("2025", "SEPTIEMBRE"),
    56: ("2025", "OCTUBRE"),
    61: ("2025", "NOVIEMBRE"),
    66: ("2025", "DICIEMBRE"),
    71: ("2026", "ENERO"),
    75: ("2026", "FEBRERO"),
    79: ("2026", "MARZO"),
    83: ("2026", "ABRIL"),
    87: ("2026", "MAYO"),
    91: ("2026", "JUNIO"),
}

# ── Leer Excel ────────────────────────────────────────────────────────────────
print("Leyendo archivo...", flush=True)
raw = pd.read_excel(XLSX_PATH, header=None, dtype=str)

# Fila 4 = headers, filas 5+ = datos
headers = raw.iloc[4].tolist()
data    = raw.iloc[5:].copy()
data.columns = range(len(headers))

# Filtrar solo filas con NIT real (col 0) y CÓDIGO (col 2)
data["_nit"]    = data[0].fillna("").str.strip()
data["_codigo"] = data[2].fillna("").str.strip()
data = data[(data["_nit"] != "") & (data["_codigo"] != "") & (data["_nit"] != "NIT")].copy()

print(f"  Filas de datos: {len(data)}", flush=True)

# ── Construir filas para insertar ─────────────────────────────────────────────
rows = []
skipped = 0

for _, row in data.iterrows():
    nit    = row["_nit"]
    codigo = row["_codigo"]

    for col_idx, (año, mes) in FORECAST_COLS.items():
        raw_val = str(row.get(col_idx, "")).strip()

        # Ignorar vacíos, nan, 0
        if raw_val in ("", "nan", "None", "0", "0.0"):
            skipped += 1
            continue

        try:
            cantidad = int(round(float(raw_val)))
        except (ValueError, TypeError):
            skipped += 1
            continue

        if cantidad <= 0:
            skipped += 1
            continue

        rows.append((nit, codigo, "manual", año, mes, cantidad, None, None, "alta", NOW))

print(f"  Pronósticos a insertar: {len(rows)} | Ceros/vacíos omitidos: {skipped}", flush=True)

# ── Conectar y guardar en DB ──────────────────────────────────────────────────
print(f"Conectando a MySQL {DB_HOST}:{DB_PORT}...", flush=True)
conn   = pymysql.connect(host=DB_HOST, port=DB_PORT, user=DB_USER,
                         password=DB_PASS, database=DB_NAME, charset="utf8mb4")
cursor = conn.cursor()

# Borrar solo pronósticos manuales previos (los algorítmicos se conservan)
cursor.execute("DELETE FROM sales_forecasts WHERE modelo = 'manual'")
print(f"  Pronósticos manuales previos eliminados.", flush=True)

sql = """
    INSERT INTO sales_forecasts
        (nit, codigo, modelo, año, mes, cantidad_forecast, lower_bound, upper_bound, confianza, generated_at)
    VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
"""

CHUNK = 2000
for i in range(0, len(rows), CHUNK):
    cursor.executemany(sql, rows[i:i+CHUNK])
    conn.commit()
    print(f"  {min(i+CHUNK, len(rows))}/{len(rows)}", flush=True)

cursor.execute("SELECT COUNT(*) FROM sales_forecasts WHERE modelo = 'manual'")
total = cursor.fetchone()[0]
cursor.close()
conn.close()

print(f"OK:{total}", flush=True)
