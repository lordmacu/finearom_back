<?php

use Database\Seeders\ProjectPotentialPermissionSeeder;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Módulo "Potencial a la vista": crea los permisos y los asigna a los
     * roles administrativos (ver ProjectPotentialPermissionSeeder).
     */
    public function up(): void
    {
        (new ProjectPotentialPermissionSeeder())->run();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::whereIn('name', ProjectPotentialPermissionSeeder::PERMISSIONS)
            ->where('guard_name', 'web')
            ->delete();
    }
};
