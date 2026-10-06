<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Materias primas provisionales (códigos 320/321 de Sensient y 330 "PC") que
 * después se reemplazan por su equivalente código 300. La marca de texto que se
 * puso en descriptores al importarlas pasa a esta columna.
 */
return new class extends Migration
{
    private const MARCA = '⚠ PENDIENTE: cambiar al equivalente código 300';

    public function up(): void
    {
        Schema::table('raw_materials', function (Blueprint $table) {
            $table->boolean('pendiente_equivalencia')->default(false)->after('activo')->index();
            $table->foreignId('equivalente_id')->nullable()->after('pendiente_equivalencia')
                ->constrained('raw_materials')->nullOnDelete();
        });

        DB::table('raw_materials')->where('descriptores', 'like', self::MARCA . '%')->orderBy('id')
            ->chunkById(500, function ($filas) {
                foreach ($filas as $f) {
                    $resto = trim(substr($f->descriptores, strlen(self::MARCA)));
                    $resto = ltrim($resto, "| \t");
                    DB::table('raw_materials')->where('id', $f->id)->update([
                        'pendiente_equivalencia' => true,
                        'descriptores' => $resto === '' ? null : $resto,
                    ]);
                }
            });
    }

    public function down(): void
    {
        DB::table('raw_materials')->where('pendiente_equivalencia', true)->orderBy('id')
            ->chunkById(500, function ($filas) {
                foreach ($filas as $f) {
                    DB::table('raw_materials')->where('id', $f->id)->update([
                        'descriptores' => self::MARCA . ($f->descriptores ? ' | ' . $f->descriptores : ''),
                    ]);
                }
            });

        Schema::table('raw_materials', function (Blueprint $table) {
            $table->dropConstrainedForeignId('equivalente_id');
            $table->dropColumn('pendiente_equivalencia');
        });
    }
};
