<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('time_fine', function (Blueprint $table) {
            $table->id();
            $table->integer('num_fragrances_min');
            $table->integer('num_fragrances_max');
            $table->enum('tipo_cliente', ['pareto', 'balance', 'none']);
            $table->integer('valor');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_fine');
    }
};
