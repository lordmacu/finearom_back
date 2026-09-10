<?php

use App\Support\MarketingVariantPermissions;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $commercial = Permission::firstOrCreate([
            'name' => MarketingVariantPermissions::COMMERCIAL, 'guard_name' => 'web',
        ]);
        $technical = Permission::firstOrCreate([
            'name' => MarketingVariantPermissions::TECHNICAL, 'guard_name' => 'web',
        ]);
        $list = Permission::firstOrCreate(['name' => 'project list', 'guard_name' => 'web']);

        Role::firstOrCreate(['name' => 'Comercial', 'guard_name' => 'web'])
            ->givePermissionTo($list, $commercial);

        Role::firstOrCreate(['name' => 'Desarrollo', 'guard_name' => 'web'])
            ->givePermissionTo($list, $technical);

        Role::firstOrCreate(['name' => 'Marketing', 'guard_name' => 'web'])
            ->givePermissionTo($list);

        // Compatibilidad: quien hoy edita proyectos sigue manejando variantes
        // y referencias. No se enumeran roles: se consulta quién tiene 'project edit'.
        Role::whereHas('permissions', fn ($q) => $q->where('name', 'project edit'))
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo($commercial, $technical));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Solo se borran los dos permisos que crea esta migración. Los roles
        // Comercial/Desarrollo/Marketing NO se borran aquí: pueden preexistir
        // (firstOrCreate en up()) y tienen usuarios asignados en producción
        // (Task 5); borrar la fila de Role arrastraría por cascade TODAS sus
        // asignaciones (model_has_roles) y demás permisos
        // (role_has_permissions), no solo lo que agregó esta migración. El
        // borrado del permiso sí limpia por cascade su fila en
        // role_has_permissions, que es exactamente lo que hay que revertir.
        Permission::whereIn('name', [
            MarketingVariantPermissions::COMMERCIAL,
            MarketingVariantPermissions::TECHNICAL,
        ])->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
