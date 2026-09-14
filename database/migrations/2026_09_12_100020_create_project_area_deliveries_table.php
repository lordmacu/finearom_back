<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Notas de entrega de las áreas SIN subentidad propia (regulatoria y
     * especiales). Aplicaciones, evaluaciones y marketing guardan las suyas
     * en project_applications / project_evaluations / project_marketing;
     * estas dos van aquí, una fila por proyecto y área.
     */
    public function up(): void
    {
        Schema::create('project_area_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('area', 30); // regulatoria | especiales
            $table->text('notas_entrega')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'area']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_area_deliveries');
    }
};
