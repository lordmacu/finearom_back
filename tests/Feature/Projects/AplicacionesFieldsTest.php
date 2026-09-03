<?php

namespace Tests\Feature\Projects;

use App\Models\EnvelopeType;
use App\Models\Project;
use App\Models\ProjectApplication;
use App\Models\ProjectSample;

class AplicacionesFieldsTest extends ProjectFieldsTestCase
{
    private function project(): Project
    {
        return Project::create(['nombre' => 'Proyecto App', 'fecha_creacion' => today()]);
    }

    public function test_guarda_cantidad_copias_en_la_muestra(): void
    {
        $project = $this->project();

        $this->putJson("/api/projects/{$project->id}/sample", [
            'cantidad'        => 3,
            'cantidad_copias' => 5,
        ])->assertOk();

        $this->assertSame(5, ProjectSample::where('project_id', $project->id)->first()->cantidad_copias);
    }

    public function test_guarda_cantidad_aplicacion(): void
    {
        $project = $this->project();

        $this->putJson("/api/projects/{$project->id}/application", [
            'cantidad_aplicacion' => 4,
        ])->assertOk();

        $this->assertSame(4, ProjectApplication::where('project_id', $project->id)->first()->cantidad_aplicacion);
    }

    /**
     * La columna es decimal(10,2): el texto libre se rechaza en validación en
     * vez de reventar en el insert.
     */
    public function test_dosis_de_aplicacion_rechaza_texto_libre(): void
    {
        $project = $this->project();

        $this->putJson("/api/projects/{$project->id}/application", [
            'dosis' => '0.5% sobre base neutra',
        ])->assertStatus(422);
    }

    public function test_dosis_de_aplicacion_acepta_un_decimal(): void
    {
        $project = $this->project();

        $this->putJson("/api/projects/{$project->id}/application", [
            'dosis' => 0.5,
        ])->assertOk();

        $this->assertSame(
            '0.50',
            ProjectApplication::where('project_id', $project->id)->first()->dosis
        );
    }

    public function test_asigna_tipo_etiquetado_y_envase_al_proyecto(): void
    {
        $project = $this->project();
        $envase  = EnvelopeType::create(['name' => 'Frasco 30ml', 'category' => 'Vidrio']);

        $this->putJson("/api/projects/{$project->id}", [
            'nombre'           => $project->nombre,
            'tipo_etiquetado'  => 'SGA',
            'envelope_type_id' => $envase->id,
        ])->assertOk();

        $project->refresh();
        $this->assertSame('SGA', $project->tipo_etiquetado);
        $this->assertSame($envase->id, $project->envelope_type_id);
    }

    public function test_rechaza_tipo_etiquetado_fuera_del_enum(): void
    {
        $project = $this->project();

        $this->putJson("/api/projects/{$project->id}", [
            'nombre'          => $project->nombre,
            'tipo_etiquetado' => 'Otro',
        ])->assertStatus(422);
    }

    /**
     * El catálogo guarda `photo_path` en el disco `local`, que no es público.
     * Debe existir una ruta que sirva la foto; el tab la usa directo como src.
     */
    public function test_existe_una_ruta_para_servir_la_foto_del_envase(): void
    {
        $envase = EnvelopeType::create([
            'name'       => 'Frasco 30ml',
            'photo_path' => 'envelope-photos/abc.png',
        ]);

        $rutas = collect(app('router')->getRoutes())
            ->map(fn ($r) => $r->uri())
            ->filter(fn ($uri) => str_contains($uri, 'envelope-types'))
            ->values()
            ->all();

        $sirveFoto = collect($rutas)->contains(fn ($uri) => str_contains($uri, 'photo') || str_contains($uri, '{envelopeType}/image'));

        $this->assertTrue(
            $sirveFoto,
            "photo_path vive en el disco `local` y no hay ruta que lo sirva. Rutas actuales: " . implode(', ', $rutas)
        );
    }
}
