<?php

namespace Database\Seeders;

use App\Models\EnvelopeType;
use Illuminate\Database\Seeder;

class EnvelopeTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['name' => 'Frasco de vidrio 30ml', 'category' => 'Personal Care', 'active' => true],
            ['name' => 'Frasco de vidrio 50ml', 'category' => 'Personal Care', 'active' => true],
            ['name' => 'Frasco de vidrio 100ml', 'category' => 'Personal Care', 'active' => true],
            ['name' => 'Frasco PET 100ml', 'category' => 'Personal Care', 'active' => true],
            ['name' => 'Frasco PET 200ml', 'category' => 'Personal Care', 'active' => true],
            ['name' => 'Atomizador 30ml', 'category' => 'Personal Care', 'active' => true],
            ['name' => 'Atomizador 50ml', 'category' => 'Personal Care', 'active' => true],
            ['name' => 'Tarro plástico 250ml', 'category' => 'Home Care', 'active' => true],
            ['name' => 'Tarro plástico 500ml', 'category' => 'Home Care', 'active' => true],
            ['name' => 'Tarro vidrio 250ml', 'category' => 'Home Care', 'active' => true],
            ['name' => 'Botella spray 250ml', 'category' => 'Air Care', 'active' => true],
            ['name' => 'Botella spray 500ml', 'category' => 'Air Care', 'active' => true],
            ['name' => 'Aerosol 250ml', 'category' => 'Air Care', 'active' => true],
            ['name' => 'Caja de cartón', 'category' => 'Empaque', 'active' => true],
            ['name' => 'Bolsa plástica', 'category' => 'Empaque', 'active' => true],
        ];

        foreach ($types as $type) {
            EnvelopeType::firstOrCreate(
                ['name' => $type['name']],
                $type
            );
        }
    }
}
