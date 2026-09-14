<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * El ingeniero de desarrollo marca "Entregado" en sus proyectos asignados.
     * El alcance (solo dept desarrollo, solo sus proyectos) lo enforce
     * ProjectWorkflowController::deliver.
     */
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permission = Permission::firstOrCreate(['name' => 'project deliver', 'guard_name' => 'web']);

        Role::where('name', 'Desarrollo')->first()?->givePermissionTo($permission);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Role::where('name', 'Desarrollo')->first()?->revokePermissionTo('project deliver');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
