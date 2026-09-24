<?php

use App\Support\ProjectEngineerPermission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * El ingeniero de desarrollo lo asigna otro ingeniero (rol Desarrollo) o un
 * admin / super-admin, no la comercial.
 */
return new class extends Migration
{
    private const ROLES = [ProjectEngineerPermission::ROLE, 'admin', 'super-admin'];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permission = Permission::firstOrCreate(['name' => ProjectEngineerPermission::ASSIGN, 'guard_name' => 'web']);
        foreach (self::ROLES as $name) {
            // Desarrollo se crea si no existe; admin y super-admin solo si ya existen
            $role = $name === ProjectEngineerPermission::ROLE
                ? Role::firstOrCreate(['name' => $name, 'guard_name' => 'web'])
                : Role::where('name', $name)->where('guard_name', 'web')->first();

            if ($role) {
                $role->givePermissionTo($permission);
                $this->forgetUserPermissions($role);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::where('name', ProjectEngineerPermission::ASSIGN)->where('guard_name', 'web')->first()?->delete();

        foreach (Role::whereIn('name', self::ROLES)->where('guard_name', 'web')->get() as $role) {
            $this->forgetUserPermissions($role);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** El login cachea los permisos por usuario una hora (AuthController). */
    private function forgetUserPermissions(Role $role): void
    {
        foreach ($role->users()->pluck('id') as $id) {
            Cache::forget("user.{$id}.permissions");
        }
    }
};
