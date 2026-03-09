<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fragrance_families', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('familia_olfativa')->nullable();
            $table->string('nucleo')->nullable();
            $table->string('genero')->nullable();
            $table->foreignId('casa_id')
                ->nullable()
                ->constrained('fragrance_houses')
                ->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fragrance_families');
    }
};
