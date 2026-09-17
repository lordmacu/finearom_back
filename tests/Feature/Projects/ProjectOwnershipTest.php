<?php

namespace Tests\Feature\Projects;

use App\Models\Process;
use App\Models\Project;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Regla de dueño: una comercial solo edita y envía correos de proyectos donde
 * es la ejecutiva; el resto de roles sigue solo por permisos.
 */
class ProjectOwnershipTest extends ProjectMailTestCase
{
    private User $owner;
    private User $other;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->template('project_created', 'Nuevo proyecto #|project_id| — |project_name|');
        Process::create(['name' => 'Lab', 'email' => 'lab@finearom.co', 'process_type' => 'proyectos']);

        $role = Role::firstOrCreate(['name' => 'Comercial', 'guard_name' => 'web']);
        $permissions = collect(['project list', 'project edit', 'project send creation'])
            ->map(fn ($name) => Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']));

        $this->owner = User::create(['name' => 'Comercial Dueña', 'email' => 'duena@finearom.co', 'password' => bcrypt('x')]);
        $this->other = User::create(['name' => 'Comercial Otra', 'email' => 'otra@finearom.co', 'password' => bcrypt('x')]);

        foreach ([$this->owner, $this->other] as $user) {
            $user->assignRole($role);
            foreach ($permissions as $permission) {
                $user->givePermissionTo($permission);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->project = $this->project([
            'ejecutivo'    => $this->owner->name,
            'ejecutivo_id' => $this->owner->id,
        ]);
    }

    public function test_la_duena_edita_su_proyecto_y_envia_los_correos(): void
    {
        $this->actingAs($this->owner, 'sanctum');

        $this->putJson("/api/projects/{$this->project->id}", ['tipo_etiquetado' => 'SGA'])->assertOk();
        $this->putJson("/api/projects/{$this->project->id}/sample", ['cantidad' => 3])->assertOk();
        $this->putJson("/api/projects/{$this->project->id}/application", ['dosis' => 2])->assertOk();
        $this->putJson("/api/projects/{$this->project->id}/evaluation", ['observacion' => 'ok'])->assertOk();
        $this->putJson("/api/projects/{$this->project->id}/marketing", ['marca' => 'Marca X'])->assertOk();

        $this->postJson("/api/projects/{$this->project->id}/send-creation")->assertOk();
        $this->assertCount(1, $this->sentMessages());

        $this->getJson("/api/projects/{$this->project->id}")
            ->assertOk()
            ->assertJsonPath('can_manage', true);
    }

    public function test_otra_comercial_no_puede_editar_ni_enviar_correos(): void
    {
        $this->actingAs($this->other, 'sanctum');

        $this->putJson("/api/projects/{$this->project->id}", ['tipo_etiquetado' => 'SGA'])->assertForbidden();
        $this->putJson("/api/projects/{$this->project->id}/sample", ['cantidad' => 3])->assertForbidden();
        $this->putJson("/api/projects/{$this->project->id}/application", ['dosis' => 2])->assertForbidden();
        $this->putJson("/api/projects/{$this->project->id}/evaluation", ['observacion' => 'x'])->assertForbidden();
        $this->putJson("/api/projects/{$this->project->id}/marketing", ['marca' => 'X'])->assertForbidden();

        $this->postJson("/api/projects/{$this->project->id}/send-creation")->assertForbidden();
        $this->postJson("/api/projects/{$this->project->id}/send-update")->assertForbidden();

        $this->assertCount(0, $this->sentMessages());
        $this->assertNull($this->project->fresh()->tipo_etiquetado);

        $this->getJson("/api/projects/{$this->project->id}")
            ->assertOk()
            ->assertJsonPath('can_manage', false);
    }

    public function test_un_rol_no_comercial_con_permiso_edita_cualquier_proyecto(): void
    {
        // El usuario Tester del TestCase (sin rol Comercial) no tiene restricción de dueño
        $this->putJson("/api/projects/{$this->project->id}", ['tipo_etiquetado' => 'Estandar'])->assertOk();
        $this->putJson("/api/projects/{$this->project->id}/sample", ['cantidad' => 7])->assertOk();

        $this->getJson("/api/projects/{$this->project->id}")
            ->assertOk()
            ->assertJsonPath('can_manage', true);
    }
}
