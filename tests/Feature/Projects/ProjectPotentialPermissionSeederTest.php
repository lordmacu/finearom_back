<?php

namespace Tests\Feature\Projects;

use Database\Seeders\ProjectPotentialPermissionSeeder;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Role;

class ProjectPotentialPermissionSeederTest extends ProjectFieldsTestCase
{
    public function test_otorga_los_permisos_al_rol_y_limpia_la_cache_de_permisos_del_usuario(): void
    {
        config(['cache.default' => 'array']);
        $role = Role::create(['name' => 'Gerente', 'guard_name' => 'web']);
        $this->user->assignRole($role);
        Cache::put("user.{$this->user->id}.permissions", ['project list'], 3600);

        (new ProjectPotentialPermissionSeeder())->run();

        $this->assertTrue($role->fresh()->hasPermissionTo('project potential edit'));
        $this->assertNull(Cache::get("user.{$this->user->id}.permissions"));
    }
}
