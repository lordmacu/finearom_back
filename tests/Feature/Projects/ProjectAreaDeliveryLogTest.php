<?php

namespace Tests\Feature\Projects;

use App\Models\EmailLog;
use App\Models\Process;
use App\Models\ProjectStatusHistory;
use App\Services\ProjectMailService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProjectAreaDeliveryLogTest extends ProjectMailTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->template('project_created', 'Nuevo proyecto #|project_id| — |project_name|');
        $this->template('project_applications_partial', 'Aplicaciones parcial — #|project_id|', '|delivered_by||notas_entrega|');
        $this->template('project_applications_ready', 'Aplicaciones listas — #|project_id|', '|delivered_by||notas_entrega|');
        $this->template('project_applications_updated', 'Aplicaciones actualizadas — #|project_id|', '|delivered_by||changes_table||notas_entrega|');
        Process::create(['name' => 'Lab', 'email' => 'lab@finearom.co', 'process_type' => 'project_created']);

        $this->givePermissions(['project list', 'project deliver']);
    }

    private function entregar($project, array $datos)
    {
        return $this->postJson("/api/projects/{$project->id}/aplicaciones/entregar", $datos);
    }

    public function test_la_entrega_parcial_queda_en_la_bitacora_con_su_correo_y_el_area_sigue_en_proceso(): void
    {
        $project = $this->project(['estado_interno' => 'En proceso']);

        $this->entregar($project, [
            'tipo'     => 'parcial',
            'notas'    => 'Primer lote de aplicaciones',
            'adjuntos' => [UploadedFile::fake()->image('lote1.jpg')],
        ])->assertOk()->assertJsonPath('message', 'Entrega parcial de Aplicaciones registrada');

        $this->assertFalse((bool) $project->fresh()->estado_laboratorio);
        $this->assertSame('En proceso', $project->fresh()->estado_interno);

        $email = $this->sentMessages()->last()->getOriginalMessage();
        $this->assertStringContainsString('Aplicaciones parcial', $email->getSubject());
        $this->assertStringContainsString('Primer lote de aplicaciones', $email->getHtmlBody());
        $this->assertCount(1, $email->getAttachments());
        $this->assertSame('project_applications_partial', EmailLog::latest('id')->first()->process_type);
        $this->assertSame('Aplicaciones registró una entrega parcial', ProjectStatusHistory::latest('id')->value('descripcion'));
    }

    public function test_varias_parciales_y_la_final_se_acumulan_cada_una_con_sus_adjuntos(): void
    {
        $project = $this->project(['estado_interno' => 'En proceso']);

        $this->entregar($project, ['tipo' => 'parcial', 'notas' => 'Parcial 1', 'adjuntos' => [UploadedFile::fake()->create('p1.pdf', 50, 'application/pdf')]])->assertOk();
        $this->entregar($project, ['tipo' => 'parcial', 'notas' => 'Parcial 2'])->assertOk();
        $this->entregar($project, ['tipo' => 'final', 'notas' => 'Cierre', 'adjuntos' => [UploadedFile::fake()->create('final.pdf', 50, 'application/pdf')]])
            ->assertOk()->assertJsonPath('message', 'Aplicaciones: entrega final registrada');

        $this->assertTrue((bool) $project->fresh()->estado_laboratorio);
        // El correo de la final lleva solo su adjunto
        $final = $this->sentMessages()->last()->getOriginalMessage();
        $this->assertSame(['final.pdf'], array_map(fn ($a) => $a->getFilename(), $final->getAttachments()));
        $this->assertSame('project_applications_ready', EmailLog::latest('id')->first()->process_type);

        $data = $this->getJson("/api/projects/{$project->id}/aplicaciones/entrega")->assertOk()->json('data');

        $this->assertTrue($data['entregado']);
        // Más reciente primero, cada entrega con su tipo, notas y adjuntos
        $this->assertSame(['final', 'parcial', 'parcial'], array_column($data['entregas'], 'tipo'));
        $this->assertSame(['Cierre', 'Parcial 2', 'Parcial 1'], array_column($data['entregas'], 'notas'));
        $this->assertSame('final.pdf', $data['entregas'][0]['adjuntos'][0]['nombre']);
        $this->assertSame([], $data['entregas'][1]['adjuntos']);
        $this->assertSame('p1.pdf', $data['entregas'][2]['adjuntos'][0]['nombre']);
        $this->assertSame('Tester', $data['entregas'][0]['ejecutivo']);
        // Compatibilidad con las pestañas: últimas notas y todos los adjuntos del área
        $this->assertSame('Cierre', $data['notas_entrega']);
        $this->assertCount(2, $data['adjuntos']);
    }

    public function test_despues_de_la_final_cada_entrega_es_una_actualizacion(): void
    {
        $project = $this->project(['estado_interno' => 'En proceso']);

        $this->entregar($project, ['tipo' => 'final', 'notas' => 'Cierre'])->assertOk();
        // Aunque llegue como parcial, el área ya está entregada
        $this->entregar($project, ['tipo' => 'parcial', 'notas' => 'Ajuste posterior'])
            ->assertOk()->assertJsonPath('message', 'Actualización de Aplicaciones enviada');

        $this->assertSame(['actualizacion', 'final'], DB::table('project_area_delivery_logs')->orderByDesc('id')->pluck('tipo')->all());
        $this->assertSame('project_applications_updated', EmailLog::latest('id')->first()->process_type);
        $this->assertStringContainsString('Se actualizaron las notas', $this->sentMessages()->last()->getOriginalMessage()->getHtmlBody());
    }

    public function test_parciales_final_y_actualizacion_van_en_el_mismo_hilo_del_proyecto(): void
    {
        $project = $this->project(['estado_interno' => 'En proceso']);
        app(ProjectMailService::class)->send($project, 'created');
        $rootId      = $project->fresh()->email_thread_message_id;
        $rootSubject = $project->fresh()->email_thread_subject;

        $this->entregar($project, ['tipo' => 'parcial', 'notas' => 'Parcial 1'])->assertOk();
        $this->entregar($project, ['tipo' => 'parcial', 'notas' => 'Parcial 2'])->assertOk();
        $this->entregar($project, ['tipo' => 'final', 'notas' => 'Cierre'])->assertOk();
        $this->entregar($project, ['notas' => 'Ajuste'])->assertOk();

        $respuestas = $this->sentMessages()->slice(1)->map(fn ($m) => $m->getOriginalMessage());
        $this->assertCount(4, $respuestas);
        foreach ($respuestas as $correo) {
            $this->assertSame('Re: ' . $rootSubject, $correo->getSubject());
            $this->assertSame('<' . $rootId . '>', $correo->getHeaders()->get('In-Reply-To')->getBodyAsString());
            $this->assertSame('<' . $rootId . '>', $correo->getHeaders()->get('References')->getBodyAsString());
        }
        $this->assertSame($rootId, $project->fresh()->email_thread_message_id);
    }

    public function test_sin_tipo_se_toma_como_entrega_final(): void
    {
        $project = $this->project(['estado_interno' => 'En proceso']);

        $this->entregar($project, ['notas' => 'Sin tipo'])->assertOk();

        $this->assertTrue((bool) $project->fresh()->estado_laboratorio);
        $this->assertSame('final', DB::table('project_area_delivery_logs')->value('tipo'));
    }

    public function test_la_parcial_vacia_se_rechaza_y_no_deja_rastro(): void
    {
        $project = $this->project(['estado_interno' => 'En proceso']);

        $this->entregar($project, ['tipo' => 'parcial', 'notas' => '<p>&nbsp;</p>'])
            ->assertStatus(422)->assertJsonValidationErrors('notas');
        $this->entregar($project, ['tipo' => 'otro', 'notas' => 'x'])
            ->assertStatus(422)->assertJsonValidationErrors('tipo');

        $this->assertSame(0, DB::table('project_area_delivery_logs')->count());
        $this->assertCount(0, $this->sentMessages());
    }
}
