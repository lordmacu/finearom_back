<?php

namespace Tests\Unit\Courier;

use App\Services\Courier\CourierEvent;
use App\Services\Courier\CourierResult;
use App\Services\Courier\CourierStatus;
use Tests\TestCase;

class CourierStatusTest extends TestCase
{
    public function test_entregado_y_devuelto_son_finales(): void
    {
        $this->assertTrue(CourierStatus::isFinal(CourierStatus::ENTREGADO));
        $this->assertTrue(CourierStatus::isFinal(CourierStatus::DEVUELTO));
    }

    public function test_los_demas_estados_no_son_finales(): void
    {
        $this->assertFalse(CourierStatus::isFinal(CourierStatus::EN_TRANSITO));
        $this->assertFalse(CourierStatus::isFinal(CourierStatus::NOVEDAD));
        $this->assertFalse(CourierStatus::isFinal(CourierStatus::SIN_DATOS));
        $this->assertFalse(CourierStatus::isFinal(CourierStatus::PENDIENTE));
    }

    public function test_result_found_expone_estado_y_eventos(): void
    {
        $evento = new CourierEvent('2026-07-17 15:57:44', 'PU', 'Shipment picked up', 'Bogota-CO', []);
        $r = CourierResult::found(CourierStatus::EN_TRANSITO, [$evento]);

        $this->assertSame(CourierStatus::EN_TRANSITO, $r->status);
        $this->assertCount(1, $r->events);
        $this->assertFalse($r->notFound);
        $this->assertNull($r->error);
    }

    public function test_result_not_found_marca_sin_datos(): void
    {
        $r = CourierResult::notFound();

        $this->assertTrue($r->notFound);
        $this->assertSame(CourierStatus::SIN_DATOS, $r->status);
        $this->assertSame([], $r->events);
    }

    public function test_result_error_guarda_el_mensaje(): void
    {
        $r = CourierResult::error('timeout');

        $this->assertSame('timeout', $r->error);
        $this->assertFalse($r->notFound);
        $this->assertSame([], $r->events);
    }
}
