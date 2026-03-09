<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fine_fragrances', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('codigo')->nullable();
            $table->decimal('precio', 10, 2)->default(0);
            $table->decimal('precio_usd', 10, 2)->default(0);
            $table->foreignId('casa_id')
                ->nullable()
                ->constrained('fragrance_houses')
                ->nullOnDelete();
            $table->foreignId('family_id')
                ->nullable()
                ->constrained('fragrance_families')
                ->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fine_fragrances');
    }
};
