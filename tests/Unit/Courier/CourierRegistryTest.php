<?php

namespace Tests\Unit\Courier;

use App\Services\Courier\CourierRegistry;
use App\Services\Courier\Drivers\DhlDriver;
use Tests\TestCase;

class CourierRegistryTest extends TestCase
{
    private CourierRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new CourierRegistry([new DhlDriver()]);
    }

    public function test_resuelve_dhl_sin_importar_mayusculas_ni_espacios(): void
    {
        $this->assertInstanceOf(DhlDriver::class, $this->registry->driverFor('dhl'));
        $this->assertInstanceOf(DhlDriver::class, $this->registry->driverFor('DHL'));
        $this->assertInstanceOf(DhlDriver::class, $this->registry->driverFor('  Dhl '));
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

        $this->assertFalse($this->registry->canTrack('dhl', '30380000550')); // formato de coordinadora
        $this->assertFalse($this->registry->canTrack('dhl', 'null'));
        $this->assertFalse($this->registry->canTrack('dhl', null));
        $this->assertFalse($this->registry->canTrack('aldia', '222603153932'));
    }

    public function test_keys_lista_las_transportadoras_con_driver(): void
    {
        $this->assertSame(['dhl'], $this->registry->keys());
    }
}
