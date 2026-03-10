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
            'project list',
            'project create',
            'project edit',
            'project delete',
            'project external status',
            'project deliver',
            'project factor edit',
            'project catalog manage',
        ];

        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'web');
        }

        // super-admin: todos los permisos de proyectos
        $superAdmin = Role::where('name', 'super-admin')->where('guard_name', 'web')->first();
        if ($superAdmin) {
            $superAdmin->givePermissionTo($permissions);
        }

        // Roles con acceso completo a proyectos
        foreach (['admin', 'Administrador', 'Gerente'] as $roleName) {
            $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();
            if ($role) {
                $role->givePermissionTo([
                    'project list', 'project create', 'project edit', 'project delete',
                    'project external status', 'project deliver', 'project factor edit',
                    'project catalog manage',
                ]);
            }
        }

        // Roles comerciales
        foreach (['Creador de Ordenes de Compra', 'order-creator'] as $roleName) {
            $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();
            if ($role) {
                $role->givePermissionTo([
                    'project list', 'project create', 'project edit', 'project external status',
                ]);
            }
        }

        // Roles observadores: solo lectura
        foreach (['Observador', 'Visualizador de Ordenes de Compra', 'Email Marketing'] as $roleName) {
            $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();
            if ($role) {
                $role->givePermissionTo(['project list']);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [
            'project list', 'project create', 'project edit', 'project delete',
            'project external status', 'project deliver', 'project factor edit', 'project catalog manage',
        ];

        foreach ($permissions as $name) {
            $perm = Permission::where('name', $name)->where('guard_name', 'web')->first();
            if ($perm) {
                $perm->delete();
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
