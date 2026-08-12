<?php

namespace Tests\Unit;

use App\Services\ForecastAlertService;
use Tests\TestCase;

/**
 * Casos tomados de órdenes reales de producción (Familia Del Pacífico, agosto 2026)
 * donde la alerta de pronóstico calculaba mal.
 */
class ForecastAlertServiceTest extends TestCase
{
    private ForecastAlertService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ForecastAlertService();
    }

    // ── Mes de referencia ────────────────────────────────────────────────────

    public function test_usa_el_mes_de_la_fecha_de_entrega_no_el_mes_en_curso(): void
    {
        $mes = $this->service->resolveMonth('2026-10-06');

        $this->assertSame('2026', $mes['año']);
        $this->assertSame('OCTUBRE', $mes['mes']);
        $this->assertSame('2026-10-01', $mes['inicio']);
        $this->assertSame('2026-10-31', $mes['fin']);
    }

    public function test_sin_fecha_de_entrega_cae_al_mes_en_curso(): void
    {
        $mes = $this->service->resolveMonth(null);

        $this->assertSame(now('America/Bogota')->format('Y'), $mes['año']);
        $this->assertSame(
            $this->service->monthName((int) now('America/Bogota')->format('n')),
            $mes['mes']
        );
    }

    // ── Evaluación ──────────────────────────────────────────────────────────

    /**
     * OC 2712: 60 kg de NUTTY CARE con entrega el 2026-10-06.
     * Antes comparaba contra agosto (250 kg despachados) y avisaba "excede en 210 kg".
     * Contra octubre no hay nada comprometido: la orden cabe en el pronóstico.
     */
    public function test_oc_2712_no_alerta_porque_entrega_en_octubre(): void
    {
        $r = $this->service->evaluate(pronostico: 100, comprometido: 0, cantidad: 60);

        $this->assertFalse($r['excede']);
        $this->assertSame(0.0, $r['excedente']);
        $this->assertSame(40.0, $r['disponible']);
    }

    /**
     * OC 2685: 200 kg de SOFT CHAMOMILLE entregados en agosto.
     * El acumulado del mes (400 kg) incluía los 200 kg de la propia OC, así que
     * avisaba "excede en 420 kg". Excluyendo la propia orden el excedente es 220.
     */
    public function test_oc_2685_no_cuenta_dos_veces_su_propio_despacho(): void
    {
        $r = $this->service->evaluate(pronostico: 180, comprometido: 200, cantidad: 200);

        $this->assertTrue($r['excede']);
        $this->assertSame(220.0, $r['excedente']);
        $this->assertSame(0.0, $r['disponible']);
    }

    /** OC 2710: 50 kg de FRESH CARE con entrega en septiembre (pronóstico 20 kg). */
    public function test_oc_2710_excede_contra_el_pronostico_de_septiembre(): void
    {
        $r = $this->service->evaluate(pronostico: 20, comprometido: 0, cantidad: 50);

        $this->assertTrue($r['excede']);
        $this->assertSame(30.0, $r['excedente']);
    }

    /**
     * Dos OCs de 50 kg para el mismo mes contra un pronóstico de 20 kg: la segunda
     * debe ver comprometida la primera aunque todavía no se haya despachado.
     */
    public function test_cuenta_lo_comprometido_no_solo_lo_despachado(): void
    {
        $r = $this->service->evaluate(pronostico: 20, comprometido: 50, cantidad: 50);

        $this->assertTrue($r['excede']);
        $this->assertSame(80.0, $r['excedente']);
    }

    public function test_sin_pronostico_no_hay_alerta(): void
    {
        $r = $this->service->evaluate(pronostico: null, comprometido: 500, cantidad: 500);

        $this->assertFalse($r['excede']);
        $this->assertFalse($r['has_forecast']);
    }

    public function test_justo_en_el_limite_no_alerta(): void
    {
        $r = $this->service->evaluate(pronostico: 100, comprometido: 40, cantidad: 60);

        $this->assertFalse($r['excede']);
        $this->assertSame(0.0, $r['disponible']);
    }
}
