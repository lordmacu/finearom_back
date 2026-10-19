<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** "Tipo de etiqueta" de la columna Aplicación: laboratorio o marketing. Distinto de tipo_etiquetado (Estándar/SGA). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('tipo_etiqueta', 20)->nullable()->after('tipo_etiquetado');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('tipo_etiqueta');
        });
    }
};
