<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('raw_material_stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('raw_material_id')
                  ->constrained('raw_materials')
                  ->cascadeOnDelete();
            $table->enum('tipo', ['entrada', 'salida', 'ajuste']);
            $table->decimal('cantidad', 12, 4);
            $table->text('notas')->nullable();
            $table->foreignId('user_id')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
            $table->date('fecha');
            $table->timestamps();

            $table->index('raw_material_id');
            $table->index('fecha');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('raw_material_stock_movements');
    }
};
