<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Administrar el catálogo de tipos de envase (crear/editar/eliminar) es
     * un módulo aparte, reservado a Marketing (más los roles gerenciales que
     * ya administran el resto de catálogos de proyectos).
     */
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permission = Permission::findOrCreate('envelope type manage', 'web');

        Role::whereIn('name', ['Marketing', 'Administrador', 'Gerente'])
            ->where('guard_name', 'web')
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo($permission));
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Role::whereIn('name', ['Marketing', 'Administrador', 'Gerente'])
            ->where('guard_name', 'web')
            ->get()
            ->each(fn (Role $role) => $role->revokePermissionTo('envelope type manage'));

        Permission::where('name', 'envelope type manage')->where('guard_name', 'web')->delete();
    }
};
