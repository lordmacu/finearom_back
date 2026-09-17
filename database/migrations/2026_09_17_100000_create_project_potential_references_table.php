<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Potencial a la vista: referencias que el cliente seleccionó de un
     * proyecto, con los datos comerciales que llena la ejecutiva (hoja
     * "POTENCIAL A LA VISTA" hasta PROBABILIDAD, sin el plan mensual).
     */
    public function up(): void
    {
        Schema::create('project_potential_references', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('reference_id')
                ->unique()
                ->constrained('project_marketing_variant_references')
                ->cascadeOnDelete();
            $table->decimal('kg_anio', 12, 2)->nullable();
            $table->date('fecha_primer_despacho')->nullable();
            $table->decimal('venta_anio_usd', 14, 2)->nullable();
            $table->string('frecuencia_compra', 20)->nullable();
            $table->text('seguimiento')->nullable();
            $table->string('estado', 20)->default('abierto');
            $table->string('probabilidad', 10)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_potential_references');
    }
};
