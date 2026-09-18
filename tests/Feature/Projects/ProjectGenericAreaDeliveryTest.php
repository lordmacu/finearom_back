<?php

namespace Tests\Feature\Projects;

use App\Models\EmailLog;
use App\Models\Process;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Regulatoria (dept calidad) y P. Especiales (dept especiales) usan el flujo
 * de modal con notas + adjuntos opcionales. Sus notas van en la tabla
 * genérica project_area_deliveries (no tienen subentidad propia).
 */
class ProjectGenericAreaDeliveryTest extends ProjectMailTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->template('project_created', 'Nuevo proyecto #|project_id| — |project_name|');
        $this->template('project_regulatoria_delivered', 'Regulatoria entregada — proyecto #|project_id|', '|delivered_by||notas_entrega|');
        $this->template('project_regulatoria_updated', 'Regulatoria actualizada — proyecto #|project_id|', '|delivered_by||changes_table||notas_entrega|');
        $this->template('project_especiales_delivered', 'P. Especiales entregado — proyecto #|project_id|', '|delivered_by||notas_entrega|');
        $this->template('project_especiales_updated', 'P. Especiales actualizada — proyecto #|project_id|', '|delivered_by||changes_table||notas_entrega|');
        Process::create(['name' => 'Lab', 'email' => 'lab@finearom.co', 'process_type' => 'proyectos']);

        $this->givePermissions(['project list', 'project deliver']);
    }

    public function test_entregar_regulatoria_con_notas_y_adjuntos(): void
    {
        $project = $this->threadedProject(['estado_interno' => 'En proceso']);

        $this->postJson("/api/projects/{$project->id}/regulatoria/entregar", [
            'notas'    => '<p>Documentación <strong>INVIMA</strong> completa</p>',
            'adjuntos' => [UploadedFile::fake()->create('invima.pdf', 500, 'application/pdf')],
        ])->assertOk();

        $this->assertTrue((bool) $project->fresh()->estado_calidad);
        $this->assertSame('<p>Documentación <strong>INVIMA</strong> completa</p>', $project->fresh()->notasEntrega('regulatoria'));
        $this->assertSame(1, DB::table('project_files')->where('categoria', 'regulatoria')->count());

        $email = $this->sentMessages()->last()->getOriginalMessage();
        $this->assertStringContainsString('<strong>INVIMA</strong>', $email->getHtmlBody());
        $this->assertCount(1, $email->getAttachments());
        $this->assertSame('project_regulatoria_delivered', EmailLog::latest('id')->first()->process_type);
    }

    public function test_reentregar_regulatoria_envia_actualizacion_con_diff(): void
    {
        $project = $this->threadedProject(['estado_interno' => 'En proceso']);

        $this->postJson("/api/projects/{$project->id}/regulatoria/entregar", ['notas' => '<p>V1</p>'])->assertOk();
        $this->postJson("/api/projects/{$project->id}/regulatoria/entregar", ['notas' => '<p>V2</p>'])->assertOk();

        $html = $this->sentMessages()->last()->getOriginalMessage()->getHtmlBody();
        // Las notas como quedaron, sin el valor anterior
        $this->assertStringContainsString('V2', $html);
        $this->assertStringNotContainsString('V1', $html);
        $this->assertSame('project_regulatoria_updated', EmailLog::latest('id')->first()->process_type);
    }

    public function test_entregar_especiales_sin_notas_ni_adjuntos(): void
    {
        $project = $this->threadedProject(['estado_interno' => 'En proceso']);

        $this->postJson("/api/projects/{$project->id}/especiales/entregar", [])->assertOk();

        $this->assertTrue((bool) $project->fresh()->estado_especiales);
        $this->assertSame('project_especiales_delivered', EmailLog::latest('id')->first()->process_type);
    }

    public function test_las_notas_de_areas_genericas_no_se_mezclan_entre_si(): void
    {
        $project = $this->threadedProject(['estado_interno' => 'En proceso']);

        $this->postJson("/api/projects/{$project->id}/regulatoria/entregar", ['notas' => '<p>Nota regulatoria</p>'])->assertOk();
        $this->postJson("/api/projects/{$project->id}/especiales/entregar", ['notas' => '<p>Nota especiales</p>'])->assertOk();

        $fresh = $project->fresh();
        $this->assertSame('<p>Nota regulatoria</p>', $fresh->notasEntrega('regulatoria'));
        $this->assertSame('<p>Nota especiales</p>', $fresh->notasEntrega('especiales'));

        $data = $this->getJson("/api/projects/{$project->id}/especiales/entrega")->json('data');
        $this->assertSame('<p>Nota especiales</p>', $data['notas_entrega']);
    }
}
