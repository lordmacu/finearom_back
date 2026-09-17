<?php

namespace Tests\Feature\Projects;

use App\Models\EmailLog;
use App\Models\Process;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Aplicaciones usa el flujo de modal (notas + adjuntos opcionales) igual que
 * Evaluaciones y Marketing. El PATCH /entregar viejo solo marca el área:
 * el correo sale del endpoint del modal.
 */
class ProjectApplicationsReadyTest extends ProjectMailTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->template('project_created', 'Nuevo proyecto #|project_id| — |project_name|');
        $this->template('project_applications_ready', 'Aplicaciones listas — proyecto #|project_id|', '|delivered_by||notas_entrega|');
        $this->template('project_applications_updated', 'Aplicaciones actualizadas — proyecto #|project_id|', '|delivered_by||changes_table||notas_entrega|');
        Process::create(['name' => 'Lab', 'email' => 'lab@finearom.co', 'process_type' => 'proyectos']);

        $this->givePermissions(['project list', 'project deliver']);
    }

    public function test_entregar_aplicaciones_con_notas_y_adjuntos_envia_el_correo(): void
    {
        $project = $this->project(['estado_interno' => 'En proceso']);

        $this->postJson("/api/projects/{$project->id}/aplicaciones/entregar", [
            'notas'    => '<p>Aplicadas en <strong>jabón</strong> y crema</p>',
            'adjuntos' => [UploadedFile::fake()->create('aplicaciones.pdf', 500, 'application/pdf')],
        ])->assertOk();

        $this->assertTrue((bool) $project->fresh()->estado_laboratorio);
        $this->assertSame(1, DB::table('project_files')->where('categoria', 'aplicaciones')->count());

        $email = $this->sentMessages()->last()->getOriginalMessage();
        // Fallback: sin lista project_applications_ready va a los de project_created
        $this->assertSame(['lab@finearom.co'], $this->addresses($email->getTo()));
        $this->assertStringContainsString('<strong>jabón</strong>', $email->getHtmlBody());
        $this->assertCount(1, $email->getAttachments());
        $this->assertSame('project_applications_ready', EmailLog::latest('id')->first()->process_type);
    }

    public function test_notas_y_adjuntos_son_opcionales(): void
    {
        $project = $this->project(['estado_interno' => 'En proceso']);

        $this->postJson("/api/projects/{$project->id}/aplicaciones/entregar", [])->assertOk();

        $this->assertTrue((bool) $project->fresh()->estado_laboratorio);
        $this->assertSame(1, $this->sentMessages()->count());
        $this->assertCount(0, $this->sentMessages()->last()->getOriginalMessage()->getAttachments());
    }

    public function test_reentregar_aplicaciones_envia_actualizacion_con_diff(): void
    {
        $project = $this->project(['estado_interno' => 'En proceso']);

        $this->postJson("/api/projects/{$project->id}/aplicaciones/entregar", [
            'notas' => '<p>Primera tanda</p>',
        ])->assertOk();

        $this->postJson("/api/projects/{$project->id}/aplicaciones/entregar", [
            'notas' => '<p>Segunda tanda corregida</p>',
        ])->assertOk();

        $html = $this->sentMessages()->last()->getOriginalMessage()->getHtmlBody();
        // Las notas como quedaron, sin el valor anterior
        $this->assertStringContainsString('Segunda tanda corregida', $html);
        $this->assertStringNotContainsString('Primera tanda', $html);
        $this->assertSame('project_applications_updated', EmailLog::latest('id')->first()->process_type);
    }

    public function test_el_patch_de_entregar_ya_no_envia_correo_para_aplicaciones(): void
    {
        $project = $this->project(['estado_interno' => 'En proceso']);

        $this->patchJson("/api/projects/{$project->id}/entregar", ['department' => 'laboratorio'])
            ->assertOk();

        $this->assertTrue((bool) $project->fresh()->estado_laboratorio);
        $this->assertCount(0, $this->sentMessages());
    }

    public function test_las_listas_viejas_por_accion_ya_no_reciben_correos(): void
    {
        // Un registro antiguo con tipo por acción no se usa: solo cuenta la lista "proyectos"
        Process::create(['name' => 'Aplicaciones', 'email' => 'aplicaciones@finearom.co', 'process_type' => 'project_applications_ready']);
        $project = $this->project(['estado_interno' => 'En proceso']);

        $this->postJson("/api/projects/{$project->id}/aplicaciones/entregar", [])->assertOk();

        $email = $this->sentMessages()->last()->getOriginalMessage();
        $this->assertSame(['lab@finearom.co'], $this->addresses($email->getTo()));
        $this->assertNotContains('aplicaciones@finearom.co', $this->addresses($email->getCc()));
    }
}
