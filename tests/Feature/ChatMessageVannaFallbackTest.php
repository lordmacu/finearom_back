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

    public function test_falls_back_when_examples_empty(): void
    {
        // Vanna responde pero sin ejemplos → debe usarse buildExamplesBlock.
        $this->mock(VannaService::class, function ($m) {
            $m->shouldReceive('retrieve')->andReturn([
                'examples'      => [],
                'ddl'           => [],
                'documentation' => [],
            ]);
        });

        $c = app(\App\Http\Controllers\MonthlyReportController::class);
        $ref = new \ReflectionMethod($c, 'resolveExamplesBlock');
        $ref->setAccessible(true);
        $block = $ref->invoke($c, '¿cuántos clientes hay?');

        $this->assertStringContainsString('EJEMPLOS DE QUERIES DE REFERENCIA', $block ?? '');
    }

    public function test_uses_retrieval_when_examples_present(): void
    {
        // Vanna responde con ejemplos → debe usarse formatRetrievedExamples (no el bloque estático).
        $this->mock(VannaService::class, function ($m) {
            $m->shouldReceive('retrieve')->andReturn([
                'examples' => [
                    ['question' => 'cuantos clientes', 'sql' => 'SELECT COUNT(*) FROM clients'],
                ],
                'ddl'           => [],
                'documentation' => [],
            ]);
        });

        $c = app(\App\Http\Controllers\MonthlyReportController::class);
        $ref = new \ReflectionMethod($c, 'resolveExamplesBlock');
        $ref->setAccessible(true);
        $block = $ref->invoke($c, '¿cuántos clientes hay?');

        $this->assertStringContainsString('SELECT COUNT(*) FROM clients', $block ?? '');
        $this->assertStringNotContainsString('EJEMPLOS DE QUERIES DE REFERENCIA', $block ?? '');
    }

    public function test_falls_back_when_retrieve_throws(): void
    {
        // Vanna lanza una excepción → debe degradar a buildExamplesBlock sin propagar.
        $this->mock(VannaService::class, function ($m) {
            $m->shouldReceive('retrieve')->andThrow(new \RuntimeException('boom'));
        });

        $c = app(\App\Http\Controllers\MonthlyReportController::class);
        $ref = new \ReflectionMethod($c, 'resolveExamplesBlock');
        $ref->setAccessible(true);
        $block = $ref->invoke($c, '¿cuántos clientes hay?');

        $this->assertStringContainsString('EJEMPLOS DE QUERIES DE REFERENCIA', $block ?? '');
    }
}
