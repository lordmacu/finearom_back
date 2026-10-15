<?php

namespace Tests\Feature;

use App\Models\ProductoFormulaLine;
use App\Models\ProductoTerminado;

/**
 * Un producto terminado puede llevar OTRO producto terminado como ingrediente
 * (además de materias primas y corazones), con costos en cascada y sin ciclos.
 */
class ProductoTerminadoAnidadoTest extends ProductoTerminadoTestCase
{
    private function pt(string $codigo, float $costo = 0): ProductoTerminado
    {
        return ProductoTerminado::create(['codigo' => $codigo, 'nombre' => "PT {$codigo}", 'costo_unitario' => $costo]);
    }

    public function test_una_misma_formula_mezcla_materias_primas_corazones_y_productos(): void
    {
        $mp = $this->materiaPrima(['codigo' => '100000', 'costo_unitario' => 10]);
        $corazon = $this->materiaPrima(['codigo' => '750100', 'tipo' => 'corazon', 'costo_unitario' => 30]);
        $base = $this->pt('14440', 20);
        $padre = $this->pt('585001');

        // cada LÍNEA lleva un solo ingrediente, pero la fórmula puede tener de los tres tipos
        $this->postJson("/api/productos-terminados/{$padre->id}/formula", ['raw_material_id' => $mp->id, 'porcentaje' => 50])->assertStatus(201);
        $this->postJson("/api/productos-terminados/{$padre->id}/formula", ['raw_material_id' => $corazon->id, 'porcentaje' => 20])->assertStatus(201);
        $this->postJson("/api/productos-terminados/{$padre->id}/formula", ['ingrediente_producto_id' => $base->id, 'porcentaje' => 30])->assertStatus(201);

        $res = $this->getJson("/api/productos-terminados/{$padre->id}/formula")->assertOk();
        $this->assertCount(3, $res->json('data.lines'));
        $this->assertEqualsWithDelta(100.0, (float) $res->json('data.suma_porcentajes'), 0.0001);
        $this->assertEqualsWithDelta(17.0, (float) $padre->fresh()->costo_unitario, 0.0001); // 0.5*10 + 0.2*30 + 0.3*20

        $this->postJson("/api/productos-terminados/{$padre->id}/activate")->assertOk();
    }

    public function test_un_producto_puede_llevar_otro_producto_y_su_costo_se_propaga(): void
    {
        $mp = $this->materiaPrima(['codigo' => '100000', 'costo_unitario' => 10]);
        $base = $this->pt('14440');
        $padre = $this->pt('585001');

        $this->postJson("/api/productos-terminados/{$padre->id}/formula", ['ingrediente_producto_id' => $base->id, 'porcentaje' => 40])
            ->assertStatus(201);
        $this->assertDatabaseHas('producto_formula_lines', ['producto_terminado_id' => $padre->id, 'ingrediente_producto_id' => $base->id, 'raw_material_id' => null]);

        // al armar la fórmula de la base, el costo del padre sube solo: 40% de 10 = 4
        $this->postJson("/api/productos-terminados/{$base->id}/formula", ['raw_material_id' => $mp->id, 'porcentaje' => 100])
            ->assertStatus(201);

        $this->assertEqualsWithDelta(10.0, (float) $base->fresh()->costo_unitario, 0.0001);
        $this->assertEqualsWithDelta(4.0, (float) $padre->fresh()->costo_unitario, 0.0001);
    }

    public function test_la_composicion_muestra_el_tipo_y_nombre_del_ingrediente_producto(): void
    {
        $base = $this->pt('14440', 8);
        $padre = $this->pt('585001');
        ProductoFormulaLine::create(['producto_terminado_id' => $padre->id, 'ingrediente_producto_id' => $base->id, 'porcentaje' => 50]);

        $res = $this->getJson("/api/productos-terminados/{$padre->id}/formula")->assertOk();

        $res->assertJsonPath('data.lines.0.tipo_ingrediente', 'producto_terminado')
            ->assertJsonPath('data.lines.0.ingrediente_codigo', '14440')
            ->assertJsonPath('data.lines.0.ingrediente_nombre', 'PT 14440');
        $this->assertEqualsWithDelta(4.0, (float) $res->json('data.costo_total_kg'), 0.0001); // 50% de 8
    }

    public function test_no_permite_ciclos_ni_contenerse_a_si_mismo(): void
    {
        $a = $this->pt('A1');
        $b = $this->pt('B1');
        $c = $this->pt('C1');

        $this->postJson("/api/productos-terminados/{$a->id}/formula", ['ingrediente_producto_id' => $a->id, 'porcentaje' => 10])->assertStatus(422);

        $this->postJson("/api/productos-terminados/{$a->id}/formula", ['ingrediente_producto_id' => $b->id, 'porcentaje' => 10])->assertStatus(201);
        $this->postJson("/api/productos-terminados/{$b->id}/formula", ['ingrediente_producto_id' => $c->id, 'porcentaje' => 10])->assertStatus(201);
        $this->postJson("/api/productos-terminados/{$c->id}/formula", ['ingrediente_producto_id' => $a->id, 'porcentaje' => 10])->assertStatus(422);

        $this->assertSame(0, ProductoFormulaLine::where('producto_terminado_id', $c->id)->count());
    }

    public function test_exige_un_ingrediente_y_no_acepta_los_dos_a_la_vez(): void
    {
        $mp = $this->materiaPrima(['codigo' => '100000']);
        $base = $this->pt('14440');
        $padre = $this->pt('585001');

        $this->postJson("/api/productos-terminados/{$padre->id}/formula", ['porcentaje' => 10])->assertStatus(422);
        $this->postJson("/api/productos-terminados/{$padre->id}/formula", ['raw_material_id' => $mp->id, 'ingrediente_producto_id' => $base->id, 'porcentaje' => 10])->assertStatus(422);
    }

    public function test_no_se_puede_eliminar_un_producto_que_es_ingrediente_de_otro(): void
    {
        $base = $this->pt('14440');
        $padre = $this->pt('585001');
        ProductoFormulaLine::create(['producto_terminado_id' => $padre->id, 'ingrediente_producto_id' => $base->id, 'porcentaje' => 30]);

        $this->deleteJson("/api/productos-terminados/{$base->id}")
            ->assertStatus(422);
        $this->assertDatabaseHas('productos_terminados', ['id' => $base->id]);

        // el padre sí se puede eliminar, y entonces la base queda libre
        $this->deleteJson("/api/productos-terminados/{$padre->id}")->assertOk();
        $this->deleteJson("/api/productos-terminados/{$base->id}")->assertOk();
    }

    public function test_el_comando_de_reproceso_carga_codigos_que_son_productos_terminados(): void
    {
        $mp = $this->materiaPrima(['codigo' => '100000', 'costo_unitario' => 10]);
        $this->pt('14440', 20);
        $producto = ProductoTerminado::create([
            'codigo' => '585001', 'nombre' => 'PADRE', 'activo' => false,
            'observaciones' => 'Ingredientes no encontrados al importar: 14440 (30%).',
        ]);
        ProductoFormulaLine::create(['producto_terminado_id' => $producto->id, 'raw_material_id' => $mp->id, 'porcentaje' => 70]);

        $this->artisan('productos-terminados:reprocesar-faltantes')->assertSuccessful();

        $producto->refresh();
        $this->assertTrue((bool) $producto->activo);
        $this->assertNull($producto->observaciones);
        $this->assertDatabaseHas('producto_formula_lines', ['producto_terminado_id' => $producto->id, 'raw_material_id' => null, 'porcentaje' => 30]);
        $this->assertEqualsWithDelta(13.0, (float) $producto->costo_unitario, 0.0001); // 0.7*10 + 0.3*20
    }

    public function test_si_el_codigo_existe_como_materia_prima_y_como_producto_manda_la_materia_prima(): void
    {
        $this->materiaPrima(['codigo' => '14440', 'costo_unitario' => 5]);
        $this->pt('14440', 99);
        $producto = ProductoTerminado::create([
            'codigo' => '585001', 'nombre' => 'PADRE', 'activo' => false,
            'observaciones' => 'Ingredientes no encontrados al importar: 14440 (100%).',
        ]);

        $this->artisan('productos-terminados:reprocesar-faltantes')->assertSuccessful();

        $linea = ProductoFormulaLine::where('producto_terminado_id', $producto->id)->first();
        $this->assertNotNull($linea->raw_material_id);
        $this->assertNull($linea->ingrediente_producto_id);
        $this->assertEqualsWithDelta(5.0, (float) $producto->fresh()->costo_unitario, 0.0001);
    }

    public function test_el_reproceso_no_carga_un_producto_que_crearia_un_ciclo(): void
    {
        $a = ProductoTerminado::create(['codigo' => 'A1', 'nombre' => 'A', 'activo' => false,
            'observaciones' => 'Ingredientes no encontrados al importar: B1 (100%).']);
        $b = ProductoTerminado::create(['codigo' => 'B1', 'nombre' => 'B', 'activo' => false,
            'observaciones' => 'Ingredientes no encontrados al importar: A1 (100%).']);

        $this->artisan('productos-terminados:reprocesar-faltantes')->assertSuccessful();

        // uno de los dos se carga y el otro queda como faltante: nunca los dos a la vez
        $this->assertSame(1, ProductoFormulaLine::whereIn('producto_terminado_id', [$a->id, $b->id])->count());
        $this->assertSame(1, ProductoTerminado::whereIn('id', [$a->id, $b->id])->where('observaciones', 'like', '%no encontrados%')->count());
    }
}
