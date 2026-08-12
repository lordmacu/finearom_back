<?php

namespace Tests\Unit\Courier;

use App\Services\Courier\CourierEvent;
use App\Services\Courier\CourierRegistry;
use App\Services\Courier\CourierResult;
use App\Services\Courier\CourierStatus;
use App\Services\Courier\ShipmentTrackingSyncService;
use Tests\TestCase;

class ShipmentTrackingSyncServiceTest extends TestCase
{
    private ShipmentTrackingSyncService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $registry = $this->createMock(CourierRegistry::class);
        $this->service = new ShipmentTrackingSyncService($registry);
    }

    public function test_lastEvent_devuelve_null_con_arreglo_vacio(): void
    {
        $result = CourierResult::found(CourierStatus::EN_TRANSITO, []);

        $evento = $this->service->lastEvent($result);

        $this->assertNull($evento);
    }

    public function test_lastEvent_devuelve_el_unico_evento(): void
    {
        $evento1 = new CourierEvent('2026-07-17 15:57:44', 'PU', 'Shipment picked up', 'Bogota-CO', []);
        $result = CourierResult::found(CourierStatus::EN_TRANSITO, [$evento1]);

        $evento = $this->service->lastEvent($result);

        $this->assertSame($evento1, $evento);
    }

    public function test_lastEvent_devuelve_el_ultimo_evento_de_varios(): void
    {
        $evento1 = new CourierEvent('2026-07-17 15:57:44', 'PU', 'Shipment picked up', 'Bogota-CO', []);
        $evento2 = new CourierEvent('2026-07-17 16:30:00', 'IN', 'In transit', 'Medellin-CO', []);
        $evento3 = new CourierEvent('2026-07-18 10:15:22', 'DE', 'Delivered', 'Cali-CO', []);
        $result = CourierResult::found(CourierStatus::ENTREGADO, [$evento1, $evento2, $evento3]);

        $evento = $this->service->lastEvent($result);

        $this->assertSame($evento3, $evento);
        $this->assertSame('DE', $evento->code);
        $this->assertSame('Delivered', $evento->description);
    }
}
