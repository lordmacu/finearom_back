<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('swissarom_price_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('swissarom_reference_id')->constrained('swissarom_references')->cascadeOnDelete();
            $table->decimal('precio_anterior', 10, 2)->nullable();
            $table->decimal('precio_nuevo', 10, 2);
            $table->string('changed_by', 200)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('swissarom_price_history');
    }
};
