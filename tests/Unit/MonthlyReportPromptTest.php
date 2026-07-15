<?php

namespace Tests\Unit;

use App\Http\Controllers\MonthlyReportController;
use Tests\TestCase;

class MonthlyReportPromptTest extends TestCase
{
    private function invokePrivate(string $method, array $args = [])
    {
        $c = app(MonthlyReportController::class);
        $ref = new \ReflectionMethod($c, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($c, $args);
    }

    public function test_rules_block_has_no_examples_marker(): void
    {
        $rules = $this->invokePrivate('buildRulesBlock');
        $this->assertStringContainsString('Finearom', $rules);
        // El bloque de reglas NO debe traer el header del bloque de ejemplos.
        $this->assertStringNotContainsString('EJEMPLOS DE QUERIES DE REFERENCIA', $rules);
    }

    public function test_format_retrieved_examples(): void
    {
        $out = $this->invokePrivate('formatRetrievedExamples', [[
            'examples' => [['question' => 'cuantos clientes', 'sql' => 'SELECT COUNT(*) FROM clients']],
            'ddl' => ['CREATE TABLE clients (id INT)'],
            'documentation' => ['clients guarda clientes'],
        ]]);
        $this->assertStringContainsString('cuantos clientes', $out);
        $this->assertStringContainsString('SELECT COUNT(*) FROM clients', $out);
        $this->assertStringContainsString('clients guarda clientes', $out);
    }
}
