<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contribution_margins', function (Blueprint $table) {
            $table->id();
            $table->enum('tipo_cliente', ['pareto', 'balance', 'none']);
            $table->integer('volumen_min');           // Kg/año mínimo (inclusive)
            $table->integer('volumen_max')->nullable(); // null = sin límite superior
            $table->decimal('factor', 10, 4);         // ej. 1.7000
            $table->string('descripcion')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->index(['tipo_cliente', 'activo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contribution_margins');
    }
};
