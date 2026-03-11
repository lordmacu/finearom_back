<?php

namespace Database\Seeders;

use App\Models\ContributionMargin;
use Illuminate\Database\Seeder;

class ContributionMarginSeeder extends Seeder
{
    public function run(): void
    {
        $registros = [
            // --- pareto ---
            [
                'tipo_cliente' => 'pareto',
                'volumen_min'  => 0,
                'volumen_max'  => 49,
                'factor'       => 1.6000,
                'descripcion'  => 'Pareto — 0 a 49 Kg/año',
                'activo'       => true,
            ],
            [
                'tipo_cliente' => 'pareto',
                'volumen_min'  => 50,
                'volumen_max'  => 199,
                'factor'       => 1.5000,
                'descripcion'  => 'Pareto — 50 a 199 Kg/año',
                'activo'       => true,
            ],
            [
                'tipo_cliente' => 'pareto',
                'volumen_min'  => 200,
                'volumen_max'  => null,
                'factor'       => 1.4000,
                'descripcion'  => 'Pareto — 200 Kg/año en adelante',
                'activo'       => true,
            ],

            // --- balance ---
            [
                'tipo_cliente' => 'balance',
                'volumen_min'  => 0,
                'volumen_max'  => 49,
                'factor'       => 1.8000,
                'descripcion'  => 'Balance — 0 a 49 Kg/año',
                'activo'       => true,
            ],
            [
                'tipo_cliente' => 'balance',
                'volumen_min'  => 50,
                'volumen_max'  => 199,
                'factor'       => 1.7000,
                'descripcion'  => 'Balance — 50 a 199 Kg/año',
                'activo'       => true,
            ],
            [
                'tipo_cliente' => 'balance',
                'volumen_min'  => 200,
                'volumen_max'  => null,
                'factor'       => 1.6000,
                'descripcion'  => 'Balance — 200 Kg/año en adelante',
                'activo'       => true,
            ],

            // --- none ---
            [
                'tipo_cliente' => 'none',
                'volumen_min'  => 0,
                'volumen_max'  => 49,
                'factor'       => 2.0000,
                'descripcion'  => 'Sin clasificar — 0 a 49 Kg/año',
                'activo'       => true,
            ],
            [
                'tipo_cliente' => 'none',
                'volumen_min'  => 50,
                'volumen_max'  => 199,
                'factor'       => 1.9000,
                'descripcion'  => 'Sin clasificar — 50 a 199 Kg/año',
                'activo'       => true,
            ],
            [
                'tipo_cliente' => 'none',
                'volumen_min'  => 200,
                'volumen_max'  => null,
                'factor'       => 1.8000,
                'descripcion'  => 'Sin clasificar — 200 Kg/año en adelante',
                'activo'       => true,
            ],
        ];

        foreach ($registros as $registro) {
            ContributionMargin::firstOrCreate(
                [
                    'tipo_cliente' => $registro['tipo_cliente'],
                    'volumen_min'  => $registro['volumen_min'],
                    'volumen_max'  => $registro['volumen_max'],
                ],
                $registro
            );
        }
    }
}
