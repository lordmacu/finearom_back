<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_marketing_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('referencia', 200)->nullable();
            $table->string('codigo', 100)->nullable();
            $table->string('aplicacion', 200)->nullable();
            $table->decimal('dosis', 8, 2)->nullable();
            $table->string('color_etiqueta', 50)->nullable();
            $table->text('claims')->nullable();
            $table->timestamps();
        });

        Schema::table('project_marketing', function (Blueprint $table) {
            $table->json('benchmark_examples')->nullable()->after('benchmark_links');
            $table->json('catalog_etiquetas')->nullable()->after('benchmark_examples');
            $table->json('catalog_piramides')->nullable()->after('catalog_etiquetas');
            $table->json('lista_presentaciones')->nullable()->after('catalog_piramides');
        });
    }

    public function down(): void
    {
        Schema::table('project_marketing', function (Blueprint $table) {
            $table->dropColumn(['lista_presentaciones', 'catalog_piramides', 'catalog_etiquetas', 'benchmark_examples']);
        });

        Schema::dropIfExists('project_marketing_variants');
    }
};