<?php

namespace App\Services\Courier;

use App\Services\Courier\Drivers\CoordinadoraDriver;
use App\Services\Courier\Drivers\DhlDriver;

/**
 * Traduce el texto libre de `partials.transporter` al driver que sabe consultarla.
 *
 * Para sumar una transportadora: crear su driver y agregarlo a defaultDrivers().
 * Todo lo que no tenga driver (aldia, reymor, nombres de conductores) devuelve
 * null y queda fuera del seguimiento automático.
 *
 * Ojo con `keys()` vs `pullKeys()`: `keys()` lista TODAS las transportadoras
 * con driver (incluye push-only, como Coordinadora, que se necesitan para el
 * descubrimiento). `pullKeys()` excluye las push-only y es la que debe usarse
 * para armar la cola de consulta activa (ShipmentTrackingSyncService::pending()).
 */
class CourierRegistry
{
    /** @var array<string, CourierDriver> */
    private array $drivers = [];

    /** @param CourierDriver[]|null $drivers */
    public function __construct(?array $drivers = null)
    {
        foreach ($drivers ?? self::defaultDrivers() as $driver) {
            $this->drivers[$driver->key()] = $driver;
        }
    }

    /** @return CourierDriver[] */
    public static function defaultDrivers(): array
    {
        return [new DhlDriver(), new CoordinadoraDriver()];
    }

    public function driverFor(?string $transporter): ?CourierDriver
    {
        if ($transporter === null) {
            return null;
        }

        return $this->drivers[strtolower(trim($transporter))] ?? null;
    }

    public function canTrack(?string $transporter, ?string $trackingNumber): bool
    {
        $driver = $this->driverFor($transporter);

        if ($driver === null || $trackingNumber === null || trim($trackingNumber) === '') {
            return false;
        }

        return $driver->matches($trackingNumber);
    }

    /** @return string[] todas las transportadoras con driver, push-only incluidas. */
    public function keys(): array
    {
        return array_keys($this->drivers);
    }

    /** @return string[] solo las transportadoras que sí se consultan (no push-only). */
    public function pullKeys(): array
    {
        return array_keys(array_filter(
            $this->drivers,
            fn (CourierDriver $driver): bool => !$driver->isPushOnly()
        ));
    }
}
