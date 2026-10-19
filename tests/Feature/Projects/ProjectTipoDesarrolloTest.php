<?php

namespace Tests\Feature\Projects;

use App\Models\Project;

/** Tipo de desarrollo: además de "desde cero" y "ajuste de fórmula", Evaluación y Solicitud de muestras. */
class ProjectTipoDesarrolloTest extends ProjectMailTestCase
{
    public function test_acepta_evaluacion_y_solicitud_de_muestras_al_editar(): void
    {
        $project = $this->project();

        foreach (['evaluacion', 'solicitud_muestras', 'desde_cero', 'ajuste_formula'] as $tipo) {
            $this->putJson("/api/projects/{$project->id}", ['tipo_desarrollo' => $tipo])->assertOk();
            $this->assertSame($tipo, $project->fresh()->tipo_desarrollo);
        }
    }

    public function test_acepta_los_nuevos_tipos_al_crear_y_rechaza_los_desconocidos(): void
    {
        $this->postJson('/api/projects', [
            'nombre' => 'Eval', 'tipo' => 'Desarrollo', 'nombre_prospecto' => 'P', 'tipo_desarrollo' => 'solicitud_muestras',
        ])->assertCreated();
        $this->assertSame('solicitud_muestras', Project::where('nombre', 'Eval')->value('tipo_desarrollo'));

        $this->postJson('/api/projects', [
            'nombre' => 'Raro', 'tipo' => 'Desarrollo', 'nombre_prospecto' => 'P', 'tipo_desarrollo' => 'inventado',
        ])->assertStatus(422)->assertJsonValidationErrors('tipo_desarrollo');
    }

    public function test_los_nuevos_tipos_no_suman_dias_de_desarrollo(): void
    {
        $service = app(\App\Services\ProjectTimeService::class);
        $base = $this->project(['tipo_desarrollo' => null]);
        $eval = $this->project(['tipo_desarrollo' => 'evaluacion']);
        $muestras = $this->project(['tipo_desarrollo' => 'solicitud_muestras']);

        $this->assertEquals($service->calculate($base)->toDateString(), $service->calculate($eval)->toDateString());
        $this->assertEquals($service->calculate($base)->toDateString(), $service->calculate($muestras)->toDateString());
    }
}
