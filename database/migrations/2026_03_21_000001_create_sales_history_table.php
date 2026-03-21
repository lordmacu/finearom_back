<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_history', function (Blueprint $table) {
            $table->id();
            $table->string('nit', 50);
            $table->string('cliente', 200);
            $table->string('ejecutivo', 200)->nullable();
            $table->string('cliente_tipo', 20)->nullable(); // NACIONAL / EXTERIOR
            $table->string('categoria', 100)->nullable();
            $table->string('codigo', 50);
            $table->string('referencia', 300);
            $table->string('año', 4);
            $table->string('mes', 20); // ENERO, FEBRERO, etc.
            $table->bigInteger('venta')->default(0);
            $table->integer('cantidad')->default(0);
            $table->boolean('newwin')->default(false);
            $table->string('estado', 50)->nullable();
            $table->timestamps();

            // Índices para consultas frecuentes
            $table->index('nit');
            $table->index('codigo');
            $table->index(['nit', 'codigo']);
            $table->index(['año', 'mes']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_history');
    }
};
