<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_quotation_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->tinyInteger('version')->default(1);
            $table->string('enviado_a')->nullable();
            $table->string('ejecutivo');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_quotation_logs');
    }
};
