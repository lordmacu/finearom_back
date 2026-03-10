<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProjectTimesSeeder extends Seeder
{
    public function run(): void
    {
        // Asegurar soporte decimal para columnas que almacenan horas fraccionadas
        DB::statement('ALTER TABLE time_marketing MODIFY valor DECIMAL(8,2) NOT NULL DEFAULT 0');
        DB::statement('ALTER TABLE time_quality MODIFY valor DECIMAL(8,2) NOT NULL DEFAULT 0');

        // Truncar todas las tablas (idempotente)
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('group_classifications')->truncate();
        DB::table('time_samples')->truncate();
        DB::table('time_evaluations')->truncate();
        DB::table('time_marketing')->truncate();
        DB::table('time_quality')->truncate();
        DB::table('time_homologations')->truncate();
        DB::table('time_responses')->truncate();
        DB::table('time_fine')->truncate();
        DB::table('time_applications')->truncate();
        DB::table('project_product_types')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        // ====================================================================
        // project_product_types — 46 tipos del legacy (productos)
        // IDs preservados para que time_applications y projects los referencien igual
        // ====================================================================
        DB::table('project_product_types')->insert([
            ['id' => 1,  'nombre' => 'ACEITES',               'categoria' => 1],
            ['id' => 2,  'nombre' => 'ACONDICIONADOR',        'categoria' => 9],
            ['id' => 3,  'nombre' => 'AMBIENTADOR',           'categoria' => 9],
            ['id' => 4,  'nombre' => 'ANTIBACTERIAL',         'categoria' => 5],
            ['id' => 5,  'nombre' => 'CERA CABELLO',          'categoria' => 8],
            ['id' => 6,  'nombre' => 'CERAS POLIMÉRICAS',     'categoria' => 11],
            ['id' => 7,  'nombre' => 'COLONIA',               'categoria' => 4],
            ['id' => 8,  'nombre' => 'CREMA CORPORAL',        'categoria' => 4],
            ['id' => 9,  'nombre' => 'CREMA DE MANOS',        'categoria' => 5],
            ['id' => 10, 'nombre' => 'DETERGENTE EN POLVO',   'categoria' => 10],
            ['id' => 11, 'nombre' => 'DETERGENTE LÍQUIDO',    'categoria' => 10],
            ['id' => 12, 'nombre' => 'GEL ANTIBACTERIAL',     'categoria' => 5],
            ['id' => 13, 'nombre' => 'GEL CAPILAR',           'categoria' => 8],
            ['id' => 14, 'nombre' => 'HIPOCLORITO',           'categoria' => 10],
            ['id' => 15, 'nombre' => 'JABÓN DE TOCADOR',      'categoria' => 9],
            ['id' => 16, 'nombre' => 'JABÓN EN BARRA',        'categoria' => 9],
            ['id' => 17, 'nombre' => 'JABÓN EN BARRA ROPA',   'categoria' => 10],
            ['id' => 18, 'nombre' => 'JABÓN LÍQUIDO',         'categoria' => 9],
            ['id' => 19, 'nombre' => 'LABIAL',                'categoria' => 1],
            ['id' => 20, 'nombre' => 'LAVALOZA CREMA',        'categoria' => 10],
            ['id' => 21, 'nombre' => 'LAVALOZA LÍQUIDO',      'categoria' => 10],
            ['id' => 22, 'nombre' => 'LIMPIAPISOS',           'categoria' => 10],
            ['id' => 23, 'nombre' => 'LOCIÓN CORPORAL',       'categoria' => 4],
            ['id' => 24, 'nombre' => 'LOCIÓN FACIAL',         'categoria' => 1],
            ['id' => 25, 'nombre' => 'PERFUME',               'categoria' => 2],
            ['id' => 26, 'nombre' => 'POLVO FACIAL',          'categoria' => 1],
            ['id' => 27, 'nombre' => 'PROTECTOR SOLAR',       'categoria' => 1],
            ['id' => 28, 'nombre' => 'QUITA ESMALTE',         'categoria' => 1],
            ['id' => 29, 'nombre' => 'SHAMPOO',               'categoria' => 8],
            ['id' => 30, 'nombre' => 'SPLASH',                'categoria' => 4],
            ['id' => 31, 'nombre' => 'SUAVIZANTE DE ROPA',    'categoria' => 10],
            ['id' => 32, 'nombre' => 'TALCO',                 'categoria' => 4],
            ['id' => 33, 'nombre' => 'TALCO CORPORAL',        'categoria' => 4],
            ['id' => 34, 'nombre' => 'TRATAMIENTO CAPILAR',   'categoria' => 8],
            ['id' => 35, 'nombre' => 'VASELINA',              'categoria' => 4],
            ['id' => 36, 'nombre' => 'CREMA FACIAL',          'categoria' => 1],
            ['id' => 37, 'nombre' => 'DESODORANTE',           'categoria' => 4],
            ['id' => 38, 'nombre' => 'ESPUMA DE AFEITAR',     'categoria' => 4],
            ['id' => 39, 'nombre' => 'GEL DE DUCHA',          'categoria' => 4],
            ['id' => 40, 'nombre' => 'MASCARILLA',            'categoria' => 1],
            ['id' => 41, 'nombre' => 'SÉRUM',                 'categoria' => 1],
            ['id' => 42, 'nombre' => 'TÓNICO FACIAL',         'categoria' => 1],
            ['id' => 43, 'nombre' => 'CERA DE DEPILACIÓN',    'categoria' => 4],
            ['id' => 44, 'nombre' => 'EXFOLIANTE',            'categoria' => 4],
            ['id' => 45, 'nombre' => 'ACEITE ESENCIAL',       'categoria' => 1],
            ['id' => 46, 'nombre' => 'OTRO',                  'categoria' => 11],
        ]);

        // ====================================================================
        // group_classifications
        // Mapping: A→pareto, B→pareto (usar A cuando hay conflicto), C→balance, D→none
        // 6 filas: pareto con 4 rangos, balance todo-rango=grupo5, none todo-rango=grupo6
        // ====================================================================
        DB::table('group_classifications')->insert([
            ['tipo_cliente' => 'pareto',  'rango_min' => 50, 'rango_max' => 999999, 'valor' => 1],
            ['tipo_cliente' => 'pareto',  'rango_min' => 30, 'rango_max' => 49,     'valor' => 2],
            ['tipo_cliente' => 'pareto',  'rango_min' => 12, 'rango_max' => 29,     'valor' => 3],
            ['tipo_cliente' => 'pareto',  'rango_min' => 0,  'rango_max' => 11,     'valor' => 4],
            ['tipo_cliente' => 'balance', 'rango_min' => 0,  'rango_max' => 999999, 'valor' => 5],
            ['tipo_cliente' => 'none',    'rango_min' => 0,  'rango_max' => 999999, 'valor' => 6],
        ]);

        // ====================================================================
        // time_samples
        // A→pareto, C→balance, D→none (skip B)
        // ====================================================================
        DB::table('time_samples')->insert([
            // pareto (A)
            ['tipo_cliente' => 'pareto',  'rango_min' => 50, 'rango_max' => 999999, 'valor' => 1],
            ['tipo_cliente' => 'pareto',  'rango_min' => 30, 'rango_max' => 49,     'valor' => 1],
            ['tipo_cliente' => 'pareto',  'rango_min' => 25, 'rango_max' => 29,     'valor' => 1],
            ['tipo_cliente' => 'pareto',  'rango_min' => 12, 'rango_max' => 24,     'valor' => 1],
            ['tipo_cliente' => 'pareto',  'rango_min' => 0,  'rango_max' => 11,     'valor' => 1],
            // balance (C)
            ['tipo_cliente' => 'balance', 'rango_min' => 50, 'rango_max' => 999999, 'valor' => 1],
            ['tipo_cliente' => 'balance', 'rango_min' => 30, 'rango_max' => 49,     'valor' => 1],
            ['tipo_cliente' => 'balance', 'rango_min' => 25, 'rango_max' => 29,     'valor' => 2],
            ['tipo_cliente' => 'balance', 'rango_min' => 12, 'rango_max' => 24,     'valor' => 2],
            ['tipo_cliente' => 'balance', 'rango_min' => 0,  'rango_max' => 11,     'valor' => 2],
            // none (D)
            ['tipo_cliente' => 'none',    'rango_min' => 50, 'rango_max' => 999999, 'valor' => 2],
            ['tipo_cliente' => 'none',    'rango_min' => 30, 'rango_max' => 49,     'valor' => 2],
            ['tipo_cliente' => 'none',    'rango_min' => 25, 'rango_max' => 29,     'valor' => 2],
            ['tipo_cliente' => 'none',    'rango_min' => 12, 'rango_max' => 24,     'valor' => 2],
            ['tipo_cliente' => 'none',    'rango_min' => 0,  'rango_max' => 11,     'valor' => 2],
        ]);

        // ====================================================================
        // time_evaluations
        // Grupos 1-6 (string legacy '1'-'6' → int)
        // ====================================================================
        DB::table('time_evaluations')->insert([
            // En Cabina: g1→3, g2→4, g3→5, g4→6, g5→5, g6→6
            ['solicitud' => 'En Cabina',   'grupo' => 1, 'valor' => 3],
            ['solicitud' => 'En Cabina',   'grupo' => 2, 'valor' => 4],
            ['solicitud' => 'En Cabina',   'grupo' => 3, 'valor' => 5],
            ['solicitud' => 'En Cabina',   'grupo' => 4, 'valor' => 6],
            ['solicitud' => 'En Cabina',   'grupo' => 5, 'valor' => 5],
            ['solicitud' => 'En Cabina',   'grupo' => 6, 'valor' => 6],
            // Estabilidad: g1→20, g2→25, g3→30, g4→35, g5→30, g6→35
            ['solicitud' => 'Estabilidad', 'grupo' => 1, 'valor' => 20],
            ['solicitud' => 'Estabilidad', 'grupo' => 2, 'valor' => 25],
            ['solicitud' => 'Estabilidad', 'grupo' => 3, 'valor' => 30],
            ['solicitud' => 'Estabilidad', 'grupo' => 4, 'valor' => 35],
            ['solicitud' => 'Estabilidad', 'grupo' => 5, 'valor' => 30],
            ['solicitud' => 'Estabilidad', 'grupo' => 6, 'valor' => 35],
            // En uso: g1→3, g2→4, g3→5, g4→6, g5→5, g6→6
            ['solicitud' => 'En uso',      'grupo' => 1, 'valor' => 3],
            ['solicitud' => 'En uso',      'grupo' => 2, 'valor' => 4],
            ['solicitud' => 'En uso',      'grupo' => 3, 'valor' => 5],
            ['solicitud' => 'En uso',      'grupo' => 4, 'valor' => 6],
            ['solicitud' => 'En uso',      'grupo' => 5, 'valor' => 5],
            ['solicitud' => 'En uso',      'grupo' => 6, 'valor' => 6],
            // Triangular: g1→2, g2→3, g3→4, g4→5, g5→4, g6→5
            ['solicitud' => 'Triangular',  'grupo' => 1, 'valor' => 2],
            ['solicitud' => 'Triangular',  'grupo' => 2, 'valor' => 3],
            ['solicitud' => 'Triangular',  'grupo' => 3, 'valor' => 4],
            ['solicitud' => 'Triangular',  'grupo' => 4, 'valor' => 5],
            ['solicitud' => 'Triangular',  'grupo' => 5, 'valor' => 4],
            ['solicitud' => 'Triangular',  'grupo' => 6, 'valor' => 5],
        ]);

        // ====================================================================
        // time_marketing — columna valor ya alterada a DECIMAL(8,2)
        // Grupos 1-4
        // ====================================================================
        DB::table('time_marketing')->insert([
            ['solicitud' => 'Descripción Olfativa',     'grupo' => 1, 'valor' => 0.5],
            ['solicitud' => 'Descripción Olfativa',     'grupo' => 2, 'valor' => 1.0],
            ['solicitud' => 'Descripción Olfativa',     'grupo' => 3, 'valor' => 1.5],
            ['solicitud' => 'Descripción Olfativa',     'grupo' => 4, 'valor' => 2.0],

            ['solicitud' => 'Pirámide Olfativa',        'grupo' => 1, 'valor' => 1.0],
            ['solicitud' => 'Pirámide Olfativa',        'grupo' => 2, 'valor' => 2.0],
            ['solicitud' => 'Pirámide Olfativa',        'grupo' => 3, 'valor' => 3.0],
            ['solicitud' => 'Pirámide Olfativa',        'grupo' => 4, 'valor' => 4.0],

            ['solicitud' => 'Caja',                     'grupo' => 1, 'valor' => 0.5],
            ['solicitud' => 'Caja',                     'grupo' => 2, 'valor' => 1.0],
            ['solicitud' => 'Caja',                     'grupo' => 3, 'valor' => 1.5],
            ['solicitud' => 'Caja',                     'grupo' => 4, 'valor' => 2.0],

            ['solicitud' => 'Presentación',             'grupo' => 1, 'valor' => 1.5],
            ['solicitud' => 'Presentación',             'grupo' => 2, 'valor' => 2.0],
            ['solicitud' => 'Presentación',             'grupo' => 3, 'valor' => 2.5],
            ['solicitud' => 'Presentación',             'grupo' => 4, 'valor' => 3.0],

            ['solicitud' => 'Presentación Cero',        'grupo' => 1, 'valor' => 5.0],
            ['solicitud' => 'Presentación Cero',        'grupo' => 2, 'valor' => 6.0],
            ['solicitud' => 'Presentación Cero',        'grupo' => 3, 'valor' => 7.0],
            ['solicitud' => 'Presentación Cero',        'grupo' => 4, 'valor' => 8.0],

            ['solicitud' => 'Dummie Digital',           'grupo' => 1, 'valor' => 1.5],
            ['solicitud' => 'Dummie Digital',           'grupo' => 2, 'valor' => 2.0],
            ['solicitud' => 'Dummie Digital',           'grupo' => 3, 'valor' => 2.5],
            ['solicitud' => 'Dummie Digital',           'grupo' => 4, 'valor' => 3.0],

            ['solicitud' => 'Dummie Fisico',            'grupo' => 1, 'valor' => 0.5],
            ['solicitud' => 'Dummie Fisico',            'grupo' => 2, 'valor' => 1.0],
            ['solicitud' => 'Dummie Fisico',            'grupo' => 3, 'valor' => 1.5],
            ['solicitud' => 'Dummie Fisico',            'grupo' => 4, 'valor' => 2.0],

            ['solicitud' => 'Investigación De Mercado', 'grupo' => 1, 'valor' => 20.0],
            ['solicitud' => 'Investigación De Mercado', 'grupo' => 2, 'valor' => 0.0],
            ['solicitud' => 'Investigación De Mercado', 'grupo' => 3, 'valor' => 0.0],
            ['solicitud' => 'Investigación De Mercado', 'grupo' => 4, 'valor' => 0.0],
        ]);

        // ====================================================================
        // time_quality — columna valor ya alterada a DECIMAL(8,2)
        // Grupos 1-6
        // ====================================================================
        DB::table('time_quality')->insert([
            ['solicitud' => 'MSDS Mezclas',            'grupo' => 1, 'valor' => 2.0],
            ['solicitud' => 'MSDS Mezclas',            'grupo' => 2, 'valor' => 3.0],
            ['solicitud' => 'MSDS Mezclas',            'grupo' => 3, 'valor' => 4.0],
            ['solicitud' => 'MSDS Mezclas',            'grupo' => 4, 'valor' => 5.0],
            ['solicitud' => 'MSDS Mezclas',            'grupo' => 5, 'valor' => 6.0],
            ['solicitud' => 'MSDS Mezclas',            'grupo' => 6, 'valor' => 6.0],

            ['solicitud' => 'Alergénos',               'grupo' => 1, 'valor' => 2.0],
            ['solicitud' => 'Alergénos',               'grupo' => 2, 'valor' => 3.0],
            ['solicitud' => 'Alergénos',               'grupo' => 3, 'valor' => 4.0],
            ['solicitud' => 'Alergénos',               'grupo' => 4, 'valor' => 5.0],
            ['solicitud' => 'Alergénos',               'grupo' => 5, 'valor' => 6.0],
            ['solicitud' => 'Alergénos',               'grupo' => 6, 'valor' => 6.0],

            ['solicitud' => 'IFRA',                    'grupo' => 1, 'valor' => 2.0],
            ['solicitud' => 'IFRA',                    'grupo' => 2, 'valor' => 3.0],
            ['solicitud' => 'IFRA',                    'grupo' => 3, 'valor' => 4.0],
            ['solicitud' => 'IFRA',                    'grupo' => 4, 'valor' => 5.0],
            ['solicitud' => 'IFRA',                    'grupo' => 5, 'valor' => 6.0],
            ['solicitud' => 'IFRA',                    'grupo' => 6, 'valor' => 6.0],

            ['solicitud' => 'Ficha Técnica',           'grupo' => 1, 'valor' => 0.5],
            ['solicitud' => 'Ficha Técnica',           'grupo' => 2, 'valor' => 1.0],
            ['solicitud' => 'Ficha Técnica',           'grupo' => 3, 'valor' => 1.5],
            ['solicitud' => 'Ficha Técnica',           'grupo' => 4, 'valor' => 2.0],
            ['solicitud' => 'Ficha Técnica',           'grupo' => 5, 'valor' => 2.5],
            ['solicitud' => 'Ficha Técnica',           'grupo' => 6, 'valor' => 2.5],

            ['solicitud' => 'Certificado De Solvente', 'grupo' => 1, 'valor' => 8.0],
            ['solicitud' => 'Certificado De Solvente', 'grupo' => 2, 'valor' => 9.0],
            ['solicitud' => 'Certificado De Solvente', 'grupo' => 3, 'valor' => 10.0],
            ['solicitud' => 'Certificado De Solvente', 'grupo' => 4, 'valor' => 11.0],
            ['solicitud' => 'Certificado De Solvente', 'grupo' => 5, 'valor' => 12.0],
            ['solicitud' => 'Certificado De Solvente', 'grupo' => 6, 'valor' => 12.0],
        ]);

        // ====================================================================
        // time_homologations
        // ====================================================================
        DB::table('time_homologations')->insert([
            ['num_variantes_min' => 1, 'num_variantes_max' => 2, 'grupo' => 1, 'valor' => 10],
            ['num_variantes_min' => 1, 'num_variantes_max' => 2, 'grupo' => 2, 'valor' => 11],
            ['num_variantes_min' => 1, 'num_variantes_max' => 2, 'grupo' => 3, 'valor' => 12],
            ['num_variantes_min' => 1, 'num_variantes_max' => 2, 'grupo' => 4, 'valor' => 13],

            ['num_variantes_min' => 3, 'num_variantes_max' => 4, 'grupo' => 1, 'valor' => 11],
            ['num_variantes_min' => 3, 'num_variantes_max' => 4, 'grupo' => 2, 'valor' => 12],
            ['num_variantes_min' => 3, 'num_variantes_max' => 4, 'grupo' => 3, 'valor' => 13],
            ['num_variantes_min' => 3, 'num_variantes_max' => 4, 'grupo' => 4, 'valor' => 14],

            ['num_variantes_min' => 5, 'num_variantes_max' => 6, 'grupo' => 1, 'valor' => 12],
            ['num_variantes_min' => 5, 'num_variantes_max' => 6, 'grupo' => 2, 'valor' => 13],
            ['num_variantes_min' => 5, 'num_variantes_max' => 6, 'grupo' => 3, 'valor' => 14],
            ['num_variantes_min' => 5, 'num_variantes_max' => 6, 'grupo' => 4, 'valor' => 15],
        ]);

        // ====================================================================
        // time_responses
        // ====================================================================
        DB::table('time_responses')->insert([
            ['num_variantes_min' => 1, 'num_variantes_max' => 2, 'grupo' => 1, 'valor' => 3],
            ['num_variantes_min' => 1, 'num_variantes_max' => 2, 'grupo' => 2, 'valor' => 4],
            ['num_variantes_min' => 1, 'num_variantes_max' => 2, 'grupo' => 3, 'valor' => 5],
            ['num_variantes_min' => 1, 'num_variantes_max' => 2, 'grupo' => 4, 'valor' => 6],

            ['num_variantes_min' => 3, 'num_variantes_max' => 4, 'grupo' => 1, 'valor' => 4],
            ['num_variantes_min' => 3, 'num_variantes_max' => 4, 'grupo' => 2, 'valor' => 5],
            ['num_variantes_min' => 3, 'num_variantes_max' => 4, 'grupo' => 3, 'valor' => 6],
            ['num_variantes_min' => 3, 'num_variantes_max' => 4, 'grupo' => 4, 'valor' => 7],

            ['num_variantes_min' => 5, 'num_variantes_max' => 6, 'grupo' => 1, 'valor' => 5],
            ['num_variantes_min' => 5, 'num_variantes_max' => 6, 'grupo' => 2, 'valor' => 6],
            ['num_variantes_min' => 5, 'num_variantes_max' => 6, 'grupo' => 3, 'valor' => 7],
            ['num_variantes_min' => 5, 'num_variantes_max' => 6, 'grupo' => 4, 'valor' => 8],
        ]);

        // ====================================================================
        // time_fine
        // A→pareto, skip B, C→balance, D→none
        // ====================================================================
        DB::table('time_fine')->insert([
            // pareto (A): 3, 5, 7, 9, 11, 13
            ['num_fragrances_min' => 1,  'num_fragrances_max' => 5,  'tipo_cliente' => 'pareto',  'valor' => 3],
            ['num_fragrances_min' => 6,  'num_fragrances_max' => 10, 'tipo_cliente' => 'pareto',  'valor' => 5],
            ['num_fragrances_min' => 11, 'num_fragrances_max' => 15, 'tipo_cliente' => 'pareto',  'valor' => 7],
            ['num_fragrances_min' => 16, 'num_fragrances_max' => 30, 'tipo_cliente' => 'pareto',  'valor' => 9],
            ['num_fragrances_min' => 31, 'num_fragrances_max' => 45, 'tipo_cliente' => 'pareto',  'valor' => 11],
            ['num_fragrances_min' => 46, 'num_fragrances_max' => 60, 'tipo_cliente' => 'pareto',  'valor' => 13],
            // balance (C): 5, 7, 9, 11, 13, 15
            ['num_fragrances_min' => 1,  'num_fragrances_max' => 5,  'tipo_cliente' => 'balance', 'valor' => 5],
            ['num_fragrances_min' => 6,  'num_fragrances_max' => 10, 'tipo_cliente' => 'balance', 'valor' => 7],
            ['num_fragrances_min' => 11, 'num_fragrances_max' => 15, 'tipo_cliente' => 'balance', 'valor' => 9],
            ['num_fragrances_min' => 16, 'num_fragrances_max' => 30, 'tipo_cliente' => 'balance', 'valor' => 11],
            ['num_fragrances_min' => 31, 'num_fragrances_max' => 45, 'tipo_cliente' => 'balance', 'valor' => 13],
            ['num_fragrances_min' => 46, 'num_fragrances_max' => 60, 'tipo_cliente' => 'balance', 'valor' => 15],
            // none (D): 6, 8, 10, 12, 14, 16
            ['num_fragrances_min' => 1,  'num_fragrances_max' => 5,  'tipo_cliente' => 'none',    'valor' => 6],
            ['num_fragrances_min' => 6,  'num_fragrances_max' => 10, 'tipo_cliente' => 'none',    'valor' => 8],
            ['num_fragrances_min' => 11, 'num_fragrances_max' => 15, 'tipo_cliente' => 'none',    'valor' => 10],
            ['num_fragrances_min' => 16, 'num_fragrances_max' => 30, 'tipo_cliente' => 'none',    'valor' => 12],
            ['num_fragrances_min' => 31, 'num_fragrances_max' => 45, 'tipo_cliente' => 'none',    'valor' => 14],
            ['num_fragrances_min' => 46, 'num_fragrances_max' => 60, 'tipo_cliente' => 'none',    'valor' => 16],
        ]);

        // ====================================================================
        // time_applications
        // product_ids: 3,6-16,18,20-21,23-25,28-36
        // A→pareto, skip B, C→balance, D→none
        // ====================================================================
        $productIds = [3,6,7,8,9,10,11,12,13,14,15,16,18,20,21,23,24,25,28,29,30,31,32,33,34,35,36];
        $ranges = [
            [50,  999999],
            [30,  49],
            [25,  29],
            [12,  24],
            [0,   11],
        ];
        // Generic values per tipo_cliente per range index (0=rango50+, 1=30-49, 2=25-29, 3=12-24, 4=0-11)
        $valoresPorTipo = [
            'pareto'  => [1, 1, 1, 2, 3],
            'balance' => [2, 2, 3, 3, 4],
            'none'    => [0, 0, 0, 0, 0],
        ];
        $appRows = [];
        foreach ($productIds as $productId) {
            foreach (['pareto', 'balance', 'none'] as $tipo) {
                foreach ($ranges as $ri => [$min, $max]) {
                    $appRows[] = [
                        'product_id'   => $productId,
                        'tipo_cliente' => $tipo,
                        'rango_min'    => $min,
                        'rango_max'    => $max,
                        'valor'        => $valoresPorTipo[$tipo][$ri],
                    ];
                }
            }
        }
        DB::table('time_applications')->insert($appRows);

        $this->command->info('ProjectTimesSeeder completado:');
        $this->command->info('  group_classifications : ' . DB::table('group_classifications')->count());
        $this->command->info('  time_samples          : ' . DB::table('time_samples')->count());
        $this->command->info('  time_evaluations      : ' . DB::table('time_evaluations')->count());
        $this->command->info('  time_marketing        : ' . DB::table('time_marketing')->count());
        $this->command->info('  time_quality          : ' . DB::table('time_quality')->count());
        $this->command->info('  time_homologations    : ' . DB::table('time_homologations')->count());
        $this->command->info('  time_responses        : ' . DB::table('time_responses')->count());
        $this->command->info('  time_fine             : ' . DB::table('time_fine')->count());
        $this->command->info('  time_applications     : ' . DB::table('time_applications')->count());
    }
}
