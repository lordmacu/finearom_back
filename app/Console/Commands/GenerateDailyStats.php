<?php

namespace App\Console\Commands;

use App\Models\TrmDaily;
use App\Services\Trm\TrmSoapClient;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Genera el snapshot diario de order_statistics (versión optimizada).
 *
 * Solo calcula las columnas que realmente se consumen:
 *
 *  MonthlyReportController.buildStats() lee:
 *    commercial_products_dispatched, orders_commercial/sample/mixed, average_trm
 *
 *  Chat IA (prompt de sistema) referencia:
 *    date, commercial_dispatched_value_usd, dispatched_orders_count,
 *    total_orders_created, dispatch_fulfillment_rate_usd,
 *    pending_dispatch_value_usd, extended_stats
 *
 *  Todo lo demás queda en 0 para mantener compatibilidad de esquema.
 *
 * Coherencia con el flujo de la plataforma:
 *  - "planeado hoy" = partials tipo TEMPORAL con dispatch_date = hoy (Marlon)
 *  - "despachado hoy" = partials tipo REAL con dispatch_date = hoy (Alexa)
 *  - fulfillment_rate = real / temporal × 100
 */
class GenerateDailyStats extends Command
{
    protected $signature = 'stats:generate-daily
                            {--date=  : Fecha objetivo YYYY-MM-DD (default: hoy)}
                            {--from=  : Inicio de rango YYYY-MM-DD}
                            {--to=    : Fin de rango YYYY-MM-DD}
                            {--force  : Sobreescribir fila existente}';

    protected $description = 'Genera snapshot diario en order_statistics (versión optimizada).';

    public function __construct(private readonly TrmSoapClient $soapClient)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $force = (bool) $this->option('force');
        $from  = $this->option('from');
        $to    = $this->option('to');

        if ($from || $to) {
            $start = $from ? Carbon::createFromFormat('Y-m-d', $from) : Carbon::now()->startOfMonth();
            $end   = $to   ? Carbon::createFromFormat('Y-m-d', $to)   : Carbon::now();

            if ($end->lt($start)) {
                $this->error('--to debe ser >= --from');
                return 1;
            }

            foreach (new \DatePeriod($start, new \DateInterval('P1D'), $end->addDay()) as $d) {
                $this->processDate(Carbon::instance($d), $force);
            }
            return 0;
        }

        $target = $this->option('date') ?: Carbon::now()->format('Y-m-d');
        $this->processDate(Carbon::createFromFormat('Y-m-d', $target), $force);
        return 0;
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function processDate(Carbon $date, bool $force): void
    {
        $d = $date->format('Y-m-d');
        $this->info("Procesando {$d}");

        if (!$force && DB::table('order_statistics')->where('date', $d)->exists()) {
            $this->line("  ⏭  Ya existe, omitiendo (usa --force para sobreescribir)");
            return;
        }

        DB::table('order_statistics')->where('date', $d)->delete();

        // Asegurar que trm_daily tiene la fila del día antes de hacer los cálculos.
        // Necesario para fines de semana/festivos donde trm:fetch-daily no genera fila.
        $this->ensureTrmForDate($d);

        // Calcular en este orden para poder pasar dispatched_usd a pending/fulfillment
        $orders   = $this->orderCreationStats($d);
        $dispatch = $this->dispatchStats($d);
        $planned  = $this->plannedStats($d);
        $trm      = $this->trmForDate($d);

        $pendingUsd     = max(0.0, $planned['planned_usd'] - $dispatch['commercial_dispatched_value_usd']);
        $fulfillmentPct = $planned['planned_usd'] > 0
            ? round($dispatch['commercial_dispatched_value_usd'] / $planned['planned_usd'] * 100, 2)
            : 0.0;

        $row = array_merge(
            $this->zeroedSchema($d),
            $orders,
            $dispatch,
            [
                'planned_dispatch_value_usd'    => round($planned['planned_usd'], 2),
                'pending_dispatch_value_usd'    => round($pendingUsd, 2),
                'dispatch_fulfillment_rate_usd' => $fulfillmentPct,
                'average_trm'                   => $trm,
                'extended_stats'                => json_encode($this->extendedStats($d, $dispatch, $orders)),
            ]
        );

        DB::table('order_statistics')->insert($row);

        $this->line(sprintf(
            "  ✓ OC: %d | Desp USD: %s | Planeado USD: %s | Fill: %s%% | TRM: %s",
            $row['total_orders_created'],
            number_format($row['commercial_dispatched_value_usd'], 0),
            number_format($row['planned_dispatch_value_usd'], 0),
            $row['dispatch_fulfillment_rate_usd'],
            $row['average_trm']
        ));
    }

    // ── 1. OC creadas en el día ───────────────────────────────────────────────

    private function orderCreationStats(string $date): array
    {
        // Conteos a nivel de orden
        $counts = DB::table('purchase_orders')
            ->where('order_creation_date', $date)
            ->selectRaw("
                COUNT(*)                                                      AS total_orders_created,
                SUM(CASE WHEN status = 'pending'        THEN 1 ELSE 0 END)   AS orders_pending,
                SUM(CASE WHEN status = 'processing'     THEN 1 ELSE 0 END)   AS orders_processing,
                SUM(CASE WHEN status = 'completed'      THEN 1 ELSE 0 END)   AS orders_completed,
                SUM(CASE WHEN status = 'parcial_status' THEN 1 ELSE 0 END)   AS orders_parcial_status,
                SUM(CASE WHEN is_new_win = 1            THEN 1 ELSE 0 END)   AS orders_new_win
            ")
            ->first();

        // Valor + clasificación (comercial / muestra / mixta) por orden
        $perOrder = DB::table('purchase_orders as po')
            ->join('purchase_order_product as pop', 'pop.purchase_order_id', '=', 'po.id')
            ->join('products as p',                 'pop.product_id',         '=', 'p.id')
            ->where('po.order_creation_date', $date)
            ->selectRaw("
                po.id,
                MAX(CASE WHEN pop.muestra = 0 THEN 1 ELSE 0 END)  AS has_commercial,
                MAX(CASE WHEN pop.muestra = 1 THEN 1 ELSE 0 END)  AS has_sample,
                SUM(CASE
                    WHEN pop.muestra = 0
                    THEN (CASE WHEN pop.price > 0 THEN pop.price ELSE p.price END) * pop.quantity
                    ELSE 0
                END) AS value_usd
            ")
            ->groupBy('po.id')
            ->get();

        $totalValueUsd    = 0.0;
        $ordersCommercial = 0;
        $ordersSample     = 0;
        $ordersMixed      = 0;

        foreach ($perOrder as $row) {
            $totalValueUsd += (float) $row->value_usd;
            if ($row->has_commercial && $row->has_sample) {
                $ordersMixed++;
            } elseif ($row->has_commercial) {
                $ordersCommercial++;
            } elseif ($row->has_sample) {
                $ordersSample++;
            }
        }

        return [
            'total_orders_created'   => (int) ($counts->total_orders_created  ?? 0),
            'orders_pending'         => (int) ($counts->orders_pending         ?? 0),
            'orders_processing'      => (int) ($counts->orders_processing      ?? 0),
            'orders_completed'       => (int) ($counts->orders_completed       ?? 0),
            'orders_parcial_status'  => (int) ($counts->orders_parcial_status  ?? 0),
            'orders_new_win'         => (int) ($counts->orders_new_win         ?? 0),
            'orders_commercial'      => $ordersCommercial,
            'orders_sample'          => $ordersSample,
            'orders_mixed'           => $ordersMixed,
            'total_orders_value_usd' => round($totalValueUsd, 2),
        ];
    }

    // ── 2. Despachos reales del día ───────────────────────────────────────────
    // Partials tipo REAL con dispatch_date = hoy.
    // TRM: partials.trm >= 3400 → trm_daily → 4000  (igual que AnalyzeQuery)

    private function dispatchStats(string $date): array
    {
        $row = DB::table('partials as pt')
            ->join('purchase_orders as po',         'pt.order_id',         '=', 'po.id')
            ->join('purchase_order_product as pop',  'pt.product_order_id', '=', 'pop.id')
            ->join('products as p',                  'pop.product_id',      '=', 'p.id')
            ->leftJoin('trm_daily as td',            'pt.dispatch_date',    '=', 'td.date')
            ->where('pt.type', 'real')
            ->whereNull('pt.deleted_at')
            ->where('pt.dispatch_date', $date)
            ->where('pop.muestra', 0)
            ->selectRaw("
                COUNT(DISTINCT po.id) AS dispatched_orders_count,
                SUM(
                    (CASE WHEN pop.price > 0 THEN pop.price ELSE p.price END) * pt.quantity
                ) AS commercial_dispatched_value_usd,
                SUM(
                    (CASE WHEN pop.price > 0 THEN pop.price ELSE p.price END) * pt.quantity *
                    (CASE
                        WHEN pt.trm IS NOT NULL AND pt.trm >= 3400 THEN pt.trm
                        WHEN td.value IS NOT NULL                  THEN td.value
                        ELSE 4000
                    END)
                ) AS commercial_dispatched_value_cop,
                SUM(pt.quantity) AS commercial_products_dispatched
            ")
            ->first();

        return [
            'dispatched_orders_count'        => (int)   ($row->dispatched_orders_count        ?? 0),
            'commercial_dispatched_value_usd' => round((float) ($row->commercial_dispatched_value_usd ?? 0), 2),
            'commercial_dispatched_value_cop' => round((float) ($row->commercial_dispatched_value_cop ?? 0), 0),
            'commercial_products_dispatched'  => (int)   ($row->commercial_products_dispatched  ?? 0),
        ];
    }

    // ── 3. Planeado para el día ───────────────────────────────────────────────
    // Partials tipo TEMPORAL con dispatch_date = hoy (lo que Marlon estimó).
    // Solo USD porque el pendiente/fulfillment que usa el AI es en USD.

    private function plannedStats(string $date): array
    {
        $row = DB::table('partials as pt')
            ->join('purchase_order_product as pop', 'pt.product_order_id', '=', 'pop.id')
            ->join('products as p',                 'pop.product_id',       '=', 'p.id')
            ->where('pt.type', 'temporal')
            ->whereNull('pt.deleted_at')
            ->where('pt.dispatch_date', $date)
            ->where('pop.muestra', 0)
            ->selectRaw("
                SUM(
                    (CASE WHEN pop.price > 0 THEN pop.price ELSE p.price END) * pt.quantity
                ) AS planned_usd
            ")
            ->first();

        return [
            'planned_usd' => (float) ($row->planned_usd ?? 0),
        ];
    }

    // ── 4. TRM del día (de trm_daily, ya cargada por trm:fetch-daily) ─────────

    private function trmForDate(string $date): float
    {
        $value = DB::table('trm_daily')->where('date', $date)->value('value');
        return $value ? round((float) $value, 2) : 4000.0;
    }

    // ── 5. Extended stats ─────────────────────────────────────────────────────
    // Solo lead_time y executive_completion — útiles para tendencias en el chat IA.
    // Se pasan los stats ya calculados para evitar queries redundantes.

    private function extendedStats(string $date, array $dispatch, array $orders): array
    {
        return [
            'lead_time'            => $this->calcLeadTime($date),
            'executive_completion' => $this->calcExecutiveCompletion($date),
            // Resumen del día para acceso rápido sin JOIN
            'summary' => [
                'dispatched_orders'  => $dispatch['dispatched_orders_count'],
                'dispatched_usd'     => $dispatch['commercial_dispatched_value_usd'],
                'orders_created'     => $orders['total_orders_created'],
            ],
        ];
    }

    /**
     * Lead time de OC que tuvieron su PRIMER despacho real en este día.
     * avg_days  = promedio(primer_despacho - fecha_creacion_OC)
     * on_time_pct = % donde primer_despacho <= required_delivery_date
     */
    private function calcLeadTime(string $date): array
    {
        $row = DB::selectOne("
            SELECT
                ROUND(AVG(DATEDIFF(d.first_dispatch, po.order_creation_date)), 2) AS avg_days,
                ROUND(
                    100.0 * SUM(CASE
                        WHEN po.required_delivery_date IS NOT NULL
                         AND d.first_dispatch <= po.required_delivery_date THEN 1 ELSE 0
                    END) / NULLIF(COUNT(*), 0)
                , 2) AS on_time_pct
            FROM purchase_orders po
            JOIN (
                SELECT   order_id, MIN(dispatch_date) AS first_dispatch
                FROM     partials
                WHERE    type = 'real'
                  AND    dispatch_date IS NOT NULL
                  AND    deleted_at   IS NULL
                GROUP BY order_id
            ) d ON d.order_id = po.id
            WHERE d.first_dispatch = ?
        ", [$date]);

        return [
            'avg_days'           => isset($row->avg_days)    ? (float) $row->avg_days    : null,
            'on_time_orders_pct' => isset($row->on_time_pct) ? (float) $row->on_time_pct : null,
        ];
    }

    /**
     * Estado de las OC cuyo PRIMER despacho real fue hoy, agrupadas por ejecutiva.
     * Estas OC ya tuvieron actividad real → su estado es significativo (parcial/completed).
     * Útil para el chat IA para ver tendencias de cumplimiento por comercial.
     */
    private function calcExecutiveCompletion(string $date): array
    {
        $rows = DB::table('purchase_orders as po')
            ->join('clients as c', 'po.client_id', '=', 'c.id')
            ->join(
                DB::raw('(
                    SELECT order_id, MIN(dispatch_date) AS first_dispatch
                    FROM   partials
                    WHERE  type = \'real\'
                      AND  dispatch_date IS NOT NULL
                      AND  deleted_at IS NULL
                    GROUP BY order_id
                ) AS fd'),
                'fd.order_id', '=', 'po.id'
            )
            ->where('fd.first_dispatch', $date)
            ->selectRaw("
                COALESCE(NULLIF(c.executive, ''), 'Sin ejecutiva')             AS executive,
                COUNT(*)                                                         AS total_orders,
                SUM(CASE WHEN po.status = 'completed'      THEN 1 ELSE 0 END)  AS completed,
                SUM(CASE WHEN po.status = 'parcial_status' THEN 1 ELSE 0 END)  AS partial,
                SUM(CASE WHEN po.status IN ('pending','processing') THEN 1 ELSE 0 END) AS pending
            ")
            ->groupBy('c.executive')
            ->get();

        return $rows->map(function ($r) {
            $total = (int) $r->total_orders;
            return [
                'executive'      => $r->executive,
                'total_orders'   => $total,
                'completed'      => (int) $r->completed,
                'partial'        => (int) $r->partial,
                'pending'        => (int) $r->pending,
                'completion_pct' => $total > 0 ? round((int) $r->completed / $total * 100, 2) : null,
            ];
        })->values()->all();
    }

    /**
     * Garantiza que trm_daily tiene fila para la fecha dada.
     * Necesario para fines de semana y festivos donde trm:fetch-daily no genera fila.
     * Si falta, intenta obtenerla del SOAP; si falla, usa el último valor disponible.
     */
    private function ensureTrmForDate(string $date): void
    {
        $exists = DB::table('trm_daily')->where('date', $date)->exists();
        if ($exists) {
            return;
        }

        try {
            $result = $this->soapClient->fetch($date);
            TrmDaily::updateOrCreate(
                ['date' => $date],
                ['value' => $result['value'], 'source' => 'soap', 'metadata' => $result]
            );
            $this->line("  TRM {$date}: {$result['value']} (soap)");
        } catch (\Throwable $e) {
            // Fines de semana/festivos: la Superfinanciera devuelve la TRM vigente del día hábil anterior.
            // Si falla, copiamos el último valor disponible para no caer a 4000.
            $last = DB::table('trm_daily')
                ->where('date', '<', $date)
                ->orderByDesc('date')
                ->value('value');

            if ($last) {
                TrmDaily::updateOrCreate(
                    ['date' => $date],
                    ['value' => $last, 'source' => 'carry_forward']
                );
                $this->line("  TRM {$date}: {$last} (carry-forward, SOAP falló: {$e->getMessage()})");
            } else {
                Log::warning("GenerateDailyStats: no se pudo obtener TRM para {$date}: " . $e->getMessage());
            }
        }
    }

    // ── Esquema base (columnas heredadas que no se calculan → 0) ─────────────

    private function zeroedSchema(string $date): array
    {
        return [
            'date'                                    => $date,
            'hour'                                    => 23,
            'updated_at'                              => now(),
            'dispatched_orders_count'                 => 0,
            'commercial_dispatched_value_usd'         => 0,
            'commercial_dispatched_value_cop'         => 0,
            'commercial_products_dispatched'          => 0,
            'sample_products_dispatched'              => 0,
            'commercial_partials_real'                => 0,
            'commercial_partials_temporal'            => 0,
            'sample_partials_real'                    => 0,
            'sample_partials_temporal'                => 0,
            'total_orders_created'                    => 0,
            'orders_pending'                          => 0,
            'orders_processing'                       => 0,
            'orders_completed'                        => 0,
            'orders_parcial_status'                   => 0,
            'orders_new_win'                          => 0,
            'orders_commercial'                       => 0,
            'orders_sample'                           => 0,
            'orders_mixed'                            => 0,
            'total_orders_value_usd'                  => 0,
            'total_orders_value_cop'                  => 0,
            'planned_dispatch_value_usd'              => 0,
            'planned_dispatch_value_cop'              => 0,
            'planned_orders_count'                    => 0,
            'planned_commercial_products'             => 0,
            'planned_sample_products'                 => 0,
            'pending_dispatch_value_usd'              => 0,
            'pending_dispatch_value_cop'              => 0,
            'pending_commercial_products'             => 0,
            'pending_sample_products'                 => 0,
            'dispatch_fulfillment_rate_usd'           => 0,
            'dispatch_fulfillment_rate_products'      => 0,
            'average_trm'                             => 0,
            'partials_with_default_trm'               => 0,
            'partials_with_custom_trm'                => 0,
            'orders_fully_dispatched'                 => 0,
            'orders_partially_dispatched'             => 0,
            'orders_not_dispatched'                   => 0,
            'avg_days_order_to_first_dispatch'        => 0,
            'dispatch_completion_percentage'          => 0,
            'unique_clients_with_orders'              => 0,
            'unique_clients_with_dispatches'          => 0,
            'unique_clients_with_planned_dispatches'  => 0,
            'extended_stats'                          => null,
        ];
    }
}
