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
            $table->string('nit', 50);        // FK → clients.nit
            $table->string('codigo', 50);     // FK → products.code
            $table->string('año', 4);
            $table->string('mes', 20);        // ENERO, FEBRERO, etc.
            $table->bigInteger('venta')->default(0);
            $table->integer('cantidad')->default(0);
            $table->boolean('newwin')->default(false);
            $table->timestamps();

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
