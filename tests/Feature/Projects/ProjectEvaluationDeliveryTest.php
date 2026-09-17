<?php

namespace Tests\Feature\Projects;

use App\Models\EmailLog;
use App\Models\Process;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProjectEvaluationDeliveryTest extends ProjectMailTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->template('project_created', 'Nuevo proyecto #|project_id| — |project_name|');
        $this->template('project_evaluation_delivered', 'Evaluaciones listas — proyecto #|project_id|', '|delivered_by||notas_entrega|');
        $this->template('project_evaluation_updated', 'Evaluaciones actualizadas — proyecto #|project_id|', '|delivered_by||changes_table||notas_entrega|');
        Process::create(['name' => 'Lab', 'email' => 'lab@finearom.co', 'process_type' => 'proyectos']);

        $this->givePermissions(['project list', 'project deliver']);
    }

    public function test_entregar_evaluaciones_con_notas_y_adjuntos_envia_el_correo(): void
    {
        $project = $this->project(['estado_interno' => 'En proceso']);

        $this->postJson("/api/projects/{$project->id}/evaluaciones/entregar", [
            'notas'     => 'Panel completado con 12 jueces',
            'adjuntos'  => [UploadedFile::fake()->create('resultados.pdf', 500, 'application/pdf')],
        ])->assertOk();

        $this->assertTrue((bool) $project->fresh()->estado_evaluaciones);
        $this->assertSame('Panel completado con 12 jueces', $project->fresh()->evaluation->notas_entrega);
        $this->assertSame(1, DB::table('project_files')->where('categoria', 'evaluaciones')->count());

        $email = $this->sentMessages()->last()->getOriginalMessage();
        $this->assertSame(['lab@finearom.co'], $this->addresses($email->getTo()));
        $this->assertStringContainsString('Panel completado con 12 jueces', $email->getHtmlBody());
        $this->assertCount(1, $email->getAttachments());
        $this->assertSame('project_evaluation_delivered', EmailLog::latest('id')->first()->process_type);
    }

    public function test_reentregar_envia_actualizacion_con_diff_de_notas_y_solo_sus_adjuntos(): void
    {
        $project = $this->project(['estado_interno' => 'En proceso']);

        $this->postJson("/api/projects/{$project->id}/evaluaciones/entregar", [
            'notas'    => 'Nota original',
            'adjuntos' => [UploadedFile::fake()->create('primero.pdf', 100, 'application/pdf')],
        ])->assertOk();

        $this->postJson("/api/projects/{$project->id}/evaluaciones/entregar", [
            'notas'    => 'Nota editada',
            'adjuntos' => [UploadedFile::fake()->create('segundo.pdf', 100, 'application/pdf')],
        ])->assertOk();

        $this->assertCount(2, $this->sentMessages());

        $email = $this->sentMessages()->last()->getOriginalMessage();
        $html  = $email->getHtmlBody();

        // Avisa que cambiaron y trae las notas como quedaron, sin el valor anterior
        $this->assertStringContainsString('Se actualizaron las notas de entrega', $html);
        $this->assertStringContainsString('Nota editada', $html);
        $this->assertStringNotContainsString('Nota original', $html);
        // El correo se atribuye al área, no a la persona que oprimió el botón
        $this->assertStringContainsString('Evaluaciones', $html);
        $this->assertStringNotContainsString('Tester', $html);
        // Cada entrega viaja con sus propios adjuntos; los anteriores quedan en la bitácora
        $this->assertCount(1, $email->getAttachments());
        $this->assertSame('segundo.pdf', $email->getAttachments()[0]->getFilename());
        $this->assertSame(2, DB::table('project_files')->where('categoria', 'evaluaciones')->count());
        $this->assertSame('project_evaluation_updated', EmailLog::latest('id')->first()->process_type);
    }

    public function test_rechaza_cuando_el_total_de_adjuntos_supera_25mb(): void
    {
        $project = $this->project(['estado_interno' => 'En proceso']);

        $this->postJson("/api/projects/{$project->id}/evaluaciones/entregar", [
            'adjuntos' => [
                UploadedFile::fake()->create('a.pdf', 9500, 'application/pdf'),
                UploadedFile::fake()->create('b.pdf', 9500, 'application/pdf'),
                UploadedFile::fake()->create('c.pdf', 9500, 'application/pdf'),
            ],
        ])->assertStatus(422);

        $this->assertSame(0, DB::table('project_files')->count());
        $this->assertFalse((bool) $project->fresh()->estado_evaluaciones);
        $this->assertCount(0, $this->sentMessages());
    }

    public function test_sin_permiso_de_entrega_no_puede_entregar_evaluaciones(): void
    {
        $this->givePermissions(['project list']);
        $project = $this->project(['estado_interno' => 'En proceso']);

        $this->postJson("/api/projects/{$project->id}/evaluaciones/entregar", [
            'notas' => 'Intento',
        ])->assertForbidden();
    }

    public function test_show_devuelve_notas_y_adjuntos_previos(): void
    {
        $project = $this->project(['estado_interno' => 'En proceso']);

        $this->postJson("/api/projects/{$project->id}/evaluaciones/entregar", [
            'notas'    => 'Nota previa',
            'adjuntos' => [UploadedFile::fake()->create('previo.pdf', 100, 'application/pdf')],
        ])->assertOk();

        $data = $this->getJson("/api/projects/{$project->id}/evaluaciones/entrega")->assertOk()->json('data');

        $this->assertSame('Nota previa', $data['notas_entrega']);
        $this->assertCount(1, $data['adjuntos']);
        $this->assertSame('previo.pdf', $data['adjuntos'][0]['nombre']);
    }
}
