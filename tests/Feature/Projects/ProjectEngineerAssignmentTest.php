<?php

namespace Tests\Feature\Projects;

use App\Models\Process;
use App\Models\Project;
use App\Models\User;
use Spatie\Permission\Models\Role;

class ProjectEngineerAssignmentTest extends ProjectMailTestCase
{
    private User $engineer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->template('project_created', 'Nuevo proyecto #|project_id| — |project_name|');
        $this->template('project_engineer_assigned', 'Se asignó ingeniero de desarrollo — proyecto #|project_id| — |project_name|', '|engineer_name|');
        Process::create(['name' => 'Lab', 'email' => 'lab@finearom.co', 'process_type' => 'proyectos']);

        $role = Role::firstOrCreate(['name' => 'Desarrollo', 'guard_name' => 'web']);
        $this->engineer = User::create(['name' => 'Ing. Prueba', 'email' => 'ingeniero@finearom.co', 'password' => bcrypt('x')]);
        $this->engineer->assignRole($role);
    }

    public function test_el_endpoint_lista_solo_usuarios_con_rol_desarrollo(): void
    {
        $response = $this->getJson('/api/projects/desarrolladores')->assertOk();

        $emails = collect($response->json('data'))->pluck('email');
        $this->assertContains('ingeniero@finearom.co', $emails);
        $this->assertNotContains('tester@finearom.co', $emails);
    }

    public function test_crear_con_ingeniero_envia_el_correo_de_asignacion(): void
    {
        $this->postJson('/api/projects', [
            'nombre'           => 'Proyecto Con Ing',
            'tipo'             => 'Desarrollo',
            'nombre_prospecto' => 'Prospecto X',
            'desarrollador_id' => $this->engineer->id,
        ])->assertCreated();

        $email = $this->sentMessages()->first()->getOriginalMessage();
        $this->assertSame(['ingeniero@finearom.co'], $this->addresses($email->getTo()));
        // En copia la lista "Proyectos", no la ejecutiva
        $this->assertSame(['lab@finearom.co'], $this->addresses($email->getCc()));
        $this->assertStringContainsString('Se asignó ingeniero de desarrollo', $email->getSubject());
        $this->assertStringContainsString('Ing. Prueba', $email->getHtmlBody());
    }

    public function test_asignar_al_editar_envia_correo_y_reasignar_avisa_al_nuevo(): void
    {
        $project = $this->project();

        // Asignar por primera vez
        $this->putJson("/api/projects/{$project->id}", ['desarrollador_id' => $this->engineer->id])->assertOk();
        $this->assertCount(1, $this->sentMessages());
        $this->assertSame(
            ['ingeniero@finearom.co'],
            $this->addresses($this->sentMessages()->first()->getOriginalMessage()->getTo())
        );

        // Editar otra cosa sin tocar el ingeniero: NO correo
        $this->putJson("/api/projects/{$project->id}", ['tipo_etiquetado' => 'SGA'])->assertOk();
        $this->assertCount(1, $this->sentMessages());

        // Reasignar: correo al nuevo ingeniero
        $otro = User::create(['name' => 'Ing. Dos', 'email' => 'ing2@finearom.co', 'password' => bcrypt('x')]);
        $this->putJson("/api/projects/{$project->id}", ['desarrollador_id' => $otro->id])->assertOk();
        $this->assertCount(2, $this->sentMessages());
        $this->assertSame(
            ['ing2@finearom.co'],
            $this->addresses($this->sentMessages()->last()->getOriginalMessage()->getTo())
        );
    }

    public function test_el_ingeniero_aparece_en_la_ficha_del_correo_de_creacion(): void
    {
        $project = $this->project(['desarrollador_id' => $this->engineer->id]);

        $this->postJson("/api/projects/{$project->id}/send-creation")->assertOk();

        $html = $this->sentMessages()->first()->getOriginalMessage()->getHtmlBody();
        $this->assertStringContainsString('Ingeniero de desarrollo', $html);
        $this->assertStringContainsString('Ing. Prueba', $html);
    }

    public function test_el_ingeniero_asignado_queda_copiado_en_los_correos_del_hilo(): void
    {
        $project = $this->project(['desarrollador_id' => $this->engineer->id]);

        $this->postJson("/api/projects/{$project->id}/send-creation")->assertOk();

        $email = $this->sentMessages()->first()->getOriginalMessage();
        $this->assertSame(['lab@finearom.co'], $this->addresses($email->getTo()));
        $this->assertContains('ingeniero@finearom.co', $this->addresses($email->getCc()));
        $this->assertNotContains('tester@finearom.co', $this->addresses($email->getCc()));
    }

    public function test_sin_ingeniero_asignado_no_se_copia_nadie_extra(): void
    {
        $project = $this->project();

        $this->postJson("/api/projects/{$project->id}/send-creation")->assertOk();

        $email = $this->sentMessages()->first()->getOriginalMessage();
        $this->assertNotContains('ingeniero@finearom.co', $this->addresses($email->getCc()));
    }
}
