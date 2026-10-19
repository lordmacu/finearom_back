<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Eliminar proyectos queda limitado al rol "Director de Investigación y Desarrollo" y a super-admin.
 *
 * - Crea el rol si no existe, con lo mínimo para entrar a Proyectos y eliminar (el resto de sus
 *   permisos se ajusta desde la pantalla de Roles).
 * - Asigna `project delete` al director y a super-admin.
 * - Se lo quita a admin, Administrador y Gerente, que lo tenían desde la migración original.
 */
return new class extends Migration
{
    private const ROL = 'Director de Investigación y Desarrollo';
    private const REVOCAR = ['admin', 'Administrador', 'Gerente'];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $delete = Permission::firstOrCreate(['name' => 'project delete', 'guard_name' => 'web']);
        $list = Permission::firstOrCreate(['name' => 'project list', 'guard_name' => 'web']);

        $director = Role::firstOrCreate(['name' => self::ROL, 'guard_name' => 'web']);
        $director->givePermissionTo([$list, $delete]);

        Role::where('name', 'super-admin')->where('guard_name', 'web')->first()?->givePermissionTo($delete);

        foreach (self::REVOCAR as $nombre) {
            Role::where('name', $nombre)->where('guard_name', 'web')->first()?->revokePermissionTo($delete);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::REVOCAR as $nombre) {
            Role::where('name', $nombre)->where('guard_name', 'web')->first()?->givePermissionTo('project delete');
        }
        // El rol y su permiso se dejan: pueden tener usuarios asignados
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
