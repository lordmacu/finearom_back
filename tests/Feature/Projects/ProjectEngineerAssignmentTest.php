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

    public function test_crear_no_asigna_ingeniero_ni_envia_correo(): void
    {
        // El ingeniero se asigna al editar, no al crear
        $this->postJson('/api/projects', [
            'nombre'           => 'Proyecto Con Ing',
            'tipo'             => 'Desarrollo',
            'nombre_prospecto' => 'Prospecto X',
            'desarrollador_id' => $this->engineer->id,
        ])->assertCreated();

        $this->assertNull(Project::where('nombre', 'Proyecto Con Ing')->value('desarrollador_id'));
        $this->assertCount(0, $this->sentMessages());
    }

    public function test_asignar_sin_hilo_espera_y_sale_despues_del_correo_de_creacion(): void
    {
        $project = $this->project();

        // Sin hilo todavía: se guarda el ingeniero pero no sale correo
        $this->putJson("/api/projects/{$project->id}", ['desarrollador_id' => $this->engineer->id])->assertOk();
        $this->assertSame($this->engineer->id, (int) $project->fresh()->desarrollador_id);
        $this->assertCount(0, $this->sentMessages());

        // Al enviar la creación: primero el correo de creación (abre el hilo)
        // y enseguida el de asignación como respuesta dentro del hilo
        $this->postJson("/api/projects/{$project->id}/send-creation")->assertOk();
        $this->assertCount(2, $this->sentMessages());

        $project->refresh();
        $creacion   = $this->sentMessages()->first()->getOriginalMessage();
        $asignacion = $this->sentMessages()->last()->getOriginalMessage();

        $this->assertStringContainsString('Nuevo proyecto', $creacion->getSubject());
        $this->assertSame(['ingeniero@finearom.co'], $this->addresses($asignacion->getTo()));
        // En copia la lista "Proyectos", no la ejecutiva
        $this->assertSame(['lab@finearom.co'], $this->addresses($asignacion->getCc()));
        $this->assertSame('Re: ' . $project->email_thread_subject, $asignacion->getSubject());
        $this->assertSame('<' . $project->email_thread_message_id . '>', $asignacion->getHeaders()->get('In-Reply-To')->getBodyAsString());
        $this->assertStringContainsString('Ing. Prueba', $asignacion->getHtmlBody());
    }

    public function test_reenviar_la_creacion_no_repite_el_aviso_al_ingeniero(): void
    {
        $project = $this->project(['desarrollador_id' => $this->engineer->id]);

        $this->postJson("/api/projects/{$project->id}/send-creation")->assertOk();
        $this->assertCount(2, $this->sentMessages());

        // Segundo envío de la creación: solo el correo de creación
        $this->postJson("/api/projects/{$project->id}/send-creation")->assertOk();
        $this->assertCount(3, $this->sentMessages());
        $this->assertStringNotContainsString('ingeniero@finearom.co', implode(',', $this->addresses($this->sentMessages()->last()->getOriginalMessage()->getTo())));
    }

    public function test_asignar_con_hilo_envia_correo_en_el_hilo_y_reasignar_avisa_al_nuevo(): void
    {
        $project = $this->project();
        $this->postJson("/api/projects/{$project->id}/send-creation")->assertOk();
        $this->assertCount(1, $this->sentMessages());
        $project->refresh();

        // Asignar con el hilo abierto: sale enseguida como Re: del hilo
        $this->putJson("/api/projects/{$project->id}", ['desarrollador_id' => $this->engineer->id])->assertOk();
        $this->assertCount(2, $this->sentMessages());
        $asignacion = $this->sentMessages()->last()->getOriginalMessage();
        $this->assertSame(['ingeniero@finearom.co'], $this->addresses($asignacion->getTo()));
        $this->assertSame('Re: ' . $project->email_thread_subject, $asignacion->getSubject());

        // Editar otra cosa sin tocar el ingeniero: NO correo
        $this->putJson("/api/projects/{$project->id}", ['tipo_etiquetado' => 'SGA'])->assertOk();
        $this->assertCount(2, $this->sentMessages());

        // Reasignar: correo al nuevo ingeniero
        $otro = User::create(['name' => 'Ing. Dos', 'email' => 'ing2@finearom.co', 'password' => bcrypt('x')]);
        $this->putJson("/api/projects/{$project->id}", ['desarrollador_id' => $otro->id])->assertOk();
        $this->assertCount(3, $this->sentMessages());
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
