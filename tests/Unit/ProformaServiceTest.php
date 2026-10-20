<?php

namespace Tests\Unit;

use App\Models\Client;
use App\Services\ProformaService;
use App\Services\TrmService;
use Carbon\Carbon;
use Mockery;
use PHPUnit\Framework\TestCase;

class ProformaServiceTest extends TestCase
{
    private function service(): ProformaService
    {
        return new ProformaService(Mockery::mock(TrmService::class));
    }

    private function client(): Client
    {
        $c = new Client();
        $c->iva = 19;
        $c->ica = 1;
        $c->reteiva = 15;
        $c->retefuente = 2.5;

        return $c;
    }

    private function lineas(float $usd, float $kg = 1): array
    {
        return [['code' => 'A', 'description' => 'X', 'quantity' => $kg, 'price_usd' => $usd]];
    }

    public function test_sin_retenciones_si_el_subtotal_no_supera_el_umbral(): void
    {
        // 100 USD * 4000 = 400.000 (< 524.000)
        $r = $this->service()->calcular($this->client(), $this->lineas(100), 'CO', 4000, 0);

        $this->assertFalse($r['retenciones_aplican']);
        $this->assertSame(0.0, $r['totals']['rete_fte']);
        $this->assertEqualsWithDelta(400000 * 1.19, $r['totals']['total'], 0.01);
    }

    public function test_con_retenciones_si_el_subtotal_supera_el_umbral(): void
    {
        // 150 USD * 4000 = 600.000 (> 524.000)
        $r = $this->service()->calcular($this->client(), $this->lineas(150), 'CO', 4000, 0);
        $t = $r['totals'];

        $this->assertTrue($r['retenciones_aplican']);
        $this->assertEqualsWithDelta(600000 + 114000 - 6000 - 90000 - 15000, $t['total'], 0.01);
    }

    public function test_flete_solo_si_el_subtotal_es_menor_a_dos_millones(): void
    {
        $pequena = $this->service()->calcular($this->client(), $this->lineas(100), 'CO', 4000, 50000);
        $this->assertTrue($pequena['flete_aplica']);
        $this->assertSame(50000.0, $pequena['totals']['flete']);

        // 600 USD * 4000 = 2.400.000 (>= 2.000.000): el flete se ignora
        $grande = $this->service()->calcular($this->client(), $this->lineas(600), 'CO', 4000, 50000);
        $this->assertFalse($grande['flete_aplica']);
        $this->assertSame(0.0, $grande['totals']['flete']);
    }

    public function test_exportacion_va_en_usd_sin_impuestos_ni_flete(): void
    {
        $r = $this->service()->calcular($this->client(), $this->lineas(150), 'US', 0, 50000);

        $this->assertSame(150.0, $r['totals']['subtotal']);
        $this->assertSame(0.0, $r['totals']['iva']);
        $this->assertSame(0.0, $r['totals']['flete']);
        $this->assertSame(150.0, $r['totals']['total']);
    }

    public function test_vigencia_en_dias_habiles(): void
    {
        $s = $this->service();
        $emision = Carbon::parse('2026-10-19'); // lunes

        $this->assertSame('2026-10-29', $s->venceEl($emision)->toDateString()); // 8 hábiles después

        $this->assertSame('vigente', $s->vigencia($emision, Carbon::parse('2026-10-20'))['estado']);
        $this->assertSame('por_vencer', $s->vigencia($emision, Carbon::parse('2026-10-27'))['estado']);
        $this->assertSame('vencida', $s->vigencia($emision, Carbon::parse('2026-10-30'))['estado']);
    }
}
