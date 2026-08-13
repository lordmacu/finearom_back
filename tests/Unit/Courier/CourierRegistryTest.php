<?php

namespace Tests\Unit\Courier;

use App\Services\Courier\CourierRegistry;
use App\Services\Courier\Drivers\CoordinadoraDriver;
use App\Services\Courier\Drivers\DhlDriver;
use Tests\TestCase;

class CourierRegistryTest extends TestCase
{
    private CourierRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new CourierRegistry([new DhlDriver(), new CoordinadoraDriver()]);
    }

    public function test_resuelve_dhl_y_coordinadora_sin_importar_mayusculas_ni_espacios(): void
    {
        $this->assertInstanceOf(DhlDriver::class, $this->registry->driverFor('dhl'));
        $this->assertInstanceOf(DhlDriver::class, $this->registry->driverFor('DHL'));
        $this->assertInstanceOf(DhlDriver::class, $this->registry->driverFor('  Dhl '));

        $this->assertInstanceOf(CoordinadoraDriver::class, $this->registry->driverFor('coordinadora'));
        $this->assertInstanceOf(CoordinadoraDriver::class, $this->registry->driverFor('COORDINADORA'));
        $this->assertInstanceOf(CoordinadoraDriver::class, $this->registry->driverFor('  Coordinadora '));
    }

    public function test_transportadoras_sin_integracion_devuelven_null(): void
    {
        $this->assertNull($this->registry->driverFor('aldia'));
        $this->assertNull($this->registry->driverFor('reymor'));
        $this->assertNull($this->registry->driverFor('josé_cifuentes'));
        $this->assertNull($this->registry->driverFor(null));
        $this->assertNull($this->registry->driverFor(''));
    }

    public function test_can_track_exige_driver_y_formato_de_guia(): void
    {
        $this->assertTrue($this->registry->canTrack('dhl', '4068449733'));
        $this->assertTrue($this->registry->canTrack('coordinadora', '30380000550'));

        $this->assertFalse($this->registry->canTrack('dhl', '30380000550')); // formato de coordinadora
        $this->assertFalse($this->registry->canTrack('dhl', 'null'));
        $this->assertFalse($this->registry->canTrack('dhl', null));
        $this->assertFalse($this->registry->canTrack('aldia', '222603153932'));
        $this->assertFalse($this->registry->canTrack('coordinadora', '4068449733'));   // formato de dhl
        $this->assertFalse($this->registry->canTrack('coordinadora', '222603153932')); // formato de aldia
        $this->assertFalse($this->registry->canTrack('coordinadora', 'null'));
        $this->assertFalse($this->registry->canTrack('coordinadora', null));
    }

    public function test_coordinadora_matches_acepta_solo_11_digitos(): void
    {
        $driver = new CoordinadoraDriver();

        $this->assertTrue($driver->matches('30380000550'));

        $this->assertFalse($driver->matches('4068449733'));   // dhl, 10
        $this->assertFalse($driver->matches('222603153932')); // aldia, 12
        $this->assertFalse($driver->matches('null'));
        $this->assertFalse($driver->matches(''));
    }

    public function test_coordinadora_es_push_only_y_track_nunca_deberia_llamarse(): void
    {
        $driver = new CoordinadoraDriver();

        $this->assertTrue($driver->isPushOnly());
        $this->assertFalse((new DhlDriver())->isPushOnly());

        $result = $driver->track('30380000550');
        $this->assertNotNull($result->error);
        $this->assertSame([], $result->events);
    }

    public function test_keys_incluye_push_only_pero_pull_keys_los_excluye(): void
    {
        $this->assertSame(['dhl', 'coordinadora'], $this->registry->keys());
        $this->assertSame(['dhl'], $this->registry->pullKeys());
    }
}
