<?php

namespace App\Services\Courier;

use App\Models\ShipmentTracking;
use App\Models\ShipmentTrackingEvent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Consulta a la transportadora y guarda estado + eventos.
 *
 * Reglas:
 *   - entregado y devuelto cierran el despacho (is_final): no se vuelve a consultar
 *   - MAX_SIN_DATOS respuestas 404 seguidas también lo cierran (DHL borra las
 *     guías viejas y seguirían consultándose para siempre)
 *   - los eventos se insertan sólo si no existían: correr dos veces no duplica
 */
class ShipmentTrackingSyncService
{
    /** Respuestas "sin datos" seguidas antes de dejar de consultar. */
    public const MAX_SIN_DATOS = 5;

    public function __construct(
        private readonly CourierRegistry $registry
    ) {}

    public function pending(): Collection
    {
        return ShipmentTracking::query()
            ->where('is_final', false)
            // pullKeys(), no keys(): Coordinadora tiene driver (para que el
            // descubrimiento reconozca sus guías) pero es push-only, así que
            // nunca debe entrar a la cola de consulta activa.
            ->whereIn('carrier', $this->registry->pullKeys())
            ->orderByRaw('checked_at IS NULL DESC')
            ->orderBy('checked_at')
            ->get();
    }

    /**
     * Reabre las guías cerradas por agotar reintentos de "sin_datos" (5
     * respuestas 404 seguidas). Pensada para cuando el cierre fue producto de
     * una falla de configuración (ej. URL de sandbox) y no de que la
     * transportadora ya no tenga la guía. No toca `entregado` ni `devuelto`:
     * sólo filas cuyo status sea `sin_datos`.
     */
    public function reopenSinDatos(): int
    {
        return ShipmentTracking::query()
            ->where('status', CourierStatus::SIN_DATOS)
            ->update(['is_final' => false, 'check_attempts' => 0]);
    }

    public function syncOne(ShipmentTracking $tracking): string
    {
        $driver = $this->registry->driverFor($tracking->carrier);

        if ($driver === null) {
            return $tracking->status;
        }

        $result = $driver->track($tracking->tracking_number);

        $tracking->checked_at    = now();
        $tracking->error_message = $result->error;

        if ($result->error !== null) {
            $tracking->save();
            return $tracking->status;
        }

        if ($result->notFound) {
            $tracking->status         = CourierStatus::SIN_DATOS;
            $tracking->check_attempts = $tracking->check_attempts + 1;

            if ($tracking->check_attempts >= self::MAX_SIN_DATOS) {
                $tracking->is_final = true;
                Log::info("[Courier] {$tracking->tracking_number} cerrado tras "
                    . self::MAX_SIN_DATOS . ' consultas sin datos');
            }

            $tracking->save();
            return $tracking->status;
        }

        $this->storeEvents($tracking, $result->events);

        $ultimo = $this->lastEvent($result);

        $tracking->status                 = $result->status;
        $tracking->check_attempts         = 0;
        $tracking->last_event_at          = $ultimo?->occurredAt;
        $tracking->last_event_code        = $ultimo?->code;
        $tracking->last_event_description = $ultimo?->description;
        $tracking->last_event_location    = $ultimo?->location;
        $tracking->is_final               = CourierStatus::isFinal($result->status);
        $tracking->save();

        return $tracking->status;
    }

    /**
     * Extrae el último evento del resultado (el más reciente).
     * Devuelve null si no hay eventos.
     */
    public function lastEvent(CourierResult $result): ?CourierEvent
    {
        if ($result->events === []) {
            return null;
        }

        return $result->events[array_key_last($result->events)];
    }

    /** @param CourierEvent[] $events */
    private function storeEvents(ShipmentTracking $tracking, array $events): void
    {
        foreach ($events as $event) {
            ShipmentTrackingEvent::firstOrCreate(
                [
                    'shipment_tracking_id' => $tracking->id,
                    'occurred_at'          => $event->occurredAt,
                    'code'                 => $event->code,
                ],
                [
                    'description' => $event->description,
                    'location'    => $event->location,
                    'raw'         => $event->raw,
                ]
            );
        }
    }
}
