<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('raw_materials', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 100)->nullable();
            $table->string('nombre');
            $table->enum('tipo', ['corazon', 'materia_prima']);
            $table->enum('unidad', ['kg', 'g', 'L', 'ml', 'un']);
            $table->decimal('costo_unitario', 12, 4)->default(0);
            $table->decimal('stock_disponible', 12, 4)->default(0);
            $table->text('descripcion')->nullable();
            $table->string('proveedor', 255)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->index('codigo');
            $table->index('nombre');
            $table->index('tipo');
            $table->index('activo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('raw_materials');
    }
};
