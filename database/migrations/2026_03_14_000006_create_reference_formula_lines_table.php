<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reference_formula_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('finearom_reference_id')
                  ->constrained('finearom_references')
                  ->cascadeOnDelete();
            $table->foreignId('raw_material_id')
                  ->constrained('raw_materials')
                  ->cascadeOnDelete();
            $table->decimal('porcentaje', 6, 4);
            $table->text('notas')->nullable();
            $table->timestamps();

            $table->unique(['finearom_reference_id', 'raw_material_id']);
            $table->index('finearom_reference_id');
            $table->index('raw_material_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reference_formula_lines');
    }
};
