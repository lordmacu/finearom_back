<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variant_id')->constrained('project_variants')->cascadeOnDelete();
            $table->foreignId('swissarom_reference_id')
                ->nullable()
                ->constrained('swissarom_references')
                ->nullOnDelete();
            $table->boolean('definitiva')->default(false);
            $table->decimal('total_propuesta', 10, 2)->nullable();
            $table->decimal('total_propuesta_cop', 12, 2)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_proposals');
    }
};
