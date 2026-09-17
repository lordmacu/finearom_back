<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Permisos del módulo "Potencial a la vista" para los usuarios que ya existen.
 *
 * En producción todo el personal interno que usa proyectos tiene alguno de
 * estos roles (verificado 2026-09-16); los usuarios cliente (order-creator,
 * Creador de Ordenes de Compra) y los de solo consulta quedan por fuera.
 * Se otorga por rol para que los usuarios nuevos de esos roles lo hereden.
 * Idempotente: se puede correr varias veces.
 *
 *   php artisan db:seed --class=ProjectPotentialPermissionSeeder --force
 */
class ProjectPotentialPermissionSeeder extends Seeder
{
    public const PERMISSIONS = ['project potential list', 'project potential edit'];

    // super-admin se incluye explícito: el menú del frontend no conoce el bypass de Gate::before
    public const ROLES = ['super-admin', 'admin', 'Administrador', 'Gerente'];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $name) {
            Permission::findOrCreate($name, 'web');
        }

        Role::whereIn('name', self::ROLES)->where('guard_name', 'web')->get()
            ->each(fn (Role $role) => $role->givePermissionTo(self::PERMISSIONS));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
