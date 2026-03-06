<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SiigoSyncUserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'siigo-sync@finearom.com'],
            [
                'name' => 'Siigo Sync Service',
                'password' => Hash::make('SiigoSync2026!'),
            ]
        );

        $this->command->info('Usuario de servicio creado: siigo-sync@finearom.com / SiigoSync2026!');
    }
}
