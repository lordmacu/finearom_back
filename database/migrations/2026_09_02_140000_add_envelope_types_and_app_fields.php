<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('envelope_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('category', 100)->nullable()->index();
            $table->string('photo_path')->nullable();
            $table->boolean('active')->default(true)->index();
            $table->timestamps();
        });

        // Nuevos campos en tablas existentes
        Schema::table('project_samples', function (Blueprint $table) {
            $table->integer('cantidad_copias')->nullable()->after('cantidad');
        });

        Schema::table('project_applications', function (Blueprint $table) {
            $table->integer('cantidad_aplicacion')->nullable()->after('dosis');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->enum('tipo_etiquetado', ['Estandar', 'SGA'])->nullable()->after('costo_perfumacion_tonelada');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('tipo_etiquetado');
        });

        Schema::table('project_applications', function (Blueprint $table) {
            $table->dropColumn('cantidad_aplicacion');
        });

        Schema::table('project_samples', function (Blueprint $table) {
            $table->dropColumn('cantidad_copias');
        });

        Schema::dropIfExists('envelope_types');
    }
};