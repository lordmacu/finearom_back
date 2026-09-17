<?php

namespace Tests\Feature\Projects;

use App\Models\Project;

class ProjectPotencialKgTest extends ProjectMailTestCase
{
    public function test_crear_proyecto_guarda_el_potencial_anual_en_kg(): void
    {
        $id = $this->postJson('/api/projects', [
            'nombre'             => 'Proyecto Potencial',
            'tipo'               => 'Desarrollo',
            'nombre_prospecto'   => 'Prospecto P',
            'potencial_anual_kg' => 1250.5,
            'potencial_anual_usd' => 30000,
        ])->assertCreated()->json('data.id');

        $this->assertEquals(1250.5, Project::find($id)->potencial_anual_kg);
        $this->assertEquals(30000, Project::find($id)->potencial_anual_usd);
    }

    public function test_editar_proyecto_actualiza_el_potencial_anual_en_kg(): void
    {
        $project = Project::create(['nombre' => 'Proyecto Edit', 'fecha_creacion' => today()]);

        $this->putJson("/api/projects/{$project->id}", ['potencial_anual_kg' => 800])->assertOk();

        $this->assertEquals(800, $project->fresh()->potencial_anual_kg);
    }
}
