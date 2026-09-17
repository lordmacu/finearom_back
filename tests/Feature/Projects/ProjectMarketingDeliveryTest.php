<?php

namespace Tests\Feature\Projects;

use App\Models\EmailLog;
use App\Models\Process;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProjectMarketingDeliveryTest extends ProjectMailTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->template('project_created', 'Nuevo proyecto #|project_id| — |project_name|');
        $this->template('project_marketing_delivered', 'Marketing entregado — proyecto #|project_id|', '|delivered_by||notas_entrega|');
        $this->template('project_marketing_updated', 'Marketing actualizado — proyecto #|project_id|', '|delivered_by||changes_table||notas_entrega|');
        Process::create(['name' => 'Lab', 'email' => 'lab@finearom.co', 'process_type' => 'proyectos']);

        $this->givePermissions(['project list', 'project deliver']);
    }

    public function test_entregar_marketing_con_notas_y_adjuntos_envia_el_correo(): void
    {
        $project = $this->project(['estado_interno' => 'En proceso']);

        $this->postJson("/api/projects/{$project->id}/marketing/entregar", [
            'notas'    => '<p><strong>Piezas listas</strong> para revisión</p>',
            'adjuntos' => [UploadedFile::fake()->create('piezas.pdf', 500, 'application/pdf')],
        ])->assertOk();

        $this->assertTrue((bool) $project->fresh()->estado_mercadeo);
        $this->assertSame('<p><strong>Piezas listas</strong> para revisión</p>', $project->fresh()->marketingYCalidad->notas_entrega);
        $this->assertSame(1, DB::table('project_files')->where('categoria', 'marketing')->count());

        $email = $this->sentMessages()->last()->getOriginalMessage();
        $this->assertSame(['lab@finearom.co'], $this->addresses($email->getTo()));
        $this->assertStringContainsString('<strong>Piezas listas</strong>', $email->getHtmlBody());
        $this->assertCount(1, $email->getAttachments());
        $this->assertSame('project_marketing_delivered', EmailLog::latest('id')->first()->process_type);
    }

    public function test_reentregar_marketing_envia_actualizacion_con_diff_de_notas(): void
    {
        $project = $this->project(['estado_interno' => 'En proceso']);

        $this->postJson("/api/projects/{$project->id}/marketing/entregar", [
            'notas' => '<p>Versión 1</p>',
        ])->assertOk();

        $this->postJson("/api/projects/{$project->id}/marketing/entregar", [
            'notas' => '<p>Versión 2 corregida</p>',
        ])->assertOk();

        $this->assertCount(2, $this->sentMessages());

        $html = $this->sentMessages()->last()->getOriginalMessage()->getHtmlBody();
        // Las notas como quedaron, sin el valor anterior
        $this->assertStringContainsString('Versión 2 corregida', $html);
        $this->assertStringNotContainsString('Versión 1', $html);
        $this->assertSame('project_marketing_updated', EmailLog::latest('id')->first()->process_type);
    }

    public function test_los_adjuntos_de_marketing_no_se_mezclan_con_los_de_evaluaciones(): void
    {
        $this->template('project_evaluation_delivered', 'Evaluaciones listas — proyecto #|project_id|', '|delivered_by||notas_entrega|');
        $project = $this->project(['estado_interno' => 'En proceso']);

        $this->postJson("/api/projects/{$project->id}/evaluaciones/entregar", [
            'adjuntos' => [UploadedFile::fake()->create('eval.pdf', 100, 'application/pdf')],
        ])->assertOk();

        $this->postJson("/api/projects/{$project->id}/marketing/entregar", [
            'adjuntos' => [UploadedFile::fake()->create('mkt.pdf', 100, 'application/pdf')],
        ])->assertOk();

        // El correo de marketing solo adjunta su archivo
        $email = $this->sentMessages()->last()->getOriginalMessage();
        $this->assertCount(1, $email->getAttachments());

        $data = $this->getJson("/api/projects/{$project->id}/marketing/entrega")->json('data');
        $this->assertSame(['mkt.pdf'], array_column($data['adjuntos'], 'nombre'));
    }
}
