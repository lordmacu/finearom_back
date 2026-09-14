<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * El rol Comercial es quien crea los proyectos (flujo del hilo de correo):
     * tenía project list/edit/send creation pero le faltaba project create.
     */
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permission = Permission::findOrCreate('project create', 'web');
        Role::where('name', 'Comercial')->where('guard_name', 'web')->first()
            ?->givePermissionTo($permission);
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Role::where('name', 'Comercial')->where('guard_name', 'web')->first()
            ?->revokePermissionTo('project create');
    }
};
