<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El benchmark de cada variante ya no es una referencia Finearom: es una
 * imagen y una descripción propias de la variante. categoria, observaciones y
 * benchmark_reference_id se conservan con los datos viejos, pero ya no se usan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_variants', function (Blueprint $table) {
            $table->text('benchmark_descripcion')->nullable()->after('descripcion');
            $table->string('benchmark_imagen', 500)->nullable()->after('benchmark_descripcion');
        });
    }

    public function down(): void
    {
        Schema::table('project_variants', function (Blueprint $table) {
            $table->dropColumn(['benchmark_descripcion', 'benchmark_imagen']);
        });
    }
};
