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

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // \App\Models\User::factory(10)->create();

        // \App\Models\User::factory()->create([
        //     'name' => 'Test User',
        //     'email' => 'test@example.com',
        // ]);
    }
}
