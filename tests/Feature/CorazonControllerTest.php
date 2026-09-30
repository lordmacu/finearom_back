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
}
