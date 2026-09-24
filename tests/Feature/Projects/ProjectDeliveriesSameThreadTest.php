<?php

namespace Tests\Feature\Projects;

use App\Models\Process;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Todo lo que sale después del correo de creación va en ESE hilo: entregas
 * parciales, finales y actualizaciones de todas las áreas, y la de Desarrollo.
 * Cada correo es "Re: <asunto de la creación>" con In-Reply-To y References
 * apuntando al Message-ID del correo de creación.
 */
class ProjectDeliveriesSameThreadTest extends ProjectMailTestCase
{
    private const AREAS = [
        'aplicaciones' => ['applications_partial', 'applications_ready', 'applications_updated'],
        'evaluaciones' => ['evaluation_partial', 'evaluation_delivered', 'evaluation_updated'],
        'marketing'    => ['marketing_partial', 'marketing_delivered', 'marketing_updated'],
        'regulatoria'  => ['regulatoria_partial', 'regulatoria_delivered', 'regulatoria_updated'],
        'especiales'   => ['especiales_partial', 'especiales_delivered', 'especiales_updated'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->template('project_created', 'Nuevo proyecto #|project_id| — |project_name|');
        foreach (self::AREAS as $acciones) {
            foreach ($acciones as $accion) {
                // Asunto propio a propósito: en el hilo debe ignorarse y usar el de la creación
                $this->template("project_{$accion}", "OTRO ASUNTO {$accion}", '|delivered_by| [|tipo_entrega|] |notas_entrega|');
            }
        }
        $this->template('project_development_delivered', 'OTRO ASUNTO desarrollo', '|engineer_name|');
        $this->template('project_development_updated', 'OTRO ASUNTO desarrollo act', '|engineer_name||changes_table|');
        Process::create(['name' => 'Lab', 'email' => 'lab@finearom.co', 'process_type' => 'proyectos']);

        $this->givePermissions(['project list', 'project edit', 'project send creation', 'project deliver']);
    }

    public function test_parciales_finales_y_actualizaciones_de_todas_las_areas_van_en_el_hilo_de_la_creacion(): void
    {
        $project = $this->project();

        // El correo de creación abre el hilo
        $this->postJson("/api/projects/{$project->id}/send-creation")->assertOk();
        $project->refresh();
        $rootId      = $project->email_thread_message_id;
        $rootSubject = $project->email_thread_subject;
        $this->assertNotNull($rootId);
        $this->assertCount(1, $this->sentMessages());

        foreach (array_keys(self::AREAS) as $area) {
            $url = "/api/projects/{$project->id}/{$area}/entregar";
            $this->postJson($url, ['tipo' => 'parcial', 'notas' => "Parcial {$area}", 'adjuntos' => [UploadedFile::fake()->create('p.pdf', 10)]])->assertOk();
            $this->postJson($url, ['tipo' => 'final', 'notas' => "Final {$area}"])->assertOk();
            $this->postJson($url, ['tipo' => 'parcial', 'notas' => "Ajuste {$area}"])->assertOk(); // ya entregada: actualización
        }

        // 1 creación + 5 áreas × 3 entregas
        $correos = $this->sentMessages()->slice(1)->values();
        $this->assertCount(15, $correos);

        foreach ($correos as $i => $sent) {
            $correo = $sent->getOriginalMessage();
            $this->assertSame('Re: ' . $rootSubject, $correo->getSubject(), "Correo #{$i} fuera del hilo");
            $this->assertSame('<' . $rootId . '>', $correo->getHeaders()->get('In-Reply-To')->getBodyAsString());
            $this->assertStringContainsString($rootId, $correo->getHeaders()->get('References')->getBodyAsString());
        }

        // Parcial, final y actualización salieron por cada área
        $cuerpos = $correos->map(fn ($s) => $s->getOriginalMessage()->getHtmlBody())->implode("\n");
        foreach (['Entrega parcial', 'Entrega final', 'Actualización'] as $tipo) {
            $this->assertSame(5, substr_count($cuerpos, "[{$tipo}]"), "Faltan correos de '{$tipo}'");
        }
    }

    public function test_la_entrega_de_desarrollo_y_su_reentrega_van_en_el_hilo_de_la_creacion(): void
    {
        $role = Role::firstOrCreate(['name' => 'Desarrollo', 'guard_name' => 'web']);
        $engineer = User::create(['name' => 'Ing. Hilo', 'email' => 'ing.hilo@finearom.co', 'password' => bcrypt('x')]);
        $engineer->assignRole($role);
        $engineer->givePermissionTo(Permission::firstOrCreate(['name' => 'project deliver', 'guard_name' => 'web']));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $project = $this->project(['desarrollador_id' => $engineer->id, 'estado_interno' => 'En proceso']);
        $this->postJson("/api/projects/{$project->id}/send-creation")->assertOk();
        $project->refresh();
        $antes = $this->sentMessages()->count();

        $this->actingAs($engineer, 'sanctum')->patchJson("/api/projects/{$project->id}/entregar", ['department' => 'desarrollo'])->assertOk();
        $this->actingAs($engineer, 'sanctum')->patchJson("/api/projects/{$project->id}/entregar", ['department' => 'desarrollo'])->assertOk();

        $correos = $this->sentMessages()->slice($antes)->values();
        $this->assertCount(2, $correos);
        foreach ($correos as $sent) {
            $correo = $sent->getOriginalMessage();
            $this->assertSame('Re: ' . $project->email_thread_subject, $correo->getSubject());
            $this->assertSame('<' . $project->email_thread_message_id . '>', $correo->getHeaders()->get('In-Reply-To')->getBodyAsString());
        }
    }
}
