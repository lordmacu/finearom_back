<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [
            'config view',
            'config edit',
            'backup create',
            'backup restore',
            'email campaign list',
            'email campaign create',
            'email campaign edit',
            'email campaign delete',
            'email campaign send',
            'email campaign resend',
            'analysis view',
            'partial edit',
            'partial delete',
            'cartera view',
            'cartera import',
            'cartera edit',
            'cartera estado view',
            'cartera estado send',
            'proforma upload',
            'product list',
            'product create',
            'product edit',
            'product delete',
            'client list',
            'client create',
            'client edit',
            'client delete',
            'branch office list',
            'branch office create',
            'branch office edit',
            'branch office delete',
            'executive list',
            'executive create',
            'executive edit',
            'executive delete',
            'project list',
            'project create',
            'project edit',
            'project delete',
            'project external status',
            'project deliver',
            'project factor edit',
            'project catalog manage',
            'client visit list',
            'client visit create',
            'client visit edit',
            'client visit delete',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $superAdminRole = Role::query()
            ->where('name', 'super-admin')
            ->where('guard_name', 'web')
            ->first();

        if ($superAdminRole) {
            $superAdminRole->syncPermissions(Permission::query()->where('guard_name', 'web')->get());
        }

        // Roles con acceso completo a proyectos
        $adminLikeRoles = ['admin', 'Administrador', 'Gerente'];
        foreach ($adminLikeRoles as $roleName) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();
            if ($role) {
                $role->givePermissionTo([
                    'project list', 'project create', 'project edit', 'project delete',
                    'project external status', 'project deliver', 'project factor edit',
                    'project catalog manage',
                    'client visit list', 'client visit create', 'client visit edit', 'client visit delete',
                ]);
            }
        }

        // Roles comerciales: pueden ver, crear, editar y cambiar estado externo
        $commercialRoles = ['Creador de Ordenes de Compra', 'order-creator'];
        foreach ($commercialRoles as $roleName) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();
            if ($role) {
                $role->givePermissionTo([
                    'project list', 'project create', 'project edit', 'project external status',
                    'client visit list', 'client visit create', 'client visit edit',
                ]);
            }
        }

        // Roles observadores: solo lectura
        $viewerRoles = ['Observador', 'Visualizador de Ordenes de Compra', 'Email Marketing'];
        foreach ($viewerRoles as $roleName) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();
            if ($role) {
                $role->givePermissionTo(['project list', 'client visit list']);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->call(ProjectTimesSeeder::class);

        // \App\Models\User::factory(10)->create();

        // \App\Models\User::factory()->create([
        //     'name' => 'Test User',
        //     'email' => 'test@example.com',
        // ]);
    }
}
