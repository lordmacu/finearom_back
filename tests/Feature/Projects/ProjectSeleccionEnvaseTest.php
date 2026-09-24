<?php

namespace Tests\Feature\Projects;

class ProjectSeleccionEnvaseTest extends ProjectMailTestCase
{
    public function test_se_marca_y_se_desmarca_la_seleccion_de_envase_para_aplicacion(): void
    {
        $project = $this->project();
        $this->assertFalse($project->fresh()->seleccion_envase_aplicacion);

        $this->putJson("/api/projects/{$project->id}", ['seleccion_envase_aplicacion' => true])
            ->assertOk()
            ->assertJsonPath('data.seleccion_envase_aplicacion', true);
        $this->assertTrue($project->fresh()->seleccion_envase_aplicacion);

        $this->putJson("/api/projects/{$project->id}", ['seleccion_envase_aplicacion' => false])->assertOk();
        $this->assertFalse($project->fresh()->seleccion_envase_aplicacion);

        $this->putJson("/api/projects/{$project->id}", ['seleccion_envase_aplicacion' => 'quizas'])
            ->assertStatus(422)->assertJsonValidationErrors('seleccion_envase_aplicacion');
    }
}
