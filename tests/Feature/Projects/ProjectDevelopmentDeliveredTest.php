<?php

namespace Tests\Feature\Projects;

use App\Models\EmailLog;
use App\Models\Process;
use App\Models\ProjectMarketingVariant;
use App\Models\ProjectMarketingVariantReference;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class ProjectDevelopmentDeliveredTest extends ProjectMailTestCase
{
    private User $engineer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->template('project_created', 'Nuevo proyecto #|project_id| — |project_name|');
        $this->template('project_development_delivered', 'Desarrollo entregado — proyecto #|project_id| — |project_name|', '|engineer_name||variants_table|');
        $this->template('project_development_updated', 'Desarrollo actualizado — proyecto #|project_id| — |project_name|', '|engineer_name||changes_table||variants_table|');
        Process::create(['name' => 'Lab', 'email' => 'lab@finearom.co', 'process_type' => 'proyectos']);

        $role = Role::firstOrCreate(['name' => 'Desarrollo', 'guard_name' => 'web']);
        $deliver = Permission::firstOrCreate(['name' => 'project deliver', 'guard_name' => 'web']);

        $this->engineer = User::create(['name' => 'Ing. Prueba', 'email' => 'ingeniero@finearom.co', 'password' => bcrypt('x')]);
        $this->engineer->assignRole($role);
        $this->engineer->givePermissionTo($deliver);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function projectConVariantes(array $attrs = []): \App\Models\Project
    {
        $project = $this->project(array_merge(['desarrollador_id' => $this->engineer->id], $attrs));
        $variant = ProjectMarketingVariant::create(['project_id' => $project->id, 'nombre' => 'Var Premium', 'claims' => 'Sin parabenos']);
        ProjectMarketingVariantReference::create([
            'variant_id' => $variant->id, 'referencia' => 'Ref Aroma 123', 'codigo' => 'A-123', 'aplicacion' => 'Shampoo', 'dosis' => 0.8,
        ]);
        return $project;
    }

    public function test_el_ingeniero_asignado_entrega_desarrollo_y_se_envia_el_correo(): void
    {
        $project = $this->projectConVariantes(['estado_interno' => 'En proceso']);

        $this->actingAs($this->engineer, 'sanctum')
            ->patchJson("/api/projects/{$project->id}/entregar", ['department' => 'desarrollo'])
            ->assertOk();

        $this->assertTrue((bool) $project->fresh()->estado_desarrollo);

        $email = $this->sentMessages()->last()->getOriginalMessage();
        // Fallback: sin lista project_development_delivered va a los de project_created
        $this->assertSame(['lab@finearom.co'], $this->addresses($email->getTo()));
        $html = $email->getHtmlBody();
        $this->assertStringContainsString('Ing. Prueba', $html);
        $this->assertStringContainsString('Var Premium', $html);
        $this->assertStringContainsString('Ref Aroma 123', $html);
        $this->assertSame('project_development_delivered', EmailLog::latest('id')->first()->process_type);
    }

    public function test_un_desarrollo_no_asignado_no_puede_entregar(): void
    {
        $otro = User::create(['name' => 'Ing. Otro', 'email' => 'ing2@finearom.co', 'password' => bcrypt('x')]);
        $otro->assignRole(Role::where('name', 'Desarrollo')->first());
        $otro->givePermissionTo('project deliver');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $project = $this->projectConVariantes();

        $this->actingAs($otro, 'sanctum')
            ->patchJson("/api/projects/{$project->id}/entregar", ['department' => 'desarrollo'])
            ->assertForbidden();

        $this->assertFalse((bool) $project->fresh()->estado_desarrollo);
        $this->assertCount(0, $this->sentMessages());
    }

    public function test_un_desarrollo_no_puede_entregar_otra_area(): void
    {
        $project = $this->projectConVariantes();

        $this->actingAs($this->engineer, 'sanctum')
            ->patchJson("/api/projects/{$project->id}/entregar", ['department' => 'laboratorio'])
            ->assertForbidden();

        $this->assertFalse((bool) $project->fresh()->estado_laboratorio);
    }

    public function test_un_rol_no_desarrollo_con_permiso_entrega_sin_restriccion(): void
    {
        $this->givePermissions(['project list', 'project deliver']);
        $project = $this->projectConVariantes(['estado_interno' => 'En proceso']);

        $this->patchJson("/api/projects/{$project->id}/entregar", ['department' => 'desarrollo'])->assertOk();

        $this->assertTrue((bool) $project->fresh()->estado_desarrollo);
    }

    public function test_reentregar_envia_correo_de_actualizacion_con_diff_y_tabla_completa(): void
    {
        $project = $this->projectConVariantes(['estado_interno' => 'En proceso']);

        $this->actingAs($this->engineer, 'sanctum')
            ->patchJson("/api/projects/{$project->id}/entregar", ['department' => 'desarrollo'])
            ->assertOk();

        // El ingeniero agrega otra referencia y vuelve a oprimir Entregado
        ProjectMarketingVariantReference::create([
            'variant_id' => $project->marketingVariants()->first()->id,
            'referencia' => 'Ref Nueva 456', 'codigo' => 'B-456', 'aplicacion' => 'Crema', 'dosis' => 1.2,
        ]);

        $this->actingAs($this->engineer, 'sanctum')
            ->patchJson("/api/projects/{$project->id}/entregar", ['department' => 'desarrollo'])
            ->assertOk();

        $this->assertCount(2, $this->sentMessages());

        $email = $this->sentMessages()->last()->getOriginalMessage();
        $html  = $email->getHtmlBody();

        // Va en el mismo hilo (Re: ...) y el ingeniero sigue copiado
        $this->assertStringStartsWith('Re: ', $email->getSubject());
        $this->assertContains('ingeniero@finearom.co', $this->addresses($email->getCc()));

        // Avisa que cambiaron y trae TODAS las variantes y referencias como quedaron
        $this->assertStringContainsString('Cambiaron las variantes o las referencias', $html);
        $this->assertStringContainsString('Ref Nueva 456', $html);
        $this->assertStringContainsString('Ref Aroma 123', $html);
        $this->assertStringContainsString('Var Premium', $html);
        $this->assertSame('project_development_updated', EmailLog::latest('id')->first()->process_type);
    }

    public function test_reentregar_sin_cambios_avisa_que_no_hubo_cambios(): void
    {
        $project = $this->projectConVariantes(['estado_interno' => 'En proceso']);

        $this->actingAs($this->engineer, 'sanctum')
            ->patchJson("/api/projects/{$project->id}/entregar", ['department' => 'desarrollo'])
            ->assertOk();

        $this->actingAs($this->engineer, 'sanctum')
            ->patchJson("/api/projects/{$project->id}/entregar", ['department' => 'desarrollo'])
            ->assertOk();

        $html = $this->sentMessages()->last()->getOriginalMessage()->getHtmlBody();
        $this->assertStringContainsString('Sin cambios', $html);
        $this->assertSame('project_development_updated', EmailLog::latest('id')->first()->process_type);
    }
}
