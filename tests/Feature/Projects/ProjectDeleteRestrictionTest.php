<?php

namespace Tests\Feature\Projects;

use App\Models\Project;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** Eliminar proyectos: solo el Director de Investigación y Desarrollo y super-admin. */
class ProjectDeleteRestrictionTest extends ProjectFieldsTestCase
{
    private function migrar(): void
    {
        (require database_path('migrations/2026_10_18_100000_restrict_project_delete_to_director_id.php'))->up();
    }

    private function proyecto(): Project
    {
        return Project::create(['nombre' => 'A borrar', 'fecha_creacion' => today()]);
    }

    private function usuarioConRol(string $rol): User
    {
        $u = User::create(['name' => $rol, 'email' => str_replace(' ', '', $rol) . '@finearom.co', 'password' => bcrypt('x')]);
        $u->assignRole(Role::where('name', $rol)->first());
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u;
    }

    public function test_crea_el_rol_y_deja_el_permiso_solo_en_director_y_super_admin(): void
    {
        $delete = Permission::create(['name' => 'project delete', 'guard_name' => 'web']);
        foreach (['super-admin', 'admin', 'Administrador', 'Gerente', 'Comercial'] as $r) {
            $rol = Role::create(['name' => $r, 'guard_name' => 'web']);
            if ($r !== 'Comercial') {
                $rol->givePermissionTo($delete);
            }
        }

        $this->migrar();

        $con = Role::whereHas('permissions', fn ($q) => $q->where('name', 'project delete'))->pluck('name')->sort()->values()->all();
        $this->assertSame(['Director de Investigación y Desarrollo', 'super-admin'], $con);
        $director = Role::where('name', 'Director de Investigación y Desarrollo')->first();
        $this->assertTrue($director->hasPermissionTo('project list'));
    }

    public function test_es_idempotente_y_no_duplica_el_rol(): void
    {
        Permission::create(['name' => 'project delete', 'guard_name' => 'web']);

        $this->migrar();
        $this->migrar();

        $this->assertSame(1, Role::where('name', 'Director de Investigación y Desarrollo')->count());
    }

    public function test_solo_el_director_y_super_admin_pueden_eliminar_un_proyecto(): void
    {
        $delete = Permission::create(['name' => 'project delete', 'guard_name' => 'web']);
        foreach (['super-admin', 'admin', 'Gerente'] as $r) {
            Role::create(['name' => $r, 'guard_name' => 'web'])->givePermissionTo($delete);
        }
        $this->migrar();

        $this->actingAs($this->usuarioConRol('admin'), 'sanctum');
        $this->deleteJson('/api/projects/' . $this->proyecto()->id)->assertForbidden();

        $this->actingAs($this->usuarioConRol('Gerente'), 'sanctum');
        $this->deleteJson('/api/projects/' . $this->proyecto()->id)->assertForbidden();

        $this->actingAs($this->usuarioConRol('Director de Investigación y Desarrollo'), 'sanctum');
        $this->deleteJson('/api/projects/' . ($p = $this->proyecto())->id)->assertOk();
        $this->assertSoftDeleted('projects', ['id' => $p->id]);

        $this->actingAs($this->usuarioConRol('super-admin'), 'sanctum');
        $this->deleteJson('/api/projects/' . ($q = $this->proyecto())->id)->assertOk();
        $this->assertSoftDeleted('projects', ['id' => $q->id]);
    }
}
