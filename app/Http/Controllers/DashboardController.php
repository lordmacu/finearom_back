<?php

namespace App\Http\Controllers;

use App\Models\Partial;
use App\Models\PartialLog;
use App\Models\PurchaseOrder;
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
                $priceUsd = (float) $firstProduct->product_price;

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
