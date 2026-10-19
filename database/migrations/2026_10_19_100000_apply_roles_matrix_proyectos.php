<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Ajuste de roles y permisos del módulo de Proyectos según la matriz revisada por el negocio
 * (roles_permisos_proyectos.xlsx, hoja Matriz, 2026-10-19) y creación del rol Regulatoria.
 *
 * Solo toca los permisos listados abajo; el resto de permisos de cada rol queda como está.
 * Es idempotente: se puede correr más de una vez.
 */
return new class extends Migration
{
    /** Rol nuevo y sus permisos. */
    private const REGULATORIA = [
        'project deliver', 'project list', 'raw material list',
        'producto terminado list', 'finearom reference list', 'fine fragrance list',
    ];

    /** Permisos que se agregan a cada rol. */
    private const DAR = [
        'Director de Investigación y Desarrollo' => ['fine fragrance create', 'fine fragrance delete', 'fine fragrance edit', 'fine fragrance list', 'finearom reference create', 'finearom reference delete', 'finearom reference edit', 'finearom reference list', 'producto terminado create', 'producto terminado delete', 'producto terminado edit', 'producto terminado list', 'project assign engineer', 'project create', 'project deliver', 'project factor view', 'project send creation', 'project variant technical', 'raw material create', 'raw material delete', 'raw material edit', 'raw material list'],
        'Desarrollo' => ['producto terminado create', 'producto terminado delete', 'producto terminado edit', 'raw material create', 'raw material delete', 'raw material edit', 'raw material list'],
        'Marketing' => ['project deliver'],
        'Comercial' => ['project external status', 'project potential edit', 'project potential list'],
    ];

    /** Permisos que se quitan de cada rol. */
    private const QUITAR = [
        'Desarrollo' => ['project assign engineer'],
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $regulatoria = Role::firstOrCreate(['name' => 'Regulatoria', 'guard_name' => 'web']);
        $regulatoria->givePermissionTo($this->permisos(self::REGULATORIA));

        foreach (self::DAR as $rol => $permisos) {
            Role::where('name', $rol)->where('guard_name', 'web')->first()?->givePermissionTo($this->permisos($permisos));
        }
        foreach (self::QUITAR as $rol => $permisos) {
            $role = Role::where('name', $rol)->where('guard_name', 'web')->first();
            foreach ($permisos as $permiso) {
                $role?->revokePermissionTo($permiso);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::DAR as $rol => $permisos) {
            $role = Role::where('name', $rol)->where('guard_name', 'web')->first();
            foreach ($permisos as $permiso) {
                $role?->revokePermissionTo($permiso);
            }
        }
        foreach (self::QUITAR as $rol => $permisos) {
            Role::where('name', $rol)->where('guard_name', 'web')->first()?->givePermissionTo($this->permisos($permisos));
        }
        // El rol Regulatoria se deja: puede tener usuarios asignados

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** @param array<int, string> $nombres */
    private function permisos(array $nombres): array
    {
        return array_map(fn ($n) => Permission::firstOrCreate(['name' => $n, 'guard_name' => 'web']), $nombres);
    }
};
