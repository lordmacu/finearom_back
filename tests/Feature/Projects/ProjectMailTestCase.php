<?php

namespace Tests\Feature\Projects;

use App\Models\Project;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Base para los tests de correos de proyecto: agrega al esquema sqlite de
 * ProjectFieldsTestCase las tablas que intervienen en el envío (templates,
 * procesos, log de correos, historial) y las columnas del hilo en `projects`.
 * El listener real LogSentMessage corre, por eso existe `email_logs`.
 */
abstract class ProjectMailTestCase extends ProjectFieldsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Caché array: EmailTemplateService cachea el template 600 s y no debe filtrarse entre tests
        config([
            'mail.default'     => 'array',
            'cache.default'    => 'array',
            'app.frontend_url' => 'https://ordenes.test',
        ]);

        Schema::table('projects', function (Blueprint $t) {
            $t->json('secciones_visibles')->nullable();
            $t->string('email_thread_message_id')->nullable();
            $t->string('email_thread_subject', 500)->nullable();
            $t->json('email_snapshot')->nullable();
        });

        Schema::create('email_templates', function (Blueprint $t) {
            $t->id();
            $t->string('key', 100);
            $t->string('name');
            $t->string('subject', 500)->nullable();
            $t->string('title')->nullable();
            $t->longText('header_content')->nullable();
            $t->longText('footer_content')->nullable();
            $t->longText('signature')->nullable();
            $t->json('available_variables')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('processes', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->text('email');
            $t->string('process_type', 100);
            $t->timestamps();
        });

        Schema::create('email_logs', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->string('sender_email')->nullable();
            $t->string('recipient_email');
            $t->string('subject')->nullable();
            $t->longText('content')->nullable();
            $t->string('process_type')->default('system');
            $t->json('metadata')->nullable();
            $t->string('status')->default('pending');
            $t->text('error_message')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('opened_at')->nullable();
            $t->integer('open_count')->default(0);
            $t->string('ip_address')->nullable();
            $t->text('user_agent')->nullable();
            $t->timestamps();
        });

        Schema::create('project_status_history', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('project_id');
            $t->string('tipo', 50);
            $t->string('descripcion', 500);
            $t->string('ejecutivo', 200)->nullable();
            $t->timestamp('created_at')->useCurrent();
        });
    }

    protected function template(string $key, string $subject, string $body = '|project_table|', bool $active = true): void
    {
        DB::table('email_templates')->insert([
            'key'            => $key,
            'name'           => $key,
            'subject'        => $subject,
            'title'          => 'Titulo',
            'header_content' => $body,
            'is_active'      => $active,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
    }

    protected function project(array $attrs = []): Project
    {
        return Project::create(array_merge([
            'nombre'           => 'Aroma Test',
            'tipo'             => 'Desarrollo',
            'fecha_creacion'   => today(),
            'nombre_prospecto' => 'Prospecto SAS',
            'ejecutivo'        => 'Tester',
            'ejecutivo_id'     => $this->user->id,
        ], $attrs));
    }

    /**
     * Proyecto al que ya se le envió la creación (hilo abierto): las entregas
     * y demás correos solo salen dentro de ese hilo.
     */
    protected function threadedProject(array $attrs = []): Project
    {
        $project = $this->project($attrs);
        $project->forceFill([
            'email_thread_message_id' => "project-{$project->id}-test@finearom.co",
            'email_thread_subject'    => "Nuevo proyecto #{$project->id} — {$project->nombre}",
        ])->save();

        return $project;
    }

    /** Correos capturados por el mailer `array` (instancias de Symfony SentMessage). */
    protected function sentMessages(): Collection
    {
        return app('mail.manager')->mailer('array')->getSymfonyTransport()->messages();
    }

    /** @param \Symfony\Component\Mime\Address[] $addresses */
    protected function addresses(array $addresses): array
    {
        return array_map(fn ($address) => $address->getAddress(), $addresses);
    }
}
