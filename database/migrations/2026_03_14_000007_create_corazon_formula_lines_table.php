<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('corazon_formula_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('corazon_id')
                  ->constrained('raw_materials')
                  ->cascadeOnDelete();
            $table->foreignId('raw_material_id')
                  ->constrained('raw_materials')
                  ->cascadeOnDelete();
            $table->decimal('porcentaje', 7, 4); // % del ingrediente en el corazón (0.0000–100.0000)
            $table->text('notas')->nullable();
            $table->timestamps();

            $table->unique(['corazon_id', 'raw_material_id']);
            $table->index('corazon_id');
            $table->index('raw_material_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('corazon_formula_lines');
    }
};
