<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Un producto terminado puede llevar OTRO producto terminado como ingrediente.
 *
 * Cada línea de fórmula apunta a una materia prima/corazón (raw_material_id) O a un
 * producto terminado (ingrediente_producto_id), nunca a los dos: lo valida la aplicación.
 * Es aditivo: las líneas existentes quedan igual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('producto_formula_lines', function (Blueprint $table) {
            $table->dropForeign(['raw_material_id']);
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE producto_formula_lines MODIFY raw_material_id BIGINT UNSIGNED NULL');
        } else {
            Schema::table('producto_formula_lines', function (Blueprint $table) {
                $table->unsignedBigInteger('raw_material_id')->nullable()->change();
            });
        }

        Schema::table('producto_formula_lines', function (Blueprint $table) {
            $table->foreign('raw_material_id')->references('id')->on('raw_materials')->cascadeOnDelete();

            // Sin cascada: no se puede borrar un producto que otro usa como ingrediente
            $table->foreignId('ingrediente_producto_id')->nullable()->after('raw_material_id')
                  ->constrained('productos_terminados')->restrictOnDelete();
            $table->unique(['producto_terminado_id', 'ingrediente_producto_id'], 'pfl_producto_ingprod_unique');
        });
    }

    public function down(): void
    {
        DB::table('producto_formula_lines')->whereNull('raw_material_id')->delete();

        Schema::table('producto_formula_lines', function (Blueprint $table) {
            $table->dropUnique('pfl_producto_ingprod_unique');
            $table->dropForeign(['ingrediente_producto_id']);
            $table->dropColumn('ingrediente_producto_id');
            $table->dropForeign(['raw_material_id']);
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE producto_formula_lines MODIFY raw_material_id BIGINT UNSIGNED NOT NULL');
        }

        Schema::table('producto_formula_lines', function (Blueprint $table) {
            $table->foreign('raw_material_id')->references('id')->on('raw_materials')->cascadeOnDelete();
        });
    }
};
