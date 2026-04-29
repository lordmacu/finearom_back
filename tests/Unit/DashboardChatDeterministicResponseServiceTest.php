<?php

namespace Tests\Unit;

use App\Services\DashboardChatDeterministicResponseService;
use Tests\TestCase;

class DashboardChatDeterministicResponseServiceTest extends TestCase
{
    public function test_builds_grouped_forecast_by_client_cop_query(): void
    {
        $service = new DashboardChatDeterministicResponseService();

        $content = $service->build(
            'muestrame el total del pronosticado por cliente y el total en pesos',
            '2026-04-01',
            '2026-04-30'
        );

        $this->assertNotNull($content);

        $payload = json_decode($content, true);
        $this->assertIsArray($payload);

        $sql = $payload['sql'] ?? '';
        $this->assertStringContainsString("sf.año = '2026'", $sql);
        $this->assertStringContainsString("sf.mes = 'ABRIL'", $sql);
        $this->assertStringContainsString('GROUP BY c.client_name, c.nit', $sql);
        $this->assertStringContainsString('valor_cop_estimado', $sql);
        $this->assertStringContainsString('ROW_NUMBER() OVER', $sql);
        $this->assertStringContainsString('pp.client_id = c.id', $sql);
        $this->assertStringNotContainsString('p.product_name AS referencia', $sql);
    }

    public function test_ignores_unrelated_messages(): void
    {
        $service = new DashboardChatDeterministicResponseService();

        $this->assertNull($service->build('muestrame la cartera vencida', '2026-04-01', '2026-04-30'));
    }
}
