<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * El modal de referencias de Proyectos (ProjectReferencesModal.vue) deja
     * buscar un producto terminado por código/nombre al armar una referencia.
     * Lo usa el ingeniero de Desarrollo asignado al proyecto, que hoy no
     * tiene ningún permiso de producto terminado — solo necesita poder
     * listarlos, no crear/editar/eliminar.
     */
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permission = Permission::firstOrCreate(['name' => 'producto terminado list', 'guard_name' => 'web']);

        $role = Role::where('name', 'Desarrollo')->first();
        if ($role) {
            $role->givePermissionTo($permission);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::where('name', 'Desarrollo')->first();
        $permission = Permission::where('name', 'producto terminado list')->first();
        if ($role && $permission) {
            $role->revokePermissionTo($permission);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
