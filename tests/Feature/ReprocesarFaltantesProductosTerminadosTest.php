<?php

namespace Tests\Feature;

use App\Models\ProductoFormulaLine;
use App\Models\ProductoTerminado;
use App\Models\RawMaterial;

/**
 * El listado de "ingredientes faltantes" es una foto del momento de importar: este comando la
 * reevalúa contra todo lo que existe hoy (materias primas y corazones).
 */
class ReprocesarFaltantesProductosTerminadosTest extends ProductoTerminadoTestCase
{
    private function producto(string $codigo, array $lineas, string $nota): ProductoTerminado
    {
        $p = ProductoTerminado::create(['codigo' => $codigo, 'nombre' => "P{$codigo}", 'observaciones' => $nota, 'activo' => false]);
        foreach ($lineas as $rmId => $pct) {
            ProductoFormulaLine::create(['producto_terminado_id' => $p->id, 'raw_material_id' => $rmId, 'porcentaje' => $pct]);
        }

        return $p;
    }

    public function test_carga_lo_que_ya_existe_como_materia_prima_o_corazon_y_activa_si_queda_completo(): void
    {
        $base = $this->materiaPrima(['codigo' => '100000', 'costo_unitario' => 10]);
        $this->materiaPrima(['codigo' => '800015', 'costo_unitario' => 20]);
        $this->materiaPrima(['codigo' => '750100', 'tipo' => 'corazon', 'costo_unitario' => 30]);
        $p = $this->producto('A1', [$base->id => 70], 'Ingredientes no encontrados al importar: 800015 (20%), 750100 (10%).');

        $this->artisan('productos-terminados:reprocesar-faltantes')->assertSuccessful();

        $p->refresh();
        $this->assertTrue((bool) $p->activo);
        $this->assertNull($p->observaciones);
        $this->assertSame(3, ProductoFormulaLine::where('producto_terminado_id', $p->id)->count());
        $this->assertEqualsWithDelta(14.0, (float) $p->costo_unitario, 0.0001); // 0.7*10 + 0.2*20 + 0.1*30 = 14
    }

    public function test_deja_anotado_lo_que_sigue_sin_existir_y_no_activa(): void
    {
        $base = $this->materiaPrima(['codigo' => '100000']);
        $this->materiaPrima(['codigo' => '800015']);
        $p = $this->producto('B1', [$base->id => 60], "Otra nota.\nIngredientes no encontrados al importar: 800015 (30%), 122948 (10%).");

        $this->artisan('productos-terminados:reprocesar-faltantes')->assertSuccessful();

        $p->refresh();
        $this->assertFalse((bool) $p->activo);
        $this->assertStringContainsString('Otra nota.', $p->observaciones);
        $this->assertStringContainsString('Ingredientes no encontrados al importar: 122948 (10%).', $p->observaciones);
        $this->assertStringNotContainsString('800015', $p->observaciones);
        $this->assertSame(2, ProductoFormulaLine::where('producto_terminado_id', $p->id)->count());
    }

    public function test_completo_pero_que_no_suma_100_queda_en_borrador_con_aviso(): void
    {
        $base = $this->materiaPrima(['codigo' => '100000']);
        $this->materiaPrima(['codigo' => '800015']);
        $p = $this->producto('C1', [$base->id => 50], 'Ingredientes no encontrados al importar: 800015 (20%).');

        $this->artisan('productos-terminados:reprocesar-faltantes')->assertSuccessful();

        $p->refresh();
        $this->assertFalse((bool) $p->activo);
        $this->assertStringContainsString('La fórmula suma 70%.', $p->observaciones);
        $this->assertStringNotContainsString('no encontrados', $p->observaciones);
    }

    public function test_dry_run_no_escribe_nada_y_es_idempotente(): void
    {
        $base = $this->materiaPrima(['codigo' => '100000']);
        $this->materiaPrima(['codigo' => '800015']);
        $p = $this->producto('D1', [$base->id => 80], 'Ingredientes no encontrados al importar: 800015 (20%).');

        $this->artisan('productos-terminados:reprocesar-faltantes', ['--dry-run' => true])->assertSuccessful();
        $this->assertFalse((bool) $p->fresh()->activo);
        $this->assertSame(1, ProductoFormulaLine::where('producto_terminado_id', $p->id)->count());

        $this->artisan('productos-terminados:reprocesar-faltantes')->assertSuccessful();
        $this->artisan('productos-terminados:reprocesar-faltantes')->assertSuccessful(); // segunda corrida: nada que hacer
        $this->assertTrue((bool) $p->fresh()->activo);
        $this->assertSame(2, ProductoFormulaLine::where('producto_terminado_id', $p->id)->count());
    }

    public function test_suma_el_porcentaje_si_el_ingrediente_ya_estaba_en_la_formula(): void
    {
        $base = $this->materiaPrima(['codigo' => '100000']);
        $p = $this->producto('E1', [$base->id => 90], 'Ingredientes no encontrados al importar: 100000 (10%).');

        $this->artisan('productos-terminados:reprocesar-faltantes')->assertSuccessful();

        $this->assertEqualsWithDelta(100.0, (float) ProductoFormulaLine::where('producto_terminado_id', $p->id)->value('porcentaje'), 0.0001);
        $this->assertTrue((bool) $p->fresh()->activo);
    }

    public function test_no_toca_productos_cuyos_faltantes_siguen_sin_existir(): void
    {
        $base = $this->materiaPrima(['codigo' => '100000']);
        $nota = 'Ingredientes no encontrados al importar: 122948 (20%).';
        $p = $this->producto('F1', [$base->id => 80], $nota);

        $this->artisan('productos-terminados:reprocesar-faltantes')->assertSuccessful();

        $this->assertSame($nota, $p->fresh()->observaciones);
        $this->assertSame(1, ProductoFormulaLine::where('producto_terminado_id', $p->id)->count());
    }
}
