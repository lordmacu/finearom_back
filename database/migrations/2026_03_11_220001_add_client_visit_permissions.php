<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [
            'client visit list',
            'client visit create',
            'client visit edit',
            'client visit delete',
        ];

        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'web');
        }

        // Super-admin recibe todos los permisos
        $superAdmin = Role::where('name', 'super-admin')->where('guard_name', 'web')->first();
        if ($superAdmin) {
            $superAdmin->syncPermissions(Permission::where('guard_name', 'web')->get());
        }

        // Roles con acceso completo
        $fullAccessRoles = ['admin', 'Administrador', 'Gerente'];
        foreach ($fullAccessRoles as $roleName) {
            $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();
            if ($role) {
                $role->givePermissionTo($permissions);
            }
        }

        // Roles comerciales: sin delete
        $commercialRoles = ['Creador de Ordenes de Compra', 'order-creator'];
        foreach ($commercialRoles as $roleName) {
            $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();
            if ($role) {
                $role->givePermissionTo(['client visit list', 'client visit create', 'client visit edit']);
            }
        }

        // Roles observadores: solo lectura
        $viewerRoles = ['Observador', 'Visualizador de Ordenes de Compra', 'Email Marketing'];
        foreach ($viewerRoles as $roleName) {
            $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();
            if ($role) {
                $role->givePermissionTo(['client visit list']);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['client visit list', 'client visit create', 'client visit edit', 'client visit delete'] as $name) {
            $permission = Permission::where('name', $name)->where('guard_name', 'web')->first();
            if ($permission) {
                $permission->delete();
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
