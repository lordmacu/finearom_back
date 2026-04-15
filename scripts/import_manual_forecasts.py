#!/usr/bin/env python3
"""
Importa pronósticos manuales desde el Excel "BD COMERCIAL PRONOSTICOS …" hacia la
tabla `sales_forecasts` con modelo='manual'.

DETECCIÓN 100% DINÁMICA:
  - Localiza la fila de headers buscando la que contenga "NIT" + al menos una "P <Mes>".
  - Detecta todas las columnas "P <Mes>" por regex, en orden izquierda→derecha.
  - Para cada "P <Mes>" ubica su columna "Ventas <Mes>" adyacente a la derecha.
  - Infiere el AÑO calendario para cada mes usando las columnas Ventas:
      * El último mes cuya columna Ventas tiene datos reales se toma como ANCLA.
      * El año del ancla = today.year si ancla.mes <= today.month, sino today.year-1.
      * Se propaga el calendario hacia izquierda (meses pasados) y derecha (futuros).
  - Soporta columnas nuevas agregadas a la derecha en meses subsiguientes.
  - Soporta columnas auxiliares insertadas en medio (PLANEACION, Cumplimiento, etc.).

Uso:
    python3 import_manual_forecasts.py <archivo.xlsx>              # import real
    python3 import_manual_forecasts.py <archivo.xlsx> --dry-run    # preview JSON
    python3 import_manual_forecasts.py <archivo.xlsx> --year=2026  # fuerza año del ancla

Salida: SIEMPRE una sola línea JSON en stdout (ok=true/false + detalles).
"""

import sys
import os
import re
import json
import pandas as pd
import pymysql
from datetime import datetime, date

# ── Helpers JSON out ──────────────────────────────────────────────────────────
def emit(obj):
    print(json.dumps(obj, ensure_ascii=False, default=str), flush=True)

def fail(msg, **extra):
    emit({"ok": False, "error": msg, **extra})
    sys.exit(1)

# ── Args ──────────────────────────────────────────────────────────────────────
if len(sys.argv) < 2:
    fail("Falta la ruta del archivo")

XLSX_PATH   = sys.argv[1]
DRY_RUN     = "--dry-run" in sys.argv[2:]
FORCE_YEAR  = None
for a in sys.argv[2:]:
    if a.startswith("--year="):
        try:
            FORCE_YEAR = int(a.split("=", 1)[1])
        except ValueError:
            fail(f"Valor inválido en {a}")

if not os.path.exists(XLSX_PATH):
    fail(f"Archivo no encontrado: {XLSX_PATH}")

# ── Constantes ────────────────────────────────────────────────────────────────
MESES_ES = ['ENERO','FEBRERO','MARZO','ABRIL','MAYO','JUNIO',
            'JULIO','AGOSTO','SEPTIEMBRE','OCTUBRE','NOVIEMBRE','DICIEMBRE']
MES_NUM = {m: i + 1 for i, m in enumerate(MESES_ES)}

# Normaliza variantes: "Sep", "Nov.", "noviembre" → "SEPTIEMBRE", "NOVIEMBRE", ...
MES_ALIAS = {
    'ENERO': 'ENERO', 'ENE': 'ENERO',
    'FEBRERO': 'FEBRERO', 'FEB': 'FEBRERO',
    'MARZO': 'MARZO', 'MAR': 'MARZO',
    'ABRIL': 'ABRIL', 'ABR': 'ABRIL',
    'MAYO': 'MAYO', 'MAY': 'MAYO',
    'JUNIO': 'JUNIO', 'JUN': 'JUNIO',
    'JULIO': 'JULIO', 'JUL': 'JULIO',
    'AGOSTO': 'AGOSTO', 'AGO': 'AGOSTO',
    'SEPTIEMBRE': 'SEPTIEMBRE', 'SEP': 'SEPTIEMBRE', 'SEPT': 'SEPTIEMBRE',
    'OCTUBRE': 'OCTUBRE', 'OCT': 'OCTUBRE',
    'NOVIEMBRE': 'NOVIEMBRE', 'NOV': 'NOVIEMBRE',
    'DICIEMBRE': 'DICIEMBRE', 'DIC': 'DICIEMBRE',
}

MES_REGEX = r'(?P<mes>ENERO|ENE|FEBRERO|FEB|MARZO|MAR|ABRIL|ABR|MAYO|MAY|JUNIO|JUN|JULIO|JUL|AGOSTO|AGO|SEPTIEMBRE|SEPT|SEP|OCTUBRE|OCT|NOVIEMBRE|NOV|DICIEMBRE|DIC)'
P_MES_RE      = re.compile(r'^\s*P\s+(?:TOTAL\s+)?' + MES_REGEX + r'\.?\s*$', re.IGNORECASE)
VENTAS_MES_RE = re.compile(r'^\s*' + MES_REGEX + r'\.?\s*$', re.IGNORECASE)

def norm_cell(v):
    if v is None:
        return ""
    s = str(v).strip()
    if s.lower() == "nan":
        return ""
    return s

def norm_mes(raw):
    key = raw.strip().upper().rstrip('.')
    return MES_ALIAS.get(key)

# ── Leer .env ─────────────────────────────────────────────────────────────────
SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
ENV_PATH   = os.path.join(SCRIPT_DIR, "..", ".env")

def parse_env(path):
    env = {}
    if not os.path.exists(path):
        return env
    try:
        with open(path) as f:
            for line in f:
                line = line.strip()
                if not line or line.startswith("#"):
                    continue
                m = re.match(r'^([A-Z0-9_]+)=(.*)$', line)
                if m:
                    k, v = m.groups()
                    env[k] = v.strip('"').strip("'")
    except PermissionError:
        # El usuario web (daemon) puede no tener permiso de lectura sobre .env.
        # En ese caso confiamos en las env vars ya exportadas por PHP.
        pass
    return env

env = parse_env(ENV_PATH)
DB_HOST = os.environ.get("DB_HOST")     or env.get("DB_HOST", "127.0.0.1")
DB_PORT = int(os.environ.get("DB_PORT") or env.get("DB_PORT", 3306))
DB_NAME = os.environ.get("DB_DATABASE") or env.get("DB_DATABASE", "finearom")
DB_USER = os.environ.get("DB_USERNAME") or env.get("DB_USERNAME", "root")
DB_PASS = os.environ.get("DB_PASSWORD") or env.get("DB_PASSWORD", "")

NOW = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
TODAY = date.today()

# ── Leer Excel crudo ──────────────────────────────────────────────────────────
try:
    raw = pd.read_excel(XLSX_PATH, header=None, dtype=object, engine="openpyxl")
except Exception as e:
    fail(f"No se pudo leer el Excel: {e}")

if raw.empty or raw.shape[0] < 2:
    fail("El archivo está vacío o no tiene datos")

# ── 1. Localizar fila de headers ──────────────────────────────────────────────
# Busca la fila que tenga "NIT" en alguna celda Y al menos 1 "P <Mes>".
header_row_idx = None
for idx in range(min(raw.shape[0], 20)):  # máx primeras 20 filas
    cells = [norm_cell(v) for v in raw.iloc[idx].tolist()]
    has_nit = any(c.upper() == 'NIT' for c in cells)
    has_p_mes = any(P_MES_RE.match(c) for c in cells)
    if has_nit and has_p_mes:
        header_row_idx = idx
        break

if header_row_idx is None:
    fail("No se encontró la fila de headers (debe contener 'NIT' y alguna columna 'P <Mes>')")

headers = [norm_cell(v) for v in raw.iloc[header_row_idx].tolist()]

# ── 2. Detectar columnas NIT y CÓDIGO ─────────────────────────────────────────
# El código que necesitamos es el CÓDIGO CORTO del producto (el que matchea con
# `products.code`, ej "10388"), NO el "CÓDIGO DE BÚSQUEDA" (NIT+código concat).
# Heurística: en el Excel "BD COMERCIAL", el código corto es la columna
# inmediatamente anterior a "NOMBRE REF" (o "REFERENCIA"/"REF").
col_nit       = None
col_codigo    = None
col_nombre_ref = None
for j, h in enumerate(headers):
    u = h.upper()
    if col_nit is None and u == 'NIT':
        col_nit = j
    if col_nombre_ref is None and (
        u == 'NOMBRE REF' or u == 'NOMBRE DE REF'
        or u == 'REFERENCIA' or u == 'NOMBRE REFERENCIA'
        or u == 'REF'
    ):
        col_nombre_ref = j

if col_nombre_ref is not None and col_nombre_ref > 0:
    col_codigo = col_nombre_ref - 1

# Fallback: "CÓDIGO DE BÚSQUEDA" (menos preciso — no matchea con products.code)
codigo_warning = None
if col_codigo is None:
    for j, h in enumerate(headers):
        u = h.upper()
        if 'CÓDIGO DE BÚSQUEDA' in u or 'CODIGO DE BUSQUEDA' in u:
            col_codigo = j
            codigo_warning = (
                f"Se usó 'CÓDIGO DE BÚSQUEDA' (col {j}) como código porque no se "
                f"encontró una columna 'NOMBRE REF' para ubicar el código corto. "
                f"Es posible que los códigos NO matcheen con products.code."
            )
            break

# Último fallback: cualquier header que contenga "CÓDIGO"
if col_codigo is None:
    for j, h in enumerate(headers):
        u = h.upper()
        if 'CÓDIGO' in u or 'CODIGO' in u:
            col_codigo = j
            codigo_warning = (
                f"Se usó '{headers[j]}' (col {j}) como código por fallback. "
                f"Verifica que matchee con products.code."
            )
            break

if col_nit is None:
    fail("No se encontró columna 'NIT' en la fila de headers")
if col_codigo is None:
    fail("No se encontró columna de código de producto (se buscó 'NOMBRE REF' + previa, o 'CÓDIGO DE BÚSQUEDA')")

# ── 3. Detectar bloques de "P <Mes>" y sus "Ventas <Mes>" adyacentes ──────────
p_blocks = []  # lista ordenada de dicts {mes, col_p, col_ventas}
for j, h in enumerate(headers):
    m = P_MES_RE.match(h)
    if m:
        mes_norm = norm_mes(m.group('mes'))
        if mes_norm:
            p_blocks.append({
                "mes": mes_norm,
                "col_p": j,
                "col_ventas": None,
            })

if not p_blocks:
    fail("No se detectó ninguna columna 'P <Mes>' en los headers")

# Para cada P, la columna Ventas es la primera "<Mes>" (sin 'P') con el mismo mes
# que aparezca a la derecha, antes del siguiente P.
for i, blk in enumerate(p_blocks):
    next_p_col = p_blocks[i + 1]["col_p"] if i + 1 < len(p_blocks) else len(headers)
    for j in range(blk["col_p"] + 1, next_p_col):
        m = VENTAS_MES_RE.match(headers[j])
        if m:
            mes_aqui = norm_mes(m.group('mes'))
            if mes_aqui == blk["mes"]:
                blk["col_ventas"] = j
                break

# ── 4. Extraer filas de datos y limpiar ───────────────────────────────────────
data_start = header_row_idx + 1
data = raw.iloc[data_start:].copy().reset_index(drop=True)
data.columns = range(len(headers))

def clean_str(v):
    return norm_cell(v)

nits = data[col_nit].map(clean_str)
codigos = data[col_codigo].map(clean_str)
valid_mask = (nits != "") & (codigos != "") & (nits.str.upper() != "NIT")
data = data[valid_mask].copy().reset_index(drop=True)

if data.empty:
    fail("No se encontraron filas de datos válidas (NIT + CÓDIGO no vacíos)")

# ── 5. Contar datos por mes (sólo informativo) ────────────────────────────────
def count_non_zero(series):
    def to_float(v):
        try:
            return float(str(v).replace(",", "").strip())
        except (ValueError, TypeError):
            return 0.0
    vals = series.map(to_float)
    return int((vals > 0).sum())

for blk in p_blocks:
    blk["p_nonzero"]      = count_non_zero(data[blk["col_p"]])
    blk["ventas_nonzero"] = count_non_zero(data[blk["col_ventas"]]) if blk["col_ventas"] is not None else 0

# ── 6. Inferir ancla calendaria ───────────────────────────────────────────────
# Estrategia primaria (robusta, no depende de qué columnas tengan datos reales):
#   la ÚLTIMA aparición en el archivo del mes que coincide con today.month
#   es el mes actual → año = today.year.
# Fallbacks si no existe ese mes en el archivo:
#   - última aparición de today.month - 1  → año = today.year (today.month - 1 calculado)
#   - última aparición que exista hacia atrás (hasta 11 meses)
#   - último bloque del archivo con P>0  (heurística final)
anchor_idx    = None
anchor_reason = ""

if FORCE_YEAR is None:
    # Buscar coincidencia con hoy, hoy-1, hoy-2, ... hasta 12 meses atrás
    for offset in range(0, 12):
        target_month_num = TODAY.month - offset
        target_year      = TODAY.year
        while target_month_num < 1:
            target_month_num += 12
            target_year -= 1
        target_mes_name = MESES_ES[target_month_num - 1]
        # Última aparición del mes en el archivo
        for i in range(len(p_blocks) - 1, -1, -1):
            if p_blocks[i]["mes"] == target_mes_name:
                anchor_idx    = i
                anchor_year   = target_year
                anchor_reason = (
                    f"último '{target_mes_name}' del archivo → calendario = {target_mes_name} {target_year}"
                    + (f" (hoy es {TODAY.strftime('%Y-%m-%d')}, ajustado {offset} mes(es) atrás)" if offset else
                       f" (coincide con el mes actual, hoy es {TODAY.strftime('%Y-%m-%d')})")
                )
                break
        if anchor_idx is not None:
            break

# Fallback final: último bloque con P > 0
if anchor_idx is None:
    for i in range(len(p_blocks) - 1, -1, -1):
        if p_blocks[i]["p_nonzero"] > 0:
            anchor_idx    = i
            anchor_year   = FORCE_YEAR if FORCE_YEAR is not None else TODAY.year
            anchor_reason = (
                "fallback: ningún mes del archivo coincide con el calendario cercano a hoy; "
                f"se ancla el último bloque con pronósticos a {p_blocks[i]['mes']} {anchor_year}"
            )
            break

if anchor_idx is None:
    fail("No se pudo determinar el ancla calendario (archivo sin pronósticos)")

anchor_mes     = p_blocks[anchor_idx]["mes"]
anchor_mes_num = MES_NUM[anchor_mes]

if FORCE_YEAR is not None:
    anchor_year = FORCE_YEAR
    anchor_reason = (
        f"año forzado por parámetro --year={FORCE_YEAR}; "
        f"ancla en última aparición de '{anchor_mes}' del archivo"
    )

# Propagar: cada bloque p_blocks[i] tiene un offset respecto al ancla.
# Asumimos que los bloques son calendario consecutivos (sin saltos).
for i, blk in enumerate(p_blocks):
    offset = i - anchor_idx  # cuántos meses de diferencia
    total_mes = anchor_mes_num + offset
    # Normalizar: año++ cada 12 meses
    year = anchor_year
    mes_num = total_mes
    while mes_num < 1:
        mes_num += 12
        year -= 1
    while mes_num > 12:
        mes_num -= 12
        year += 1
    blk["año"]     = year
    blk["mes_num"] = mes_num
    blk["is_anchor"] = (i == anchor_idx)
    # Sanity: el mes deducido debe coincidir con el nombre del header
    if MESES_ES[mes_num - 1] != blk["mes"]:
        # Hay un salto inesperado en la secuencia del archivo — forzamos por nombre
        # y calculamos año a partir del vecino más cercano
        blk["año"] = year  # dejamos el calculado, pero marcamos warning
        blk["warning_mismatch"] = True

warnings_list = []
if codigo_warning:
    warnings_list.append(codigo_warning)
for blk in p_blocks:
    if blk.get("warning_mismatch"):
        warnings_list.append(
            f"Secuencia no consecutiva: el header '{blk['mes']}' no coincide con el mes calendario esperado "
            f"({MESES_ES[blk['mes_num']-1]}). Revisa que no falten meses en el archivo."
        )

# ── 7. Construir filas a insertar ─────────────────────────────────────────────
inserts = []
skipped_invalid = 0
skipped_zero = 0

def to_int(v):
    try:
        f = float(str(v).replace(",", "").strip())
        if f != f:  # NaN
            return None
        return int(round(f))
    except (ValueError, TypeError):
        return None

per_month_valid = {i: 0 for i in range(len(p_blocks))}

for _, row in data.iterrows():
    nit = clean_str(row[col_nit])
    cod = clean_str(row[col_codigo])
    for i, blk in enumerate(p_blocks):
        raw_val = row[blk["col_p"]]
        cant = to_int(raw_val)
        if cant is None:
            if norm_cell(raw_val) != "":
                skipped_invalid += 1
            continue
        if cant <= 0:
            skipped_zero += 1
            continue
        inserts.append((
            nit, cod, "manual",
            str(blk["año"]), blk["mes"],
            cant, None, None, "alta", NOW,
        ))
        per_month_valid[i] += 1

for i, blk in enumerate(p_blocks):
    blk["valid_count"] = per_month_valid[i]

# ── 8. Armar respuesta de preview ─────────────────────────────────────────────
preview_months = [{
    "año": blk["año"],
    "mes": blk["mes"],
    "col_p": blk["col_p"],
    "col_ventas": blk["col_ventas"],
    "valid_count": blk["valid_count"],
    "p_nonzero": blk["p_nonzero"],
    "ventas_nonzero": blk["ventas_nonzero"],
    "is_anchor": blk["is_anchor"],
} for blk in p_blocks]

years_in_file_list = sorted({blk["año"] for blk in p_blocks})

result = {
    "ok": True,
    "dry_run": DRY_RUN,
    "file_name": os.path.basename(XLSX_PATH),
    "header_row": header_row_idx + 1,  # humano (1-based)
    "col_nit": col_nit,
    "col_codigo": col_codigo,
    "total_data_rows": int(len(data)),
    "months": preview_months,
    "total_inserts": len(inserts),
    "skipped_zero": skipped_zero,
    "skipped_invalid": skipped_invalid,
    "anchor": {
        "año": p_blocks[anchor_idx]["año"],
        "mes": anchor_mes,
        "reason": anchor_reason,
        "today": TODAY.strftime("%Y-%m-%d"),
        "force_year": FORCE_YEAR,
    },
    "years_replaced": years_in_file_list,
    "warnings": warnings_list,
}

if DRY_RUN:
    result["committed"] = False
    emit(result)
    sys.exit(0)

# ── 9. Insertar en DB ─────────────────────────────────────────────────────────
try:
    conn = pymysql.connect(
        host=DB_HOST, port=DB_PORT, user=DB_USER,
        password=DB_PASS, database=DB_NAME, charset="utf8mb4",
    )
except Exception as e:
    fail(f"No se pudo conectar a MySQL: {e}")

cursor = conn.cursor()

try:
    # Borrar sólo los años presentes en el Excel.
    # Los pronósticos manuales de años no incluidos en este archivo se preservan.
    years_in_file = sorted({str(blk["año"]) for blk in p_blocks})
    placeholders  = ",".join(["%s"] * len(years_in_file))
    cursor.execute(
        f"DELETE FROM sales_forecasts WHERE modelo = 'manual' AND año IN ({placeholders})",
        years_in_file,
    )
    deleted = cursor.rowcount

    sql = """
        INSERT INTO sales_forecasts
            (nit, codigo, modelo, año, mes, cantidad_forecast,
             lower_bound, upper_bound, confianza, generated_at)
        VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)
    """

    CHUNK = 2000
    for i in range(0, len(inserts), CHUNK):
        cursor.executemany(sql, inserts[i:i + CHUNK])
    conn.commit()

    cursor.execute("SELECT COUNT(*) FROM sales_forecasts WHERE modelo = 'manual'")
    total = cursor.fetchone()[0]
except Exception as e:
    conn.rollback()
    cursor.close()
    conn.close()
    fail(f"Error al insertar en la BD: {e}")

cursor.close()
conn.close()

result["committed"]      = True
result["inserted"]       = int(total)
result["deleted_previous"] = int(deleted)
emit(result)
