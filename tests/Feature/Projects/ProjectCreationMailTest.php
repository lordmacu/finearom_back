<?php

namespace Tests\Feature\Projects;

use App\Models\Process;
use App\Models\Project;
use App\Models\ProjectApplication;
use App\Models\ProjectMarketingVariant;
use App\Models\ProjectMarketingVariantReference;
use App\Models\ProjectSample;
use App\Services\ProjectTimeService;

class ProjectCreationMailTest extends ProjectMailTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->template('project_created', 'Nuevo proyecto #|project_id| — |project_name|');
        Process::create(['name' => 'Lab', 'email' => 'lab@finearom.co', 'process_type' => 'project_created']);

        // La fecha calculada necesita las 9 tablas de tiempos; no es lo que se prueba aquí.
        $this->mock(ProjectTimeService::class, function ($mock) {
            $mock->shouldReceive('calculate')->andReturn(null);
        });
    }

    public function test_crear_proyecto_no_envia_correo(): void
    {
        $this->postJson('/api/projects', [
            'nombre'           => 'Proyecto API',
            'tipo'             => 'Colección',
            'nombre_prospecto' => 'Prospecto API',
        ])->assertCreated();

        $this->assertCount(0, $this->sentMessages());
        $this->assertNull(Project::where('nombre', 'Proyecto API')->value('email_thread_message_id'));
    }

    public function test_duplicar_proyecto_no_envia_correo(): void
    {
        $original = $this->project();

        $this->postJson("/api/projects/{$original->id}/duplicate")->assertCreated();

        $this->assertCount(0, $this->sentMessages());
        $this->assertNull(Project::where('nombre', 'Aroma Test (copia)')->value('email_thread_message_id'));
    }

    public function test_el_boton_enviar_creacion_envia_y_abre_el_hilo(): void
    {
        $project = $this->project();

        $this->postJson("/api/projects/{$project->id}/send-creation")->assertOk();

        $this->assertCount(1, $this->sentMessages());
        $email = $this->sentMessages()->first()->getOriginalMessage();
        $this->assertSame(['lab@finearom.co'], $this->addresses($email->getTo()));
        // El ejecutivo del proyecto va en CC
        $this->assertSame(['tester@finearom.co'], $this->addresses($email->getCc()));
        $this->assertSame("Nuevo proyecto #{$project->id} — Aroma Test", $email->getSubject());
        $this->assertNotNull($project->fresh()->email_thread_message_id);
    }

    public function test_el_correo_trae_el_resumen_separado_por_areas(): void
    {
        $project = $this->project(['max_variantes' => 3, 'fecha_entrega' => '2026-12-15']);
        ProjectSample::create([
            'project_id' => $project->id, 'cantidad' => 5, 'cantidad_copias' => 2, 'observaciones' => 'Urgente',
        ]);
        ProjectApplication::create([
            'project_id' => $project->id, 'dosis' => 1.5, 'cantidad_aplicacion' => 10,
        ]);
        $variant = ProjectMarketingVariant::create([
            'project_id' => $project->id, 'nombre' => 'Var Premium', 'claims' => 'Sin parabenos', 'color_etiqueta' => 'Dorado',
        ]);
        ProjectMarketingVariantReference::create([
            'variant_id' => $variant->id, 'referencia' => 'Ref Aroma 123', 'aplicacion' => 'Shampoo', 'dosis' => 0.8,
        ]);

        $this->postJson("/api/projects/{$project->id}/send-creation")->assertOk();

        $html = $this->sentMessages()->first()->getOriginalMessage()->getHtmlBody();
        $this->assertStringContainsString('Información general', $html);
        $this->assertStringContainsString('Aroma Test', $html);
        $this->assertStringContainsString('Máx. variantes permitidas', $html);
        $this->assertStringContainsString('15/12/2026', $html);
        $this->assertStringContainsString('Desarrollo', $html);
        $this->assertStringContainsString('Muestra aceite', $html);
        $this->assertStringContainsString('Urgente', $html);
        $this->assertStringContainsString('Aplicación', $html);
        $this->assertStringContainsString('Variantes de marketing', $html);
        $this->assertStringContainsString('Var Premium', $html);
        $this->assertStringContainsString('Ref Aroma 123', $html);
        // Las áreas sin dato no se pintan
        $this->assertStringNotContainsString('Evaluaciones', $html);
        $this->assertStringNotContainsString('Regulatoria', $html);
    }

    public function test_reenviar_creacion_responde_en_el_mismo_hilo(): void
    {
        $project = $this->project();

        $this->postJson("/api/projects/{$project->id}/send-creation")->assertOk();
        $this->postJson("/api/projects/{$project->id}/send-creation")->assertOk();

        $this->assertCount(2, $this->sentMessages());
        $reply = $this->sentMessages()->last()->getOriginalMessage();
        $this->assertSame('Re: ' . $project->fresh()->email_thread_subject, $reply->getSubject());
        $this->assertSame(
            '<' . $project->fresh()->email_thread_message_id . '>',
            $reply->getHeaders()->get('In-Reply-To')->getBodyAsString()
        );
    }

    public function test_sin_el_permiso_no_puede_enviar_la_creacion(): void
    {
        $this->givePermissions(['project list']);
        $project = $this->project();

        $this->postJson("/api/projects/{$project->id}/send-creation")->assertForbidden();

        $this->assertCount(0, $this->sentMessages());
    }

    public function test_sin_destinatarios_el_boton_responde_422(): void
    {
        Process::query()->delete();
        $project = $this->project(['ejecutivo_id' => null, 'ejecutivo' => 'Nadie Registrado']);

        $this->postJson("/api/projects/{$project->id}/send-creation")->assertStatus(422);

        $this->assertCount(0, $this->sentMessages());
    }
}
