<?php

namespace Tests\Feature;

use App\Services\VannaService;
use Tests\TestCase;

class ChatMessageVannaFallbackTest extends TestCase
{
    public function test_uses_static_examples_when_vanna_returns_null(): void
    {
        // Vanna devuelve null → debe usarse buildExamplesBlock (no romperse).
        $this->mock(VannaService::class, function ($m) {
            $m->shouldReceive('retrieve')->andReturn(null);
        });

        $c = app(\App\Http\Controllers\MonthlyReportController::class);
        $ref = new \ReflectionMethod($c, 'resolveExamplesBlock');
        $ref->setAccessible(true);
        $block = $ref->invoke($c, '¿cuántos clientes hay?');

        // buildExamplesBlock incluye este header conocido en su salida.
        $this->assertStringContainsString('EJEMPLOS DE QUERIES DE REFERENCIA', $block ?? '');
    }
}
