<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * La regla de dueño (ProjectOwnership) permite a una comercial editar solo
     * SUS proyectos, pero el rol Comercial no tenía el permiso base de edición:
     * sin este grant ni la dueña podría editar. El alcance "solo sus proyectos"
     * lo enforce ProjectOwnership en los FormRequests y los botones de correo.
     */
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permission = Permission::firstOrCreate(['name' => 'project edit', 'guard_name' => 'web']);

        Role::where('name', 'Comercial')->first()?->givePermissionTo($permission);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Role::where('name', 'Comercial')->first()?->revokePermissionTo('project edit');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
