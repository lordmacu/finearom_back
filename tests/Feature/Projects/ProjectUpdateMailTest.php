<?php

namespace Tests\Feature\Projects;

use App\Models\EmailLog;
use App\Models\Process;
use App\Models\ProjectSample;

class ProjectUpdateMailTest extends ProjectMailTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->template('project_created', 'Nuevo proyecto #|project_id| — |project_name|');
        $this->template('project_updated', 'Actualización proyecto #|project_id| — |project_name|', '|changes_table|');
        Process::create(['name' => 'Lab', 'email' => 'lab@finearom.co', 'process_type' => 'proyectos']);
    }

    public function test_sin_hilo_responde_422(): void
    {
        $project = $this->project();

        $this->postJson("/api/projects/{$project->id}/send-update")->assertStatus(422);

        $this->assertCount(0, $this->sentMessages());
    }

    public function test_envia_el_diff_de_lo_que_cambio_en_el_mismo_hilo(): void
    {
        $project = $this->project(['potencial_anual_kg' => 100]);
        ProjectSample::create(['project_id' => $project->id, 'cantidad' => 5]);

        $this->postJson("/api/projects/{$project->id}/send-creation")->assertOk();
        $snapshot = $project->fresh()->email_snapshot;
        $this->assertNotNull($snapshot);

        // La comercial modifica el proyecto después de enviada la creación
        $project->update(['potencial_anual_kg' => 250]);
        ProjectSample::where('project_id', $project->id)->update(['cantidad' => 9]);

        $this->postJson("/api/projects/{$project->id}/send-update")->assertOk();

        $this->assertCount(2, $this->sentMessages());
        $reply = $this->sentMessages()->last()->getOriginalMessage();
        $this->assertSame('Re: ' . $project->fresh()->email_thread_subject, $reply->getSubject());

        $html = $reply->getHtmlBody();
        // El correo dice el campo y CÓMO QUEDÓ; el valor anterior no viaja
        // (sigue visible en el correo previo del mismo hilo)
        $this->assertStringContainsString('Cómo quedó', $html);
        $this->assertStringContainsString('Potencial anual (Kg)', $html);
        $this->assertStringContainsString('250,00', $html);
        $this->assertStringNotContainsString('100,00', $html);
        $this->assertStringContainsString('Muestra aceite', $html);
        $this->assertStringContainsString('Cantidad: 9,00', $html);
        $this->assertStringNotContainsString('Cantidad: 5,00', $html);
        // Lo que no cambió no aparece en el diff
        $this->assertStringNotContainsString('Ejecutivo', $html);

        // Todos los correos de proyectos van a la lista única "proyectos"
        $this->assertSame(['lab@finearom.co'], $this->addresses($reply->getTo()));
        $this->assertSame('project_updated', EmailLog::latest('id')->first()->process_type);
    }

    public function test_sin_cambios_desde_el_ultimo_correo_responde_422(): void
    {
        $project = $this->project();

        $this->postJson("/api/projects/{$project->id}/send-creation")->assertOk();
        $this->postJson("/api/projects/{$project->id}/send-update")->assertStatus(422);

        $this->assertCount(1, $this->sentMessages());
    }

    public function test_el_snapshot_es_incremental_solo_reporta_lo_nuevo(): void
    {
        $project = $this->project(['potencial_anual_kg' => 100]);

        $this->postJson("/api/projects/{$project->id}/send-creation")->assertOk();

        $project->update(['potencial_anual_kg' => 200]);
        $this->postJson("/api/projects/{$project->id}/send-update")->assertOk();

        $project->update(['precio' => 50]);
        $this->postJson("/api/projects/{$project->id}/send-update")->assertOk();

        $html = $this->sentMessages()->last()->getOriginalMessage()->getHtmlBody();
        $this->assertStringContainsString('Precio (USD)', $html);
        $this->assertStringContainsString('50,00', $html);
        // El cambio de potencial en Kg ya se reportó en el correo anterior
        $this->assertStringNotContainsString('Potencial anual (Kg)', $html);
    }

    public function test_campo_eliminado_se_reporta_con_guion(): void
    {
        $project = $this->project(['potencial_anual_kg' => 100]);

        $this->postJson("/api/projects/{$project->id}/send-creation")->assertOk();

        $project->update(['potencial_anual_kg' => null]);
        $this->postJson("/api/projects/{$project->id}/send-update")->assertOk();

        $html = $this->sentMessages()->last()->getOriginalMessage()->getHtmlBody();
        $this->assertStringContainsString('Potencial anual (Kg)', $html);
        // Quedó vacío: solo el guion, sin repetir el valor que tenía
        $this->assertStringContainsString('—', $html);
        $this->assertStringNotContainsString('100,00', $html);
    }
}
