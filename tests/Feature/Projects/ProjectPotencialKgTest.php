<?php

namespace Tests\Feature\Projects;

use App\Models\Project;

class ProjectPotencialKgTest extends ProjectMailTestCase
{
    public function test_crear_proyecto_calcula_el_potencial_usd_con_rango_maximo_por_kg(): void
    {
        $id = $this->postJson('/api/projects', [
            'nombre'              => 'Proyecto Potencial',
            'tipo'                => 'Desarrollo',
            'nombre_prospecto'    => 'Prospecto P',
            'potencial_anual_kg'  => 1250.5,
            'rango_min'           => 10,
            'rango_max'           => 20,
            // Se ignora: el precio ya no cuenta
            'precio'              => 999,
            // Se ignora: el USD siempre se calcula
            'potencial_anual_usd' => 99999,
        ])->assertCreated()->json('data.id');

        $project = Project::find($id);
        $this->assertEquals(1250.5, $project->potencial_anual_kg);
        $this->assertEquals(25010, $project->potencial_anual_usd);
    }

    public function test_sin_rango_maximo_o_sin_kg_el_potencial_usd_queda_vacio(): void
    {
        $id = $this->postJson('/api/projects', [
            'nombre'             => 'Proyecto Sin Rango',
            'tipo'               => 'Desarrollo',
            'nombre_prospecto'   => 'Prospecto P',
            'potencial_anual_kg' => 500,
        ])->assertCreated()->json('data.id');

        $this->assertNull(Project::find($id)->potencial_anual_usd);
    }

    public function test_editar_kg_o_rango_maximo_recalcula_el_potencial_usd(): void
    {
        $project = Project::create(['nombre' => 'Proyecto Edit', 'fecha_creacion' => today(), 'rango_max' => 10]);

        $this->putJson("/api/projects/{$project->id}", ['potencial_anual_kg' => 800])->assertOk();
        $this->assertEquals(800, $project->fresh()->potencial_anual_kg);
        $this->assertEquals(8000, $project->fresh()->potencial_anual_usd);

        $this->putJson("/api/projects/{$project->id}", ['rango_max' => 2.5])->assertOk();
        $this->assertEquals(2000, $project->fresh()->potencial_anual_usd);

        $this->putJson("/api/projects/{$project->id}", ['potencial_anual_kg' => null])->assertOk();
        $this->assertNull($project->fresh()->potencial_anual_usd);
    }

    public function test_el_potencial_usd_no_se_escribe_a_mano(): void
    {
        $project = Project::create(['nombre' => 'Proyecto Manual', 'fecha_creacion' => today(), 'rango_max' => 10, 'potencial_anual_kg' => 100]);

        $this->putJson("/api/projects/{$project->id}", ['potencial_anual_usd' => 5])->assertOk();

        $this->assertEquals(1000, $project->fresh()->potencial_anual_usd);
    }

    public function test_el_rango_maximo_puede_ir_sin_minimo_pero_no_por_debajo_de_el(): void
    {
        $project = Project::create(['nombre' => 'Rango', 'fecha_creacion' => today(), 'potencial_anual_kg' => 10]);

        // Solo máximo: válido y ya calcula el USD
        $this->putJson("/api/projects/{$project->id}", ['rango_max' => 7])->assertOk();
        $this->assertEquals(70, $project->fresh()->potencial_anual_usd);

        // Máximo menor que el mínimo guardado: se rechaza
        $project->update(['rango_min' => 5]);
        $this->putJson("/api/projects/{$project->id}", ['rango_max' => 4])
            ->assertStatus(422)->assertJsonValidationErrors('rango_max');
    }
}
