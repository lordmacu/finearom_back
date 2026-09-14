<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * El bypass de Gate::before para super-admin es solo a nivel de backend:
     * el menú del frontend decide qué mostrar mirando los permisos explícitos
     * del usuario, sin saber de ese bypass. Sin esto, super-admin no ve el
     * link "Envases" aunque la API sí le dejaría usarlo.
     */
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permission = Permission::findOrCreate('envelope type manage', 'web');
        Role::where('name', 'super-admin')->where('guard_name', 'web')->first()
            ?->givePermissionTo($permission);
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Role::where('name', 'super-admin')->where('guard_name', 'web')->first()
            ?->revokePermissionTo('envelope type manage');
    }
};
