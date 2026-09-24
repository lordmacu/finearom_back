<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca "Selección de envase para aplicación" en la sección de envases de
 * Aplicaciones: solo se marca o se desmarca.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->boolean('seleccion_envase_aplicacion')->default(false)->after('area_aplicacion');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('seleccion_envase_aplicacion');
        });
    }
};
