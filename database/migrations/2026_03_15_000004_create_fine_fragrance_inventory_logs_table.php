<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fine_fragrance_inventory_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fine_fragrance_id')
                ->constrained('fine_fragrances')
                ->cascadeOnDelete();
            $table->enum('tipo', ['entrada', 'salida', 'ajuste', 'importacion']);
            $table->decimal('cantidad_kg', 10, 3);
            $table->decimal('inventario_anterior_kg', 10, 3);
            $table->decimal('inventario_nuevo_kg', 10, 3);
            $table->string('notas')->nullable();
            $table->string('registrado_por')->nullable();
            $table->timestamps();

            $table->index('fine_fragrance_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fine_fragrance_inventory_logs');
    }
};
