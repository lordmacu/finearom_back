<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('raw_material_price_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('raw_material_id')
                  ->constrained('raw_materials')
                  ->cascadeOnDelete();
            $table->decimal('costo_anterior', 12, 4);
            $table->decimal('costo_nuevo', 12, 4);
            $table->foreignId('changed_by')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
            $table->timestamps();

            $table->index('raw_material_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('raw_material_price_history');
    }
};
