<?php

namespace Tests\Unit\Courier;

use App\Services\Courier\CourierStatus;
use App\Services\Courier\Drivers\DhlDriver;
use Tests\TestCase;

class DhlDriverTest extends TestCase
{
    private DhlDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->driver = new DhlDriver();
    }

    public function test_key_es_dhl(): void
    {
        $this->assertSame('dhl', $this->driver->key());
    }

    public function test_solo_acepta_guias_de_10_digitos(): void
    {
        $this->assertTrue($this->driver->matches('4068449733'));
        $this->assertFalse($this->driver->matches('30380000550'));  // coordinadora, 11
        $this->assertFalse($this->driver->matches('222603153932')); // aldia, 12
        $this->assertFalse($this->driver->matches('null'));
        $this->assertFalse($this->driver->matches(''));
    }

    public function test_mapeo_de_codigos_a_estados(): void
    {
        $this->assertSame(CourierStatus::ENTREGADO, $this->driver->mapStatus('OK'));
        $this->assertSame(CourierStatus::DEVUELTO, $this->driver->mapStatus('RT'));
        $this->assertSame(CourierStatus::EN_TRANSITO, $this->driver->mapStatus('PU'));
        $this->assertSame(CourierStatus::EN_TRANSITO, $this->driver->mapStatus('PL'));
        $this->assertSame(CourierStatus::EN_TRANSITO, $this->driver->mapStatus(null));
    }

    public function test_codigo_de_excepcion_configurado_es_novedad(): void
    {
        config(['custom.dhl_exception_codes' => ['HP', 'UD']]);
        $driver = new DhlDriver();

        $this->assertSame(CourierStatus::NOVEDAD, $driver->mapStatus('HP'));
        $this->assertSame(CourierStatus::NOVEDAD, $driver->mapStatus('UD'));
    }

    public function test_parsea_la_respuesta_real_de_dhl(): void
    {
        $json = json_decode(file_get_contents(base_path('tests/Fixtures/dhl_tracking_response.json')), true);

        $r = $this->driver->parse($json);

        $this->assertFalse($r->notFound);
        $this->assertNull($r->error);
        $this->assertCount(4, $r->events);

        // El estado sale del ÚLTIMO evento, no del primero
        $this->assertSame(CourierStatus::DEVUELTO, $r->status);

        $primero = $r->events[0];
        $this->assertSame('2026-07-17 15:57:44', $primero->occurredAt);
        $this->assertSame('PU', $primero->code);
        $this->assertSame('Shipment picked up', $primero->description);
        $this->assertSame('Bogota-CO', $primero->location);
    }

    public function test_respuesta_sin_shipments_es_not_found(): void
    {
        $r = $this->driver->parse(['shipments' => []]);

        $this->assertTrue($r->notFound);
        $this->assertSame(CourierStatus::SIN_DATOS, $r->status);
    }
}
