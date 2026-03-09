<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fragrances', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('referencia')->nullable();
            $table->string('codigo')->nullable();
            $table->decimal('precio', 10, 2)->default(0);
            $table->decimal('precio_usd', 10, 2)->default(0);
            $table->json('usos')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fragrances');
    }
};
