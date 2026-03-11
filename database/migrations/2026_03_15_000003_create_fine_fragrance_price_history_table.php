<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fine_fragrance_price_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fine_fragrance_id')
                ->constrained('fine_fragrances')
                ->cascadeOnDelete();
            $table->decimal('precio_coleccion', 10, 2)->nullable();
            $table->decimal('costo', 10, 2)->nullable();
            $table->decimal('precio_oferta', 10, 2)->nullable();
            $table->string('registrado_por')->nullable();
            $table->string('notas')->nullable();
            $table->timestamps();

            $table->index('fine_fragrance_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fine_fragrance_price_history');
    }
};
