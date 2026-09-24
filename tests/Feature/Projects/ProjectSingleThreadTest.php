<?php

namespace Tests\Feature\Projects;

use App\Models\Process;
use App\Services\ProjectMailService;
use Illuminate\Support\Facades\DB;

/**
 * Todos los correos de un proyecto van en un solo hilo, que abre el correo de
 * creación: las entregas (parciales y finales) se bloquean hasta que se envíe
 * la creación, y ningún otro correo abre el hilo ni sale suelto.
 */
class ProjectSingleThreadTest extends ProjectMailTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->template('project_created', 'Nuevo proyecto #|project_id| — |project_name|');
        $this->template('project_regulatoria_partial', 'Regulatoria parcial — #|project_id|', '|delivered_by| |notas_entrega|');
        $this->template('project_regulatoria_delivered', 'Regulatoria lista — #|project_id|', '|delivered_by| |notas_entrega|');
        $this->template('project_development_delivered', 'Desarrollo entregado — #|project_id|', '|delivered_by|');
        $this->template('project_engineer_reminder', 'Sin ingeniero — #|project_id|');
        Process::create(['name' => 'Lab', 'email' => 'lab@finearom.co', 'process_type' => 'proyectos']);

        $this->givePermissions(['project list', 'project deliver', 'project send creation']);
    }

    public function test_entrega_parcial_sin_creacion_se_bloquea(): void
    {
        $project = $this->project(['estado_interno' => 'En proceso']);

        $this->postJson("/api/projects/{$project->id}/regulatoria/entregar", ['tipo' => 'parcial', 'notas' => 'Lote 1'])
            ->assertStatus(422)
            ->assertJsonPath('message', ProjectMailService::SIN_HILO_ENTREGA);

        $this->assertSame(0, DB::table('project_area_delivery_logs')->count());
        $this->assertCount(0, $this->sentMessages());
        $this->assertNull($project->fresh()->email_thread_message_id);
    }

    public function test_entrega_de_desarrollo_sin_creacion_se_bloquea(): void
    {
        $project = $this->project(['estado_interno' => 'En proceso']);

        $this->patchJson("/api/projects/{$project->id}/entregar", ['department' => 'desarrollo'])
            ->assertStatus(422)
            ->assertJsonPath('message', ProjectMailService::SIN_HILO_ENTREGA);

        $this->assertFalse((bool) $project->fresh()->estado_desarrollo);
        $this->assertCount(0, $this->sentMessages());
    }

    public function test_entregas_parcial_y_final_van_como_respuesta_al_correo_de_creacion(): void
    {
        $project = $this->project(['estado_interno' => 'En proceso']);
        $this->postJson("/api/projects/{$project->id}/send-creation")->assertOk();
        $project->refresh();

        $this->postJson("/api/projects/{$project->id}/regulatoria/entregar", ['tipo' => 'parcial', 'notas' => 'Lote 1'])->assertOk();
        $this->postJson("/api/projects/{$project->id}/regulatoria/entregar", ['tipo' => 'final', 'notas' => 'Cierre'])->assertOk();

        $this->assertCount(3, $this->sentMessages());
        foreach ($this->sentMessages()->slice(1) as $sent) {
            $correo = $sent->getOriginalMessage();
            $this->assertSame('Re: ' . $project->email_thread_subject, $correo->getSubject());
            $this->assertSame('<' . $project->email_thread_message_id . '>', $correo->getHeaders()->get('In-Reply-To')->getBodyAsString());
        }
    }

    public function test_ningun_correo_distinto_a_la_creacion_abre_el_hilo(): void
    {
        $project = $this->project();

        $this->assertFalse(app(ProjectMailService::class)->send($project, 'regulatoria_partial'));

        $this->assertCount(0, $this->sentMessages());
        $this->assertNull($project->fresh()->email_thread_message_id);
    }

    public function test_el_recordatorio_de_ingeniero_solo_sale_en_proyectos_con_hilo(): void
    {
        $attrs = ['fecha_creacion' => today()->subDays(3), 'estado_externo' => 'Cancelado', 'estado_interno' => 'En proceso'];
        $this->project($attrs);
        $conHilo = $this->threadedProject($attrs);

        $this->artisan('projects:engineer-reminders')->assertSuccessful();

        $this->assertCount(1, $this->sentMessages());
        $correo = $this->sentMessages()->first()->getOriginalMessage();
        $this->assertSame('Re: ' . $conHilo->fresh()->email_thread_subject, $correo->getSubject());
    }
}
