<?php

namespace App\Services;

use App\Models\PurchaseOrder;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Alerta de pronóstico: avisa cuando los kilos de una línea de OC, sumados a lo
 * que el cliente ya tiene comprometido de esa referencia para el mes de entrega,
 * superan el pronóstico manual de ese mes.
 *
 * Decisiones verificadas contra datos de producción (agosto 2026):
 *  - El mes de referencia es el de la ENTREGA de la línea, no el mes en curso.
 *    El mes de creación sólo acierta el mes de despacho real en el 37% de los kilos;
 *    la fecha de entrega acierta en el 72%.
 *  - "Comprometido" = despachos reales del mes + líneas pendientes cuya entrega cae
 *    en ese mes. Contar sólo lo despachado dejaba pasar el 37% de los excesos reales.
 *  - Se excluye siempre la propia OC: su despacho ya está en la tabla de parciales y
 *    se contaba dos veces al reenviar el correo.
 *  - Se cruza por `products.code` (no por `product_id`) porque un mismo cliente puede
 *    tener el mismo código en varias filas de `products`, y el pronóstico vive en
 *    `sales_forecasts` con la clave (nit, codigo).
 */
class ForecastAlertService
{
    private const TZ = 'America/Bogota';

    private const MESES = [
        1 => 'ENERO', 2 => 'FEBRERO', 3 => 'MARZO', 4 => 'ABRIL',
        5 => 'MAYO', 6 => 'JUNIO', 7 => 'JULIO', 8 => 'AGOSTO',
        9 => 'SEPTIEMBRE', 10 => 'OCTUBRE', 11 => 'NOVIEMBRE', 12 => 'DICIEMBRE',
    ];

    public function monthName(int $month): string
    {
        return self::MESES[$month] ?? '';
    }

    /**
     * Mes de referencia a partir de la fecha de entrega de la línea.
     * Sin fecha (o fecha inválida) cae al mes en curso.
     *
     * @return array{año: string, mes: string, inicio: string, fin: string}
     */
    public function resolveMonth(string|\DateTimeInterface|null $deliveryDate): array
    {
        try {
            $date = empty($deliveryDate)
                ? Carbon::now(self::TZ)
                : Carbon::parse($deliveryDate, self::TZ);
        } catch (\Throwable) {
            $date = Carbon::now(self::TZ);
        }

        return [
            'año'    => $date->format('Y'),
            'mes'    => $this->monthName((int) $date->format('n')),
            'inicio' => $date->copy()->startOfMonth()->toDateString(),
            'fin'    => $date->copy()->endOfMonth()->toDateString(),
        ];
    }

    /**
     * Decide si la línea excede el pronóstico del mes.
     *
     * @return array{has_forecast: bool, excede: bool, excedente: float, disponible: float,
     *               pronostico: float, comprometido: float, cantidad: float}
     */
    public function evaluate(?float $pronostico, float $comprometido, float $cantidad): array
    {
        if ($pronostico === null) {
            return [
                'has_forecast' => false,
                'excede'       => false,
                'excedente'    => 0.0,
                'disponible'   => 0.0,
                'pronostico'   => 0.0,
                'comprometido' => round($comprometido, 2),
                'cantidad'     => round($cantidad, 2),
            ];
        }

        $excedente  = round(max(0, ($comprometido + $cantidad) - $pronostico), 2);
        $disponible = round(max(0, $pronostico - $comprometido - $cantidad), 2);

        return [
            'has_forecast' => true,
            'excede'       => $excedente > 0,
            'excedente'    => $excedente,
            'disponible'   => $disponible,
            'pronostico'   => round($pronostico, 2),
            'comprometido' => round($comprometido, 2),
            'cantidad'     => round($cantidad, 2),
        ];
    }

    /**
     * Pronóstico manual del mes para (nit, código). Null si no hay fila cargada.
     */
    public function forecastFor(string $nit, string $code, array $month): ?float
    {
        if ($nit === '' || $code === '') {
            return null;
        }

        $row = DB::table('sales_forecasts')
            ->where('nit', $nit)
            ->where('codigo', $code)
            ->where('modelo', 'manual')
            ->where('año', $month['año'])
            ->where('mes', $month['mes'])
            ->selectRaw('SUM(cantidad_forecast) as total, COUNT(*) as filas')
            ->first();

        return ($row && $row->filas > 0) ? (float) $row->total : null;
    }

    /**
     * Kilos que el cliente ya tiene comprometidos de esa referencia para el mes:
     * lo despachado realmente + lo pedido en líneas que todavía no despachan.
     * Excluye la OC indicada para no contarla dos veces.
     */
    public function committed(int $clientId, string $code, array $month, ?int $excludeOrderId = null): float
    {
        $despachado = DB::table('partials as pt')
            ->join('purchase_orders as po', 'pt.order_id', '=', 'po.id')
            ->join('purchase_order_product as pop', 'pt.product_order_id', '=', 'pop.id')
            ->join('products as p', 'pop.product_id', '=', 'p.id')
            ->where('po.client_id', $clientId)
            ->where('p.code', $code)
            ->where('pt.type', 'real')
            ->whereNull('pt.deleted_at')
            ->where('pop.muestra', 0)
            ->where('po.status', '!=', 'cancelled')
            ->whereBetween('pt.dispatch_date', [$month['inicio'], $month['fin']])
            ->when($excludeOrderId, fn ($q) => $q->where('pt.order_id', '!=', $excludeOrderId))
            ->sum('pt.quantity');

        $pendiente = DB::table('purchase_order_product as pop')
            ->join('purchase_orders as po', 'pop.purchase_order_id', '=', 'po.id')
            ->join('products as p', 'pop.product_id', '=', 'p.id')
            ->where('po.client_id', $clientId)
            ->where('p.code', $code)
            ->where('pop.muestra', 0)
            ->where('po.status', '!=', 'cancelled')
            ->whereBetween('pop.delivery_date', [$month['inicio'], $month['fin']])
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                  ->from('partials as pt2')
                  ->whereColumn('pt2.product_order_id', 'pop.id')
                  ->where('pt2.type', 'real')
                  ->whereNull('pt2.deleted_at');
            })
            ->when($excludeOrderId, fn ($q) => $q->where('po.id', '!=', $excludeOrderId))
            ->sum('pop.quantity');

        return round((float) $despachado + (float) $pendiente, 2);
    }

    /**
     * Fecha de referencia de una línea: la fecha estimada que cargó planta
     * (parcial temporal) si ya existe; si no, la fecha de entrega de la línea.
     */
    public function referenceDate(?int $productOrderId, string|\DateTimeInterface|null $deliveryDate): string|\DateTimeInterface|null
    {
        if ($productOrderId) {
            $estimada = DB::table('partials')
                ->where('product_order_id', $productOrderId)
                ->where('type', 'temporal')
                ->whereNull('deleted_at')
                ->whereNotNull('dispatch_date')
                ->min('dispatch_date');

            if (!empty($estimada)) {
                return (string) $estimada;
            }
        }

        return $deliveryDate;
    }

    /**
     * Evalúa una línea suelta (formulario de OC, antes de guardar).
     */
    public function checkLine(int $clientId, int $productId, string|\DateTimeInterface|null $deliveryDate, ?int $excludeOrderId = null): array
    {
        $client  = DB::table('clients')->where('id', $clientId)->select('nit')->first();
        $product = DB::table('products')->where('id', $productId)->select('code')->first();

        if (!$client || !$product) {
            return ['found' => false];
        }

        $month     = $this->resolveMonth($deliveryDate);
        $forecast  = $this->forecastFor((string) $client->nit, (string) $product->code, $month);
        $committed = $forecast === null
            ? 0.0
            : $this->committed($clientId, (string) $product->code, $month, $excludeOrderId);

        return [
            'found'        => true,
            'month'        => $month,
            'pronostico'   => $forecast,
            'comprometido' => $committed,
        ];
    }

    /**
     * Filas de productos de la orden que exceden el pronóstico de su mes de entrega.
     *
     * @return array<int, array{nombre: string, codigo: string, cantidad: float,
     *                          excedente: float, pronostico: float, comprometido: float, mes: string}>
     */
    public function exceedancesForOrder(PurchaseOrder $purchaseOrder): array
    {
        $purchaseOrder->loadMissing('client', 'products');

        $nit = (string) ($purchaseOrder->client->nit ?? '');
        if ($nit === '') {
            return [];
        }

        $filas = [];

        foreach ($purchaseOrder->products as $product) {
            if ($product->pivot->muestra) {
                continue;
            }

            $cantidad = (float) $product->pivot->quantity;
            $codigo   = (string) ($product->code ?? '');
            if ($cantidad <= 0 || $codigo === '') {
                continue;
            }

            $fecha = $this->referenceDate($product->pivot->id, $product->pivot->delivery_date);
            $month = $this->resolveMonth($fecha);

            $forecast = $this->forecastFor($nit, $codigo, $month);
            if ($forecast === null) {
                continue;
            }

            $committed = $this->committed(
                (int) $purchaseOrder->client_id,
                $codigo,
                $month,
                (int) $purchaseOrder->id
            );

            $resultado = $this->evaluate($forecast, $committed, $cantidad);
            if (!$resultado['excede']) {
                continue;
            }

            $filas[] = [
                'nombre'       => (string) $product->product_name,
                'codigo'       => $codigo,
                'cantidad'     => $resultado['cantidad'],
                'excedente'    => $resultado['excedente'],
                'pronostico'   => $resultado['pronostico'],
                'comprometido' => $resultado['comprometido'],
                'mes'          => ucfirst(mb_strtolower($month['mes'])),
            ];
        }

        return $filas;
    }
}
