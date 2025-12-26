<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Models\PurchaseOrder;

/**
 * Service to calculate daily statistics for orders, dispatches, and financial data
 */
class DailyStatisticsService
{
    private TrmService $trmService;

    public function __construct(TrmService $trmService)
    {
        $this->trmService = $trmService;
    }


public function calculateCurrentMonthStatistics(?int $days = null): array
{
    $today = Carbon::now();
    
    // Determine start date based on $days parameter
    if ($days === null) {
        // Default behavior: from first day of month to today
        $startDate = $today->copy()->startOfMonth();
    } else {
        // Process the last N days (including today)
        $startDate = $today->copy()->subDays($days - 1)->startOfDay();
    }
    
    $results = [];
    $currentDate = $startDate->copy();
    
    // Iterate from start date to today
    while ($currentDate->lte($today)) {
        $dateKey = $currentDate->format('Y-m-d');
        
        try {
            $stats = $this->calculateForDate($currentDate->copy());
            $results[$dateKey] = $stats;
        } catch (\Exception $e) {
            $results[$dateKey] = [
                'date' => $dateKey,
                'error' => $e->getMessage()
            ];
        }
        
        // Move to next day
        $currentDate->addDay();
    }
    
    return $results;
}

    /**
     * Calculate all statistics for a given date
     * 
     * @param Carbon|string $date The date to calculate statistics for (Y-m-d format or Carbon instance)
     * @return array Complete statistics array
     */
    public function calculateForDate($date): array
    {
        // Normalize date input
        if (is_string($date)) {
            $date = Carbon::createFromFormat('Y-m-d', $date);
        }

        $from = $date->copy()->startOfDay();
        $to = $date->copy()->endOfDay();
        $now = Carbon::now();

        $stats = [
            'date' => $from->format('Y-m-d'),
            'hour' => 23,
            'updated_at' => $now,
        ];

        // 1. Order Creation Statistics
        $orderStats = $this->calculateOrderCreationStats($from, $to);
        $stats = array_merge($stats, $orderStats);

        // 2. Dispatch Statistics
        $dispatchStats = $this->calculateDispatchStats($from, $to);
        $stats = array_merge($stats, $dispatchStats);

        // 3. Planned Statistics
        $plannedStats = $this->calculatePlannedStatsForDay($from, $to);
        $stats = array_merge($stats, $plannedStats);

        // 4. Pending Dispatch Statistics
        $pendingStats = $this->calculatePendingDispatchStats($dispatchStats, $plannedStats);
        $stats = array_merge($stats, $pendingStats);

        // 5. Completion Statistics
        $completionStats = $this->calculateCompletionStats($from, $to);
        $stats = array_merge($stats, $completionStats);

        // 6. Financial and TRM Statistics
        $financialStats = $this->calculateFinancialStats($from, $to);
        $stats = array_merge($stats, $financialStats);

        // 7. Client Statistics
        $clientStats = $this->calculateClientStats($from, $to);
        $stats = array_merge($stats, $clientStats);

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

        $dayAverageTrm = $this->getDayAverageTrm($from, $to);
        $stats['total_orders_value_cop'] = $totalValueUsd * $dayAverageTrm;

        return $stats;
    }

    /**
     * Calculate dispatch statistics using exact analyzeClientsByStatus logic
     */
    private function calculateDispatchStats(Carbon $from, Carbon $to): array
    {
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
        $commercial_partials_real = 0;
        $sample_partials_real = 0;

        foreach ($orders as $order) {
            $dispatched_any_for_order = false;

            foreach ($order->partials as $partial) {
                if (
                    !Carbon::parse($partial->dispatch_date)->between($from, $to) ||
                    $partial->type !== 'real'
                ) {
                    continue;
                }

                // Usar precio efectivo: 0 si es muestra, sino pivot_price si > 0, sino product->price
                $isSampleCheck = isset($partial->muestra) && (int) $partial->muestra === 1;
                if ($isSampleCheck) {
                    $price_usd = 0.0;
                } else {
                    $effectivePrice = ($partial->pivot_price > 0) ? $partial->pivot_price : ($partial->product->price ?? 0);
                    $price_usd = (float) $effectivePrice;
                }

                $trm_data = $this->trmService->getEffectiveTrm($partial->trm, $partial->dispatch_date);
                $trm = (float) $trm_data['trm'];

                $amount_cop = ($price_usd * (float) $partial->quantity) * $trm;

                $total_value_usd += $price_usd * (float) $partial->quantity;
                $total_value_cop += $amount_cop;

                $isSample = isset($partial->muestra) && (int) $partial->muestra === 1;

                if ($isSample) {
                    $sample_products_dispatched += (int) $partial->quantity;
                    $sample_partials_real++;
                } else {
                    $commercial_products_dispatched += (int) $partial->quantity;
                    $commercial_partials_real++;
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
            'commercial_partials_real' => $commercial_partials_real,
            'commercial_partials_temporal' => 0,
            'sample_partials_real' => $sample_partials_real,
            'sample_partials_temporal' => 0,
        ];
    }

    /**
     * Calculate planned statistics for the day
     */
    private function calculatePlannedStatsForDay(Carbon $from, Carbon $to): array
    {
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

            if ($isSample) {
                $totalSampleProducts += $quantity;
                continue;
            } else {
                $totalCommercialProducts += $quantity;
            }

            $priceUsd = (float) $orderProduct->product_price;

            $deliveryDate = $orderProduct->delivery_date;
            $trmToUse = null;

            if (isset($trmData[$deliveryDate])) {
                $trmToUse = (float) $trmData[$deliveryDate];
            } elseif (!empty($orderProduct->order_trm) && $orderProduct->order_trm > 3800) {
                $trmToUse = (float) $orderProduct->order_trm;
            } else {
                $trmToUse = 4000;
            }

            $valueUsd = $priceUsd * $quantity;
            $valueCop = $valueUsd * $trmToUse;

            $totalValueUsd += $valueUsd;
            $totalValueCop += $valueCop;

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
     * Calculate pending dispatch statistics
     */
    private function calculatePendingDispatchStats(array $dispatchStats, array $plannedStats): array
    {
        $pendingValueUsd = max(0, $plannedStats['planned_dispatch_value_usd'] - $dispatchStats['commercial_dispatched_value_usd']);
        $pendingValueCop = max(0, $plannedStats['planned_dispatch_value_cop'] - $dispatchStats['commercial_dispatched_value_cop']);
        $pendingCommercialProducts = max(0, $plannedStats['planned_commercial_products'] - $dispatchStats['commercial_products_dispatched']);
        $pendingSampleProducts = max(0, $plannedStats['planned_sample_products'] - $dispatchStats['sample_products_dispatched']);

        return [
            'pending_dispatch_value_usd' => $pendingValueUsd,
            'pending_dispatch_value_cop' => $pendingValueCop,
            'pending_commercial_products' => $pendingCommercialProducts,
            'pending_sample_products' => $pendingSampleProducts,
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
     */
    private function calculateCompletionStats(Carbon $from, Carbon $to): array
    {
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
            $productStatus = [];
            foreach ($order->products as $product) {
                $ordered = $product->pivot->quantity ?? 0;
                $dispatched = $order->partials
                    ->where('product_id', $product->id)
                    ->sum('quantity');

                // Usar precio efectivo: si pivot->price > 0, usar ese, sino usar product->price
                $effectivePrice = ($product->pivot->price > 0) ? $product->pivot->price : ($product->price ?? 0);

                $productStatus[$product->id] = [
                    'ordered' => $ordered,
                    'dispatched' => $dispatched,
                    'pending' => max(0, $ordered - $dispatched),
                    'price' => $effectivePrice,
                    'is_sample' => $product->pivot->muestra == 1
                ];
            }

            $totalOrdered = collect($productStatus)->sum('ordered');
            $totalDispatched = collect($productStatus)->sum('dispatched');

            if ($totalDispatched >= $totalOrdered && $totalOrdered > 0) {
                $fullyDispatched++;
            } elseif ($totalDispatched > 0) {
                $partiallyDispatched++;
            } else {
                $notDispatched++;
            }

            foreach ($productStatus as $status) {
                if (!$status['is_sample'] && $status['pending'] > 0) {
                    $pendingUsd = $status['pending'] * $status['price'];
                    $pendingValueUsd += $pendingUsd;
                    $pendingValueCop += $pendingUsd * $dayAverageTrm;
                }
            }

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
    }

    /**
     * Calculate financial and TRM statistics
     */
    private function calculateFinancialStats(Carbon $from, Carbon $to): array
    {
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

        $defaultTrmCount = 0;
        $customTrmCount = 0;
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

                $trm_data = $this->trmService->getEffectiveTrm($partial->trm, $partial->dispatch_date);
                $trm = (float) $trm_data['trm'];
                $is_default_trm = $trm_data['is_default'];

                if ($is_default_trm) {
                    $defaultTrmCount++;
                } else {
                    $customTrmCount++;
                }

                $trmSum += $trm;
                $totalPartials++;
            }
        }

        $averageTrm = $totalPartials > 0 ? round($trmSum / $totalPartials, 2) : $this->trmService->getTrm();

        return [
            'average_trm' => $averageTrm,
            'partials_with_default_trm' => $defaultTrmCount,
            'partials_with_custom_trm' => $customTrmCount,
        ];
    }

    /**
     * Calculate client statistics
     */
    private function calculateClientStats(Carbon $from, Carbon $to): array
    {
        $clientsWithOrders = PurchaseOrder::whereBetween('order_creation_date', [$from, $to])
            ->distinct('client_id')
            ->count('client_id');

        $clientsWithDispatches = PurchaseOrder::whereHas('partials', function ($query) use ($from, $to) {
            $query->where('type', 'real')
                ->whereBetween('dispatch_date', [$from, $to]);
        })->distinct('client_id')->count('client_id');

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
    }

    /**
     * Get day average TRM
     */
    private function getDayAverageTrm(Carbon $from, Carbon $to): float
    {
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

                $trm_data = $this->trmService->getEffectiveTrm($partial->trm, $partial->dispatch_date);
                $trm = (float) $trm_data['trm'];

                $trmSum += $trm;
                $validTrmCount++;
            }
        }

        if ($validTrmCount > 0) {
            return $trmSum / $validTrmCount;
        }

        $dailyTrm = DB::table('trm_daily')
            ->where('date', $from->format('Y-m-d'))
            ->value('value');

        if ($dailyTrm) {
            return (float) $dailyTrm;
        }

        return $this->trmService->getTrm();
    }
}
