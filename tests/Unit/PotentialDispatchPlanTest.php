<?php

namespace Tests\Unit;

use App\Support\PotentialDispatchPlan;
use PHPUnit\Framework\TestCase;

class PotentialDispatchPlanTest extends TestCase
{
    private function meses(?array $plan): array
    {
        return collect($plan['meses'])->filter(fn ($m) => $m['kg'] > 0)->pluck('kg', 'mes')->all();
    }

    public function test_trimestral_desde_junio_reparte_un_cuarto_del_anual_en_junio_septiembre_y_diciembre(): void
    {
        $plan = PotentialDispatchPlan::for(1200, 20, 'trimestral', '2026-06-01', 2026);

        $this->assertSame([6 => 300.0, 9 => 300.0, 12 => 300.0], $this->meses($plan));
        $this->assertSame(6000.0, $plan['meses'][5]['usd']);
        $this->assertSame(900.0, $plan['total_kg']);
        $this->assertSame(18000.0, $plan['total_usd']);
        $this->assertSame(3, $plan['despachos']);
    }

    public function test_los_anios_siguientes_siguen_la_misma_cadencia(): void
    {
        $plan = PotentialDispatchPlan::for(1200, 20, 'trimestral', '2026-06-01', 2027);

        $this->assertSame([3 => 300.0, 6 => 300.0, 9 => 300.0, 12 => 300.0], $this->meses($plan));
        $this->assertSame(1200.0, $plan['total_kg']);
    }

    public function test_antes_del_primer_despacho_todo_queda_en_cero(): void
    {
        $plan = PotentialDispatchPlan::for(1200, 20, 'mensual', '2027-02-01', 2026);

        $this->assertSame([], $this->meses($plan));
        $this->assertSame(0.0, $plan['total_usd']);
        $this->assertSame(0, $plan['despachos']);
    }

    public function test_frecuencias(): void
    {
        $this->assertSame([1 => 100.0, 2 => 100.0, 3 => 100.0, 4 => 100.0, 5 => 100.0, 6 => 100.0, 7 => 100.0, 8 => 100.0, 9 => 100.0, 10 => 100.0, 11 => 100.0, 12 => 100.0],
            $this->meses(PotentialDispatchPlan::for(1200, 1, 'mensual', '2026-01-01', 2026)));
        $this->assertSame([2 => 200.0, 4 => 200.0, 6 => 200.0, 8 => 200.0, 10 => 200.0, 12 => 200.0],
            $this->meses(PotentialDispatchPlan::for(1200, 1, 'bimensual', '2026-02-01', 2026)));
        $this->assertSame([5 => 400.0, 9 => 400.0],
            $this->meses(PotentialDispatchPlan::for(1200, 1, 'cuatrimestral', '2026-05-01', 2026)));
        $this->assertSame([3 => 600.0, 9 => 600.0],
            $this->meses(PotentialDispatchPlan::for(1200, 1, 'semestral', '2026-03-01', 2026)));
        $this->assertSame([11 => 1200.0],
            $this->meses(PotentialDispatchPlan::for(1200, 1, 'anual', '2025-11-01', 2026)));
    }

    public function test_una_vez_despacha_todo_solo_en_el_mes_del_primer_despacho(): void
    {
        $this->assertSame([4 => 1200.0], $this->meses(PotentialDispatchPlan::for(1200, 1, 'una_vez', '2026-04-01', 2026)));
        $this->assertSame([], $this->meses(PotentialDispatchPlan::for(1200, 1, 'una_vez', '2026-04-01', 2027)));
    }

    public function test_sin_precio_calcula_kg_pero_no_usd(): void
    {
        $plan = PotentialDispatchPlan::for(1200, null, 'semestral', '2026-01-01', 2026);

        $this->assertSame(1200.0, $plan['total_kg']);
        $this->assertNull($plan['total_usd']);
        $this->assertNull($plan['meses'][0]['usd']);
    }

    public function test_sin_kg_frecuencia_o_fecha_no_hay_plan(): void
    {
        $this->assertNull(PotentialDispatchPlan::for(null, 20, 'mensual', '2026-01-01', 2026));
        $this->assertNull(PotentialDispatchPlan::for(1200, 20, null, '2026-01-01', 2026));
        $this->assertNull(PotentialDispatchPlan::for(1200, 20, 'mensual', null, 2026));
    }
}
