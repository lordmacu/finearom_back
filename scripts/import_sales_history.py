#!/usr/bin/env python3
"""
Importa HISTORICO VENTAS .xlsx directo a MySQL.
Se llama desde Laravel: python3 import_sales_history.py <ruta_archivo>

Campos importados: nit, codigo, año, mes, venta, cantidad, newwin
Los demás (cliente, ejecutivo, categoria, referencia) se obtienen
por JOIN con clients y products ya existentes en el sistema.
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

# ── Leer .env de Laravel ──────────────────────────────────────────────────────
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

env = parse_env(ENV_PATH)

DB_HOST = os.environ.get("DB_HOST") or env.get("DB_HOST", "127.0.0.1")
DB_PORT = int(os.environ.get("DB_PORT") or env.get("DB_PORT", 3306))
DB_NAME = os.environ.get("DB_DATABASE") or env.get("DB_DATABASE", "finearom")
DB_USER = os.environ.get("DB_USERNAME") or env.get("DB_USERNAME", "root")
DB_PASS = os.environ.get("DB_PASSWORD") or env.get("DB_PASSWORD", "")

NOW = datetime.now().strftime("%Y-%m-%d %H:%M:%S")

# ── 1. Leer Excel ─────────────────────────────────────────────────────────────
print("Leyendo archivo...", flush=True)
df = pd.read_excel(XLSX_PATH, header=0, dtype=str, engine="openpyxl")
print(f"Filas raw: {len(df)}", flush=True)

# ── 2. Mapear columnas por posición ───────────────────────────────────────────
cols = df.columns.tolist()
col_map = {
    cols[0]:  "nit",
    cols[6]:  "codigo",
    cols[9]:  "año",
    cols[10]: "mes",
    cols[11]: "venta",
    cols[12]: "cantidad",
    cols[13]: "newwin_raw",
}
df = df.rename(columns=col_map)

# ── 3. Limpiar y transformar ──────────────────────────────────────────────────
df["nit"]    = df["nit"].fillna("").str.strip()
df["codigo"] = df["codigo"].fillna("").str.strip()
df["año"]    = df["año"].fillna("").str.strip()
df["mes"]    = df["mes"].fillna("").str.strip().str.upper()

# Filtrar filas sin los 4 campos clave
mask     = (df["nit"] != "") & (df["codigo"] != "") & (df["año"] != "") & (df["mes"] != "")
saltadas = (~mask).sum()
df       = df[mask].copy()
print(f"Filas saltadas: {saltadas} | A insertar: {len(df)}", flush=True)

df["venta"]    = pd.to_numeric(df["venta"],    errors="coerce").fillna(0).astype(int)
df["cantidad"] = pd.to_numeric(df["cantidad"], errors="coerce").fillna(0).astype(int)
df["newwin"]   = (df["newwin_raw"].fillna("").str.strip() == "NEW WIN").astype(int)
df["created_at"] = NOW
df["updated_at"] = NOW

df_final = df[["nit", "codigo", "año", "mes", "venta", "cantidad", "newwin", "created_at", "updated_at"]]

# ── 4. Insertar en MySQL ──────────────────────────────────────────────────────
print(f"Conectando a MySQL {DB_HOST}:{DB_PORT}...", flush=True)
conn   = pymysql.connect(host=DB_HOST, port=DB_PORT, user=DB_USER, password=DB_PASS, database=DB_NAME, charset="utf8mb4")
cursor = conn.cursor()

print("Vaciando tabla...", flush=True)
cursor.execute("DELETE FROM sales_history")

sql = """
    INSERT INTO sales_history (nit, codigo, año, mes, venta, cantidad, newwin, created_at, updated_at)
    VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s)
"""

CHUNK = 2000
rows  = df_final.astype(object).where(pd.notnull(df_final), None).values.tolist()

print("Insertando...", flush=True)
for i in range(0, len(rows), CHUNK):
    cursor.executemany(sql, rows[i:i+CHUNK])
    conn.commit()
    print(f"  {min(i+CHUNK, len(rows))}/{len(rows)}", flush=True)

cursor.execute("SELECT COUNT(*) FROM sales_history")
total = cursor.fetchone()[0]

cursor.close()
conn.close()

print(f"OK:{total}", flush=True)
