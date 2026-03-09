<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('time_samples', function (Blueprint $table) {
            $table->id();
            $table->decimal('rango_min', 10, 2);
            $table->decimal('rango_max', 10, 2);
            $table->enum('tipo_cliente', ['pareto', 'balance', 'none']);
            $table->integer('valor');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_samples');
    }
};
