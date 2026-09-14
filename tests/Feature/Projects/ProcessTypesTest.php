<?php

namespace Tests\Feature\Projects;

use App\Models\Process;

class ProcessTypesTest extends ProjectMailTestCase
{
    public function test_acepta_el_tipo_de_proceso_de_creacion_de_proyecto(): void
    {
        $this->putJson('/api/settings/processes', [
            'rows' => [
                ['name' => 'Laboratorio', 'email' => 'lab@finearom.co', 'process_type' => 'project_created'],
            ],
        ])->assertOk();

        $this->assertSame('project_created', Process::first()->process_type);
    }

    public function test_rechaza_un_tipo_de_proceso_desconocido(): void
    {
        $this->putJson('/api/settings/processes', [
            'rows' => [
                ['name' => 'X', 'email' => 'x@finearom.co', 'process_type' => 'no_existe'],
            ],
        ])->assertStatus(422);
    }
}
