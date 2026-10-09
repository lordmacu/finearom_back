<?php

namespace Tests\Feature;

use App\Models\CorazonFormulaLine;
use App\Models\RawMaterial;

class CorazonControllerTest extends CorazonTestCase
{
    private function materiaPrima(array $overrides = []): RawMaterial
    {
        return RawMaterial::create(array_merge([
            'codigo'  => 'MP-001',
            'nombre'  => 'Bergamota',
            'tipo'    => 'materia_prima',
            'unidad'  => 'kg',
            'activo'  => true,
        ], $overrides));
    }

    public function test_crear_corazon_queda_en_borrador(): void
    {
        $response = $this->postJson('/api/corazones', [
            'codigo'      => 'COR-001',
            'nombre'      => 'Corazón Floral',
            'descripcion' => 'Salida: bergamota. Corazón: jazmín. Fondo: almizcle.',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.tipo', 'corazon');
        $response->assertJsonPath('data.activo', false);
        $response->assertJsonPath('data.unidad', 'kg');

        $this->assertDatabaseHas('raw_materials', [
            'codigo' => 'COR-001',
            'tipo'   => 'corazon',
            'activo' => 0,
        ]);
    }

    public function test_crear_falla_sin_descripcion(): void
    {
        $this->postJson('/api/corazones', [
            'codigo' => 'COR-002',
            'nombre' => 'Corazón sin descripción',
        ])->assertStatus(422)->assertJsonValidationErrors(['descripcion']);
    }

    public function test_crear_falla_con_codigo_duplicado_de_materia_prima(): void
    {
        $this->materiaPrima(['codigo' => 'MP-999']);

        $this->postJson('/api/corazones', [
            'codigo'      => 'MP-999',
            'nombre'      => 'Corazón Duplicado',
            'descripcion' => 'Descripción cualquiera',
        ])->assertStatus(422)->assertJsonValidationErrors(['codigo']);
    }

    public function test_index_solo_lista_corazones(): void
    {
        $this->materiaPrima();
        RawMaterial::create([
            'codigo' => 'COR-010', 'nombre' => 'Corazón A', 'tipo' => 'corazon',
            'unidad' => 'kg', 'descripcion' => 'x', 'activo' => false,
        ]);

        $response = $this->getJson('/api/corazones?activo=all');

        $response->assertOk();
        $data = $response->json('data.data');
        $this->assertCount(1, $data);
        $this->assertEquals('COR-010', $data[0]['codigo']);
    }

    public function test_update_edita_datos_basicos(): void
    {
        $corazon = RawMaterial::create([
            'codigo' => 'COR-020', 'nombre' => 'Original', 'tipo' => 'corazon',
            'unidad' => 'kg', 'descripcion' => 'x', 'activo' => false,
        ]);

        $this->putJson("/api/corazones/{$corazon->id}", [
            'nombre' => 'Renombrado',
        ])->assertOk()->assertJsonPath('data.nombre', 'Renombrado');
    }

    public function test_show_update_destroy_con_id_de_materia_prima_da_404(): void
    {
        $mp = $this->materiaPrima();

        $this->getJson("/api/corazones/{$mp->id}")->assertNotFound();
        $this->putJson("/api/corazones/{$mp->id}", ['nombre' => 'X'])->assertNotFound();
        $this->deleteJson("/api/corazones/{$mp->id}")->assertNotFound();
    }

    public function test_destroy_bloquea_si_es_usado_como_ingrediente(): void
    {
        $ingrediente = RawMaterial::create([
            'codigo' => 'COR-030', 'nombre' => 'Ingrediente', 'tipo' => 'corazon',
            'unidad' => 'kg', 'descripcion' => 'x', 'activo' => false,
        ]);
        $contenedor = RawMaterial::create([
            'codigo' => 'COR-031', 'nombre' => 'Contenedor', 'tipo' => 'corazon',
            'unidad' => 'kg', 'descripcion' => 'x', 'activo' => false,
        ]);
        CorazonFormulaLine::create([
            'corazon_id' => $contenedor->id,
            'raw_material_id' => $ingrediente->id,
            'porcentaje' => 50,
        ]);

        $this->deleteJson("/api/corazones/{$ingrediente->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'No se puede eliminar: este corazón se usa como ingrediente en otra fórmula.');

        $this->assertDatabaseHas('raw_materials', ['id' => $ingrediente->id]);
    }

    public function test_destroy_elimina_corazon_libre(): void
    {
        $corazon = RawMaterial::create([
            'codigo' => 'COR-040', 'nombre' => 'Libre', 'tipo' => 'corazon',
            'unidad' => 'kg', 'descripcion' => 'x', 'activo' => false,
        ]);

        $this->deleteJson("/api/corazones/{$corazon->id}")->assertOk();

        $this->assertDatabaseMissing('raw_materials', ['id' => $corazon->id]);
    }

    private function corazonConIngredientes(float ...$porcentajes): RawMaterial
    {
        $corazon = RawMaterial::create([
            'codigo' => 'COR-'.uniqid(), 'nombre' => 'Mezcla', 'tipo' => 'corazon',
            'unidad' => 'kg', 'descripcion' => 'x', 'activo' => false,
        ]);

        foreach ($porcentajes as $i => $pct) {
            $ingrediente = $this->materiaPrima(['codigo' => 'MP-'.uniqid().$i, 'nombre' => "Ingrediente {$i}"]);
            CorazonFormulaLine::create([
                'corazon_id'      => $corazon->id,
                'raw_material_id' => $ingrediente->id,
                'porcentaje'      => $pct,
            ]);
        }

        return $corazon;
    }

    public function test_activar_exitoso_con_suma_exacta_100(): void
    {
        $corazon = $this->corazonConIngredientes(33.34, 33.33, 33.33);

        $this->postJson("/api/corazones/{$corazon->id}/activate")
            ->assertOk()
            ->assertJsonPath('data.activo', true);

        $this->assertDatabaseHas('raw_materials', ['id' => $corazon->id, 'activo' => 1]);
    }

    public function test_activar_falla_con_suma_en_el_limite_99_99(): void
    {
        $corazon = $this->corazonConIngredientes(33.33, 33.33, 33.33);

        $this->postJson("/api/corazones/{$corazon->id}/activate")
            ->assertStatus(422);

        $this->assertDatabaseHas('raw_materials', ['id' => $corazon->id, 'activo' => 0]);
    }

    public function test_activar_falla_sin_ingredientes(): void
    {
        $corazon = RawMaterial::create([
            'codigo' => 'COR-050', 'nombre' => 'Vacío', 'tipo' => 'corazon',
            'unidad' => 'kg', 'descripcion' => 'x', 'activo' => false,
        ]);

        $this->postJson("/api/corazones/{$corazon->id}/activate")->assertStatus(422);
    }

    public function test_activate_deactivate_con_id_de_materia_prima_da_404(): void
    {
        $mp = $this->materiaPrima();

        $this->postJson("/api/corazones/{$mp->id}/activate")->assertNotFound();
        $this->postJson("/api/corazones/{$mp->id}/deactivate")->assertNotFound();
    }

    public function test_deactivate_no_valida_suma(): void
    {
        $corazon = $this->corazonConIngredientes(50);
        $corazon->update(['activo' => true]);

        $this->postJson("/api/corazones/{$corazon->id}/deactivate")
            ->assertOk()
            ->assertJsonPath('data.activo', false);
    }

    public function test_un_corazon_puede_llevar_otro_corazon_y_su_costo_se_propaga(): void
    {
        config(['custom.corazones_calcular_costos' => true]); // el cálculo está apagado por defecto
        $mp = RawMaterial::create(['codigo' => 'MP-1', 'nombre' => 'MP', 'tipo' => 'materia_prima', 'unidad' => 'kg', 'costo_unitario' => 10, 'activo' => true]);
        $hijo = RawMaterial::create(['codigo' => 'COR-060', 'nombre' => 'Hijo', 'tipo' => 'corazon', 'unidad' => 'kg', 'descripcion' => 'x', 'activo' => false]);
        $padre = RawMaterial::create(['codigo' => 'COR-061', 'nombre' => 'Padre', 'tipo' => 'corazon', 'unidad' => 'kg', 'descripcion' => 'x', 'activo' => false]);

        $this->postJson("/api/raw-materials/{$padre->id}/corazon-formula", ['raw_material_id' => $hijo->id, 'porcentaje' => 50])
            ->assertStatus(201);
        $this->assertDatabaseHas('corazon_formula_lines', ['corazon_id' => $padre->id, 'raw_material_id' => $hijo->id]);

        // al cambiar la fórmula del hijo, el costo del padre sube solo
        $this->postJson("/api/raw-materials/{$hijo->id}/corazon-formula", ['raw_material_id' => $mp->id, 'porcentaje' => 100])
            ->assertStatus(201);

        $this->assertEqualsWithDelta(10.0, (float) $hijo->fresh()->costo_unitario, 0.0001);
        $this->assertEqualsWithDelta(5.0, (float) $padre->fresh()->costo_unitario, 0.0001); // 50% de 10
    }

    public function test_un_corazon_no_puede_contenerse_a_si_mismo_ni_en_ciclo(): void
    {
        $a = RawMaterial::create(['codigo' => 'COR-070', 'nombre' => 'A', 'tipo' => 'corazon', 'unidad' => 'kg', 'descripcion' => 'x', 'activo' => false]);
        $b = RawMaterial::create(['codigo' => 'COR-071', 'nombre' => 'B', 'tipo' => 'corazon', 'unidad' => 'kg', 'descripcion' => 'x', 'activo' => false]);
        $c = RawMaterial::create(['codigo' => 'COR-072', 'nombre' => 'C', 'tipo' => 'corazon', 'unidad' => 'kg', 'descripcion' => 'x', 'activo' => false]);

        $this->postJson("/api/raw-materials/{$a->id}/corazon-formula", ['raw_material_id' => $a->id, 'porcentaje' => 10])
            ->assertStatus(422);

        // A contiene B, B contiene C: C no puede contener a A
        $this->postJson("/api/raw-materials/{$a->id}/corazon-formula", ['raw_material_id' => $b->id, 'porcentaje' => 10])->assertStatus(201);
        $this->postJson("/api/raw-materials/{$b->id}/corazon-formula", ['raw_material_id' => $c->id, 'porcentaje' => 10])->assertStatus(201);
        $this->postJson("/api/raw-materials/{$c->id}/corazon-formula", ['raw_material_id' => $a->id, 'porcentaje' => 10])
            ->assertStatus(422);

        $this->assertDatabaseMissing('corazon_formula_lines', ['corazon_id' => $c->id, 'raw_material_id' => $a->id]);
    }

    public function test_destroy_bloquea_si_es_usado_como_ingrediente_de_producto_terminado(): void
    {
        $corazon = RawMaterial::create([
            'codigo' => 'COR-070', 'nombre' => 'Usado en PT', 'tipo' => 'corazon',
            'unidad' => 'kg', 'descripcion' => 'x', 'activo' => false,
        ]);
        $producto = \App\Models\ProductoTerminado::create(['codigo' => 'PROD-100', 'nombre' => 'Usa corazón']);
        \App\Models\ProductoFormulaLine::create([
            'producto_terminado_id' => $producto->id,
            'raw_material_id'       => $corazon->id,
            'porcentaje'            => 40,
        ]);

        $this->deleteJson("/api/corazones/{$corazon->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'No se puede eliminar: este corazón se usa como ingrediente en otra fórmula.');

        $this->assertDatabaseHas('raw_materials', ['id' => $corazon->id]);
    }

    public function test_editar_formula_de_corazon_recalcula_producto_terminado_que_lo_usa(): void
    {
        config(['custom.corazones_calcular_costos' => true]); // el cálculo está apagado por defecto
        $mp = $this->materiaPrima(['costo_unitario' => 10, 'unidad' => 'kg']);
        $corazon = RawMaterial::create([
            'codigo' => 'COR-071', 'nombre' => 'Recalculo', 'tipo' => 'corazon',
            'unidad' => 'kg', 'descripcion' => 'x', 'activo' => false,
        ]);
        $producto = \App\Models\ProductoTerminado::create(['codigo' => 'PROD-101', 'nombre' => 'Depende de corazón']);
        \App\Models\ProductoFormulaLine::create([
            'producto_terminado_id' => $producto->id,
            'raw_material_id'       => $corazon->id,
            'porcentaje'            => 50,
        ]);

        $this->assertEquals(0.0, (float) $producto->fresh()->costo_unitario);

        $this->postJson("/api/raw-materials/{$corazon->id}/corazon-formula", [
            'raw_material_id' => $mp->id,
            'porcentaje'      => 100,
        ])->assertCreated();

        // Corazón pasa a costar 10 (100% de una materia a 10); producto: 50% de 10 => 5.
        $this->assertEquals(10.0, (float) $corazon->fresh()->costo_unitario);
        $this->assertEquals(5.0, (float) $producto->fresh()->costo_unitario);
    }
}
