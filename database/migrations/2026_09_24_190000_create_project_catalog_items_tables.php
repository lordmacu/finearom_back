<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogos de Marketing que se eligen en el proyecto igual que los envases:
 * diseños de etiqueta y pirámides (nombre, categoría y foto). Una sola tabla
 * con `tipo` y un pivote proyecto ↔ ítem.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_catalog_items', function (Blueprint $table) {
            $table->id();
            $table->string('tipo', 20)->index(); // etiqueta | piramide
            $table->string('name', 100);
            $table->string('category', 100)->nullable();
            $table->string('photo_path')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('project_catalog_item_project', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('catalog_item_id')->constrained('project_catalog_items')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['project_id', 'catalog_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_catalog_item_project');
        Schema::dropIfExists('project_catalog_items');
    }
};
