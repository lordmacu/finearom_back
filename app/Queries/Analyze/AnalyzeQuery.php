<?php

namespace App\Queries\Analyze;

use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AnalyzeQuery
{
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

        if ($status && ! in_array($status, ['completed', 'pending', 'processing', 'parcial_status'], true)) {
            abort(400, 'Invalid status');
        }

        if (! in_array($type, ['real', 'temporal'], true)) {
            abort(400, 'Invalid type');
        }

        return [$status, $type];
    }

    public function base(Carbon $from, Carbon $to, string $type, ?string $status): Builder
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

        if ($status) {
            if ($status === 'completed') {
                $query->whereIn('purchase_orders.status', ['parcial_status', 'completed']);
            } elseif ($status === 'pending') {
                $query->where('purchase_orders.status', 'pending');
            } elseif ($status === 'processing') {
                $query->where('purchase_orders.status', 'processing');
            } elseif ($status === 'parcial_status') {
                if ($type === 'temporal') {
                    $query->where('purchase_orders.status', 'parcial_status');
                } else {
                    $query->whereRaw('1 = 0');
                }
            }
        }

        return $query;
    }

    public function totals(Carbon $from, Carbon $to, string $type, ?string $status): object
    {
        $base = $this->base($from, $to, $type, $status);

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
                        WHEN partials.trm IS NOT NULL AND partials.trm > 3800 THEN partials.trm
                        WHEN trm_daily.value IS NOT NULL THEN trm_daily.value
                        ELSE 4000
                    END)
                ) as total_cop
            ')
            ->first();
    }

    public function paginateClients(
        Carbon $from,
        Carbon $to,
        string $type,
        ?string $status,
        int $perPage
    ): LengthAwarePaginator {
        $base = $this->base($from, $to, $type, $status);

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
                        WHEN partials.trm IS NOT NULL AND partials.trm > 3800 THEN partials.trm
                        WHEN trm_daily.value IS NOT NULL THEN trm_daily.value
                        ELSE 4000
                    END)
                ) as total_cop
            ')
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
        $base = $this->base($from, $to, $type, $status);

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
                        WHEN partials.trm IS NOT NULL AND partials.trm > 3800 THEN partials.trm
                        WHEN trm_daily.value IS NOT NULL THEN trm_daily.value
                        ELSE 4000
                    END)
                ) as total_cop
            ')
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
                    WHEN partials.trm IS NOT NULL AND partials.trm > 3800 THEN partials.trm
                    WHEN trm_daily.value IS NOT NULL THEN trm_daily.value
                    ELSE 4000
                END) as trm
            ')
            ->selectRaw('COALESCE(trm_daily.value, 4000) as trm_real')
            ->selectRaw('
                (CASE
                    WHEN partials.trm IS NOT NULL AND partials.trm > 3800 THEN "no"
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
                        WHEN partials.trm IS NOT NULL AND partials.trm > 3800 THEN partials.trm
                        WHEN trm_daily.value IS NOT NULL THEN trm_daily.value
                        ELSE 4000
                    END)
                ) as total
            ')
            ->orderBy('partials.dispatch_date')
            ->get();
    }
}
