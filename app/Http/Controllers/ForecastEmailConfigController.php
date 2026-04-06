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

        return response()->json([
            'success' => true,
            'data'    => $config,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'frequency'    => ['required', 'in:weekly,biweekly,monthly'],
            'day_of_week'  => ['nullable', 'integer', 'min:1', 'max:7'],
            'day_of_month' => ['nullable', 'integer', 'min:1', 'max:31'],
            'send_hour'    => ['required', 'regex:/^\d{2}:\d{2}$/'],
            'enabled'      => ['required', 'boolean'],
        ]);

        DB::table('forecast_email_config')->update([
            'frequency'    => $validated['frequency'],
            'day_of_week'  => $validated['day_of_week']  ?? null,
            'day_of_month' => $validated['day_of_month'] ?? null,
            'send_hour'    => $validated['send_hour'],
            'enabled'      => $validated['enabled'],
            'updated_at'   => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Configuración guardada',
        ]);
    }

    /**
     * POST /settings/forecast-email/test
     * Envía un email de prueba al correo indicado usando datos reales del mes actual.
     */
    public function sendTest(Request $request): JsonResponse
    {
        $request->validate([
            'email'           => ['required', 'email'],
            'executive_email' => ['nullable', 'email'],
            'date_from'       => ['nullable', 'date_format:Y-m-d'],
            'date_to'         => ['nullable', 'date_format:Y-m-d'],
        ]);

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

        $data = $this->buildEmailData($exec, $año, $mes, $mesStart, $mesEnd, $semana);

        try {
            Mail::to($request->email)->send(new ForecastWeeklyMail($data));
        } catch (\Throwable $e) {
            Log::error('ForecastEmailTest: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al enviar: ' . $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => "Email de prueba enviado a {$request->email} (datos de {$exec->executive}, semana {$semana} de {$mes} {$año})",
        ]);
    }

    private function buildEmailData(object $exec, int $año, string $mes, string $mesStart, string $mesEnd, int $semana): array
    {
        // Pronósticos con precio del producto para calcular USD
        $forecasts = DB::table('sales_forecasts as sf')
            ->join('clients as c', DB::raw('c.nit COLLATE utf8mb4_unicode_ci'), '=', DB::raw('sf.nit COLLATE utf8mb4_unicode_ci'))
            ->join('products as p', DB::raw('p.code COLLATE utf8mb4_unicode_ci'), '=', DB::raw('sf.codigo COLLATE utf8mb4_unicode_ci'))
            ->where('sf.modelo', 'manual')
            ->where('sf.año', (string) $año)
            ->where('sf.mes', $mes)
            ->where('c.executive_email', $exec->executive_email)
            ->select('c.nit', 'c.client_name', 'sf.codigo', 'p.product_name', 'sf.cantidad_forecast', 'p.price as product_price')
            ->orderBy('c.client_name')->orderBy('p.product_name')
            ->get();

        // Despachos reales: kg y USD (precio pivote o catálogo × cantidad)
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

        $clientesMap      = [];
        $totalPronostico  = 0;
        $totalVendido     = 0;
        $totalPronUsd     = 0.0;
        $totalVendidoUsd  = 0.0;

        foreach ($forecasts as $f) {
            $key          = $f->nit . '|' . $f->codigo;
            $vendido      = (int) ($despachados[$key]->cantidad_real ?? 0);
            $pronostico   = (int) $f->cantidad_forecast;
            $cumplimiento = $pronostico > 0 ? round($vendido / $pronostico * 100, 1) : null;
            $pronUsd      = round($pronostico * (float) $f->product_price, 2);
            $vendUsd      = round((float) ($despachados[$key]->value_usd ?? 0), 2);

            $clientesMap[$f->nit]['nombre'] = $f->client_name;
            $clientesMap[$f->nit]['productos'][] = [
                'nombre'        => $f->product_name,
                'codigo'        => $f->codigo,
                'pronostico'    => $pronostico,
                'vendido'       => $vendido,
                'cumplimiento'  => $cumplimiento,
                'pron_usd'      => $pronUsd,
                'vendido_usd'   => $vendUsd,
            ];
            $totalPronostico += $pronostico;
            $totalVendido    += $vendido;
            $totalPronUsd    += $pronUsd;
            $totalVendidoUsd += $vendUsd;
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
            'total_pron_usd'      => $totalPronUsd,
            'total_vendido_usd'   => $totalVendidoUsd,
            'total_cumplimiento'  => $totalPronostico > 0
                ? round($totalVendido / $totalPronostico * 100, 1)
                : null,
        ];
    }
}
