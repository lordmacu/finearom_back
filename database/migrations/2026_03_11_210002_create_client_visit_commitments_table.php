<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_visit_commitments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('visit_id')->constrained('client_visits')->cascadeOnDelete();
            $table->text('descripcion');
            $table->string('responsable', 200)->nullable();
            $table->date('fecha_estimada')->nullable();
            $table->boolean('completado')->default(false);
            $table->timestamps();

            $table->index('visit_id');
            $table->index('completado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_visit_commitments');
    }
};
