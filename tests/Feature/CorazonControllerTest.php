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
}
