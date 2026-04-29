<?php

namespace App\Queries\Analyze;

use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AnalyzeQuery
{
    private const VALID_STATUSES_BY_TYPE = [
        'real' => ['completed', 'parcial_status'],
        'temporal' => ['pending', 'processing'],
    ];

    public function resolveDateRange(array $validated): array
    {
        $from = Carbon::now()->startOfMonth()->startOfDay();
        $to = Carbon::now()->endOfDay();

        if (! empty($validated['from']) && ! empty($validated['to'])) {
            $from = Carbon::parse($validated['from'])->startOfDay();
            $to = Carbon::parse($validated['to'])->endOfDay();
        } elseif (! empty($validated['creation_date']) && str_contains($validated['creation_date'], ' - ')) {
            [$fromStr, $toStr] = explode(' - ', $validated['creation_date']);
            $from = Carbon::createFromFormat('Y-m-d', trim($fromStr))->startOfDay();
            $to = Carbon::createFromFormat('Y-m-d', trim($toStr))->endOfDay();
        }

        return [$from, $to];
    }

    public function resolveStatusAndType(array $validated): array
    {
        $type = $validated['type'] ?? 'real';
        $status = $validated['status'] ?? null;

        $validStatuses = array_unique(array_merge(...array_values(self::VALID_STATUSES_BY_TYPE)));
        if ($status && ! in_array($status, $validStatuses, true)) {
            abort(400, 'Invalid status');
        }

        if (! array_key_exists($type, self::VALID_STATUSES_BY_TYPE)) {
            abort(400, 'Invalid type');
        }

        return [$status, $type];
    }

    public function base(Carbon $from, Carbon $to, string $type, ?string $status): Builder
    {
        return $type === 'temporal'
            ? $this->ordersBase($from, $to, $type, $status)
            : $this->partialsBase($from, $to, $type, $status);
    }

    private function partialsBase(Carbon $from, Carbon $to, string $type, ?string $status): Builder
    {
        $query = DB::table('partials')
            ->join('purchase_orders', 'partials.order_id', '=', 'purchase_orders.id')
            ->join('clients', 'purchase_orders.client_id', '=', 'clients.id')
            ->join('products', 'partials.product_id', '=', 'products.id')
            ->join('purchase_order_product', 'partials.product_order_id', '=', 'purchase_order_product.id')
            ->leftJoin('trm_daily', 'partials.dispatch_date', '=', 'trm_daily.date')
            ->where('partials.type', $type)
            ->whereNull('partials.deleted_at')
            ->whereBetween('partials.dispatch_date', [$from->toDateString(), $to->toDateString()]);

        return $this->applyStatusFilter($query, $type, $status);
    }

    private function ordersBase(Carbon $from, Carbon $to, string $type, ?string $status): Builder
    {
        $query = DB::table('purchase_orders')
            ->join('clients', 'purchase_orders.client_id', '=', 'clients.id')
            ->join('purchase_order_product', 'purchase_orders.id', '=', 'purchase_order_product.purchase_order_id')
            ->join('products', 'purchase_order_product.product_id', '=', 'products.id')
            ->leftJoin('trm_daily', 'purchase_orders.order_creation_date', '=', 'trm_daily.date')
            ->whereBetween('purchase_orders.order_creation_date', [$from->toDateString(), $to->toDateString()]);

        return $this->applyStatusFilter($query, $type, $status);
    }

    private function temporalDispatchDateExpression(
        string $orderTable = 'purchase_orders',
        string $orderProductTable = 'purchase_order_product'
    ): string {
        return "COALESCE(
            (
                SELECT MIN(pt.dispatch_date)
                FROM partials pt
                WHERE pt.order_id = {$orderTable}.id
                  AND pt.product_order_id = {$orderProductTable}.id
                  AND pt.type = 'temporal'
                  AND pt.deleted_at IS NULL
            ),
            {$orderProductTable}.delivery_date,
            {$orderTable}.dispatch_date
        )";
    }

    private function applyStatusFilter(Builder $query, string $type, ?string $status): Builder
    {
        $validStatuses = self::VALID_STATUSES_BY_TYPE[$type] ?? [];

        if ($status) {
            if (! in_array($status, $validStatuses, true)) {
                return $query->whereRaw('1 = 0');
            }

            return $query->where('purchase_orders.status', $status);
        }

        return $query->whereIn('purchase_orders.status', $validStatuses);
    }

    public function totals(Carbon $from, Carbon $to, string $type, ?string $status): object
    {
        $base = $this->base($from, $to, $type, $status);

        if ($type === 'temporal') {
            return $base
                ->selectRaw('
                    SUM(
                        (CASE
                            WHEN purchase_order_product.muestra = 1 THEN 0
                            WHEN purchase_order_product.price > 0 THEN purchase_order_product.price
                            ELSE products.price
                        END) * purchase_order_product.quantity
                    ) as total_usd
                ')
                ->selectRaw('
                    SUM(
                        (CASE
                            WHEN purchase_order_product.muestra = 1 THEN 0
                            WHEN purchase_order_product.price > 0 THEN purchase_order_product.price
                            ELSE products.price
                        END) *
                        purchase_order_product.quantity *
                        (CASE
                            WHEN purchase_orders.trm IS NOT NULL AND purchase_orders.trm >= 3400 THEN purchase_orders.trm
                            WHEN trm_daily.value IS NOT NULL THEN trm_daily.value
                            ELSE 4000
                        END)
                    ) as total_cop
                ')
                ->first();
        }

        return $base
            ->selectRaw('
                SUM(
                    (CASE
                        WHEN purchase_order_product.muestra = 1 THEN 0
                        WHEN purchase_order_product.price > 0 THEN purchase_order_product.price
                        ELSE products.price
                    END) * partials.quantity
                ) as total_usd
            ')
            ->selectRaw('
                SUM(
                    (CASE
                        WHEN purchase_order_product.muestra = 1 THEN 0
                        WHEN purchase_order_product.price > 0 THEN purchase_order_product.price
                        ELSE products.price
                    END) *
                    partials.quantity *
                    (CASE
                        WHEN partials.trm IS NOT NULL AND partials.trm >= 3400 THEN partials.trm
                        WHEN trm_daily.value IS NOT NULL THEN trm_daily.value
                        ELSE 4000
                    END)
                ) as total_cop
            ')
            ->first();
    }

    /// Suma el total de ventas Siigo (valor) para todos los clientes en el rango de meses dado.
    public function totalSiigo(Carbon $from, Carbon $to): float
    {
        $fromMes = $from->format('Y-m');
        $toMes = $to->format('Y-m');

        return (float) DB::table('siigo_sales')
            ->whereBetween('mes', [$fromMes, $toMes])
            ->sum('valor');
    }

    public function paginateClients(
        Carbon $from,
        Carbon $to,
        string $type,
        ?string $status,
        int $perPage
    ): LengthAwarePaginator {
        $fromMes = $from->format('Y-m');
        $toMes = $to->format('Y-m');

        $base = $this->base($from, $to, $type, $status);

        if ($type === 'temporal') {
            return $base
                ->selectRaw('clients.id as client_id')
                ->selectRaw('clients.client_name as client_name')
                ->selectRaw('clients.nit as nit')
                ->selectRaw('COUNT(DISTINCT purchase_orders.id) as partials_count')
                ->selectRaw('
                    SUM(
                        (CASE
                            WHEN purchase_order_product.muestra = 1 THEN 0
                            WHEN purchase_order_product.price > 0 THEN purchase_order_product.price
                            ELSE products.price
                        END) * purchase_order_product.quantity
                    ) as total_usd
                ')
                ->selectRaw('
                    SUM(
                        (CASE
                            WHEN purchase_order_product.muestra = 1 THEN 0
                            WHEN purchase_order_product.price > 0 THEN purchase_order_product.price
                            ELSE products.price
                        END) *
                        purchase_order_product.quantity *
                        (CASE
                            WHEN purchase_orders.trm IS NOT NULL AND purchase_orders.trm >= 3400 THEN purchase_orders.trm
                            WHEN trm_daily.value IS NOT NULL THEN trm_daily.value
                            ELSE 4000
                        END)
                    ) as total_cop
                ')
                ->selectRaw('0 as total_cop_siigo')
                ->groupBy('clients.id', 'clients.client_name', 'clients.nit')
                ->orderByDesc('total_cop')
                ->paginate($perPage);
        }

        return $base
            ->selectRaw('clients.id as client_id')
            ->selectRaw('clients.client_name as client_name')
            ->selectRaw('clients.nit as nit')
            ->selectRaw('COUNT(partials.id) as partials_count')
            ->selectRaw('
                SUM(
                    (CASE
                        WHEN purchase_order_product.muestra = 1 THEN 0
                        WHEN purchase_order_product.price > 0 THEN purchase_order_product.price
                        ELSE products.price
                    END) * partials.quantity
                ) as total_usd
            ')
            ->selectRaw('
                SUM(
                    (CASE
                        WHEN purchase_order_product.muestra = 1 THEN 0
                        WHEN purchase_order_product.price > 0 THEN purchase_order_product.price
                        ELSE products.price
                    END) *
                    partials.quantity *
                    (CASE
                        WHEN partials.trm IS NOT NULL AND partials.trm >= 3400 THEN partials.trm
                        WHEN trm_daily.value IS NOT NULL THEN trm_daily.value
                        ELSE 4000
                    END)
                ) as total_cop
            ')
            ->selectRaw(
                'COALESCE((SELECT SUM(ss.valor) FROM siigo_sales ss WHERE ss.nit COLLATE utf8mb4_unicode_ci = clients.nit COLLATE utf8mb4_unicode_ci AND ss.mes BETWEEN ? AND ?), 0) as total_cop_siigo',
                [$fromMes, $toMes]
            )
            ->groupBy('clients.id', 'clients.client_name', 'clients.nit')
            ->orderByDesc('total_cop')
            ->paginate($perPage);
    }

    public function allClients(
        Carbon $from,
        Carbon $to,
        string $type,
        ?string $status,
        int $limit = 5000
    ): Collection {
        $fromMes = $from->format('Y-m');
        $toMes = $to->format('Y-m');

        $base = $this->base($from, $to, $type, $status);

        if ($type === 'temporal') {
            return $base
                ->selectRaw('clients.id as client_id')
                ->selectRaw('clients.client_name as client_name')
                ->selectRaw('clients.nit as nit')
                ->selectRaw('COUNT(DISTINCT purchase_orders.id) as partials_count')
                ->selectRaw('
                    SUM(
                        (CASE
                            WHEN purchase_order_product.muestra = 1 THEN 0
                            WHEN purchase_order_product.price > 0 THEN purchase_order_product.price
                            ELSE products.price
                        END) * purchase_order_product.quantity
                    ) as total_usd
                ')
                ->selectRaw('
                    SUM(
                        (CASE
                            WHEN purchase_order_product.muestra = 1 THEN 0
                            WHEN purchase_order_product.price > 0 THEN purchase_order_product.price
                            ELSE products.price
                        END) *
                        purchase_order_product.quantity *
                        (CASE
                            WHEN purchase_orders.trm IS NOT NULL AND purchase_orders.trm >= 3400 THEN purchase_orders.trm
                            WHEN trm_daily.value IS NOT NULL THEN trm_daily.value
                            ELSE 4000
                        END)
                    ) as total_cop
                ')
                ->selectRaw('0 as total_cop_siigo')
                ->groupBy('clients.id', 'clients.client_name', 'clients.nit')
                ->orderByDesc('total_cop')
                ->limit($limit)
                ->get();
        }

        return $base
            ->selectRaw('clients.id as client_id')
            ->selectRaw('clients.client_name as client_name')
            ->selectRaw('clients.nit as nit')
            ->selectRaw('COUNT(partials.id) as partials_count')
            ->selectRaw('
                SUM(
                    (CASE
                        WHEN purchase_order_product.muestra = 1 THEN 0
                        WHEN purchase_order_product.price > 0 THEN purchase_order_product.price
                        ELSE products.price
                    END) * partials.quantity
                ) as total_usd
            ')
            ->selectRaw('
                SUM(
                    (CASE
                        WHEN purchase_order_product.muestra = 1 THEN 0
                        WHEN purchase_order_product.price > 0 THEN purchase_order_product.price
                        ELSE products.price
                    END) *
                    partials.quantity *
                    (CASE
                        WHEN partials.trm IS NOT NULL AND partials.trm >= 3400 THEN partials.trm
                        WHEN trm_daily.value IS NOT NULL THEN trm_daily.value
                        ELSE 4000
                    END)
                ) as total_cop
            ')
            ->selectRaw(
                'COALESCE((SELECT SUM(ss.valor) FROM siigo_sales ss WHERE ss.nit COLLATE utf8mb4_unicode_ci = clients.nit COLLATE utf8mb4_unicode_ci AND ss.mes BETWEEN ? AND ?), 0) as total_cop_siigo',
                [$fromMes, $toMes]
            )
            ->groupBy('clients.id', 'clients.client_name', 'clients.nit')
            ->orderByDesc('total_cop')
            ->limit($limit)
            ->get();
    }

    public function clientPartials(
        Carbon $from,
        Carbon $to,
        string $type,
        ?string $status,
        int $clientId
    ): Collection {
        $base = $this->base($from, $to, $type, $status);

        if ($type === 'temporal') {
            $dispatchDateExpression = $this->temporalDispatchDateExpression();

            return $base
                ->where('clients.id', $clientId)
                ->selectRaw('purchase_orders.order_consecutive as consecutivo')
                ->selectRaw('products.product_name as product')
                ->selectRaw('purchase_order_product.id as partial')
                ->selectRaw('purchase_order_product.quantity as quantity')
                ->selectRaw("{$dispatchDateExpression} as date")
                ->selectRaw('(CASE
                    WHEN purchase_order_product.muestra = 1 THEN 0
                    WHEN purchase_order_product.price > 0 THEN purchase_order_product.price
                    ELSE products.price
                END) as price_usd')
                ->selectRaw('
                    (CASE
                        WHEN purchase_orders.trm IS NOT NULL AND purchase_orders.trm >= 3400 THEN purchase_orders.trm
                        WHEN trm_daily.value IS NOT NULL THEN trm_daily.value
                        ELSE 4000
                    END) as trm
                ')
                ->selectRaw('COALESCE(trm_daily.value, 4000) as trm_real')
                ->selectRaw('
                    (CASE
                        WHEN purchase_orders.trm IS NOT NULL AND purchase_orders.trm >= 3400 THEN "no"
                        ELSE "si"
                    END) as defaultTrm
                ')
                ->selectRaw('purchase_order_product.muestra as is_muestra')
                ->selectRaw('
                    (
                        (CASE
                            WHEN purchase_order_product.muestra = 1 THEN 0
                            WHEN purchase_order_product.price > 0 THEN purchase_order_product.price
                            ELSE products.price
                        END) *
                        purchase_order_product.quantity *
                        (CASE
                            WHEN purchase_orders.trm IS NOT NULL AND purchase_orders.trm >= 3400 THEN purchase_orders.trm
                            WHEN trm_daily.value IS NOT NULL THEN trm_daily.value
                            ELSE 4000
                        END)
                    ) as total
                ')
                ->orderByRaw("{$dispatchDateExpression} IS NULL")
                ->orderByRaw($dispatchDateExpression)
                ->orderBy('purchase_orders.order_consecutive')
                ->get();
        }

        return $base
            ->where('clients.id', $clientId)
            ->selectRaw('purchase_orders.order_consecutive as consecutivo')
            ->selectRaw('products.product_name as product')
            ->selectRaw('partials.id as partial')
            ->selectRaw('partials.quantity as quantity')
            ->selectRaw('partials.dispatch_date as date')
            ->selectRaw('(CASE
                WHEN purchase_order_product.muestra = 1 THEN 0
                WHEN purchase_order_product.price > 0 THEN purchase_order_product.price
                ELSE products.price
            END) as price_usd')
            ->selectRaw('
                (CASE
                    WHEN partials.trm IS NOT NULL AND partials.trm >= 3400 THEN partials.trm
                    WHEN trm_daily.value IS NOT NULL THEN trm_daily.value
                    ELSE 4000
                END) as trm
            ')
            ->selectRaw('COALESCE(trm_daily.value, 4000) as trm_real')
            ->selectRaw('
                (CASE
                    WHEN partials.trm IS NOT NULL AND partials.trm >= 3400 THEN "no"
                    ELSE "si"
                END) as defaultTrm
            ')
            ->selectRaw('purchase_order_product.muestra as is_muestra')
            ->selectRaw('
                (
                    (CASE
                        WHEN purchase_order_product.muestra = 1 THEN 0
                        WHEN purchase_order_product.price > 0 THEN purchase_order_product.price
                        ELSE products.price
                    END) *
                    partials.quantity *
                    (CASE
                        WHEN partials.trm IS NOT NULL AND partials.trm >= 3400 THEN partials.trm
                        WHEN trm_daily.value IS NOT NULL THEN trm_daily.value
                        ELSE 4000
                    END)
                ) as total
            ')
            ->orderBy('partials.dispatch_date')
            ->get();
    }

    /// Retorna las ventas de Siigo para un NIT en el rango de meses, con detalle de producto.
    public function clientSiigoSales(string $nit, Carbon $from, Carbon $to): Collection
    {
        $fromMes = $from->format('Y-m');
        $toMes = $to->format('Y-m');

        return DB::table('siigo_sales')
            ->leftJoin('products', DB::raw('products.code COLLATE utf8mb4_unicode_ci'), '=', DB::raw('siigo_sales.product_code COLLATE utf8mb4_unicode_ci'))
            ->where('siigo_sales.nit', $nit)
            ->whereBetween('siigo_sales.mes', [$fromMes, $toMes])
            ->selectRaw('siigo_sales.product_code')
            ->selectRaw('MIN(products.product_name) as product_name')
            ->selectRaw('siigo_sales.mes')
            ->selectRaw('siigo_sales.cantidad')
            ->selectRaw('siigo_sales.valor')
            ->selectRaw('siigo_sales.precio_unitario')
            ->groupBy('siigo_sales.product_code', 'siigo_sales.mes', 'siigo_sales.cantidad', 'siigo_sales.valor', 'siigo_sales.precio_unitario')
            ->orderBy('siigo_sales.mes')
            ->orderBy('siigo_sales.product_code')
            ->get();
    }
}
