<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Consolidar cert_* booleanos en el array `calidad`.
        // Para cada project_marketing:
        //   - Renombrar legacy items: 'MSDS Mezclas' → 'MSDS', 'Alergénos' → 'Certificados Alergenos'
        //   - Si cert_* = true, agregar el item correspondiente al array (sin duplicados)
        DB::table('project_marketing')->orderBy('id')->chunkById(100, function ($rows) {
            foreach ($rows as $row) {
                $calidad = json_decode($row->calidad, true);
                if (!is_array($calidad)) {
                    $calidad = [];
                }

                $calidad = array_map(function ($item) {
                    if ($item === 'MSDS Mezclas') return 'MSDS';
                    if ($item === 'Alergénos') return 'Certificados Alergenos';
                    return $item;
                }, $calidad);

                $addIfMissing = function (&$arr, $val) {
                    if ($val && !in_array($val, $arr, true)) {
                        $arr[] = $val;
                    }
                };
                $addIfMissing($calidad, 'Certificados Alergenos');
                $addIfMissing($calidad, 'Biodegradabilidad');
                $addIfMissing($calidad, 'Libre crueldad animal');
                $addIfMissing($calidad, 'Certificado de análisis');

                $calidad = array_values(array_unique($calidad));

                DB::table('project_marketing')
                    ->where('id', $row->id)
                    ->update(['calidad' => json_encode($calidad)]);
            }
        });

        // Drop de las columnas booleanas (su info ya está en `calidad`).
        Schema::table('project_marketing', function (Blueprint $table) {
            $table->dropColumn([
                'cert_alergenos',
                'cert_biodegradabilidad',
                'cert_animal_testing',
                'cert_coa',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('project_marketing', function (Blueprint $table) {
            $table->boolean('cert_alergenos')->default(false)->after('obs_calidad');
            $table->boolean('cert_biodegradabilidad')->default(false)->after('cert_alergenos');
            $table->boolean('cert_animal_testing')->default(false)->after('cert_biodegradabilidad');
            $table->boolean('cert_coa')->default(false)->after('cert_animal_testing');
        });
    }
};