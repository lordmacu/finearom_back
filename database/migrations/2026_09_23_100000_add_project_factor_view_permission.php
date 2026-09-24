<?php

use App\Models\User;
use App\Support\ProjectFactorPermission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * El factor del proyecto solo lo ven Mónica y el rol Desarrollo.
 */
return new class extends Migration
{
    private const MONICA = 'monica.castano@finearom.com';

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permission = Permission::firstOrCreate(['name' => ProjectFactorPermission::VIEW, 'guard_name' => 'web']);

        $desarrollo = Role::firstOrCreate(['name' => 'Desarrollo', 'guard_name' => 'web']);
        $desarrollo->givePermissionTo($permission);

        User::where('email', self::MONICA)->first()?->givePermissionTo($permission);

        $this->forgetUserPermissions($desarrollo);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permission = Permission::where('name', ProjectFactorPermission::VIEW)->where('guard_name', 'web')->first();
        $desarrollo = Role::where('name', 'Desarrollo')->where('guard_name', 'web')->first();
        $permission?->delete();

        if ($desarrollo) {
            $this->forgetUserPermissions($desarrollo);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** El login cachea los permisos por usuario una hora (AuthController). */
    private function forgetUserPermissions(Role $desarrollo): void
    {
        $ids = $desarrollo->users()->pluck('id')
            ->merge(User::where('email', self::MONICA)->pluck('id'));

        foreach ($ids as $id) {
            Cache::forget("user.{$id}.permissions");
        }
    }
};
