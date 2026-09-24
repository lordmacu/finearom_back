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

    public function test_se_marca_y_se_desmarca_requiere_creacion_de_piramides(): void
    {
        $project = $this->project();
        $this->assertFalse($project->fresh()->requiere_piramides);

        $this->putJson("/api/projects/{$project->id}", ['requiere_piramides' => true])
            ->assertOk()->assertJsonPath('data.requiere_piramides', true);
        $this->putJson("/api/projects/{$project->id}", ['requiere_piramides' => false])->assertOk();
        $this->assertFalse($project->fresh()->requiere_piramides);
    }

    public function test_el_area_de_evaluaciones_ya_no_suma_dias_ni_se_guarda(): void
    {
        // Proyecto viejo con el área guardada (ya no es asignable)
        $project = $this->project(['fecha_creacion' => '2026-09-01']);
        $project->forceFill(['area_evaluaciones' => 'evaluacion_laundry'])->saveQuietly();

        $this->assertEquals('2026-09-01', app(\App\Services\ProjectTimeService::class)->calculate($project)->toDateString());

        $this->putJson("/api/projects/{$project->id}", ['area_evaluaciones' => 'evaluacion_cabinas'])->assertOk();
        $this->assertSame('evaluacion_laundry', $project->fresh()->area_evaluaciones);
    }
}
