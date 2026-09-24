<?php

namespace Tests\Feature\Projects;

use App\Models\Project;
use App\Support\ProjectFactorPermission;

/**
 * El campo Factor del formulario ("Auto") puede llegar vacío (null); la
 * columna es NOT NULL DEFAULT 1, así que null nunca debe llegar al insert.
 */
class ProjectFactorNullTest extends ProjectMailTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Solo quien ve el factor lo escribe
        $this->givePermissions(['project list', 'project edit', 'project create', ProjectFactorPermission::VIEW]);
    }

    public function test_crear_con_factor_vacio_usa_el_factor_por_defecto(): void
    {
        $this->postJson('/api/projects', [
            'nombre'           => 'Proyecto Factor Auto',
            'tipo'             => 'Desarrollo',
            'nombre_prospecto' => 'Prospecto X',
            'factor'           => null,
        ])->assertCreated();

        $this->assertEquals(1, (float) Project::where('nombre', 'Proyecto Factor Auto')->value('factor'));
    }

    public function test_crear_con_factor_explicito_lo_respeta(): void
    {
        $this->postJson('/api/projects', [
            'nombre'           => 'Proyecto Factor 2',
            'tipo'             => 'Desarrollo',
            'nombre_prospecto' => 'Prospecto X',
            'factor'           => 2.5,
        ])->assertCreated();

        $this->assertEquals(2.5, (float) Project::where('nombre', 'Proyecto Factor 2')->value('factor'));
    }

    public function test_editar_con_factor_vacio_conserva_el_actual(): void
    {
        $project = $this->project(['factor' => 1.8]);

        $this->putJson("/api/projects/{$project->id}", ['factor' => null])->assertOk();

        $this->assertEquals(1.8, (float) $project->fresh()->factor);
    }
}
