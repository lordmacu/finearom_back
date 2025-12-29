<?php

namespace App\Http\Controllers;

use App\Models\Partial;
use App\Models\PartialLog;
use App\Models\PurchaseOrder;
use App\Models\Client;
use App\Services\TrmService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

// Exports removed for now
// use Maatwebsite\Excel\Facades\Excel;
// use App\Exports\PlannedStatsExport;

class DashboardController extends Controller
{
    /**
     * Exportar estadísticas planeadas detalladas a Excel
     * TODO: Implement when excel package is installed
     */
    public function exportPlannedStats(Request $request)
    {
        return response()->json([
            'success' => false,
            'message' => 'Export functionality not available yet (Package missing)'
        ], 501);
    }

    /**
     * Quick stats for a single client (current month, on-demand)
     */
    public function clientQuickStats(Request $request)
    {
        $request->validate([
            'nit' => 'required|string',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date'
        ]);

        $client = Client::where('nit', $request->nit)->first();
        if (!$client) {
            return response()->json([
                'success' => false,
                'message' => 'Cliente no encontrado'
            ], 404);
        }

        $startDate = $request->start_date ?? Carbon::now()->startOfMonth()->toDateString();
        $endDate = $request->end_date ?? Carbon::now()->endOfMonth()->toDateString();

        try { 
            // Planned: productos de órdenes creadas en el mes
            $plannedProducts = DB::table('purchase_order_product as pop')
                ->join('purchase_orders as po', 'pop.purchase_order_id', '=', 'po.id')
                ->join('products as p', 'pop.product_id', '=', 'p.id')
                ->leftJoin('partials as pt', function($join) use ($startDate, $endDate) {
                    $join->on('pt.product_order_id', '=', 'pop.id')
                        ->where('pt.type', '=', 'real')
                        ->whereNotNull('pt.dispatch_date')
                        ->whereBetween('pt.dispatch_date', [$startDate, $endDate]);
                })
                ->leftJoin('trm_daily as td', 'po.order_creation_date', '=', 'td.date')
                ->where('po.client_id', $client->id)
                ->whereBetween('po.order_creation_date', [$startDate, $endDate])
                ->select('pop.id as pop_id',
                    'pop.quantity',
                    DB::raw('CASE
                        WHEN pop.muestra = 1 THEN 0
                        WHEN pop.price > 0 THEN pop.price
                        ELSE p.price
                    END as price'),
                    'po.trm as order_trm',
                    'pt.trm as partial_real_trm',
                    'td.value as daily_trm')
                ->get();

            $plannedUsd = $plannedProducts->sum(function ($row) {
                return (float) $row->price * (float) $row->quantity;
            });

            $plannedCop = $plannedProducts->sum(function ($row) {
                // Same priority order as dispatched:
                // 1. TRM from real partial (if exists and valid)
                // 2. TRM from order (if exists and valid)
                // 3. Daily TRM (if exists)
                // 4. Default 4000
                $trm = 4000;

                if (!empty($row->partial_real_trm) && (float)$row->partial_real_trm > 0) {
                    $trm = (float)$row->partial_real_trm;
                } elseif (!empty($row->order_trm) && (float)$row->order_trm > 0) {
                    $trm = (float)$row->order_trm;
                } elseif (!empty($row->daily_trm)) {
                    $trm = (float)$row->daily_trm;
                }

                $valueCop = (float) $row->price * (float) $row->quantity * $trm;

                Log::info('ClientQuickStats - Planned TRM', [
                    'pop_id' => $row->pop_id,
                    'partial_real_trm' => $row->partial_real_trm,
                    'partial_real_trm_float' => (float)$row->partial_real_trm,
                    'order_trm' => $row->order_trm,
                    'daily_trm' => $row->daily_trm,
                    'selected_trm' => $trm,
                    'price' => $row->price,
                    'quantity' => $row->quantity,
                    'value_cop' => $valueCop
                ]);

                return $valueCop;
            });

            // Dispatched: partials del mes
            $partials = DB::table('partials')
                ->join('purchase_order_product as pop', 'partials.product_order_id', '=', 'pop.id')
                ->join('purchase_orders as po', 'pop.purchase_order_id', '=', 'po.id')
                ->join('products as p', 'pop.product_id', '=', 'p.id')
                ->leftJoin('trm_daily', 'partials.dispatch_date', '=', 'trm_daily.date')
                ->where('po.client_id', $client->id)
                ->where('partials.type', 'real')
                ->whereNotNull('partials.dispatch_date')
                ->whereBetween('partials.dispatch_date', [$startDate, $endDate])
                ->select('partials.quantity',
                    DB::raw('CASE
                        WHEN pop.muestra = 1 THEN 0
                        WHEN pop.price > 0 THEN pop.price
                        ELSE p.price
                    END as price'),
                    'partials.trm as partial_real_trm',
                    'po.trm as order_trm',
                    'trm_daily.value as daily_trm')
                ->get();

            $dispatchedUsd = $partials->sum(function ($row) {
                return (float) $row->price * (float) $row->quantity;
            });

            $dispatchedCop = $partials->sum(function ($row) {
                // Priority order for TRM:
                // 1. TRM from real partial (if exists and valid)
                // 2. TRM from order (if exists and valid)
                // 3. Daily TRM (if exists)
                // 4. Default 4000
                $trm = 4000;

                if (!empty($row->partial_real_trm) && (float)$row->partial_real_trm > 0) {
                    $trm = (float)$row->partial_real_trm;
                } elseif (!empty($row->order_trm) && (float)$row->order_trm > 0) {
                    $trm = (float)$row->order_trm;
                } elseif (!empty($row->daily_trm)) {
                    $trm = (float)$row->daily_trm;
                }

                $valueCop = (float) $row->price * (float) $row->quantity * $trm;

                Log::info('ClientQuickStats - Dispatched TRM', [
                    'partial_real_trm' => $row->partial_real_trm,
                    'partial_real_trm_float' => (float)$row->partial_real_trm,
                    'order_trm' => $row->order_trm,
                    'daily_trm' => $row->daily_trm,
                    'selected_trm' => $trm,
                    'price' => $row->price,
                    'quantity' => $row->quantity,
                    'value_cop' => $valueCop
                ]);

                return $valueCop;
            });

            // Weighted average TRM: Total COP / Total USD
            // This ensures mathematical consistency: USD * AvgTRM = COP
            $averageTrm = $dispatchedUsd > 0
                ? round($dispatchedCop / $dispatchedUsd, 2)
                : 0;

            $pendingUsd = max(0, $plannedUsd - $dispatchedUsd);
            $pendingCop = max(0, $plannedCop - $dispatchedCop);

            $totalOrdersDispatched = DB::table('purchase_orders')
                ->where('client_id', $client->id)
                ->whereBetween('order_creation_date', [$startDate, $endDate])
                ->where('status', 'completed')
                ->count();

            $totalOrdersPartial = DB::table('purchase_orders')
                ->where('client_id', $client->id)
                ->whereBetween('order_creation_date', [$startDate, $endDate])
                ->where('status', 'parcial_status')
                ->count();

            return response()->json([
                'success' => true,
                'data' => [
                    'client' => [
                        'id' => $client->id,
                        'name' => $client->client_name,
                        'nit' => $client->nit
                    ],
                    'period' => [
                        'start_date' => $startDate,
                        'end_date' => $endDate
                    ],
                    'financial_analysis' => [
                        'planned_value_usd' => round($plannedUsd, 2),
                        'planned_value_cop' => round($plannedCop, 0),
                        'dispatched_value_usd' => round($dispatchedUsd, 2),
                        'dispatched_value_cop' => round($dispatchedCop, 0),
                        'pending_value_usd' => round($pendingUsd, 2),
                        'pending_value_cop' => round($pendingCop, 0),
                        'average_trm' => $averageTrm,
                        'orders_dispatched' => $totalOrdersDispatched ?? 0,
                        'orders_partial' => $totalOrdersPartial ?? 0,
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error in clientQuickStats: '.$e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error obteniendo estadisticas del cliente'
            ], 500);
        }
    }


    /**
     * Get dashboard statistics for a date range
     */
    public function getStats(Request $request)
    {
        $startDate = $request->get('start_date');
        $endDate = $request->get('end_date');

        // Si no se proporcionan fechas, usar el mes actual
        if (!$startDate || !$endDate) {
            $now = Carbon::now();
            $startDate = $now->copy()->startOfMonth()->format('Y-m-d');
            $endDate = $now->copy()->endOfMonth()->format('Y-m-d');
        }

        try {
            // Estadísticas existentes de dispatches reales (agregadas del período)
            $stats = DB::table('order_statistics')
                ->whereBetween('date', [$startDate, $endDate])
                ->get();

            // Estadísticas detalladas día por día para métricas avanzadas
            $dailyStats = DB::table('order_statistics')
                ->whereBetween('date', [$startDate, $endDate])
                ->orderBy('date')
                ->get();

            // Calcular valores planeados basados en dispatch_date de purchase_orders
            $plannedStats = $this->calculatePlannedStats($startDate, $endDate);

            // ✨ NUEVO: Calcular valor total de recaudos esperados
            $expectedRecaudos = $this->calculateExpectedRecaudos($startDate, $endDate);

            if ($stats->isEmpty() && $plannedStats['orders_count'] == 0) {
                // If no stats, Return empty structure but with success=true to avoid frontend error
                // Or maybe null data? Legacy returns 404 but frontend handles it? 
                // Let's create an empty structure to be safe or just minimal data
                 $dashboardData = [
                    'period' => [
                        'start_date' => $startDate,
                        'end_date' => $endDate,
                        'days_count' => 0
                    ],
                    'commercial_dispatched' => ['value_usd' => 0, 'value_cop' => 0, 'products_count' => 0, 'orders_count' => 0],
                    'planned_month' => ['value_usd' => 0, 'value_cop' => 0, 'orders_count' => 0, 'products_count' => 0],
                    'daily_average' => ['value_usd' => 0, 'value_cop' => 0, 'products_count' => 0],
                    'last_updated' => null,
                    'expected_collections' => ['total_value' => 0, 'count' => 0],
                    'orders_created' => $this->getEmptyMetrics(),
                    // ... fill others if needed, but legacy returns 404.
                ];
                 // Legacy returned 404, let's stick to that if strict, or return success with empty data.
                 // Legacy:
                 /*
                  if ($stats->isEmpty() && $plannedStats['orders_count'] == 0) {
                        return response()->json(... 404);
                    }
                 */
                 // Let's stick to legacy behavior
                 if ($stats->isEmpty() && $plannedStats['orders_count'] == 0) {
                     return response()->json([
                         'success' => false,
                         'message' => 'No statistics found for the specified date range',
                         'data' => null
                     ], 404);
                 }
            }

            // Agregar totales del período (dispatches reales)
            $totalCommercialUsd = $stats->sum('commercial_dispatched_value_usd') ?? 0;
            $totalCommercialCop = $stats->sum('commercial_dispatched_value_cop') ?? 0;
            $totalProductsDispatched = $stats->sum('commercial_products_dispatched') ?? 0;
            $totalOrdersDispatched = $stats->sum('dispatched_orders_count') ?? 0;

            // Calcular métricas adicionales del comando
            // Note: In legacy this method was private inside controller, creating it here too.
            $additionalMetrics = $this->calculateAdditionalMetrics($dailyStats);

            // Decodificar y agregar extended_stats de todos los días
            $extendedStatsAggregated = $this->aggregateExtendedStats($dailyStats);

            $dashboardData = [
                'period' => [
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'days_count' => $stats->count()
                ],
                'commercial_dispatched' => [
                    'value_usd' => round($totalCommercialUsd, 2),
                    'value_cop' => round($totalCommercialCop, 0),
                    'products_count' => $totalProductsDispatched,
                    'orders_count' => $totalOrdersDispatched,
                ],
                'planned_month' => [
                    'value_usd' => round($plannedStats['total_value_usd'], 2),
                    'value_cop' => round($plannedStats['total_value_cop'], 0),
                    'orders_count' => $plannedStats['orders_count'],
                    'products_count' => $plannedStats['products_count'],
                ],
                'daily_average' => [
                    'value_usd' => $stats->count() > 0 ? round($totalCommercialUsd / $stats->count(), 2) : 0,
                    'value_cop' => $stats->count() > 0 ? round($totalCommercialCop / $stats->count(), 0) : 0,
                    'products_count' => $stats->count() > 0 ? round($totalProductsDispatched / $stats->count(), 1) : 0,
                ],
                'last_updated' => $stats->max('updated_at'),

                // ✨ NUEVO: Valor de recaudos esperados
                'expected_collections' => [
                    'total_value' => round($expectedRecaudos, 2),
                    'count' => $this->getRecaudosCount($startDate, $endDate)
                ],

                // MÉTRICAS EXISTENTES DEL COMANDO
                'orders_created' => [
                    'total' => $additionalMetrics['total_orders_created'],
                    'commercial' => $additionalMetrics['orders_commercial'],
                    'sample' => $additionalMetrics['orders_sample'],
                    'mixed' => $additionalMetrics['orders_mixed'],
                    'new_win' => $additionalMetrics['orders_new_win'],
                    'pending' => $additionalMetrics['orders_pending'],
                    'processing' => $additionalMetrics['orders_processing'],
                    'completed' => $additionalMetrics['orders_completed'],
                    'value_usd' => $additionalMetrics['total_orders_value_usd'],
                    'value_cop' => $additionalMetrics['total_orders_value_cop'],
                ],
                'dispatch_performance' => [
                    'fulfillment_rate_usd' => $additionalMetrics['avg_fulfillment_rate_usd'],
                    'fulfillment_rate_products' => $additionalMetrics['avg_fulfillment_rate_products'],
                    'completion_percentage' => $additionalMetrics['avg_completion_percentage'],
                    'avg_days_to_dispatch' => $additionalMetrics['avg_days_to_first_dispatch'],
                    'orders_fully_dispatched' => $additionalMetrics['orders_fully_dispatched'],
                    'orders_partially_dispatched' => $additionalMetrics['orders_partially_dispatched'],
                    'orders_not_dispatched' => $additionalMetrics['orders_not_dispatched'],
                ],
                'products_analysis' => [
                    'commercial_dispatched' => $additionalMetrics['commercial_products_dispatched'],
                    'sample_dispatched' => $additionalMetrics['sample_products_dispatched'],
                    'commercial_pending' => $additionalMetrics['pending_commercial_products'],
                    'sample_pending' => $additionalMetrics['pending_sample_products'],
                    'planned_commercial' => $additionalMetrics['planned_commercial_products'],
                    'planned_sample' => $additionalMetrics['planned_sample_products'],
                ],
                'client_activity' => [
                    'with_orders' => $additionalMetrics['unique_clients_with_orders'],
                    'with_dispatches' => $additionalMetrics['unique_clients_with_dispatches'],
                    'with_planned_dispatches' => $additionalMetrics['unique_clients_with_planned_dispatches'],
                ],
                'financial_analysis' => [
                    'average_trm' => $additionalMetrics['average_trm'],
                    'pending_value_usd' => $additionalMetrics['pending_dispatch_value_usd'],
                    'pending_value_cop' => $additionalMetrics['pending_dispatch_value_cop'],
                    'planned_value_usd' => $additionalMetrics['planned_dispatch_value_usd'],
                    'planned_value_cop' => $additionalMetrics['planned_dispatch_value_cop'],
                ],

                // ✨ NUEVO: Extended Stats (KPIs avanzados)
                'extended_stats' => $extendedStatsAggregated,

                // Para debug/análisis avanzado (opcional)
                'stats' => $dailyStats, // Datos día por día para gráficos futuros
            ];

            return response()->json([
                'success' => true,
                'data' => $dashboardData
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving dashboard data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * ✨ NUEVA FUNCIÓN: Calcular valor total de recaudos esperados
     */
    private function calculateExpectedRecaudos($startDate, $endDate)
    {
        try {
            $totalRecaudos = DB::table('recaudos')
                ->whereBetween('fecha_recaudo', [$startDate . ' 00:00:00', $endDate . ' 23:59:59'])
                ->whereNotNull('fecha_recaudo')
                ->where('valor_cancelado', '>', 0)
                ->sum('valor_cancelado');

            return $totalRecaudos ?? 0;
        } catch (\Exception $e) {
            Log::error('Error calculating expected recaudos: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * ✨ NUEVA FUNCIÓN: Obtener cantidad de recaudos esperados
     */
    private function getRecaudosCount($startDate, $endDate)
    {
        try {
            $count = DB::table('recaudos')
                ->whereBetween('fecha_recaudo', [$startDate . ' 00:00:00', $endDate . ' 23:59:59'])
                ->whereNotNull('fecha_recaudo')
                ->where('valor_cancelado', '>', 0)
                ->count();

            return $count ?? 0;
        } catch (\Exception $e) {
            Log::error('Error counting expected recaudos: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Calculate additional metrics from daily statistics generated by the command
     */
    private function calculateAdditionalMetrics($dailyStats)
    {
        if ($dailyStats->isEmpty()) {
            return $this->getEmptyMetrics();
        }

        $totals = [
            // Order creation metrics
            'total_orders_created' => $dailyStats->sum('total_orders_created'),
            'orders_commercial' => $dailyStats->sum('orders_commercial'),
            'orders_sample' => $dailyStats->sum('orders_sample'),
            'orders_mixed' => $dailyStats->sum('orders_mixed'),
            'orders_new_win' => $dailyStats->sum('orders_new_win'),
            'orders_pending' => $dailyStats->sum('orders_pending'),
            'orders_processing' => $dailyStats->sum('orders_processing'),
            'orders_completed' => $dailyStats->sum('orders_completed'),
            'total_orders_value_usd' => $dailyStats->sum('total_orders_value_usd'),
            'total_orders_value_cop' => $dailyStats->sum('total_orders_value_cop'),

            // Dispatch performance metrics
            'orders_fully_dispatched' => $dailyStats->sum('orders_fully_dispatched'),
            'orders_partially_dispatched' => $dailyStats->sum('orders_partially_dispatched'),
            'orders_not_dispatched' => $dailyStats->sum('orders_not_dispatched'),

            // Products analysis
            'commercial_products_dispatched' => $dailyStats->sum('commercial_products_dispatched'),
            'sample_products_dispatched' => $dailyStats->sum('sample_products_dispatched'),
            'pending_commercial_products' => $dailyStats->sum('pending_commercial_products'),
            'pending_sample_products' => $dailyStats->sum('pending_sample_products'),
            'planned_commercial_products' => $dailyStats->sum('planned_commercial_products'),
            'planned_sample_products' => $dailyStats->sum('planned_sample_products'),

            // Client activity (max values, not sum, to avoid double counting)
            'unique_clients_with_orders' => $dailyStats->max('unique_clients_with_orders'),
            'unique_clients_with_dispatches' => $dailyStats->max('unique_clients_with_dispatches'),
            'unique_clients_with_planned_dispatches' => $dailyStats->max('unique_clients_with_planned_dispatches'),

            // Financial metrics
            'pending_dispatch_value_usd' => $dailyStats->sum('pending_dispatch_value_usd'),
            'pending_dispatch_value_cop' => $dailyStats->sum('pending_dispatch_value_cop'),
            'planned_dispatch_value_usd' => $dailyStats->sum('planned_dispatch_value_usd'),
            'planned_dispatch_value_cop' => $dailyStats->sum('planned_dispatch_value_cop'),
        ];

        // Calculate averages for percentage metrics
        $validDaysForFulfillment = $dailyStats->where('dispatch_fulfillment_rate_usd', '>', 0);
        $validDaysForCompletion = $dailyStats->where('dispatch_completion_percentage', '>', 0);
        $validDaysForDaysToDispatch = $dailyStats->where('avg_days_order_to_first_dispatch', '>', 0);
        $validDaysForTrm = $dailyStats->where('average_trm', '>', 0);

        $totals['avg_fulfillment_rate_usd'] = $validDaysForFulfillment->count() > 0
            ? round($validDaysForFulfillment->avg('dispatch_fulfillment_rate_usd'), 2)
            : 0;

        $totals['avg_fulfillment_rate_products'] = $validDaysForFulfillment->count() > 0
            ? round($validDaysForFulfillment->avg('dispatch_fulfillment_rate_products'), 2)
            : 0;

        $totals['avg_completion_percentage'] = $validDaysForCompletion->count() > 0
            ? round($validDaysForCompletion->avg('dispatch_completion_percentage'), 2)
            : 0;

        $totals['avg_days_to_first_dispatch'] = $validDaysForDaysToDispatch->count() > 0
            ? round($validDaysForDaysToDispatch->avg('avg_days_order_to_first_dispatch'), 1)
            : 0;

        $totals['average_trm'] = $validDaysForTrm->count() > 0
            ? round($validDaysForTrm->avg('average_trm'), 2)
            : 4000;

        return $totals;
    }

    /**
     * Agrega y decodifica los extended_stats de todos los días del período
     * Acumula datos de múltiples días para mostrar estadísticas del rango completo
     *
     * @param \Illuminate\Support\Collection $dailyStats
     * @return array
     */
    private function aggregateExtendedStats($dailyStats): array
    {
        if ($dailyStats->isEmpty()) {
            return $this->getEmptyExtendedStats();
        }

        // Decodificar todos los extended_stats del período
        $allStats = [];
        foreach ($dailyStats as $dayStat) {
            if (!empty($dayStat->extended_stats)) {
                $decoded = json_decode($dayStat->extended_stats, true);
                if ($decoded) {
                    $allStats[] = $decoded;
                }
            }
        }

        if (empty($allStats)) {
            return $this->getEmptyExtendedStats();
        }

        // Agregar datos de todos los días
        $aggregated = [
            'lead_time' => $this->aggregateLeadTime($allStats),
            'fill_rate' => $this->aggregateFillRate($allStats),
            'samples_vs_commercial' => $this->aggregateSamplesVsCommercial($allStats),
            'cartera_vs_despachos' => $this->aggregateCarteraVsDespachos($allStats),
            'executive_completion' => $this->aggregateExecutiveCompletion($allStats),
            'new_accounts' => $this->aggregateNewAccounts($allStats),
            'recaudos_vs_dispatch' => $this->aggregateRecaudosVsDispatch($allStats),
            'billing_projection' => $this->aggregateBillingProjection($allStats),
            'alerts' => [],
            'logistics' => $this->aggregateLogistics($allStats),
        ];

        return $aggregated;
    }

    /**
     * Agregar Lead Time de múltiples días
     */
    private function aggregateLeadTime(array $allStats): array
    {
        $avgDays = [];
        $onTimePcts = [];

        foreach ($allStats as $stat) {
            if (isset($stat['lead_time']['avg_days']) && $stat['lead_time']['avg_days'] !== null) {
                $avgDays[] = $stat['lead_time']['avg_days'];
            }
            if (isset($stat['lead_time']['on_time_orders_pct']) && $stat['lead_time']['on_time_orders_pct'] !== null) {
                $onTimePcts[] = $stat['lead_time']['on_time_orders_pct'];
            }
        }

        return [
            'avg_days' => !empty($avgDays) ? round(array_sum($avgDays) / count($avgDays), 2) : null,
            'on_time_orders_pct' => !empty($onTimePcts) ? round(array_sum($onTimePcts) / count($onTimePcts), 2) : null,
        ];
    }

    /**
     * Agregar Fill Rate de múltiples días
     */
    private function aggregateFillRate(array $allStats): array
    {
        $qtyPcts = [];
        $valuePcts = [];

        foreach ($allStats as $stat) {
            if (isset($stat['fill_rate']['qty_pct']) && $stat['fill_rate']['qty_pct'] !== null) {
                $qtyPcts[] = $stat['fill_rate']['qty_pct'];
            }
            if (isset($stat['fill_rate']['value_pct']) && $stat['fill_rate']['value_pct'] !== null) {
                $valuePcts[] = $stat['fill_rate']['value_pct'];
            }
        }

        return [
            'qty_pct' => !empty($qtyPcts) ? round(array_sum($qtyPcts) / count($qtyPcts), 2) : null,
            'value_pct' => !empty($valuePcts) ? round(array_sum($valuePcts) / count($valuePcts), 2) : null,
        ];
    }

    /**
     * Agregar Samples vs Commercial de múltiples días
     */
    private function aggregateSamplesVsCommercial(array $allStats): array
    {
        $totalCommercialOrders = 0;
        $totalOrdersWithSamples = 0;
        $totalSampleProductsDispatched = 0;

        foreach ($allStats as $stat) {
            $totalCommercialOrders += $stat['samples_vs_commercial']['commercial_orders'] ?? 0;
            $totalOrdersWithSamples += $stat['samples_vs_commercial']['orders_with_samples'] ?? 0;
            $totalSampleProductsDispatched += $stat['samples_vs_commercial']['sample_products_dispatched'] ?? 0;
        }

        return [
            'commercial_orders' => $totalCommercialOrders,
            'orders_with_samples' => $totalOrdersWithSamples,
            'sample_products_dispatched' => $totalSampleProductsDispatched,
        ];
    }

    /**
     * Agregar Cartera vs Despachos - Consolidar por NIT
     */
    private function aggregateCarteraVsDespachos(array $allStats): array
    {
        $byNit = [];

        foreach ($allStats as $stat) {
            if (empty($stat['cartera_vs_despachos'])) continue;

            foreach ($stat['cartera_vs_despachos'] as $item) {
                $nit = $item['nit'] ?? 'unknown';

                if (!isset($byNit[$nit])) {
                    $byNit[$nit] = [
                        'nit' => $nit,
                        'client_id' => $item['client_id'] ?? null,
                        'dispatch_value_cop' => 0,
                        'cartera_saldo' => $item['cartera_saldo'] ?? 0, // Último valor
                    ];
                }

                $byNit[$nit]['dispatch_value_cop'] += $item['dispatch_value_cop'] ?? 0;
            }
        }

        // Ordenar por dispatch_value_cop descendente y retornar todos
        usort($byNit, fn($a, $b) => $b['dispatch_value_cop'] <=> $a['dispatch_value_cop']);
        return array_values($byNit);
    }

    /**
     * Agregar Executive Completion - Consolidar por ejecutivo
     */
    private function aggregateExecutiveCompletion(array $allStats): array
    {
        $byExecutive = [];

        foreach ($allStats as $stat) {
            if (empty($stat['executive_completion'])) continue;

            foreach ($stat['executive_completion'] as $exec) {
                $name = $exec['executive'] ?? 'Sin ejecutivo';

                if (!isset($byExecutive[$name])) {
                    $byExecutive[$name] = [
                        'executive' => $name,
                        'total_orders' => 0,
                        'completed' => 0,
                        'partial' => 0,
                        'pending' => 0,
                    ];
                }

                $byExecutive[$name]['total_orders'] += $exec['total_orders'] ?? 0;
                $byExecutive[$name]['completed'] += $exec['completed'] ?? 0;
                $byExecutive[$name]['partial'] += $exec['partial'] ?? 0;
                $byExecutive[$name]['pending'] += $exec['pending'] ?? 0;
            }
        }

        // Calcular porcentaje de cumplimiento
        foreach ($byExecutive as &$exec) {
            $exec['completion_pct'] = $exec['total_orders'] > 0
                ? round(($exec['completed'] / $exec['total_orders']) * 100, 2)
                : null;
        }

        return array_values($byExecutive);
    }

    /**
     * Agregar New Accounts de múltiples días
     */
    private function aggregateNewAccounts(array $allStats): array
    {
        $totalCount = 0;
        $winRates = [];

        foreach ($allStats as $stat) {
            $totalCount += $stat['new_accounts']['count'] ?? 0;
            if (isset($stat['new_accounts']['win_rate_pct']) && $stat['new_accounts']['win_rate_pct'] !== null) {
                $winRates[] = $stat['new_accounts']['win_rate_pct'];
            }
        }

        return [
            'count' => $totalCount,
            'win_rate_pct' => !empty($winRates) ? round(array_sum($winRates) / count($winRates), 2) : null,
        ];
    }

    /**
     * Agregar Recaudos vs Dispatch - Consolidar por NIT
     */
    private function aggregateRecaudosVsDispatch(array $allStats): array
    {
        $byNit = [];

        foreach ($allStats as $stat) {
            if (empty($stat['recaudos_vs_dispatch'])) continue;

            foreach ($stat['recaudos_vs_dispatch'] as $item) {
                $nit = $item['nit'] ?? 'unknown';

                if (!isset($byNit[$nit])) {
                    $byNit[$nit] = [
                        'nit' => $nit,
                        'cliente' => $item['cliente'] ?? null,
                        'client_id' => $item['client_id'] ?? null,
                        'dispatch_value_cop' => 0,
                        'recaudo_cop' => 0,
                    ];
                }

                $byNit[$nit]['dispatch_value_cop'] += $item['dispatch_value_cop'] ?? 0;
                $byNit[$nit]['recaudo_cop'] += $item['recaudo_cop'] ?? 0;
            }
        }

        // Ordenar por dispatch_value_cop descendente y retornar todos
        usort($byNit, fn($a, $b) => $b['dispatch_value_cop'] <=> $a['dispatch_value_cop']);
        return array_values($byNit);
    }

    /**
     * Agregar Billing Projection de múltiples días
     */
    private function aggregateBillingProjection(array $allStats): array
    {
        $totalUsd = 0;
        $totalCop = 0;

        foreach ($allStats as $stat) {
            $totalUsd += $stat['billing_projection']['usd'] ?? 0;
            $totalCop += $stat['billing_projection']['cop'] ?? 0;
        }

        return [
            'usd' => $totalUsd,
            'cop' => $totalCop,
        ];
    }

    /**
     * Agregar Logistics de múltiples días
     */
    private function aggregateLogistics(array $allStats): array
    {
        $avgPartialsPerOrder = [];
        $totalOrdersGt2 = 0;
        $avgDaysBetween = [];

        foreach ($allStats as $stat) {
            if (isset($stat['logistics']['avg_partials_per_order']) && $stat['logistics']['avg_partials_per_order'] !== null) {
                $avgPartialsPerOrder[] = $stat['logistics']['avg_partials_per_order'];
            }
            $totalOrdersGt2 += $stat['logistics']['orders_gt2_partials'] ?? 0;
            if (isset($stat['logistics']['avg_days_between_partials']) && $stat['logistics']['avg_days_between_partials'] !== null) {
                $avgDaysBetween[] = $stat['logistics']['avg_days_between_partials'];
            }
        }

        return [
            'avg_partials_per_order' => !empty($avgPartialsPerOrder) ? round(array_sum($avgPartialsPerOrder) / count($avgPartialsPerOrder), 2) : null,
            'orders_gt2_partials' => $totalOrdersGt2,
            'avg_days_between_partials' => !empty($avgDaysBetween) ? round(array_sum($avgDaysBetween) / count($avgDaysBetween), 2) : null,
        ];
    }

    /**
     * Return empty extended stats structure
     */
    private function getEmptyExtendedStats(): array
    {
        return [
            'lead_time' => ['avg_days' => null, 'on_time_orders_pct' => null],
            'fill_rate' => ['qty_pct' => null, 'value_pct' => null],
            'samples_vs_commercial' => [
                'orders_with_samples' => 0,
                'sample_products_dispatched' => 0,
                'commercial_orders' => 0,
            ],
            'cartera_vs_despachos' => [],
            'executive_completion' => [],
            'new_accounts' => ['count' => 0, 'win_rate_pct' => null],
            'recaudos_vs_dispatch' => [],
            'billing_projection' => ['usd' => null, 'cop' => null],
            'alerts' => [],
            'logistics' => [
                'avg_partials_per_order' => null,
                'orders_gt2_partials' => 0,
                'avg_days_between_partials' => null,
            ],
        ];
    }

    /**
     * Return empty metrics structure
     */
    private function getEmptyMetrics()
    {
        return [
            'total_orders_created' => 0,
            'orders_commercial' => 0,
            'orders_sample' => 0,
            'orders_mixed' => 0,
            'orders_new_win' => 0,
            'orders_pending' => 0,
            'orders_processing' => 0,
            'orders_completed' => 0,
            'total_orders_value_usd' => 0,
            'total_orders_value_cop' => 0,
            'orders_fully_dispatched' => 0,
            'orders_partially_dispatched' => 0,
            'orders_not_dispatched' => 0,
            'commercial_products_dispatched' => 0,
            'sample_products_dispatched' => 0,
            'pending_commercial_products' => 0,
            'pending_sample_products' => 0,
            'planned_commercial_products' => 0,
            'planned_sample_products' => 0,
            'unique_clients_with_orders' => 0,
            'unique_clients_with_dispatches' => 0,
            'unique_clients_with_planned_dispatches' => 0,
            'pending_dispatch_value_usd' => 0,
            'pending_dispatch_value_cop' => 0,
            'planned_dispatch_value_usd' => 0,
            'planned_dispatch_value_cop' => 0,
            'avg_fulfillment_rate_usd' => 0,
            'avg_fulfillment_rate_products' => 0,
            'avg_completion_percentage' => 0,
            'avg_days_to_first_dispatch' => 0,
            'average_trm' => 4000,
        ];
    }


    /**
     * Calculate planned statistics based on partials with priority: real -> temporal -> calculated
     * 1. Usa partials 'real' con dispatch_date (máxima prioridad)
     * 2. Si no existe, usa partials 'temporal' con dispatch_date  
     * 3. Si no existe, usa order_creation_date + 10 días hábiles
     */
    private function calculatePlannedStats($startDate, $endDate)
    {
        // Obtener todos los productos de órdenes y sus partials (real y temporal)
        $orderProducts = DB::table('purchase_order_product')
            ->join('purchase_orders', 'purchase_order_product.purchase_order_id', '=', 'purchase_orders.id')
            ->join('products', 'purchase_order_product.product_id', '=', 'products.id')
            ->join('clients', 'purchase_orders.client_id', '=', 'clients.id')
            ->leftJoin('partials', function ($join) {
                $join->on('partials.product_order_id', '=', 'purchase_order_product.id')
                    ->whereIn('partials.type', ['real', 'temporal']); // Incluir ambos tipos
            })
            ->select(
                'purchase_orders.id as order_id',
                'purchase_orders.order_creation_date',
                'purchase_orders.trm as order_trm',
                'purchase_order_product.id as product_order_id',
                'purchase_order_product.quantity',
                'purchase_order_product.price as order_product_price',
                'purchase_order_product.muestra',
                'products.price as product_price',
                'partials.dispatch_date as partial_dispatch_date',
                'partials.quantity as partial_quantity',
                'partials.type as partial_type', // Agregar el tipo de partial
                'partials.trm as partial_trm' // Agregar TRM del partial
            )
            ->get();

        // Obtener TRMs del rango ampliado (considerando los +10 días hábiles)
        $expandedEndDate = $this->addBusinessDays($endDate, 15); // Buffer adicional
        $trmData = DB::table('trm_daily')
            ->whereBetween('date', [$startDate, $expandedEndDate])
            ->pluck('value', 'date')
            ->toArray();

        // Agrupar por product_order_id para manejar múltiples partials
        $productOrdersGrouped = $orderProducts->groupBy('product_order_id');

        $totalValueUsd = 0;
        $totalValueCop = 0;
        $ordersSet = [];
        $totalCommercialProducts = 0;
        $totalSampleProducts = 0;
        $trmUsageStats = [
            'from_partial_real' => 0,       // Nueva: TRM desde partial real
            'from_table' => 0,              // TRM de trm_daily (principal)
            'from_order' => 0,              // TRM de la orden (fallback)
            'default' => 0                  // TRM por defecto (último fallback)
        ];
        $dispatchSourceStats = [
            'from_partial_real' => 0,           // Nueva: desde partial real
            'from_partial_temporal' => 0,       // Existente: desde partial temporal
            'calculated_business_days' => 0     // Existente: calculado
        ];

        foreach ($productOrdersGrouped as $productOrderId => $productData) {
            $firstProduct = $productData->first();
            $orderQuantity = (int) $firstProduct->quantity;
            $isSample = (int) $firstProduct->muestra === 1;

            // Contar productos por tipo
            if ($isSample) {
                $totalSampleProducts += $orderQuantity;
            } else {
                $totalCommercialProducts += $orderQuantity;
            }

            // Obtener la fecha de despacho planeada y su fuente (con nueva lógica de prioridad)
            $dispatchInfo = $this->getPlannedDispatchDateWithSource(
                $productData,
                $firstProduct->order_creation_date,
                $dispatchSourceStats
            );

            $plannedDispatchDate = $dispatchInfo['date'];
            $sourceType = $dispatchInfo['source_type'];
            $partialTrm = $dispatchInfo['partial_trm'] ?? null; // TRM del partial si existe

            // Verificar si la fecha planeada está en nuestro rango de interés
            if ($plannedDispatchDate < $startDate || $plannedDispatchDate > $endDate) {
                continue;
            }

            // Solo calcular valor para productos comerciales (no muestras)
            if (!$isSample) {
                // Usar precio efectivo: si order_product_price > 0, usar ese, sino usar product_price
                $effectivePrice = ($firstProduct->order_product_price > 0) ? $firstProduct->order_product_price : ($firstProduct->product_price ?? 0);
                $priceUsd = (float) $effectivePrice;

                // Obtener TRM considerando si viene de partial real
                $trmToUse = $this->getTrmForDateWithPartial(
                    $plannedDispatchDate,
                    $partialTrm,              // TRM del partial (solo si es de tipo real)
                    $firstProduct->order_trm,
                    $trmData,
                    $sourceType,              // Para saber si usar TRM del partial
                    $trmUsageStats
                );

                // Calcular valores
                $valueUsd = $priceUsd * $orderQuantity;
                $valueCop = $valueUsd * $trmToUse;

                $totalValueUsd += $valueUsd;
                $totalValueCop += $valueCop;
            }

            // Contar órdenes únicas
            $ordersSet[$firstProduct->order_id] = true;
        }

        return [
            'total_value_usd' => $totalValueUsd,
            'total_value_cop' => $totalValueCop,
            'orders_count' => count($ordersSet),
            'products_count' => $totalCommercialProducts + $totalSampleProducts,
            'commercial_products_count' => $totalCommercialProducts,
            'sample_products_count' => $totalSampleProducts,
            'trm_usage_stats' => $trmUsageStats,
            'dispatch_source_stats' => $dispatchSourceStats,
        ];
    }

    /**
     * Obtiene la TRM considerando si viene de partial real o usando la lógica anterior
     */
    private function getTrmForDateWithPartial($date, $partialTrm, $orderTrm, $trmData, $sourceType, &$trmUsageStats)
    {
        // 1. PRIORIDAD MÁXIMA: Si viene de partial real y tiene TRM válida, usar esa
        if ($sourceType === 'partial_real' && !empty($partialTrm) && $partialTrm > 3800) {
            $trmUsageStats['from_partial_real']++;
            return (float) $partialTrm;
        }

        // 2. Prioridad: TRM de la tabla trm_daily para la fecha específica
        if (isset($trmData[$date])) {
            $trmUsageStats['from_table']++;
            return (float) $trmData[$date];
        }

        // 3. Fallback: TRM de la orden si existe y es válida
        if (!empty($orderTrm) && $orderTrm > 3800) {
            $trmUsageStats['from_order']++;
            return (float) $orderTrm;
        }

        // 4. Último fallback: TRM por defecto
        $trmUsageStats['default']++;
        return 4000.0;
    }


    /**
     * Determina la fecha de despacho planeada con nueva lógica de prioridad:
     * 1. Partials 'real' con dispatch_date (máxima prioridad)
     * 2. Partials 'temporal' con dispatch_date
     * 3. Order creation date + 10 días hábiles (fallback)
     */
    private function getPlannedDispatchDateWithSource($productData, $orderCreationDate, &$dispatchSourceStats)
    {
        // Separar partials por tipo y filtrar los que tienen dispatch_date
        $realPartials = $productData->where('partial_type', 'real')
            ->whereNotNull('partial_dispatch_date');

        $temporalPartials = $productData->where('partial_type', 'temporal')
            ->whereNotNull('partial_dispatch_date');

        // 1. PRIORIDAD MÁXIMA: Partials tipo 'real' con dispatch_date
        if ($realPartials->isNotEmpty()) {
            $earliestReal = $realPartials->sortBy('partial_dispatch_date')->first();
            $dispatchSourceStats['from_partial_real']++;

            return [
                'date' => $earliestReal->partial_dispatch_date,
                'source_type' => 'partial_real',
                'partial_trm' => $earliestReal->partial_trm // Incluir TRM del partial
            ];
        }

        // 2. SEGUNDA PRIORIDAD: Partials tipo 'temporal' con dispatch_date
        if ($temporalPartials->isNotEmpty()) {
            $earliestTemporal = $temporalPartials->sortBy('partial_dispatch_date')->first();
            $dispatchSourceStats['from_partial_temporal']++;

            return [
                'date' => $earliestTemporal->partial_dispatch_date,
                'source_type' => 'partial_temporal'
            ];
        }

        // 3. FALLBACK: Calcular order_creation_date + 10 días hábiles
        $plannedDate = $this->addBusinessDays($orderCreationDate, 10);
        $dispatchSourceStats['calculated_business_days']++;

        return [
            'date' => $plannedDate,
            'source_type' => 'calculated_business_days'
        ];
    }


    /**
     * Calcula una fecha sumando días hábiles (excluyendo sábados y domingos)
     * Podrías mejorar esto para excluir también días festivos colombianos
     */
    private function addBusinessDays($date, $businessDays)
    {
        $currentDate = Carbon::parse($date);
        $addedDays = 0;

        while ($addedDays < $businessDays) {
            $currentDate->addDay();

            // Si no es sábado (6) ni domingo (0), contar como día hábil
            if (!in_array($currentDate->dayOfWeek, [Carbon::SATURDAY, Carbon::SUNDAY])) {
                $addedDays++;
            }
        }

        return $currentDate->format('Y-m-d');
    }

    public function updatePartial(Request $request)
    {
        // Validar los datos de entrada
        $request->validate([
            'id' => 'required|integer',
            'dispatch_date' => 'nullable|date',
            'quantity' => 'required|integer|min:1',
            'trm' => 'required|numeric|min:0'
        ]);

        // Buscar el parcial
        $partial = Partial::find($request->get('id'));

        if (!$partial) {
            return response()->json([
                'success' => false,
                'message' => 'Parcial no encontrado.'
            ], 404);
        }

        try {
            // Actualizar los campos según tu estructura de tabla
            if ($request->has('dispatch_date') && $request->get('dispatch_date')) {
                $partial->dispatch_date = $request->get('dispatch_date');
            }

            $partial->quantity = $request->get('quantity');
            $partial->trm = $request->get('trm');

            // Actualizar updated_at automáticamente
            $partial->touch();

            // Guardar los cambios
            $partial->save();

            return response()->json([
                'success' => true,
                'message' => 'Parcial actualizado con éxito.',
                'data' => [
                    'id' => $partial->id,
                    'quantity' => $partial->quantity,
                    'trm' => $partial->trm,
                    'dispatch_date' => $partial->dispatch_date,
                    'updated_at' => $partial->updated_at
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar el parcial: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Restaurar un parcial desde un log específico
     */
    public function restorePartialFromLog(Request $request)
    {
        $request->validate([
            'log_id' => 'required|integer|exists:partial_logs,id'
        ]);

        $log = PartialLog::find($request->log_id);

        if (!$log->canRestore()) {
            return response()->json([
                'success' => false,
                'message' => 'Este log no puede ser restaurado.'
            ], 400);
        }

        try {
            $success = $log->restore();

            if ($success) {
                return response()->json([
                    'success' => true,
                    'message' => 'Parcial restaurado exitosamente.',
                    'restored_fields' => count($log->changed_fields ?? [])
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'No se pudo restaurar el parcial.'
                ], 500);
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al restaurar: ' . $e->getMessage()
            ], 500);
        }
    }
}
