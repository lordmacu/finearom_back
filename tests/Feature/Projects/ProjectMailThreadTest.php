<?php

namespace Tests\Feature\Projects;

use App\Models\EmailLog;
use App\Models\Process;
use App\Services\ProjectMailService;
use Illuminate\Support\Facades\DB;

class ProjectMailThreadTest extends ProjectMailTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->template(
            'project_created',
            'Nuevo proyecto #|project_id| — |project_name| · |client_name|',
            '|project_table|<a href="|project_url|">Ver proyecto</a>'
        );
        $this->template('project_test_action', 'Acción de prueba |project_name|', '<p>Novedad: |detalle|</p>');
    }

    public function test_el_primer_correo_abre_el_hilo_y_lo_guarda(): void
    {
        Process::create(['name' => 'Lab', 'email' => 'lab@finearom.co, desarrollo@finearom.co', 'process_type' => 'project_created']);
        $project = $this->project();

        app(ProjectMailService::class)->send($project, 'created');

        $this->assertCount(1, $this->sentMessages());
        $sent  = $this->sentMessages()->first();
        $email = $sent->getOriginalMessage();

        $this->assertSame(['lab@finearom.co'], $this->addresses($email->getTo()));
        $this->assertSame(['desarrollo@finearom.co', 'tester@finearom.co'], $this->addresses($email->getCc()));
        $this->assertSame("Nuevo proyecto #{$project->id} — Aroma Test · Prospecto SAS", $email->getSubject());

        $html = $email->getHtmlBody();
        $this->assertStringContainsString("https://ordenes.test/projects/{$project->id}", $html);
        $this->assertStringContainsString('Prospecto SAS', $html);
        // Las filas sin dato no se pintan en la ficha
        $this->assertStringNotContainsString('Fecha requerida', $html);

        $project->refresh();
        $this->assertSame($sent->getMessageId(), $project->email_thread_message_id);
        // El id del hilo es el header Message-ID REAL del correo (no el id de
        // cola del servidor SMTP): las respuestas referencian este valor
        $messageIdHeader = $email->getHeaders()->get('Message-ID')?->getId();
        $this->assertSame($messageIdHeader, $project->email_thread_message_id);
        $this->assertStringStartsWith("project-{$project->id}-", (string) $messageIdHeader);
        $this->assertSame($email->getSubject(), $project->email_thread_subject);
        $this->assertSame('project_created', EmailLog::first()->process_type);
    }

    public function test_el_siguiente_correo_responde_en_el_mismo_hilo(): void
    {
        Process::create(['name' => 'Lab', 'email' => 'lab@finearom.co', 'process_type' => 'project_created']);
        Process::create(['name' => 'Mkt', 'email' => 'mkt@finearom.co', 'process_type' => 'project_test_action']);
        $project = $this->project();
        $service = app(ProjectMailService::class);

        $service->send($project, 'created');
        $rootId      = $project->fresh()->email_thread_message_id;
        $rootSubject = $project->fresh()->email_thread_subject;

        $service->send($project->fresh(), 'test_action', ['detalle' => 'variante creada']);

        $this->assertCount(2, $this->sentMessages());
        $reply = $this->sentMessages()->last()->getOriginalMessage();

        $this->assertSame('Re: ' . $rootSubject, $reply->getSubject());
        $this->assertSame('<' . $rootId . '>', $reply->getHeaders()->get('In-Reply-To')->getBodyAsString());
        $this->assertSame('<' . $rootId . '>', $reply->getHeaders()->get('References')->getBodyAsString());
        $this->assertSame(['mkt@finearom.co'], $this->addresses($reply->getTo()));
        $this->assertStringContainsString('variante creada', $reply->getHtmlBody());

        // El hilo sigue apuntando a la raíz
        $this->assertSame($rootId, $project->fresh()->email_thread_message_id);
        $this->assertSame($rootSubject, $project->fresh()->email_thread_subject);
    }

    public function test_sin_destinatarios_no_envia_ni_abre_hilo(): void
    {
        $project = $this->project(['ejecutivo_id' => null, 'ejecutivo' => 'Nadie Registrado']);

        app(ProjectMailService::class)->send($project, 'created');

        $this->assertCount(0, $this->sentMessages());
        $this->assertNull($project->fresh()->email_thread_message_id);
    }

    public function test_template_inexistente_no_lanza_ni_abre_hilo(): void
    {
        Process::create(['name' => 'Lab', 'email' => 'lab@finearom.co', 'process_type' => 'project_sin_template']);
        $project = $this->project();

        app(ProjectMailService::class)->send($project, 'sin_template');

        $this->assertCount(0, $this->sentMessages());
        $this->assertNull($project->fresh()->email_thread_message_id);
    }

    public function test_template_inactivo_no_envia(): void
    {
        DB::table('email_templates')->where('key', 'project_created')->update(['is_active' => false]);
        Process::create(['name' => 'Lab', 'email' => 'lab@finearom.co', 'process_type' => 'project_created']);
        $project = $this->project();

        app(ProjectMailService::class)->send($project, 'created');

        $this->assertCount(0, $this->sentMessages());
        $this->assertNull($project->fresh()->email_thread_message_id);
    }

    public function test_el_ejecutivo_no_se_duplica_si_ya_esta_en_la_lista(): void
    {
        Process::create(['name' => 'Ejecutivo', 'email' => 'TESTER@finearom.co', 'process_type' => 'project_created']);
        $project = $this->project();

        app(ProjectMailService::class)->send($project, 'created');

        $email = $this->sentMessages()->first()->getOriginalMessage();
        $this->assertSame(['tester@finearom.co'], $this->addresses($email->getTo()));
        $this->assertSame([], $this->addresses($email->getCc()));
    }

    public function test_sin_ejecutivo_id_el_ejecutivo_se_busca_por_nombre(): void
    {
        $project = $this->project(['ejecutivo_id' => null, 'ejecutivo' => 'Tester']);

        app(ProjectMailService::class)->send($project, 'created');

        $email = $this->sentMessages()->first()->getOriginalMessage();
        $this->assertSame(['tester@finearom.co'], $this->addresses($email->getTo()));
    }

    public function test_la_asignacion_del_ingeniero_antes_de_la_creacion_no_abre_el_hilo(): void
    {
        Process::create(['name' => 'Lab', 'email' => 'lab@finearom.co', 'process_type' => 'project_created']);
        $this->template('project_engineer_assigned', 'Se asignó ingeniero — proyecto #|project_id|', '|engineer_name|');
        $engineer = \App\Models\User::create(['name' => 'Ing. Hilo', 'email' => 'ing.hilo@finearom.co', 'password' => bcrypt('x')]);
        $project  = $this->project();

        // La asignación sale ANTES del correo de creación (ingeniero elegido en el formulario)
        app(ProjectMailService::class)->sendEngineerAssigned($project, $engineer);

        // Sale standalone: NO abre el hilo ni le roba el asunto
        $this->assertCount(1, $this->sentMessages());
        $this->assertNull($project->fresh()->email_thread_message_id);
        $this->assertNull($project->fresh()->email_thread_subject);

        // La creación sigue abriendo el hilo con su propio asunto y Message-ID
        app(ProjectMailService::class)->send($project->fresh(), 'created');

        $project->refresh();
        $this->assertNotNull($project->email_thread_message_id);
        $this->assertSame("Nuevo proyecto #{$project->id} — Aroma Test · Prospecto SAS", $project->email_thread_subject);
        $creation = $this->sentMessages()->last()->getOriginalMessage();
        $this->assertSame(
            '<' . $project->email_thread_message_id . '>',
            $creation->getHeaders()->get('Message-ID')->getBodyAsString()
        );
    }
}
