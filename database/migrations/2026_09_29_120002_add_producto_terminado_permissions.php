<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Permisos del módulo Producto Terminado. Se otorgan a los mismos roles
     * que ya tengan el permiso equivalente de raw material — así quien hoy
     * administra materias primas/corazones puede administrar productos
     * terminados sin un paso manual adicional en Settings > Permisos.
     */
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $map = [
            'producto terminado list'   => 'raw material list',
            'producto terminado create' => 'raw material create',
            'producto terminado edit'   => 'raw material edit',
            'producto terminado delete' => 'raw material delete',
        ];

        foreach ($map as $new => $existing) {
            $permission = Permission::firstOrCreate(['name' => $new, 'guard_name' => 'web']);

            $equivalent = Permission::where('name', $existing)->first();
            if ($equivalent) {
                $equivalent->roles()->get()->each(
                    fn (Role $role) => $role->givePermissionTo($permission)
                );
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::whereIn('name', [
            'producto terminado list', 'producto terminado create',
            'producto terminado edit', 'producto terminado delete',
        ])->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
