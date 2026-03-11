<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finearom_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('finearom_reference_id')
                  ->constrained('finearom_references')
                  ->cascadeOnDelete();
            $table->date('fecha_evaluacion');
            $table->json('benchmarks')->nullable()->comment('Array de strings con nombres de referencias comparadas');
            $table->decimal('puntaje_agradabilidad', 4, 1)->nullable();
            $table->decimal('puntaje_intensidad', 4, 1)->nullable();
            $table->decimal('puntaje_promedio', 4, 1)->nullable();
            $table->text('observaciones')->nullable();
            $table->foreignId('evaluado_por')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
            $table->timestamps();

            $table->index('finearom_reference_id');
            $table->index('fecha_evaluacion');
            $table->index('puntaje_promedio');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finearom_evaluations');
    }
};
