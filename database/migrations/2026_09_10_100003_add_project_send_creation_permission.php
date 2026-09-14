<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Permiso del botón "Enviar creación de proyecto" (detalle del proyecto):
     * dispara el correo que abre el hilo interno. Solo Comercial y admins.
     */
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permission = Permission::firstOrCreate([
            'name' => 'project send creation', 'guard_name' => 'web',
        ]);

        Role::whereIn('name', ['Comercial', 'Administrador', 'admin', 'super-admin'])
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo($permission));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Borrar el permiso limpia por cascade sus filas en role_has_permissions
        // y model_has_permissions; los roles no se tocan (pueden preexistir).
        Permission::where('name', 'project send creation')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
