<?php

namespace Tests\Feature\Projects;

use App\Models\Process;
use App\Models\ProjectMarketingVariant;
use App\Models\ProjectMarketingVariantReference;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Desarrollo entrega con el mismo modal que las demás áreas: notas + adjuntos,
 * parcial y final, en el hilo del proyecto y con la tabla de variantes.
 */
class ProjectDevelopmentAreaDeliveryTest extends ProjectMailTestCase
{
    private User $engineer;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->template('project_created', 'Nuevo proyecto #|project_id|');
        $this->template('project_development_delivered', 'x', '|engineer_name||variants_table|');
        $this->template('project_development_updated', 'x', '|engineer_name||changes_table||variants_table|');
        // Plantilla parcial y notas en final/actualización: las agrega la migración
        (require database_path('migrations/2026_09_24_140000_development_delivery_with_notes_templates.php'))->up();
        Process::create(['name' => 'Lab', 'email' => 'lab@finearom.co', 'process_type' => 'proyectos']);

        $this->engineer = User::create(['name' => 'Ing. Prueba', 'email' => 'ingeniero@finearom.co', 'password' => bcrypt('x')]);
        $this->engineer->assignRole(Role::firstOrCreate(['name' => 'Desarrollo', 'guard_name' => 'web']));
        $this->engineer->givePermissionTo(Permission::firstOrCreate(['name' => 'project deliver', 'guard_name' => 'web']));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function proyecto(array $attrs = [])
    {
        $project = $this->threadedProject(array_merge(['desarrollador_id' => $this->engineer->id, 'estado_interno' => 'En proceso'], $attrs));
        $v = ProjectMarketingVariant::create(['project_id' => $project->id, 'nombre' => 'Var Premium']);
        ProjectMarketingVariantReference::create(['variant_id' => $v->id, 'referencia' => 'Ref Aroma 123', 'codigo' => 'A-1', 'orden' => 1]);

        return $project;
    }

    private function entregar($project, array $datos, ?User $como = null)
    {
        return $this->actingAs($como ?? $this->engineer, 'sanctum')
            ->postJson("/api/projects/{$project->id}/desarrollo/entregar", $datos);
    }

    public function test_parcial_y_final_con_notas_y_adjuntos_en_el_hilo(): void
    {
        $project = $this->proyecto();

        $this->entregar($project, ['tipo' => 'parcial', 'notas' => '<p>Primer avance</p>', 'adjuntos' => [UploadedFile::fake()->create('avance.pdf', 20)]])
            ->assertOk()->assertJsonPath('message', 'Entrega parcial de Desarrollo registrada');
        $this->assertFalse((bool) $project->fresh()->estado_desarrollo);

        $this->entregar($project, ['tipo' => 'final', 'notas' => '<p>Fórmulas listas</p>'])
            ->assertOk()->assertJsonPath('message', 'Desarrollo: entrega final registrada');
        $this->assertTrue((bool) $project->fresh()->estado_desarrollo);

        $this->entregar($project, ['tipo' => 'parcial', 'notas' => '<p>Ajuste posterior</p>'])->assertOk();

        [$parcial, $final, $actualizacion] = $this->sentMessages()->map->getOriginalMessage()->values()->all();
        foreach ([$parcial, $final, $actualizacion] as $correo) {
            $this->assertSame('Re: ' . $project->email_thread_subject, $correo->getSubject());
            $this->assertStringContainsString('Ref Aroma 123', $correo->getHtmlBody());
            $this->assertStringContainsString('Ing. Prueba', $correo->getHtmlBody());
        }
        $this->assertStringContainsString('Primer avance', $parcial->getHtmlBody());
        $this->assertCount(1, $parcial->getAttachments());
        $this->assertStringContainsString('Fórmulas listas', $final->getHtmlBody());
        $this->assertStringContainsString('Ajuste posterior', $actualizacion->getHtmlBody());

        $this->assertSame(['actualizacion', 'final', 'parcial'],
            DB::table('project_area_delivery_logs')->where('area', 'desarrollo')->orderByDesc('id')->pluck('tipo')->all());
    }

    public function test_la_bitacora_de_desarrollo_se_consulta_para_el_modal(): void
    {
        $project = $this->proyecto();
        $this->entregar($project, ['tipo' => 'parcial', 'notas' => '<p>Avance</p>'])->assertOk();

        $this->givePermissions(['project list']);
        $this->actingAs($this->user, 'sanctum')->getJson("/api/projects/{$project->id}/desarrollo/entrega")
            ->assertOk()
            ->assertJsonPath('data.entregado', false)
            ->assertJsonPath('data.entregas.0.tipo', 'parcial');
    }

    public function test_un_ingeniero_no_entrega_proyectos_de_otro(): void
    {
        $otro = User::create(['name' => 'Otro Ing', 'email' => 'otro@finearom.co', 'password' => bcrypt('x')]);
        $project = $this->proyecto(['desarrollador_id' => $otro->id]);

        $this->entregar($project, ['tipo' => 'final', 'notas' => '<p>x</p>'])->assertForbidden();
        $this->assertFalse((bool) $project->fresh()->estado_desarrollo);
    }

    public function test_sin_hilo_de_creacion_no_se_entrega(): void
    {
        $project = $this->project(['desarrollador_id' => $this->engineer->id]);

        $this->entregar($project, ['tipo' => 'parcial', 'notas' => '<p>x</p>'])->assertStatus(422);
    }
}
