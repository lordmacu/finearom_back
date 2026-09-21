<?php

namespace App\Support;

use App\Models\ConfigSystem;
use App\Models\PurchaseOrder;
use App\Services\TrmService;
use App\Services\TrmNormalizer;

/**
 * Aviso de flete en el correo de confirmación de la orden de compra.
 *
 * Solicitado por Mónica Castaño: el aviso sale únicamente en las órdenes
 * pequeñas, y nunca en las de los clientes grandes. Se cumplen las dos
 * condiciones a la vez:
 *
 *   1. el cliente NO es de tipo AA ni A, y
 *   2. el total de la orden es menor a $10.000.000 COP.
 *
 * Ojo con el total: los precios de la orden están en dólares, así que el
 * total en pesos sale de multiplicar por la TRM de la orden. Si no se puede
 * establecer una TRM confiable no se muestra el aviso, para no arriesgarse a
 * mandárselo a un cliente grande por un error de cálculo.
 */
final class PurchaseOrderFreightNotice
{
    /** Tope en pesos. Por debajo de este valor se muestra el aviso. */
    public const LIMITE_COP = 10000000;

    /** Tipos de cliente que nunca reciben el aviso. */
    public const TIPOS_EXCLUIDOS = ['AA', 'A'];

    /** Clave en config_system para editar el texto sin tocar código. */
    public const CONFIG_KEY = 'avisoFlete';

    public const TEXTO_POR_DEFECTO = 'Teniendo en cuenta los ajustes al alza que hemos experimentado durante este año en los costos de transporte, queremos informarles que FINEAROM asumirá el costo del flete para las facturas correspondientes a un único despacho por valores superiores a $2.000.000 COP. Para despachos por valores inferiores a este monto, el costo del flete será facturado.';

    /**
     * La regla de negocio, sin base de datos de por medio.
     *
     * @param  string|null  $clientType  clients.client_type
     * @param  float|null   $totalCop    null = no se pudo calcular
     */
    public static function aplica(?string $clientType, ?float $totalCop): bool
    {
        if ($totalCop === null) {
            return false;
        }

        if (in_array((string) $clientType, self::TIPOS_EXCLUIDOS, true)) {
            return false;
        }

        return $totalCop < self::LIMITE_COP;
    }

    /**
     * Total de la orden en pesos. null cuando no hay productos o no hay una
     * TRM confiable con la cual convertir.
     */
    public static function totalCop(PurchaseOrder $order): ?float
    {
        $order->loadMissing('products');

        if ($order->products->isEmpty()) {
            return null;
        }

        $totalUsd = $order->products->sum(
            fn ($product) => (float) $product->pivot->quantity * (float) $product->pivot->price
        );

        $trm = self::trmDeLaOrden($order);

        return $trm === null ? null : $totalUsd * $trm;
    }

    public static function debeMostrarse(PurchaseOrder $order): bool
    {
        $order->loadMissing('client');

        return self::aplica($order->client?->client_type, self::totalCop($order));
    }

    /** Bloque HTML listo para insertar en el correo. */
    public static function html(): string
    {
        // Si la configuración no responde se usa el texto fijo: el aviso no
        // puede ser motivo para que falle el envío del correo.
        try {
            $texto = ConfigSystem::query()->where('key', self::CONFIG_KEY)->value('value');
        } catch (\Throwable $e) {
            $texto = null;
        }

        $texto = is_string($texto) && trim($texto) !== '' ? $texto : self::TEXTO_POR_DEFECTO;

        return '<p style="background-color:#fff3cd;padding:12px;border-left:4px solid #f0ad4e;'
            . 'font-size:14px;color:#374151;margin:16px 0;"><i>'
            . e($texto)
            . '</i></p>';
    }

    /**
     * TRM de la orden; si no es confiable, la del día. null si no hay ninguna.
     *
     * Mismo umbral que usan los reportes (TrmNormalizer::MIN_VALID_TRM): en la
     * base hay órdenes viejas con TRM de un dígito o en cero.
     */
    private static function trmDeLaOrden(PurchaseOrder $order): ?float
    {
        $trm = (float) ($order->trm ?? 0);

        if ($trm >= TrmNormalizer::MIN_VALID_TRM) {
            return $trm;
        }

        $delDia = (float) app(TrmService::class)->getTrm();

        return $delDia >= TrmNormalizer::MIN_VALID_TRM ? $delDia : null;
    }
}
