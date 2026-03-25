#!/usr/bin/env python3
"""
Genera pronósticos de ventas para todas las combinaciones cliente+producto.
Modelos: Holt-Winters, Croston, Theta, XGBoost
Se llama desde Laravel: python3 generate_forecasts.py
Lee credenciales de DB desde variables de entorno (Docker) o .env de Laravel.
"""

import sys
import os
import re
import signal
import warnings
import numpy as np
import pandas as pd
import pymysql
from datetime import datetime
from dateutil.relativedelta import relativedelta

# ── Timeout por modelo ────────────────────────────────────────────────────────
MODEL_TIMEOUT = 10  # segundos máximos por modelo/serie

class ModelTimeout(Exception):
    pass

def _timeout_handler(signum, frame):
    raise ModelTimeout("Timeout")

def run_with_timeout(fn, *args, **kwargs):
    """Ejecuta fn con un timeout. Si supera MODEL_TIMEOUT segundos lanza ModelTimeout."""
    signal.signal(signal.SIGALRM, _timeout_handler)
    signal.alarm(MODEL_TIMEOUT)
    try:
        result = fn(*args, **kwargs)
    finally:
        signal.alarm(0)
    return result

# Solo suprimir advertencias de convergencia de statsmodels — no advertencias
# de NumPy (overflow, nan) que indican problemas reales en los datos
from statsmodels.tools.sm_exceptions import ConvergenceWarning
warnings.filterwarnings('ignore', category=ConvergenceWarning)
warnings.filterwarnings('ignore', message='Maximum Likelihood')

# ── Credenciales DB ───────────────────────────────────────────────────────────
SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))

def parse_env(path):
    env = {}
    try:
        with open(path) as f:
            for line in f:
                line = line.strip()
                if not line or line.startswith("#"):
                    continue
                match = re.match(r'^([A-Z0-9_]+)=(.*)$', line)
                if match:
                    key, val = match.groups()
                    env[key] = val.strip('"').strip("'")
    except FileNotFoundError:
        pass
    return env

# Leer backend/.env y raíz — si backend tiene valor vacío, usar el de raíz
env_backend = parse_env(os.path.join(SCRIPT_DIR, "..", ".env"))
env_root    = parse_env(os.path.join(SCRIPT_DIR, "..", "..", ".env"))
env = { k: (env_backend.get(k) or env_root.get(k) or '')
        for k in set(list(env_backend) + list(env_root)) }

DB_HOST = os.environ.get("DB_HOST") or env.get("DB_HOST", "127.0.0.1")
DB_PORT = int(os.environ.get("DB_PORT") or env.get("DB_PORT", 3306))
DB_NAME = os.environ.get("DB_DATABASE") or env.get("DB_DATABASE", "finearom")
DB_USER = os.environ.get("DB_USERNAME") or env.get("DB_USERNAME", "root")
DB_PASS = os.environ.get("DB_PASSWORD") or env.get("DB_PASSWORD", "")


# ── Constantes ────────────────────────────────────────────────────────────────
MES_NUM = {
    'ENERO':1,'FEBRERO':2,'MARZO':3,'ABRIL':4,'MAYO':5,'JUNIO':6,
    'JULIO':7,'AGOSTO':8,'SEPTIEMBRE':9,'OCTUBRE':10,'NOVIEMBRE':11,'DICIEMBRE':12
}
NUM_MES = {v: k for k, v in MES_NUM.items()}
FORECAST_MONTHS = 4
NOW = datetime.now()
GENERATED_AT = NOW.strftime("%Y-%m-%d %H:%M:%S")

# ── Conectar DB ───────────────────────────────────────────────────────────────
print(f"Conectando a MySQL {DB_HOST}:{DB_PORT}...", flush=True)
conn = pymysql.connect(
    host=DB_HOST, port=DB_PORT, user=DB_USER,
    password=DB_PASS, database=DB_NAME, charset="utf8mb4"
)

# ── Cargar datos ──────────────────────────────────────────────────────────────
print("Cargando sales_history...", flush=True)
# Usar cursor directamente en lugar de pd.read_sql con conexión DBAPI2 cruda
# (pd.read_sql con pymysql está deprecado en pandas 2.x y fallará en pandas 3.0+)
with conn.cursor() as _cur:
    _cur.execute("SELECT nit, codigo, año, mes, cantidad FROM sales_history")
    df = pd.DataFrame(_cur.fetchall(), columns=['nit', 'codigo', 'año', 'mes', 'cantidad'])

# FIX #1: normalizar mes antes del map para evitar NaN por mayúsculas/espacios
df['mes'] = df['mes'].str.strip().str.upper()
df['mes_num'] = df['mes'].map(MES_NUM)

# Descartar filas con mes inválido (nombre no reconocido)
bad_mes = df['mes_num'].isna().sum()
if bad_mes > 0:
    print(f"  ⚠ {bad_mes} registros con mes inválido descartados", flush=True)
    df = df.dropna(subset=['mes_num'])

df['mes_num']  = df['mes_num'].astype(int)

# Validar año antes del cast — igual que mes_num y cantidad
df['año'] = pd.to_numeric(df['año'], errors='coerce')
bad_año = df['año'].isna().sum()
if bad_año > 0:
    print(f"  ⚠ {bad_año} registros con año inválido/NULL descartados", flush=True)
    df = df.dropna(subset=['año'])
df['año'] = df['año'].astype(int)

df['cantidad'] = pd.to_numeric(df['cantidad'], errors='coerce')

# Descartar filas con cantidad NULL, no numérica o negativa
# NULL/NaN rompe modelos silenciosamente; negativos corrompen el entrenamiento de XGBoost
null_cantidad = df['cantidad'].isna().sum()
neg_cantidad  = (df['cantidad'].fillna(0) < 0).sum()
if null_cantidad > 0:
    print(f"  ⚠ {null_cantidad} registros con cantidad inválida/NULL descartados", flush=True)
if neg_cantidad > 0:
    print(f"  ⚠ {neg_cantidad} registros con cantidad negativa descartados", flush=True)
df = df[df['cantidad'].notna() & (df['cantidad'] >= 0)]

df['periodo'] = df['año'] * 100 + df['mes_num']
print(f"  {len(df):,} registros | {df[['nit','codigo']].drop_duplicates().shape[0]:,} combinaciones", flush=True)

# ── Calcular próximos 4 meses ─────────────────────────────────────────────────
future_months = []
base = NOW.replace(day=1)
for i in range(1, FORECAST_MONTHS + 1):
    m = base + relativedelta(months=i)
    future_months.append({'año': m.year, 'mes': NUM_MES[m.month], 'mes_num': m.month})

# ── Construir serie mensual completa (con ceros para meses sin venta) ─────────
def build_series(group):
    """Retorna array numpy con kilos mensuales desde primer mes hasta el último
    mes con datos reales. No se extiende al mes en curso para evitar trailing
    zeros de meses incompletos que sesgarían los modelos hacia abajo."""
    min_p = group['periodo'].min()
    max_p = group['periodo'].max()

    min_y, min_m = divmod(min_p, 100)

    months = []
    y, m = min_y, min_m
    while y * 100 + m <= max_p:
        months.append(y * 100 + m)
        m += 1
        if m > 12:
            m = 1
            y += 1

    lookup = group.groupby('periodo')['cantidad'].sum().to_dict()
    return np.array([lookup.get(p, 0.0) for p in months], dtype=float)

def find_active_start(series, min_gap=12):
    """Detecta el inicio del último período activo del cliente.
    Si existe un gap de min_gap+ meses consecutivos en cero, descarta todo
    lo anterior. Esto evita que períodos de inactividad antigua contaminen
    los componentes estacionales de los modelos.
    Ejemplo: cliente inactivo 18 meses y luego reactivado → solo usa el
    período posterior al gap para ajustar los modelos.
    """
    n = len(series)
    last_gap_end = 0
    i = 0
    while i < n:
        if series[i] == 0:
            j = i + 1
            while j < n and series[j] == 0:
                j += 1
            if j - i >= min_gap:
                last_gap_end = j
            i = j
        else:
            i += 1
    return last_gap_end

# ── Clasificar serie ──────────────────────────────────────────────────────────
def classify(series):
    n = len(series)
    # FIX: serie completamente en cero — no hay qué pronosticar
    if not (series > 0).any():
        return None, 'baja'
    # Evaluar el ratio de ceros sobre los últimos 24 meses (o toda la serie si
    # es más corta). Esto evita que un cliente "renacido" — inactivo mucho tiempo
    # y luego activo regularmente — quede mal clasificado por sus ceros antiguos.
    window = series[-24:] if n >= 24 else series
    zeros  = (window == 0).sum() / len(window)
    if n >= 24 and zeros < 0.4:
        return 'holt_winters', 'alta'
    elif n >= 24 and zeros >= 0.4:
        return 'croston', 'media'
    elif n >= 5:
        return 'theta', 'media'
    else:
        return None, 'baja'

# ── Modelo: Holt-Winters ──────────────────────────────────────────────────────
def forecast_holt_winters(series, n=4):
    from statsmodels.tsa.holtwinters import ExponentialSmoothing
    # Trim: si hay un gap de 12+ meses consecutivos en cero, usar solo el
    # período activo posterior. Evita que inactividades antiguas contaminen
    # los componentes estacionales. Fallback a serie completa si el recorte
    # deja menos de 24 puntos (mínimo para HW con period=12).
    trim_idx = find_active_start(series, min_gap=12)
    s = series[trim_idx:] if trim_idx > 0 and len(series) - trim_idx >= 24 else series
    model = ExponentialSmoothing(
        s, trend='add', seasonal='add', seasonal_periods=12,
        initialization_method='estimated'
    )
    fit  = model.fit(optimized=True, remove_bias=True)
    pred = np.maximum(np.asarray(fit.forecast(n)), 0)
    sim  = fit.simulate(n, repetitions=100, error='add')
    lb   = np.maximum(np.percentile(sim, 10, axis=1), 0)
    ub   = np.maximum(np.percentile(sim, 90, axis=1), 0)
    # FIX: garantizar coherencia lb <= pred <= ub
    lb = np.minimum(lb, pred)
    ub = np.maximum(ub, pred)
    return pred, lb, ub

# ── Modelo: Croston (demanda intermitente) ────────────────────────────────────
def forecast_croston(series, n=4):
    """Método de Croston para demanda intermitente (series con muchos ceros)."""
    s = np.maximum(series, 0)
    nonzero = s[s > 0]

    # FIX #4: serie completamente vacía → pronóstico 0, no 1 kg
    if len(nonzero) == 0:
        pred = np.zeros(n)
        return pred, pred, pred

    alpha = 0.1
    z = nonzero[0]
    p = 1.0
    last_nonzero = -1  # -1 para que la primera demanda en i=0 tenga intervalo=1
    for i, val in enumerate(s):
        interval = i - last_nonzero
        if val > 0:
            z = alpha * val + (1 - alpha) * z
            p = alpha * interval + (1 - alpha) * p
            last_nonzero = i

    pred_val = z / p if p > 0 else 0.0
    pred = np.full(n, max(pred_val, 0))
    return pred, pred * 0.7, pred * 1.3

# ── Modelo: Theta ─────────────────────────────────────────────────────────────
def forecast_theta(series, n=4, start_period=None):
    from statsmodels.tsa.forecasting.theta import ThetaModel
    # Trim: mismo criterio que HW — descartar inactividad de 12+ meses
    # para no contaminar la descomposición estacional.
    # Fallback a serie completa si el recorte deja menos de 5 puntos
    # (mínimo para que ThetaModel pueda ajustarse sin error).
    trim_idx = find_active_start(series, min_gap=12)
    if trim_idx > 0 and len(series) - trim_idx >= 5:
        s_raw = series[trim_idx:]
    else:
        s_raw  = series
        trim_idx = 0
    s = np.maximum(s_raw, 0)
    period = 12 if len(s) >= 24 else (6 if len(s) >= 12 else None)
    # Ajustar start_period al nuevo inicio si se recortó la serie
    if trim_idx > 0 and start_period:
        sy, sm   = int(start_period) // 100, int(start_period) % 100
        total_m  = (sm - 1) + trim_idx
        start_year  = sy + total_m // 12
        start_month = total_m % 12 + 1
    elif start_period:
        start_year  = int(start_period) // 100
        start_month = int(start_period) % 100
    else:
        start_year, start_month = 2000, 1
    idx = pd.date_range(f'{start_year}-{start_month:02d}-01', periods=len(s), freq='MS')
    ts  = pd.Series(s, index=idx, dtype=float)
    fit  = ThetaModel(ts, period=period).fit()
    pred = np.maximum(np.asarray(fit.forecast(n)), 0)
    try:
        pi   = fit.prediction_intervals(n, alpha=0.2)
        lb   = np.maximum(np.asarray(pi['lower']), 0)
        ub   = np.maximum(np.asarray(pi['upper']), 0)
    except Exception:
        lb   = pred * 0.8
        ub   = pred * 1.2
    return pred, lb, ub

# ── Modelo: XGBoost (global) ──────────────────────────────────────────────────
def build_xgb_features(df_all):
    """Construye features para el modelo global XGBoost."""
    rows = []
    groups = df_all.groupby(['nit', 'codigo'])
    for (nit, codigo), grp in groups:
        series = build_series(grp)
        # FIX: mismo mínimo que classify (5) para no entrenar con series
        # que nunca se pronosticarán e introducen ruido con lags casi todos en 0
        if len(series) < 5:
            continue
        start_mes = int(grp['periodo'].min() % 100)
        n_series  = len(series)
        for i in range(n_series):
            mes_i = (start_mes - 1 + i) % 12 + 1
            row = {
                'nit':    nit,
                'codigo': codigo,
                'mes_num': mes_i,
                'pos':    i,
                'n':      n_series,
                'lag1':   series[i-1] if i >= 1 else 0,
                'lag2':   series[i-2] if i >= 2 else 0,
                'lag3':   series[i-3] if i >= 3 else 0,
                'lag6':   series[i-6] if i >= 6 else 0,
                'lag12':  series[i-12] if i >= 12 else 0,
                'roll3':  series[max(0,i-3):i].mean() if i >= 1 else 0,
                'roll6':  series[max(0,i-6):i].mean() if i >= 1 else 0,
                'is_oct': 1 if mes_i == 10 else 0,
                'is_nov': 1 if mes_i == 11 else 0,
                'is_dic': 1 if mes_i == 12 else 0,
                'target': series[i],
            }
            rows.append(row)
    return pd.DataFrame(rows)

print("Entrenando XGBoost global...", flush=True)
from xgboost import XGBRegressor
from sklearn.preprocessing import LabelEncoder

df_feat = build_xgb_features(df)
if df_feat.empty:
    print("ERROR: no hay series con suficiente historia para entrenar XGBoost (mínimo 5 meses).", flush=True)
    conn.close()
    sys.exit(1)

le_nit  = LabelEncoder().fit(df_feat['nit'])
le_cod  = LabelEncoder().fit(df_feat['codigo'])
df_feat['nit_enc']    = le_nit.transform(df_feat['nit'])
df_feat['codigo_enc'] = le_cod.transform(df_feat['codigo'])

FEAT_COLS = ['nit_enc','codigo_enc','mes_num','pos','n',
             'lag1','lag2','lag3','lag6','lag12','roll3','roll6',
             'is_oct','is_nov','is_dic']

xgb = XGBRegressor(n_estimators=200, max_depth=6, learning_rate=0.05,
                   subsample=0.8, colsample_bytree=0.8, random_state=42,
                   n_jobs=-1, verbosity=0)
xgb.fit(df_feat[FEAT_COLS], df_feat['target'])
print("  XGBoost entrenado.", flush=True)

def forecast_xgboost(series, nit, codigo, n=4):
    preds    = []
    s        = list(series)
    nit_enc  = le_nit.transform([nit])[0] if nit in le_nit.classes_ else 0
    cod_enc  = le_cod.transform([codigo])[0] if codigo in le_cod.classes_ else 0
    base_pos = len(s)

    for i in range(n):
        pos     = base_pos + i
        mes_num = future_months[i]['mes_num']
        row = {
            'nit_enc':    nit_enc,
            'codigo_enc': cod_enc,
            'mes_num':    mes_num,
            'pos':        pos,
            # FIX #6: n fijo al tamaño original de la serie, no crece con las predicciones
            'n':          base_pos,
            'lag1':  s[-1] if len(s) >= 1 else 0,
            'lag2':  s[-2] if len(s) >= 2 else 0,
            'lag3':  s[-3] if len(s) >= 3 else 0,
            'lag6':  s[-6] if len(s) >= 6 else 0,
            'lag12': s[-12] if len(s) >= 12 else 0,
            'roll3': np.mean(s[-3:]) if len(s) >= 1 else 0,
            'roll6': np.mean(s[-6:]) if len(s) >= 1 else 0,
            'is_oct': 1 if mes_num == 10 else 0,
            'is_nov': 1 if mes_num == 11 else 0,
            'is_dic': 1 if mes_num == 12 else 0,
        }
        pred = max(float(xgb.predict(pd.DataFrame([row])[FEAT_COLS])[0]), 0)
        preds.append(pred)
        s.append(pred)

    preds = np.array(preds)
    return preds, preds * 0.8, preds * 1.2


# ── Modelo: Prophet ───────────────────────────────────────────────────────────
def forecast_prophet(series, start_period, n=4):
    import logging
    logging.getLogger('prophet').setLevel(logging.ERROR)
    logging.getLogger('cmdstanpy').setLevel(logging.ERROR)
    from prophet import Prophet

    min_y = int(start_period) // 100
    min_m = int(start_period) % 100

    dates = []
    y, m = min_y, min_m
    for _ in range(len(series)):
        dates.append(f'{y}-{m:02d}-01')
        m += 1
        if m > 12:
            m = 1
            y += 1

    df_p = pd.DataFrame({'ds': pd.to_datetime(dates), 'y': np.maximum(series, 0).tolist()})

    yearly = len(series) >= 12
    # Multiplicativo solo si la media es > 0 para evitar división por cero
    s_mode = 'multiplicative' if df_p['y'].mean() > 0 else 'additive'
    model  = Prophet(
        yearly_seasonality=yearly,
        weekly_seasonality=False,
        daily_seasonality=False,
        seasonality_mode=s_mode,
        uncertainty_samples=200,
    )
    model.fit(df_p)

    future   = model.make_future_dataframe(periods=n, freq='MS')
    forecast = model.predict(future)

    pred = np.maximum(forecast['yhat'].values[-n:], 0)
    lb   = np.maximum(forecast['yhat_lower'].values[-n:], 0)
    ub   = np.maximum(forecast['yhat_upper'].values[-n:], 0)
    lb   = np.minimum(lb, pred)
    ub   = np.maximum(ub, pred)
    return pred, lb, ub


# ── Modelo: AutoARIMA ─────────────────────────────────────────────────────────
def forecast_auto_arima(series, start_period, n=4):
    from pmdarima import auto_arima

    s   = np.maximum(series, 0)
    # Periodo estacional: 12 si hay 24+ meses, 6 si hay 12+, 1 (sin estacionalidad) si menos
    m   = 12 if len(s) >= 24 else (6 if len(s) >= 12 else 1)

    model = auto_arima(
        s,
        seasonal=m > 1,
        m=m,
        stepwise=True,
        suppress_warnings=True,
        error_action='ignore',
        information_criterion='aic',
        max_p=3, max_q=3, max_P=2, max_Q=2,
    )
    pred, conf = model.predict(n_periods=n, return_conf_int=True, alpha=0.2)
    pred = np.maximum(pred, 0)
    lb   = np.maximum(conf[:, 0], 0)
    ub   = np.maximum(conf[:, 1], 0)
    lb   = np.minimum(lb, pred)
    ub   = np.maximum(ub, pred)
    return pred, lb, ub


# ── Modelo: Ensemble (promedio de todos los modelos exitosos) ─────────────────
def compute_ensemble(successful_preds, n=4):
    all_pred = np.array([v[0] for v in successful_preds.values()])
    all_lb   = np.array([v[1] for v in successful_preds.values()])
    all_ub   = np.array([v[2] for v in successful_preds.values()])
    return all_pred.mean(axis=0), all_lb.mean(axis=0), all_ub.mean(axis=0)


# ── Procesar todas las series (secuencial — statsmodels no es fork-safe) ──────
print("Generando pronósticos...", flush=True)
groups_list = list(df.groupby(['nit', 'codigo']))
total       = len(groups_list)
results  = []
ok = skipped = errors = 0
error_samples = {}

for idx, ((nit, codigo), grp) in enumerate(groups_list):
    if idx % 200 == 0:
        print(f"  {idx}/{total}...", flush=True)

    series       = build_series(grp)
    start_period = grp['periodo'].min()

    # La clasificación usa la ventana de los últimos 24 meses (ver classify).
    # El trim por gaps largos se aplica internamente en HW y Theta.
    primary_model, confianza = classify(series)

    if primary_model is None:
        skipped += 1
        continue

    if primary_model == 'holt_winters':
        individual_models = ['holt_winters', 'theta', 'xgboost']
    elif primary_model == 'croston':
        individual_models = ['croston', 'xgboost']
    else:  # theta
        individual_models = ['theta', 'xgboost']

    # Correr cada modelo individualmente y colectar los exitosos
    successful_preds = {}
    for model_name in individual_models:
        try:
            if model_name == 'holt_winters':
                pred, lb, ub = run_with_timeout(forecast_holt_winters, series)
            elif model_name == 'croston':
                pred, lb, ub = run_with_timeout(forecast_croston, series)
            elif model_name == 'theta':
                pred, lb, ub = run_with_timeout(forecast_theta, series, start_period=start_period)
            elif model_name == 'xgboost':
                pred, lb, ub = run_with_timeout(forecast_xgboost, series, nit, codigo)
            elif model_name == 'prophet':
                pred, lb, ub = run_with_timeout(forecast_prophet, series, start_period)
            elif model_name == 'auto_arima':
                pred, lb, ub = run_with_timeout(forecast_auto_arima, series, start_period)
            else:
                raise ValueError(f"Modelo desconocido: {model_name}")

            model_confianza = confianza if model_name == primary_model else 'media'
            successful_preds[model_name] = (pred, lb, ub)

            for i, fm in enumerate(future_months):
                results.append({
                    'nit':               nit,
                    'codigo':            codigo,
                    'modelo':            model_name,
                    'año':               str(fm['año']),
                    'mes':               fm['mes'],
                    'cantidad_forecast': max(int(round(pred[i])), 0),
                    'lower_bound':       max(int(round(lb[i])), 0),
                    'upper_bound':       max(int(round(ub[i])), 0),
                    'confianza':         model_confianza,
                    'generated_at':      GENERATED_AT,
                })
            ok += 1
        except ModelTimeout:
            # Timeout silencioso — el modelo se saltea, no cuenta como error crítico
            key = f"{model_name}:Timeout"
            if key not in error_samples:
                error_samples[key] = f"Superó {MODEL_TIMEOUT}s — serie omitida"
        except Exception as e:
            errors += 1
            key = f"{model_name}:{type(e).__name__}"
            if key not in error_samples:
                error_samples[key] = str(e)[:200]

    # Ensemble: promedio de todos los modelos exitosos (mínimo 2)
    if len(successful_preds) >= 2:
        try:
            ens_pred, ens_lb, ens_ub = compute_ensemble(successful_preds)
            for i, fm in enumerate(future_months):
                results.append({
                    'nit':               nit,
                    'codigo':            codigo,
                    'modelo':            'ensemble',
                    'año':               str(fm['año']),
                    'mes':               fm['mes'],
                    'cantidad_forecast': max(int(round(ens_pred[i])), 0),
                    'lower_bound':       max(int(round(ens_lb[i])), 0),
                    'upper_bound':       max(int(round(ens_ub[i])), 0),
                    'confianza':         'alta',
                    'generated_at':      GENERATED_AT,
                })
            ok += 1
        except Exception as e:
            errors += 1
            key = f"ensemble:{type(e).__name__}"
            if key not in error_samples:
                error_samples[key] = str(e)[:200]

print(f"  Completado: {ok} modelos OK | {skipped} series omitidas | {errors} errores", flush=True)
if error_samples:
    print("  Tipos de errores encontrados:", flush=True)
    for key, msg in error_samples.items():
        print(f"    [{key}] {msg}", flush=True)

# ── Guardar en DB (dentro de transacción para evitar estado inválido) ─────────
print(f"Guardando {len(results):,} pronósticos en DB...", flush=True)
cursor = conn.cursor()

sql = """
    INSERT INTO sales_forecasts
        (nit, codigo, modelo, año, mes, cantidad_forecast, lower_bound, upper_bound, confianza, generated_at)
    VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)
"""

CHUNK = 2000
# FIX: extraer columnas en orden explícito para que el INSERT no dependa
# del orden de inserción del dict (evita mapeo incorrecto si se añaden claves)
RESULT_COLS = ['nit', 'codigo', 'modelo', 'año', 'mes',
               'cantidad_forecast', 'lower_bound', 'upper_bound',
               'confianza', 'generated_at']
df_res = pd.DataFrame(results)
rows   = df_res[RESULT_COLS].values.tolist() if len(results) > 0 else []

try:
    # FIX #7: DELETE e INSERT dentro de la misma transacción — si algo falla
    # se hace rollback y la tabla queda con los datos anteriores, no vacía
    conn.begin()
    cursor.execute("DELETE FROM sales_forecasts WHERE modelo != 'manual'")
    for i in range(0, len(rows), CHUNK):
        cursor.executemany(sql, rows[i:i+CHUNK])
    conn.commit()
except Exception as e:
    conn.rollback()
    print(f"ERROR al guardar en DB: {e}", flush=True)
    cursor.close()
    conn.close()
    sys.exit(1)

cursor.execute("SELECT COUNT(*) FROM sales_forecasts")
total_saved = cursor.fetchone()[0]
cursor.close()
conn.close()

print(f"OK:{total_saved}", flush=True)
