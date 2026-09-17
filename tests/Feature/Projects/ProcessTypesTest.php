<?php

namespace Tests\Feature\Projects;

use App\Models\Process;

class ProcessTypesTest extends ProjectMailTestCase
{
    public function test_acepta_la_lista_unica_de_proyectos(): void
    {
        $this->putJson('/api/settings/processes', [
            'rows' => [
                ['name' => 'Laboratorio', 'email' => 'lab@finearom.co', 'process_type' => 'proyectos'],
            ],
        ])->assertOk();

        $this->assertSame('proyectos', Process::first()->process_type);
    }

    public function test_rechaza_los_tipos_viejos_por_accion_de_proyecto(): void
    {
        $this->putJson('/api/settings/processes', [
            'rows' => [
                ['name' => 'Lab', 'email' => 'lab@finearom.co', 'process_type' => 'project_created'],
            ],
        ])->assertStatus(422);
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
