<?php

namespace Tests\Feature\Projects;

use App\Models\Client;
use App\Models\Project;
use App\Support\ProjectFactorPermission;

/**
 * El factor ya no se escribe a mano: es siempre automático. Al crear toma el default del cliente
 * (o el DEFAULT 1 de la columna, que es NOT NULL); al editar se conserva, y solo se recalcula
 * cuando cambia el potencial en Kg. Lo que llegue en `factor` se ignora.
 */
class ProjectFactorNullTest extends ProjectMailTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->givePermissions(['project list', 'project edit', 'project create', ProjectFactorPermission::VIEW, 'project factor edit']);
    }

    public function test_crear_sin_cliente_usa_el_factor_por_defecto(): void
    {
        $this->postJson('/api/projects', [
            'nombre'           => 'Proyecto Factor Auto',
            'tipo'             => 'Desarrollo',
            'nombre_prospecto' => 'Prospecto X',
        ])->assertCreated();

        $this->assertEquals(1, (float) Project::where('nombre', 'Proyecto Factor Auto')->value('factor'));
    }

    public function test_crear_con_factor_enviado_no_lo_respeta(): void
    {
        $this->postJson('/api/projects', [
            'nombre'           => 'Proyecto Factor 2',
            'tipo'             => 'Desarrollo',
            'nombre_prospecto' => 'Prospecto X',
            'factor'           => 2.5,
        ])->assertCreated();

        $this->assertEquals(1, (float) Project::where('nombre', 'Proyecto Factor 2')->value('factor'));
    }

    public function test_crear_con_cliente_toma_su_factor_por_defecto(): void
    {
        $client = Client::create(['client_name' => 'Cliente F', 'default_factor' => 2.2]);

        $this->postJson('/api/projects', [
            'nombre'    => 'Proyecto Con Cliente',
            'tipo'      => 'Desarrollo',
            'client_id' => $client->id,
            'factor'    => 9,
        ])->assertCreated();

        $this->assertEquals(2.2, (float) Project::where('nombre', 'Proyecto Con Cliente')->value('factor'));
    }

    public function test_editar_conserva_el_factor_actual(): void
    {
        $project = $this->project(['factor' => 1.8]);

        $this->putJson("/api/projects/{$project->id}", ['factor' => null])->assertOk();
        $this->putJson("/api/projects/{$project->id}", ['factor' => 4])->assertOk();

        $this->assertEquals(1.8, (float) $project->fresh()->factor);
    }
}
