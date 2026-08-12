<?php

namespace App\Services\Courier;

class CourierStatus
{
    public const PENDIENTE   = 'pendiente';
    public const EN_TRANSITO = 'en_transito';
    public const ENTREGADO   = 'entregado';
    public const DEVUELTO    = 'devuelto';
    public const NOVEDAD     = 'novedad';
    public const SIN_DATOS   = 'sin_datos';

    /** Estados que cierran el despacho: no se vuelve a consultar. */
    public const FINALES = [self::ENTREGADO, self::DEVUELTO];

    public static function isFinal(string $status): bool
    {
        return in_array($status, self::FINALES, true);
    }
}
