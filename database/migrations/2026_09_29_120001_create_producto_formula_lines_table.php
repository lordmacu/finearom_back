<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('producto_formula_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('producto_terminado_id')
                  ->constrained('productos_terminados')
                  ->cascadeOnDelete();
            $table->foreignId('raw_material_id')
                  ->constrained('raw_materials')
                  ->cascadeOnDelete();
            $table->decimal('porcentaje', 7, 4);
            $table->text('notas')->nullable();
            $table->timestamps();

            $table->unique(['producto_terminado_id', 'raw_material_id'], 'pfl_producto_rm_unique');
            $table->index('raw_material_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('producto_formula_lines');
    }
};
