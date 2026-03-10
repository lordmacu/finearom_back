<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prospects', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('nit')->nullable();
            $table->enum('tipo_cliente', ['pareto', 'balance', 'none'])->default('none');
            $table->string('ejecutivo')->nullable();
            $table->unsignedBigInteger('legacy_id')->nullable()->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prospects');
    }
};
