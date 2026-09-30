<?php

namespace Tests\Feature;

use App\Models\ProductoTerminado;

class ProductoTerminadoControllerTest extends ProductoTerminadoTestCase
{
    public function test_crear_producto_terminado_genera_consecutivo(): void
    {
        $response = $this->postJson('/api/productos-terminados', [
            'codigo' => 'PROD-001',
            'nombre' => 'Colonia Floral 100ml',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.consecutivo', 'PT-'.str_pad($response->json('data.id'), 4, '0', STR_PAD_LEFT));
        $response->assertJsonPath('data.activo', false);

        $this->assertDatabaseHas('productos_terminados', [
            'codigo' => 'PROD-001',
            'activo' => 0,
        ]);
    }

    public function test_consecutivos_son_distintos_entre_dos_creaciones(): void
    {
        $r1 = $this->postJson('/api/productos-terminados', ['codigo' => 'PROD-010', 'nombre' => 'A']);
        $r2 = $this->postJson('/api/productos-terminados', ['codigo' => 'PROD-011', 'nombre' => 'B']);

        $this->assertNotEquals($r1->json('data.consecutivo'), $r2->json('data.consecutivo'));
        $this->assertEquals('PT-0001', $r1->json('data.consecutivo'));
        $this->assertEquals('PT-0002', $r2->json('data.consecutivo'));
    }

    public function test_crear_falla_con_codigo_duplicado(): void
    {
        ProductoTerminado::create(['codigo' => 'PROD-020', 'nombre' => 'Existente']);

        $this->postJson('/api/productos-terminados', [
            'codigo' => 'PROD-020',
            'nombre' => 'Duplicado',
        ])->assertStatus(422)->assertJsonValidationErrors(['codigo']);
    }

    public function test_index_filtra_por_estado_y_por_defecto_muestra_todos(): void
    {
        ProductoTerminado::create(['codigo' => 'PROD-030', 'nombre' => 'Borrador', 'activo' => false]);
        ProductoTerminado::create(['codigo' => 'PROD-031', 'nombre' => 'Activo', 'activo' => true]);

        $this->getJson('/api/productos-terminados')->assertOk()
            ->assertJsonCount(2, 'data.data');

        $this->getJson('/api/productos-terminados?activo=true')->assertOk()
            ->assertJsonCount(1, 'data.data');
    }

    public function test_update_edita_datos_basicos(): void
    {
        $producto = ProductoTerminado::create(['codigo' => 'PROD-040', 'nombre' => 'Original']);

        $this->putJson("/api/productos-terminados/{$producto->id}", ['nombre' => 'Renombrado'])
            ->assertOk()
            ->assertJsonPath('data.nombre', 'Renombrado');
    }

    public function test_destroy_no_tiene_restricciones(): void
    {
        $producto = ProductoTerminado::create(['codigo' => 'PROD-050', 'nombre' => 'Libre']);

        $this->deleteJson("/api/productos-terminados/{$producto->id}")->assertOk();

        $this->assertDatabaseMissing('productos_terminados', ['id' => $producto->id]);
    }

    private function productoConIngredientes(float ...$porcentajes): ProductoTerminado
    {
        $producto = ProductoTerminado::create(['codigo' => 'PROD-'.uniqid(), 'nombre' => 'Mezcla']);

        foreach ($porcentajes as $i => $pct) {
            $ingrediente = $this->materiaPrima(['nombre' => "Ingrediente {$i}"]);
            \App\Models\ProductoFormulaLine::create([
                'producto_terminado_id' => $producto->id,
                'raw_material_id'       => $ingrediente->id,
                'porcentaje'            => $pct,
            ]);
        }

        return $producto;
    }

    public function test_activar_exitoso_con_suma_exacta_100(): void
    {
        $producto = $this->productoConIngredientes(33.34, 33.33, 33.33);

        $this->postJson("/api/productos-terminados/{$producto->id}/activate")
            ->assertOk()
            ->assertJsonPath('data.activo', true);
    }

    public function test_activar_falla_con_suma_en_el_limite_99_99(): void
    {
        $producto = $this->productoConIngredientes(33.33, 33.33, 33.33);

        $this->postJson("/api/productos-terminados/{$producto->id}/activate")->assertStatus(422);

        $this->assertDatabaseHas('productos_terminados', ['id' => $producto->id, 'activo' => 0]);
    }

    public function test_activar_falla_sin_ingredientes(): void
    {
        $producto = ProductoTerminado::create(['codigo' => 'PROD-060', 'nombre' => 'Vacío']);

        $this->postJson("/api/productos-terminados/{$producto->id}/activate")->assertStatus(422);
    }

    public function test_deactivate_no_valida_suma(): void
    {
        $producto = $this->productoConIngredientes(50);
        $producto->update(['activo' => true]);

        $this->postJson("/api/productos-terminados/{$producto->id}/deactivate")
            ->assertOk()
            ->assertJsonPath('data.activo', false);
    }

    public function test_agregar_y_eliminar_ingrediente_actualiza_costo_del_producto(): void
    {
        $producto = ProductoTerminado::create(['codigo' => 'PROD-070', 'nombre' => 'Con ingredientes']);
        $mp = $this->materiaPrima(['costo_unitario' => 10, 'unidad' => 'kg']);

        $addResponse = $this->postJson("/api/productos-terminados/{$producto->id}/formula", [
            'raw_material_id' => $mp->id,
            'porcentaje'      => 50,
        ]);
        $addResponse->assertCreated();

        $this->assertEquals(5.0, (float) $producto->fresh()->costo_unitario);

        $line = $addResponse->json('data.id');
        $this->deleteJson("/api/productos-terminados/{$producto->id}/formula/{$line}")->assertOk();

        $this->assertEquals(0.0, (float) $producto->fresh()->costo_unitario);
    }

    public function test_formula_incluye_corazon_como_ingrediente(): void
    {
        $producto = ProductoTerminado::create(['codigo' => 'PROD-080', 'nombre' => 'Con corazón']);
        $corazon  = $this->corazon(['costo_unitario' => 20]);

        $this->postJson("/api/productos-terminados/{$producto->id}/formula", [
            'raw_material_id' => $corazon->id,
            'porcentaje'      => 25,
        ])->assertCreated();

        $response = $this->getJson("/api/productos-terminados/{$producto->id}/formula");
        $response->assertOk();
        $this->assertEquals('corazon', $response->json('data.lines.0.raw_material.tipo'));
    }

    public function test_cambiar_costo_de_materia_prima_recalcula_producto_terminado_directo(): void
    {
        $producto = ProductoTerminado::create(['codigo' => 'PROD-090', 'nombre' => 'Directo']);
        $mp = $this->materiaPrima(['costo_unitario' => 10, 'unidad' => 'kg']);
        \App\Models\ProductoFormulaLine::create([
            'producto_terminado_id' => $producto->id,
            'raw_material_id'       => $mp->id,
            'porcentaje'            => 50,
        ]);

        $this->postJson("/api/raw-materials/{$mp->id}/update-cost", ['costo_unitario' => 20])->assertOk();

        $this->assertEquals(10.0, (float) $producto->fresh()->costo_unitario);
    }

    public function test_cambiar_costo_de_materia_prima_recalcula_producto_terminado_via_corazon(): void
    {
        $mp = $this->materiaPrima(['costo_unitario' => 10, 'unidad' => 'kg']);
        $corazon = $this->corazon();
        \App\Models\CorazonFormulaLine::create([
            'corazon_id'      => $corazon->id,
            'raw_material_id' => $mp->id,
            'porcentaje'      => 100,
        ]);
        $producto = ProductoTerminado::create(['codigo' => 'PROD-091', 'nombre' => 'Vía corazón']);
        \App\Models\ProductoFormulaLine::create([
            'producto_terminado_id' => $producto->id,
            'raw_material_id'       => $corazon->id,
            'porcentaje'            => 50,
        ]);

        // Costo inicial del corazón: 100% de una materia prima a 10 => 10.
        $this->assertEquals(0.0, (float) $producto->fresh()->costo_unitario); // aún no se ha recalculado

        $this->postJson("/api/raw-materials/{$mp->id}/update-cost", ['costo_unitario' => 40])->assertOk();

        // Corazón pasa a costar 40 (100% de 40); producto terminado: 50% de 40 => 20.
        $this->assertEquals(40.0, (float) $corazon->fresh()->costo_unitario);
        $this->assertEquals(20.0, (float) $producto->fresh()->costo_unitario);
    }
}
