<?php

namespace App\Services\Courier\Drivers;

use App\Services\Courier\CourierDriver;
use App\Services\Courier\CourierResult;

/**
 * Coordinadora — a ella no se le pregunta: empuja sus notificaciones por
 * webhook (ver CoordinadoraPayloadParser). Este driver solo existe para que
 * el registro reconozca sus guías durante el descubrimiento (formato de 11
 * dígitos) y quede excluida de la cola de consulta vía
 * CourierRegistry::pullKeys().
 */
class CoordinadoraDriver implements CourierDriver
{
    public function key(): string
    {
        return 'coordinadora';
    }

    public function matches(string $trackingNumber): bool
    {
        return (bool) preg_match('/^\d{11}$/', trim($trackingNumber));
    }

    public function isPushOnly(): bool
    {
        return true;
    }

    /** Nunca debería llamarse: Coordinadora no se consulta, ella empuja. */
    public function track(string $trackingNumber): CourierResult
    {
        return CourierResult::error(
            'Coordinadora no se consulta: es push-only y no debería entrar a la cola de sincronización.'
        );
    }
}
