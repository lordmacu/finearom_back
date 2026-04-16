<?php

namespace App\Http\Controllers;

use App\Mail\ForecastWeeklyMail;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class ForecastEmailConfigController extends Controller
{
    public function __construct()
    {
        $this->middleware('can:settings view')->only(['show']);
        $this->middleware('can:settings edit')->only(['update', 'sendTest']);
    }

    public function show(): JsonResponse
    {
        $config = DB::table('forecast_email_config')->first();

        // Normalizar fallback_emails a array (si es null o string JSON)
        if ($config) {
            $config->fallback_emails = $this->parseFallbackEmails($config->fallback_emails ?? null);
        }

        return response()->json([
            'success' => true,
            'data'    => $config,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'schedule_rules'             => ['required', 'array', 'min:1', 'max:3'],
            'schedule_rules.*.week'      => ['required', 'integer', 'min:1', 'max:4'],
            'schedule_rules.*.day'       => ['required', 'integer', 'min:1', 'max:7'],
            'send_hour'                  => ['required', 'regex:/^\d{2}:\d{2}$/'],
            'enabled'                    => ['required', 'boolean'],
        ]);

        DB::table('forecast_email_config')->update([
            'schedule_rules' => json_encode($validated['schedule_rules']),
            'send_hour'      => $validated['send_hour'],
            'enabled'        => $validated['enabled'],
            'updated_at'     => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Configuración guardada',
        ]);
    }

    /**
     * PUT /settings/forecast-email/fallback-emails
     * Guarda los emails alternativos que se adjuntan a cada envío (cron y prueba).
     */
    public function updateFallbackEmails(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'emails'   => ['present', 'array'],
            'emails.*' => ['email'],
        ]);

        $clean = array_values(array_unique(array_filter(array_map(
            fn ($e) => strtolower(trim((string) $e)),
            $validated['emails']
        ))));

        DB::table('forecast_email_config')->update([
            'fallback_emails' => json_encode($clean),
            'updated_at'      => now(),
        ]);

        return response()->json([
            'success' => true,
            'data'    => $clean,
            'message' => 'Emails alternativos guardados (' . count($clean) . ')',
        ]);
    }

    /** Decodifica fallback_emails desde BD a array plano y saneado. */
    private function parseFallbackEmails(mixed $raw): array
    {
        if (is_array($raw)) return array_values(array_filter($raw));
        if (!is_string($raw) || $raw === '') return [];
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? array_values(array_filter($decoded)) : [];
    }

    /**
     * POST /settings/forecast-email/test
     * Envía un email de prueba al correo indicado usando datos reales del mes actual.
     */
    public function sendTest(Request $request): JsonResponse
    {
        // Soportar tanto `emails[]` (nuevo, múltiples) como `email` (legacy, uno solo)
        $request->validate([
            'emails'          => ['sometimes', 'array', 'min:1'],
            'emails.*'        => ['email'],
            'email'           => ['sometimes', 'email'],
            'executive_email' => ['nullable', 'email'],
            'date_from'       => ['nullable', 'date_format:Y-m-d'],
            'date_to'         => ['nullable', 'date_format:Y-m-d'],
        ]);

        $emails = $request->input('emails', []);
        if (empty($emails) && $request->filled('email')) {
            $emails = [$request->input('email')];
        }

        // Limpiar duplicados, trim, lowercase — éstos son los destinatarios TO (principales)
        $emails = array_values(array_unique(array_filter(array_map(
            fn ($e) => strtolower(trim((string) $e)),
            $emails
        ))));

        // Cargar emails alternativos guardados — éstos van en CC (copia)
        $config = DB::table('forecast_email_config')->first();
        $ccEmails = $this->parseFallbackEmails($config->fallback_emails ?? null);
        $ccEmails = array_values(array_unique(array_filter(array_map(
            fn ($e) => strtolower(trim((string) $e)),
            $ccEmails
        ))));

        if (empty($emails) && empty($ccEmails)) {
            return response()->json([
                'success' => false,
                'message' => 'Debes proporcionar al menos un correo destino.',
            ], 422);
        }

        $meses = [1=>'ENERO',2=>'FEBRERO',3=>'MARZO',4=>'ABRIL',5=>'MAYO',6=>'JUNIO',
                  7=>'JULIO',8=>'AGOSTO',9=>'SEPTIEMBRE',10=>'OCTUBRE',11=>'NOVIEMBRE',12=>'DICIEMBRE'];

        if ($request->filled('date_from') && $request->filled('date_to')) {
            $from     = Carbon::parse($request->date_from, 'America/Bogota');
            $mesStart = $request->date_from;
            $mesEnd   = $request->date_to;
            $año      = $from->year;
            $semana   = (int) ceil($from->day / 7);
            $mes      = $meses[$from->month];
        } else {
            $now      = Carbon::now('America/Bogota');
            $año      = $now->year;
            $semana   = (int) ceil($now->day / 7);
            $mes      = $meses[$now->month];
            $mesStart = $now->copy()->startOfMonth()->toDateString();
            $mesEnd   = $now->copy()->endOfMonth()->toDateString();
        }

        // Buscar la ejecutiva seleccionada o la primera disponible con pronósticos este mes
        $execQuery = DB::table('clients as c')
            ->join('sales_forecasts as sf', function ($j) use ($año, $mes) {
                $j->on(DB::raw('sf.nit COLLATE utf8mb4_unicode_ci'), '=', DB::raw('c.nit COLLATE utf8mb4_unicode_ci'))
                  ->where('sf.modelo', 'manual')
                  ->where('sf.año', (string) $año)
                  ->where('sf.mes', $mes);
            })
            ->whereNotNull('c.executive_email')
            ->where('c.executive_email', '!=', '')
            ->select('c.executive_email', 'c.executive');

        if ($request->filled('executive_email')) {
            $execQuery->where('c.executive_email', $request->executive_email);
        }

        $exec = $execQuery->first();

        if (!$exec) {
            return response()->json([
                'success' => false,
                'message' => 'No hay ejecutivas con pronósticos manuales para este mes. Importa pronósticos primero.',
            ], 422);
        }

        // Quitar del CC los que ya están en TO para evitar duplicados visuales.
        // NOTA: en el TEST no incluimos el email de la ejecutiva — la prueba es
        // para que la veas tú (los chips que escribes) + los alternativos en copia.
        // El cron real sí le envía a la ejecutiva.
        $ccEmails = array_values(array_diff($ccEmails, $emails));

        // Si TO quedó vacío (no se pusieron chips y no hay CC tampoco), ya lo
        // validamos antes. Si solo hay CC, movemos el primero al TO para tener
        // al menos un destinatario principal.
        if (empty($emails) && !empty($ccEmails)) {
            $emails = [array_shift($ccEmails)];
        }

        $data = $this->buildEmailData($exec, $año, $mes, $mesStart, $mesEnd, $semana);

        try {
            $mailer = Mail::to($emails);
            if (!empty($ccEmails)) {
                $mailer->cc($ccEmails);
            }
            $mailer->send(new ForecastWeeklyMail($data));
        } catch (\Throwable $e) {
            Log::error('ForecastEmailTest: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al enviar el email de prueba: ' . $e->getMessage(),
            ], 500);
        }

        $msg = 'Email de prueba enviado — TO: ' . implode(', ', $emails);
        if (!empty($ccEmails)) $msg .= '  |  CC: ' . implode(', ', $ccEmails);
        $msg .= " (datos de {$exec->executive}, {$mes} {$año})";

        return response()->json([
            'success' => true,
            'to'      => $emails,
            'cc'      => $ccEmails,
            'message' => $msg,
        ]);
    }

    private function buildEmailData(object $exec, int $año, string $mes, string $mesStart, string $mesEnd, int $semana): array
    {
        // Pronósticos con precio del producto para calcular USD.
        // NOTA: `products` tiene códigos duplicados (mismo `code` en varios rows).
        // Usamos un subquery deduplicado (MIN(id) GROUP BY code) para evitar
        // que cada forecast se multiplique por cuantos productos compartan el code.
        $productsUniqCode = DB::table('products')
            ->selectRaw('code, MIN(id) as id')
            ->whereNotNull('code')
            ->where('code', '!=', '')
            ->groupBy('code');

        $forecasts = DB::table('sales_forecasts as sf')
            ->join('clients as c', DB::raw('c.nit COLLATE utf8mb4_unicode_ci'), '=', DB::raw('sf.nit COLLATE utf8mb4_unicode_ci'))
            ->joinSub($productsUniqCode, 'pu', function ($j) {
                $j->on(DB::raw('pu.code COLLATE utf8mb4_unicode_ci'), '=', DB::raw('sf.codigo COLLATE utf8mb4_unicode_ci'));
            })
            ->join('products as p', 'p.id', '=', 'pu.id')
            ->where('sf.modelo', 'manual')
            ->where('sf.año', (string) $año)
            ->where('sf.mes', $mes)
            ->where('c.executive_email', $exec->executive_email)
            ->select('c.nit', 'c.client_name', 'sf.codigo', 'p.product_name', 'sf.cantidad_forecast', 'p.price as product_price')
            ->orderBy('c.client_name')->orderBy('p.product_name')
            ->get();

        // Despachos reales (partials.type='real'): kg y USD (precio pivote o catálogo × cantidad)
        $despachados = DB::table('partials as pt')
            ->join('purchase_orders as po', 'pt.order_id', '=', 'po.id')
            ->join('clients as c', 'po.client_id', '=', 'c.id')
            ->join('purchase_order_product as pop', 'pt.product_order_id', '=', 'pop.id')
            ->join('products as p', 'pop.product_id', '=', 'p.id')
            ->where('pt.type', 'real')->whereNull('pt.deleted_at')
            ->whereBetween('pt.dispatch_date', [$mesStart, $mesEnd])
            ->where('pop.muestra', 0)
            ->where('c.executive_email', $exec->executive_email)
            ->selectRaw('c.nit, p.code as codigo,
                SUM(pt.quantity) as cantidad_real,
                SUM((CASE WHEN pop.price > 0 THEN pop.price ELSE p.price END) * pt.quantity) as value_usd')
            ->groupBy('c.nit', 'p.code')
            ->get()->keyBy(fn ($r) => $r->nit . '|' . $r->codigo);

        // Pendientes por despachar (partials.type='temporal'): OC programadas sin facturar
        $pendientes = DB::table('partials as pt')
            ->join('purchase_orders as po', 'pt.order_id', '=', 'po.id')
            ->join('clients as c', 'po.client_id', '=', 'c.id')
            ->join('purchase_order_product as pop', 'pt.product_order_id', '=', 'pop.id')
            ->join('products as p', 'pop.product_id', '=', 'p.id')
            ->where('pt.type', 'temporal')->whereNull('pt.deleted_at')
            ->whereBetween('pt.dispatch_date', [$mesStart, $mesEnd])
            ->where('pop.muestra', 0)
            ->where('c.executive_email', $exec->executive_email)
            ->selectRaw('c.nit, p.code as codigo,
                SUM(pt.quantity) as cantidad_pendiente,
                SUM((CASE WHEN pop.price > 0 THEN pop.price ELSE p.price END) * pt.quantity) as value_usd')
            ->groupBy('c.nit', 'p.code')
            ->get()->keyBy(fn ($r) => $r->nit . '|' . $r->codigo);

        $clientesMap       = [];
        $totalPronostico   = 0;
        $totalVendido      = 0;
        $totalPendiente    = 0;
        $totalPronUsd      = 0.0;
        $totalVendidoUsd   = 0.0;
        $totalPendienteUsd = 0.0;

        foreach ($forecasts as $f) {
            $key         = $f->nit . '|' . $f->codigo;
            $pronostico  = (int) $f->cantidad_forecast;
            $vendido     = (int) ($despachados[$key]->cantidad_real ?? 0);
            $pendiente   = (int) ($pendientes[$key]->cantidad_pendiente ?? 0);
            $pronUsd     = round($pronostico * (float) $f->product_price, 2);
            $vendUsd     = round((float) ($despachados[$key]->value_usd ?? 0), 2);
            $pendUsd     = round((float) ($pendientes[$key]->value_usd ?? 0), 2);
            // Cumplimiento = (facturado + pendiente por despacho) / pronosticado
            $cumplimiento = $pronostico > 0
                ? round((($vendido + $pendiente) / $pronostico) * 100, 1)
                : null;

            $clientesMap[$f->nit]['nombre'] = $f->client_name;
            $clientesMap[$f->nit]['productos'][] = [
                'nombre'        => $f->product_name,
                'codigo'        => $f->codigo,
                'pronostico'    => $pronostico,
                'vendido'       => $vendido,
                'pendiente'     => $pendiente,
                'cumplimiento'  => $cumplimiento,
                'pron_usd'      => $pronUsd,
                'vendido_usd'   => $vendUsd,
                'pendiente_usd' => $pendUsd,
            ];
            $totalPronostico   += $pronostico;
            $totalVendido      += $vendido;
            $totalPendiente    += $pendiente;
            $totalPronUsd      += $pronUsd;
            $totalVendidoUsd   += $vendUsd;
            $totalPendienteUsd += $pendUsd;
        }

        // Buscar nombre real en tabla executives por email
        $execName = DB::table('executives')
            ->whereRaw('LOWER(email) = ?', [strtolower($exec->executive_email)])
            ->value('name') ?? ($exec->executive ?: $exec->executive_email);

        return [
            'ejecutiva'           => $execName,
            'mes'                 => ucfirst(strtolower($mes)),
            'año'                 => $año,
            'semana'              => $semana,
            'clientes'            => array_values($clientesMap),
            'total_pronostico'    => $totalPronostico,
            'total_vendido'       => $totalVendido,
            'total_pendiente'     => $totalPendiente,
            'total_pron_usd'      => $totalPronUsd,
            'total_vendido_usd'   => $totalVendidoUsd,
            'total_pendiente_usd' => $totalPendienteUsd,
            'total_cumplimiento'  => $totalPronostico > 0
                ? round((($totalVendido + $totalPendiente) / $totalPronostico) * 100, 1)
                : null,
        ];
    }
}
