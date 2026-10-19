<?php

namespace Tests\Feature\Projects;

use App\Support\ProjectFactorPermission;

/**
 * El factor solo lo ven (y escriben) Mónica y el rol Desarrollo: permiso
 * 'project factor view'. Para el resto no viaja en el JSON y se ignora.
 */
class ProjectFactorVisibilityTest extends ProjectMailTestCase
{
    public function test_sin_permiso_el_factor_no_viaja_en_el_json(): void
    {
        $project = $this->project(['factor' => 1.8]);

        $this->getJson("/api/projects/{$project->id}")
            ->assertOk()
            ->assertJsonMissingPath('data.factor');
    }

    public function test_con_permiso_el_factor_se_ve(): void
    {
        $this->givePermissions(['project list', 'project edit', ProjectFactorPermission::VIEW]);
        $project = $this->project(['factor' => 1.8]);

        $this->getJson("/api/projects/{$project->id}")
            ->assertOk()
            ->assertJsonPath('data.factor', '1.8000');
    }

    public function test_el_factor_enviado_se_ignora_aun_con_permiso(): void
    {
        $this->givePermissions(['project list', 'project edit', 'project create', ProjectFactorPermission::VIEW, 'project factor edit']);
        $project = $this->project(['factor' => 1.8]);

        $this->putJson("/api/projects/{$project->id}", ['factor' => 3])->assertOk();
        $this->assertEquals(1.8, (float) $project->fresh()->factor);

        $id = $this->postJson('/api/projects', [
            'nombre'           => 'Proyecto Factor Ignorado',
            'tipo'             => 'Desarrollo',
            'nombre_prospecto' => 'Prospecto X',
            'factor'           => 3,
        ])->assertCreated()->json('data.id');
        $this->assertEquals(1, (float) \App\Models\Project::find($id)->factor);
    }

    public function test_ya_no_existe_la_ruta_para_cambiar_el_factor_a_mano(): void
    {
        $this->givePermissions(['project list', 'project edit', ProjectFactorPermission::VIEW, 'project factor edit']);
        $project = $this->project(['factor' => 1.8]);

        $this->patchJson("/api/projects/{$project->id}/factor", ['factor' => 3])->assertNotFound();
        $this->assertEquals(1.8, (float) $project->fresh()->factor);
    }
}
