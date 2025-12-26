<?php

namespace App\Console\Commands;

use App\Models\PurchaseOrder;
use App\Services\TrmService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Generates complete daily statistics:
 * - Dispatch stats using analyzeClientsByStatus logic
 * - Order creation stats for the day
 * - Completion and fulfillment stats
 * - Financial and TRM stats
 * - Client stats
 * - Pending dispatch stats (temporal vs real)
 */
class GenerateOrderStatistics extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'stats:daily-dispatch
                            {--date= : Target date (YYYY-MM-DD)}
                            {--from= : Start date for range (YYYY-MM-DD)}
                            {--to= : End date for range (YYYY-MM-DD)}
                            {--force : Overwrite existing row for that date/range}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate complete daily statistics including dispatch, orders, completion, pending dispatch and financial data.';

    /**
     * TRM service.
     *
     * @var \App\Services\TrmService
     */
    private TrmService $trm_service;

    /**
     * Constructor.
     */
    public function __construct(TrmService $trm_service)
    {
        parent::__construct();
        $this->trm_service = $trm_service;
    }

    /**
     * Handle the command execution.
     */
    public function handle(): int
    {
        $force_write = (bool) $this->option('force');

        // Range option (from/to) for backfill or testing
        $fromOption = $this->option('from');
        $toOption = $this->option('to');

        if ($fromOption || $toOption) {
            try {
                $fromDate = $fromOption
                    ? Carbon::createFromFormat('Y-m-d', $fromOption)
                    : Carbon::now()->startOfMonth();
                $toDate = $toOption
                    ? Carbon::createFromFormat('Y-m-d', $toOption)
                    : Carbon::now();
            } catch (\Throwable $e) {
                $this->error('Invalid --from/--to value. Use YYYY-MM-DD.');
                return 1;
            }

            if ($toDate->lessThan($fromDate)) {
                $this->error('--to must be >= --from.');
                return 1;
            }

            $period = new \DatePeriod($fromDate, new \DateInterval('P1D'), $toDate->copy()->addDay());
            foreach ($period as $date) {
                /** @var \DateTime $date */
                $this->generateForDate(Carbon::instance($date), $force_write);
            }
            return 0;
        }

        // Single day path
        $target_date = $this->option('date') ?: Carbon::now()->format('Y-m-d');
        try {
            $date = Carbon::createFromFormat('Y-m-d', $target_date);
        } catch (\Throwable $e) {
            $this->error('Invalid --date value. Use YYYY-MM-DD.');
            return 1;
        }

        $this->generateForDate($date, $force_write);
        return 0;
    }

    /**
     * Generate statistics for a specific date and persist them.
     */
    private function generateForDate(Carbon $date, bool $force_write): void
    {
        $from = $date->copy()->startOfDay();
        $to   = $date->copy()->endOfDay();

        $this->info("Computing complete statistics for {$date->format('Y-m-d')}");

        // Always overwrite: remove existing row for that date to keep data refreshed
        DB::table('order_statistics')->where('date', $date->format('Y-m-d'))->delete();

        // Calculate all statistics
        $stats = $this->calculateAllStatistics($from, $to);

        // Insert fresh row
        DB::table('order_statistics')->insert($stats);

        // Display summary
        $this->displaySummary($stats);
    }

    /**
     * Calculate all statistics for the day
     */
    private function calculateAllStatistics(Carbon $from, Carbon $to): array
    {
        $now = Carbon::now();

        $stats = [
            'date' => $from->format('Y-m-d'),
            'hour' => 23, // End of day
            'updated_at' => $now,
        ];

        // 1. Order Creation Statistics (orders created on this day)
        $this->line('   Calculating order creation stats...');
        $orderStats = $this->calculateOrderCreationStats($from, $to);
        $stats = array_merge($stats, $orderStats);

        // 2. Dispatch Statistics (using exact analyzeClientsByStatus logic)
        $this->line('   Calculating dispatch stats...');
        $dispatchStats = $this->calculateDispatchStats($from, $to);
        $stats = array_merge($stats, $dispatchStats);

        // 3. Planned Statistics (using calculatePlannedStats logic from controller)
        $this->line('   Calculating planned stats...');
        $plannedStats = $this->calculatePlannedStatsForDay($from, $to);
        $stats = array_merge($stats, $plannedStats);

        // 4. Pending Dispatch Statistics (planned - real)
        $this->line('   Calculating pending dispatch stats...');
        $pendingStats = $this->calculatePendingDispatchStats($dispatchStats, $plannedStats);
        $stats = array_merge($stats, $pendingStats);

        // 5. Financial Statistics (only average_trm is used in dashboard)
        $this->line('   Calculating financial stats...');
        $financialStats = $this->calculateFinancialStats($from, $to);
        // Only keep average_trm, set unused fields to 0
        $stats['average_trm'] = $financialStats['average_trm'];
        $stats['partials_with_default_trm'] = 0;  // Not used in dashboard
        $stats['partials_with_custom_trm'] = 0;   // Not used in dashboard

        // 6. Completion/Client stats (not used in dashboard) - set to 0 by default
        $unusedStats = [
            'orders_fully_dispatched' => 0,
            'orders_partially_dispatched' => 0,
            'orders_not_dispatched' => 0,
            'avg_days_order_to_first_dispatch' => 0,
            'dispatch_completion_percentage' => 0,
            'unique_clients_with_orders' => 0,
            'unique_clients_with_dispatches' => 0,
            'unique_clients_with_planned_dispatches' => 0,
        ];
        $stats = array_merge($stats, $unusedStats);

        // 7. Extended stats snapshot (compute KPIs to avoid frontend recalculation)
        // Encode to JSON because we insert via query builder
        $stats['extended_stats'] = json_encode(
            $this->buildExtendedStatsSnapshot($from, $to, $orderStats, $dispatchStats, $plannedStats)
        );

        return $stats;
    }



    /**
     * Calculate order creation statistics for orders created on this specific day
     */
    private function calculateOrderCreationStats(Carbon $from, Carbon $to): array
    {
        $orders = PurchaseOrder::with(['products'])
            ->whereBetween('order_creation_date', [$from, $to])
            ->get();

        $stats = [
            'total_orders_created' => $orders->count(),
            'orders_pending' => $orders->where('status', 'pending')->count(),
            'orders_processing' => $orders->where('status', 'processing')->count(),
            'orders_completed' => $orders->where('status', 'completed')->count(),
            'orders_parcial_status' => $orders->where('status', 'parcial_status')->count(),
            'orders_new_win' => $orders->where('is_new_win', 1)->count(),
        ];

        // Calculate order values and types
        $totalValueUsd = 0;
        $ordersCommercial = 0;
        $ordersSample = 0;
        $ordersMixed = 0;

        foreach ($orders as $order) {
            $commercialProducts = 0;
            $sampleProducts = 0;
            $orderValue = 0;

            foreach ($order->products as $product) {
                $quantity = $product->pivot->quantity ?? 0;
                // Usar precio efectivo: si pivot->price > 0, usar ese, sino usar product->price
                $effectivePrice = ($product->pivot->price > 0) ? $product->pivot->price : ($product->price ?? 0);
                $isSample = $product->pivot->muestra == 1;

                if ($isSample) {
                    $sampleProducts++;
                } else {
                    $commercialProducts++;
                    $orderValue += $quantity * $effectivePrice;
                }
            }

            $totalValueUsd += $orderValue;

            // Classify order type
            if ($commercialProducts > 0 && $sampleProducts > 0) {
                $ordersMixed++;
            } elseif ($commercialProducts > 0) {
                $ordersCommercial++;
            } elseif ($sampleProducts > 0) {
                $ordersSample++;
            }
        }

        $stats['total_orders_value_usd'] = $totalValueUsd;
        $stats['orders_commercial'] = $ordersCommercial;
        $stats['orders_sample'] = $ordersSample;
        $stats['orders_mixed'] = $ordersMixed;

        // Calculate COP value using day average TRM
        $dayAverageTrm = $this->getDayAverageTrm($from, $to);
        $stats['total_orders_value_cop'] = $totalValueUsd * $dayAverageTrm;

        return $stats;
    }

    /**
     * Calculate dispatch statistics using exact analyzeClientsByStatus logic
     */
    private function calculateDispatchStats(Carbon $from, Carbon $to): array
    {
        // Exact same query as analyzeClientsByStatus
        $orders = PurchaseOrder::with([
            'client',
            'partials' => function ($q) use ($from, $to) {
                $q->where('type', 'real')
                  ->whereBetween('dispatch_date', [$from, $to])
                  ->with('product')
                  ->join('purchase_order_product', 'partials.product_order_id', '=', 'purchase_order_product.id')
                  ->select('partials.*', 'purchase_order_product.muestra as muestra', 'purchase_order_product.price as pivot_price')
                  ->distinct();
            },
        ])->whereHas('partials', function ($q) use ($from, $to) {
            $q->where('type', 'real')->whereBetween('dispatch_date', [$from, $to]);
        })->get();

        $dispatched_orders_set = [];
        $total_value_usd = 0.0;
        $total_value_cop = 0.0;
        $commercial_products_dispatched = 0;
        $sample_products_dispatched = 0;

        foreach ($orders as $order) {
            $dispatched_any_for_order = false;

            foreach ($order->partials as $partial) {
                // Extra guard (same as controller)
                if (
                    !Carbon::parse($partial->dispatch_date)->between($from, $to) ||
                    $partial->type !== 'real'
                ) {
                    continue;
                }

                // Price USD: 0 if sample (muestra == 1), else effective price
                $isSample = isset($partial->muestra) && (int) $partial->muestra === 1;
                if ($isSample) {
                    $price_usd = 0.0;
                } else {
                    // Usar precio efectivo: si pivot_price > 0, usar ese, sino usar product->price
                    $effectivePrice = ($partial->pivot_price > 0) ? $partial->pivot_price : ($partial->product->price ?? 0);
                    $price_usd = (float) $effectivePrice;
                }

                // Effective TRM identical call to the controller
                $trm_data = $this->trm_service->getEffectiveTrm($partial->trm, $partial->dispatch_date);
                $trm = (float) $trm_data['trm'];
                // Alinear con analyze: si la TRM es muy baja o viene nula, usar TRM del día y último fallback
                if ($trm < 3800) {
                    $fallbackTrm = $this->trm_service->getTrm($partial->dispatch_date);
                    $trm = $fallbackTrm > 0 ? (float) $fallbackTrm : 4000;
                    if ($trm < 3800) {
                        $trm = 4000;
                    }
                }

                // Amount in COP
                $amount_cop = ($price_usd * (float) $partial->quantity) * $trm;

                // Accumulate totals
                $total_value_usd += $price_usd * (float) $partial->quantity;
                $total_value_cop += $amount_cop;

                // Count products by type
                $isSample = isset($partial->muestra) && (int) $partial->muestra === 1;

                if ($isSample) {
                    $sample_products_dispatched += (int) $partial->quantity;
                } else {
                    $commercial_products_dispatched += (int) $partial->quantity;
                }

                $dispatched_any_for_order = true;
            }

            if ($dispatched_any_for_order) {
                $dispatched_orders_set[$order->id] = true;
            }
        }

        return [
            'dispatched_orders_count' => count($dispatched_orders_set),
            'commercial_dispatched_value_usd' => $total_value_usd,
            'commercial_dispatched_value_cop' => $total_value_cop,
            'commercial_products_dispatched' => $commercial_products_dispatched,
            'sample_products_dispatched' => $sample_products_dispatched,
            // Unused fields set to 0 (not displayed in dashboard)
            'commercial_partials_real' => 0,
            'commercial_partials_temporal' => 0,
            'sample_partials_real' => 0,
            'sample_partials_temporal' => 0,
        ];
    }

    /**
     * Calculate planned statistics using the same logic as controller's calculatePlannedStats
     * but filtering by delivery_date (same as dispatch_date for the day)
     */
    private function calculatePlannedStatsForDay(Carbon $from, Carbon $to): array
    {
        // Get products with delivery_date in the specified range (same logic as controller)
        $orderProducts = DB::table('purchase_order_product')
            ->join('purchase_orders', 'purchase_order_product.purchase_order_id', '=', 'purchase_orders.id')
            ->join('products', 'purchase_order_product.product_id', '=', 'products.id')
            ->join('clients', 'purchase_orders.client_id', '=', 'clients.id')
            ->select(
                'purchase_orders.id as order_id',
                'purchase_orders.trm as order_trm',
                'purchase_order_product.delivery_date',
                'purchase_order_product.quantity',
                'purchase_order_product.price as order_product_price',
                'purchase_order_product.muestra',
                'products.price as product_price'
            )
            ->whereBetween('purchase_order_product.delivery_date', [$from->format('Y-m-d'), $to->format('Y-m-d')])
            ->whereNotNull('purchase_order_product.delivery_date')
            ->get();

        // Get TRM data for the range
        $trmData = DB::table('trm_daily')
            ->whereBetween('date', [$from->format('Y-m-d'), $to->format('Y-m-d')])
            ->pluck('value', 'date')
            ->toArray();

        $totalValueUsd = 0;
        $totalValueCop = 0;
        $ordersSet = [];
        $totalCommercialProducts = 0;
        $totalSampleProducts = 0;

        foreach ($orderProducts as $orderProduct) {
            $quantity = (int) $orderProduct->quantity;
            $isSample = (int) $orderProduct->muestra === 1;
            
            // Count products by type
            if ($isSample) {
                $totalSampleProducts += $quantity;
                continue; // No calcular valor para muestras
            } else {
                $totalCommercialProducts += $quantity;
            }
            
            // Use product price (same as controller)
            $priceUsd = (float) $orderProduct->product_price;
            
            // TRM logic identical to controller
            $deliveryDate = $orderProduct->delivery_date;
            $trmToUse = null;

            // 1. Try to get TRM from trm_daily table
            if (isset($trmData[$deliveryDate])) {
                $trmToUse = (float) $trmData[$deliveryDate];
            }
            // 2. Fallback: TRM from order if valid
            elseif (!empty($orderProduct->order_trm) && $orderProduct->order_trm > 3800) {
                $trmToUse = (float) $orderProduct->order_trm;
            }
            // 3. Default TRM
            else {
                $trmToUse = 4000;
            }
            
            // Calculate values
            $valueUsd = $priceUsd * $quantity;
            $valueCop = $valueUsd * $trmToUse;
            
            $totalValueUsd += $valueUsd;
            $totalValueCop += $valueCop;
            
            // Count unique orders
            $ordersSet[$orderProduct->order_id] = true;
        }

        return [
            'planned_dispatch_value_usd' => $totalValueUsd,
            'planned_dispatch_value_cop' => $totalValueCop,
            'planned_orders_count' => count($ordersSet),
            'planned_commercial_products' => $totalCommercialProducts,
            'planned_sample_products' => $totalSampleProducts,
        ];
    }

    /**
     * Calculate pending dispatch statistics (planned - actual)
     */
    private function calculatePendingDispatchStats(array $dispatchStats, array $plannedStats): array
    {
        // Calculate pending amounts (planned - actual)
        $pendingValueUsd = max(0, $plannedStats['planned_dispatch_value_usd'] - $dispatchStats['commercial_dispatched_value_usd']);
        $pendingValueCop = max(0, $plannedStats['planned_dispatch_value_cop'] - $dispatchStats['commercial_dispatched_value_cop']);
        $pendingCommercialProducts = max(0, $plannedStats['planned_commercial_products'] - $dispatchStats['commercial_products_dispatched']);
        $pendingSampleProducts = max(0, $plannedStats['planned_sample_products'] - $dispatchStats['sample_products_dispatched']);

        return [
            'pending_dispatch_value_usd' => $pendingValueUsd,
            'pending_dispatch_value_cop' => $pendingValueCop,
            'pending_commercial_products' => $pendingCommercialProducts,
            'pending_sample_products' => $pendingSampleProducts,
            
            // Fulfillment rate
            'dispatch_fulfillment_rate_usd' => $plannedStats['planned_dispatch_value_usd'] > 0 
                ? round(($dispatchStats['commercial_dispatched_value_usd'] / $plannedStats['planned_dispatch_value_usd']) * 100, 2) 
                : 0,
            'dispatch_fulfillment_rate_products' => ($plannedStats['planned_commercial_products'] + $plannedStats['planned_sample_products']) > 0 
                ? round((($dispatchStats['commercial_products_dispatched'] + $dispatchStats['sample_products_dispatched']) / 
                        ($plannedStats['planned_commercial_products'] + $plannedStats['planned_sample_products'])) * 100, 2) 
                : 0,
        ];
    }

    /**
     * Calculate completion statistics
     * NOT USED - All fields set to 0 in main calculation
     * Kept for reference only
     *
     * @param Carbon $from - Not used in current implementation
     * @param Carbon $to - Not used in current implementation
     * @return array
     */
    private function calculateCompletionStats(Carbon $from, Carbon $to): array
    {
        // This method is no longer called as these metrics are not displayed in the dashboard
        // Suppress unused parameter warnings - parameters kept for method signature consistency
        unset($from, $to);

        // If needed in the future, uncomment the code below:

        /*
        // Get all orders with their partials dispatched up to the end of the day
        $orders = PurchaseOrder::with(['products', 'partials' => function ($query) use ($to) {
            $query->where('type', 'real')
                ->where('dispatch_date', '<=', $to);
        }])->get();

        $fullyDispatched = 0;
        $partiallyDispatched = 0;
        $notDispatched = 0;
        $pendingValueUsd = 0;
        $pendingValueCop = 0;
        $totalDaysToFirstDispatch = 0;
        $ordersWithDispatch = 0;

        $dayAverageTrm = $this->getDayAverageTrm($from, $to);

        foreach ($orders as $order) {
            // Calculate quantities per product
            $productStatus = [];

            foreach ($order->products as $product) {
                $ordered = $product->pivot->quantity ?? 0;
                $dispatched = $order->partials
                    ->where('product_id', $product->id)
                    ->sum('quantity');

                $productStatus[$product->id] = [
                    'ordered' => $ordered,
                    'dispatched' => $dispatched,
                    'pending' => max(0, $ordered - $dispatched),
                    'price' => $product->pivot->price ?? $product->price ?? 0,
                    'is_sample' => $product->pivot->muestra == 1
                ];
            }

            // Determine order status
            $totalOrdered = collect($productStatus)->sum('ordered');
            $totalDispatched = collect($productStatus)->sum('dispatched');

            if ($totalDispatched >= $totalOrdered && $totalOrdered > 0) {
                $fullyDispatched++;
            } elseif ($totalDispatched > 0) {
                $partiallyDispatched++;
            } else {
                $notDispatched++;
            }

            // Calculate pending value
            foreach ($productStatus as $status) {
                if (!$status['is_sample'] && $status['pending'] > 0) {
                    $pendingUsd = $status['pending'] * $status['price'];
                    $pendingValueUsd += $pendingUsd;
                    $pendingValueCop += $pendingUsd * $dayAverageTrm;
                }
            }

            // Calculate days to first dispatch
            $firstDispatch = $order->partials->sortBy('dispatch_date')->first();
            if ($firstDispatch && Carbon::parse($firstDispatch->dispatch_date)->lte($to)) {
                $daysToFirstDispatch = Carbon::parse($order->order_creation_date)
                    ->diffInDays(Carbon::parse($firstDispatch->dispatch_date));
                $totalDaysToFirstDispatch += $daysToFirstDispatch;
                $ordersWithDispatch++;
            }
        }

        $totalOrders = $orders->count();
        $dispatchCompletionPercentage = $totalOrders > 0 ? round(($fullyDispatched / $totalOrders) * 100, 2) : 0;

        return [
            'orders_fully_dispatched' => $fullyDispatched,
            'orders_partially_dispatched' => $partiallyDispatched,
            'orders_not_dispatched' => $notDispatched,
            'pending_dispatch_value_usd' => $pendingValueUsd,
            'pending_dispatch_value_cop' => $pendingValueCop,
            'avg_days_order_to_first_dispatch' => $ordersWithDispatch > 0 ? round($totalDaysToFirstDispatch / $ordersWithDispatch, 2) : 0,
            'dispatch_completion_percentage' => $dispatchCompletionPercentage,
        ];
        */

        return [
            'orders_fully_dispatched' => 0,
            'orders_partially_dispatched' => 0,
            'orders_not_dispatched' => 0,
            'pending_dispatch_value_usd' => 0,
            'pending_dispatch_value_cop' => 0,
            'avg_days_order_to_first_dispatch' => 0,
            'dispatch_completion_percentage' => 0,
        ];
    }

    /**
     * Calculate financial and TRM statistics
     * Only average_trm is used in dashboard, other fields set to 0
     */
    private function calculateFinancialStats(Carbon $from, Carbon $to): array
    {
        // Use same query pattern as analyzeClientsByStatus for TRM calculations
        $orders = PurchaseOrder::with([
            'partials' => function ($q) use ($from, $to) {
                $q->where('type', 'real')
                  ->whereBetween('dispatch_date', [$from, $to])
                  ->with('product')
                  ->join('purchase_order_product', 'partials.product_order_id', '=', 'purchase_order_product.id')
                  ->select('partials.*', 'purchase_order_product.muestra as muestra', 'purchase_order_product.price as pivot_price');
            }
        ])->whereHas('partials', function ($q) use ($from, $to) {
            $q->where('type', 'real')->whereBetween('dispatch_date', [$from, $to]);
        })->get()->fresh();

        $trmSum = 0;
        $totalPartials = 0;

        foreach ($orders as $order) {
            foreach ($order->partials as $partial) {
                if (
                    !Carbon::parse($partial->dispatch_date)->between($from, $to) ||
                    $partial->type !== 'real'
                ) {
                    continue;
                }

                $trm_data = $this->trm_service->getEffectiveTrm($partial->trm, $partial->dispatch_date);
                $trm = (float) $trm_data['trm'];
                if ($trm < 3800) {
                    $fallbackTrm = $this->trm_service->getTrm($partial->dispatch_date);
                    $trm = $fallbackTrm > 0 ? (float) $fallbackTrm : 4000;
                    if ($trm < 3800) {
                        $trm = 4000;
                    }
                }

                $trmSum += $trm;
                $totalPartials++;
            }
        }

        $averageTrm = $totalPartials > 0 ? round($trmSum / $totalPartials, 2) : $this->trm_service->getTrm();

        return [
            'average_trm' => $averageTrm,
        ];
    }

    /**
     * Calculate client statistics
     * NOT USED - All fields set to 0 in main calculation
     * Kept for reference only
     *
     * @param Carbon $from - Not used in current implementation
     * @param Carbon $to - Not used in current implementation
     * @return array
     */
    private function calculateClientStats(Carbon $from, Carbon $to): array
    {
        // This method is no longer called as these metrics are not displayed in the dashboard
        // Suppress unused parameter warnings - parameters kept for method signature consistency
        unset($from, $to);

        // If needed in the future, uncomment the code below:

        /*
        // Unique clients with orders created on this day
        $clientsWithOrders = PurchaseOrder::whereBetween('order_creation_date', [$from, $to])
            ->distinct('client_id')
            ->count('client_id');

        // Unique clients with dispatches on this day
        $clientsWithDispatches = PurchaseOrder::whereHas('partials', function ($query) use ($from, $to) {
            $query->where('type', 'real')
                  ->whereBetween('dispatch_date', [$from, $to]);
        })->distinct('client_id')->count('client_id');

        // Unique clients with planned dispatches on this day (based on delivery_date)
        $clientsWithPlannedDispatches = DB::table('purchase_orders')
            ->join('purchase_order_product', 'purchase_orders.id', '=', 'purchase_order_product.purchase_order_id')
            ->whereBetween('purchase_order_product.delivery_date', [$from->format('Y-m-d'), $to->format('Y-m-d')])
            ->whereNotNull('purchase_order_product.delivery_date')
            ->distinct('purchase_orders.client_id')
            ->count('purchase_orders.client_id');

        return [
            'unique_clients_with_orders' => $clientsWithOrders,
            'unique_clients_with_dispatches' => $clientsWithDispatches,
            'unique_clients_with_planned_dispatches' => $clientsWithPlannedDispatches,
        ];
        */

        return [
            'unique_clients_with_orders' => 0,
            'unique_clients_with_dispatches' => 0,
            'unique_clients_with_planned_dispatches' => 0,
        ];
    }

    /**
     * Build an extended stats payload to be expanded with detailed KPIs without recomputing on frontend.
     */
    private function buildExtendedStatsSnapshot(
        Carbon $from,
        Carbon $to,
        array $orderStats,
        array $dispatchStats,
        array $plannedStats
    ): array
    {
        // Orders with products and partials (real) in the day
        $orders = PurchaseOrder::with([
            'products',
            'partials' => function ($q) use ($from, $to) {
                $q->where('type', 'real')
                    ->whereBetween('dispatch_date', [$from, $to])
                    ->join('purchase_order_product', 'partials.product_order_id', '=', 'purchase_order_product.id')
                    ->select('partials.*', 'purchase_order_product.muestra as muestra', 'purchase_order_product.price as pivot_price');
            }
        ])->whereHas('partials', function ($q) use ($from, $to) {
            $q->where('type', 'real')->whereBetween('dispatch_date', [$from, $to]);
        })->get()->fresh();

        // Lead time and on-time
        $leadTimes = [];
        $onTimeCount = 0;
        foreach ($orders as $order) {
            $firstDispatch = $order->partials->sortBy('dispatch_date')->first();
            if ($firstDispatch && $order->order_creation_date) {
                $days = Carbon::parse($order->order_creation_date)->diffInDays(Carbon::parse($firstDispatch->dispatch_date));
                $leadTimes[] = $days;
                if ($order->required_delivery_date && Carbon::parse($firstDispatch->dispatch_date)->lte(Carbon::parse($order->required_delivery_date))) {
                    $onTimeCount++;
                }
            }
        }
        $leadTimeAvg = count($leadTimes) > 0 ? round(array_sum($leadTimes) / count($leadTimes), 2) : null;
        $onTimePct = count($leadTimes) > 0 ? round(($onTimeCount / count($leadTimes)) * 100, 2) : null;

        // Fill rate (qty/value) using partials of the day vs ordered
        $totalOrderedQty = 0;
        $totalDispatchedQty = 0;
        $totalOrderedValue = 0.0;
        $totalDispatchedValue = 0.0;

        foreach ($orders as $order) {
            foreach ($order->products as $product) {
                $orderedQty = $product->pivot->quantity ?? 0;
                // Usar precio efectivo: si pivot->price > 0, usar ese, sino usar product->price
                $effectivePrice = ($product->pivot->price > 0) ? $product->pivot->price : ($product->price ?? 0);
                $isSample = $product->pivot->muestra == 1;

                $totalOrderedQty += $orderedQty;
                if (!$isSample) {
                    $totalOrderedValue += $orderedQty * $effectivePrice;
                }
            }

            foreach ($order->partials as $partial) {
                // skip if out of range or not real (already filtered)
                $qty = (int) $partial->quantity;
                $isSample = isset($partial->muestra) && (int) $partial->muestra === 1;

                if ($isSample) {
                    $priceUsd = 0.0;
                } else {
                    // Usar precio efectivo: si pivot_price > 0, usar ese, sino buscar el precio del producto
                    $effectivePrice = ($partial->pivot_price > 0) ? $partial->pivot_price : ($partial->product->price ?? 0);
                    $priceUsd = (float) $effectivePrice;
                }

                $totalDispatchedQty += $qty;
                $totalDispatchedValue += $qty * $priceUsd;
            }
        }

        $fillRateQty = $totalOrderedQty > 0 ? round(($totalDispatchedQty / $totalOrderedQty) * 100, 2) : null;
        $fillRateValue = $totalOrderedValue > 0 ? round(($totalDispatchedValue / $totalOrderedValue) * 100, 2) : null;

        // Samples vs commercial
        $ordersWithSamples = $orders->filter(function ($order) {
            return $order->products->contains(function ($p) {
                return (int) ($p->pivot->muestra ?? 0) === 1;
            });
        })->count();
        $commercialOrders = $orders->filter(function ($order) {
            return $order->products->every(function ($p) {
                return (int) ($p->pivot->muestra ?? 0) === 0;
            });
        })->count();
        $sampleProductsDispatched = $orders->flatMap->partials->filter(function ($partial) {
            return isset($partial->muestra) && (int) $partial->muestra === 1;
        })->sum('quantity');

        // Logistics: partials per order (of the day)
        $partialsPerOrder = $orders->map->partials->map->count();
        $avgPartialsPerOrder = $partialsPerOrder->count() > 0 ? round($partialsPerOrder->avg(), 2) : null;
        $ordersGt2Partials = $partialsPerOrder->filter(fn ($c) => $c > 2)->count();
        // Avg days between partials (per order, then average)
        $daysBetweenAll = [];
        foreach ($orders as $order) {
            $dates = $order->partials->pluck('dispatch_date')->sort()->values();
            for ($i = 1; $i < $dates->count(); $i++) {
                $daysBetweenAll[] = Carbon::parse($dates[$i - 1])->diffInDays(Carbon::parse($dates[$i]));
            }
        }
        $avgDaysBetweenPartials = count($daysBetweenAll) > 0 ? round(array_sum($daysBetweenAll) / count($daysBetweenAll), 2) : null;

        // New accounts
        $newAccountsCount = PurchaseOrder::select('client_id')
            ->selectRaw('MIN(order_creation_date) as first_date')
            ->groupBy('client_id')
            ->having('first_date', '=', $from->toDateString())
            ->get()
            ->count();
        $winRatePct = ($orderStats['total_orders_created'] ?? 0) > 0
            ? round((($orderStats['orders_new_win'] ?? 0) / $orderStats['total_orders_created']) * 100, 2)
            : null;

        // Billing projection: reuse planned values of the day
        $billingProjectionUsd = $plannedStats['planned_dispatch_value_usd'] ?? null;
        $billingProjectionCop = $plannedStats['planned_dispatch_value_cop'] ?? null;

        // Helpers to hydrate related, aggregated arrays so they are not empty
        $dayTrm = $this->getDayAverageTrm($from, $to);

        // Dispatch value per client (usd/cop) for the day
        $dispatchByClient = DB::table('partials')
            ->join('purchase_orders', 'partials.order_id', '=', 'purchase_orders.id')
            ->join('purchase_order_product', 'partials.product_order_id', '=', 'purchase_order_product.id')
            ->where('partials.type', 'real')
            ->whereBetween('partials.dispatch_date', [$from, $to])
            ->groupBy('purchase_orders.client_id')
            ->selectRaw('purchase_orders.client_id, SUM(partials.quantity * purchase_order_product.price) as value_usd')
            ->get();

        $clientNits = DB::table('clients')
            ->whereIn('id', $dispatchByClient->pluck('client_id'))
            ->pluck('nit', 'id')
            // Normalise NITs so lookups against cartera/recaudos always match
            ->mapWithKeys(function ($nit, $id) {
                $cleanNit = preg_replace('/\D/', '', (string) $nit);
                return [$id => $cleanNit];
            });

        // Cartera: obtener el saldo total más reciente por NIT
        // Suma todos los documentos (facturas) del NIT en la fecha_cartera más reciente
        $carteraByNit = DB::table('cartera as c1')
            ->whereIn('c1.nit', $clientNits->values()->filter()->unique())
            ->whereRaw('c1.fecha_cartera = (
                SELECT MAX(c2.fecha_cartera)
                FROM cartera c2
                WHERE c2.nit = c1.nit
            )')
            ->groupBy('c1.nit')
            ->selectRaw("c1.nit, SUM(CAST(REPLACE(REPLACE(c1.saldo_contable, ',', ''), '$', '') AS DECIMAL(20,2))) as saldo")
            ->pluck('saldo', 'nit');

        $carteraVsDespachos = $dispatchByClient->map(function ($row) use ($clientNits, $carteraByNit, $dayTrm) {
            $nit = $clientNits[$row->client_id] ?? null;
            $saldo = $nit ? (float) ($carteraByNit[$nit] ?? 0) : 0;
            return [
                'client_id' => $row->client_id,
                'nit' => $nit,
                'dispatch_value_cop' => round(((float) $row->value_usd) * $dayTrm, 2),
                'cartera_saldo' => round($saldo, 2),
            ];
        })->values()->all();

        // Completion per executive (using client executive field)
        $execStats = PurchaseOrder::with('client')
            ->whereBetween('order_creation_date', [$from, $to])
            ->get()
            ->groupBy(fn ($order) => $order->client->executive ?? 'Sin ejecutivo')
            ->map(function ($orders, $executive) {
                $total = $orders->count();
                $completed = $orders->where('status', 'completed')->count();
                $partial = $orders->where('status', 'parcial_status')->count();
                $pending = $orders->whereIn('status', ['pending', 'processing'])->count();
                $completionPct = $total > 0 ? round(($completed / $total) * 100, 2) : null;
                return [
                    'executive' => $executive,
                    'total_orders' => $total,
                    'completed' => $completed,
                    'partial' => $partial,
                    'pending' => $pending,
                    'completion_pct' => $completionPct,
                ];
            })
            ->values()
            ->all();

        // Obtener nombres de clientes desde la tabla clients
        $clientNames = DB::table('clients')
            ->whereIn('id', $dispatchByClient->pluck('client_id'))
            ->pluck('client_name', 'id');

        // Recaudos vs dispatch per client (day range)
        $recaudosByClient = DB::table('recaudos')
            ->whereBetween('fecha_recaudo', [$from, $to])
            ->groupBy('nit', 'cliente')
            ->selectRaw('nit, cliente, SUM(valor_cancelado) as valor')
            ->get()
            ->keyBy(function ($row) {
                return preg_replace('/\D/', '', (string) $row->nit);
            });

        $recaudosVsDispatch = $dispatchByClient->map(function ($row) use ($clientNits, $recaudosByClient, $clientNames, $dayTrm) {
            $nit = $clientNits[$row->client_id] ?? null;
            $recaudoRow = $nit ? ($recaudosByClient[$nit] ?? null) : null;

            // Prioridad: nombre de recaudos, si no existe usar nombre de clients
            $clienteName = $recaudoRow->cliente ?? ($clientNames[$row->client_id] ?? null);

            return [
                'client_id' => $row->client_id,
                'nit' => $nit,
                'cliente' => $clienteName,
                'dispatch_value_cop' => round(((float) $row->value_usd) * $dayTrm, 2),
                'recaudo_cop' => $recaudoRow ? (float) $recaudoRow->valor : 0,
            ];
        })->values()->all();

        return [
            'lead_time' => [
                'avg_days' => $leadTimeAvg,
                'on_time_orders_pct' => $onTimePct,
            ],
            'fill_rate' => [
                'qty_pct' => $fillRateQty,
                'value_pct' => $fillRateValue,
            ],
            'samples_vs_commercial' => [
                'orders_with_samples' => $ordersWithSamples,
                'sample_products_dispatched' => (int) $sampleProductsDispatched,
                'commercial_orders' => $commercialOrders,
            ],
            'cartera_vs_despachos' => $carteraVsDespachos,
            'executive_completion' => $execStats,
            'new_accounts' => [
                'count' => $newAccountsCount,
                'win_rate_pct' => $winRatePct,
            ],
            'recaudos_vs_dispatch' => $recaudosVsDispatch,
            'billing_projection' => [
                'usd' => $billingProjectionUsd,
                'cop' => $billingProjectionCop,
            ],
            'alerts' => [], // early alerts: orders without partials, low TRM, etc.
            'logistics' => [
                'avg_partials_per_order' => $avgPartialsPerOrder,
                'orders_gt2_partials' => $ordersGt2Partials,
                'avg_days_between_partials' => $avgDaysBetweenPartials,
            ],
        ];
    }

    /**
     * Get day average TRM using same logic as analyzeClientsByStatus
     */
    private function getDayAverageTrm(Carbon $from, Carbon $to): float
    {
        // First try to get TRM from real dispatches on this day
        $orders = PurchaseOrder::with([
            'partials' => function ($q) use ($from, $to) {
                $q->where('type', 'real')
                  ->whereBetween('dispatch_date', [$from, $to])
                  ->with('product')
                  ->join('purchase_order_product', 'partials.product_order_id', '=', 'purchase_order_product.id')
                  ->select('partials.*', 'purchase_order_product.muestra as muestra');
            }
        ])->whereHas('partials', function ($q) use ($from, $to) {
            $q->where('type', 'real')->whereBetween('dispatch_date', [$from, $to]);
        })->get()->fresh();

        $trmSum = 0;
        $validTrmCount = 0;

        foreach ($orders as $order) {
            foreach ($order->partials as $partial) {
                if (
                    !Carbon::parse($partial->dispatch_date)->between($from, $to) ||
                    $partial->type !== 'real'
                ) {
                    continue;
                }

                $trm_data = $this->trm_service->getEffectiveTrm($partial->trm, $partial->dispatch_date);
                $trm = (float) $trm_data['trm'];
                
                $trmSum += $trm;
                $validTrmCount++;
            }
        }

        // If we have real dispatches, use their average TRM
        if ($validTrmCount > 0) {
            return $trmSum / $validTrmCount;
        }

        // Otherwise, try to get TRM from trm_daily table for this day
        $dailyTrm = DB::table('trm_daily')
            ->where('date', $from->format('Y-m-d'))
            ->value('value');

        if ($dailyTrm) {
            return (float) $dailyTrm;
        }

        // Final fallback to current TRM
        return $this->trm_service->getTrm();
    }

    /**
     * Display summary of calculated statistics
     */
    private function displaySummary(array $stats): void
    {
        $this->info("\nComplete statistics generated for {$stats['date']}:");
        $this->line(str_repeat('-', 70));

        $this->line("ORDERS CREATED:");
        $this->line("  Total: {$stats['total_orders_created']}");
        $this->line("  USD Value: $" . number_format($stats['total_orders_value_usd'], 2));
        $this->line("  COP Value: $" . number_format($stats['total_orders_value_cop'], 0));
        $this->line("  Commercial: {$stats['orders_commercial']} | Sample: {$stats['orders_sample']} | Mixed: {$stats['orders_mixed']}");

        $this->line("\nDISPATCHES (REAL):");
        $this->line("  Orders dispatched: {$stats['dispatched_orders_count']}");
        $this->line("  USD Value: $" . number_format($stats['commercial_dispatched_value_usd'], 2));
        $this->line("  COP Value: $" . number_format($stats['commercial_dispatched_value_cop'], 0));
        $this->line("  Commercial products: {$stats['commercial_products_dispatched']}");
        $this->line("  Sample products: {$stats['sample_products_dispatched']}");

        $this->line("\nPLANNED DISPATCHES:");
        $this->line("  Orders planned: {$stats['planned_orders_count']}");
        $this->line("  USD Value: $" . number_format($stats['planned_dispatch_value_usd'], 2));
        $this->line("  COP Value: $" . number_format($stats['planned_dispatch_value_cop'], 0));
        $this->line("  Commercial products: {$stats['planned_commercial_products']}");
        $this->line("  Sample products: {$stats['planned_sample_products']}");

        $this->line("\nPENDING DISPATCHES (PLANNED - REAL):");
        $this->line("  USD Value: $" . number_format($stats['pending_dispatch_value_usd'], 2));
        $this->line("  COP Value: $" . number_format($stats['pending_dispatch_value_cop'], 0));
        $this->line("  Commercial products: {$stats['pending_commercial_products']}");
        $this->line("  Sample products: {$stats['pending_sample_products']}");
        $this->line("  Fulfillment rate (USD): {$stats['dispatch_fulfillment_rate_usd']}%");
        $this->line("  Fulfillment rate (Products): {$stats['dispatch_fulfillment_rate_products']}%");

        $this->line("\nCOMPLETION:");
        $this->line("  Fully dispatched: {$stats['orders_fully_dispatched']}");
        $this->line("  Partially dispatched: {$stats['orders_partially_dispatched']}");
        $this->line("  Not dispatched: {$stats['orders_not_dispatched']}");
        $this->line("  Completion rate: {$stats['dispatch_completion_percentage']}%");

        $this->line("\nFINANCIAL:");
        $this->line("  Average TRM: {$stats['average_trm']}");
        $this->line("  Default TRM partials: {$stats['partials_with_default_trm']}");
        $this->line("  Custom TRM partials: {$stats['partials_with_custom_trm']}");

        $this->line("\nCLIENTS:");
        $this->line("  With orders: {$stats['unique_clients_with_orders']}");
        $this->line("  With dispatches: {$stats['unique_clients_with_dispatches']}");
        $this->line("  With planned dispatches: {$stats['unique_clients_with_planned_dispatches']}");

        $this->line(str_repeat('-', 70));
    }
}
