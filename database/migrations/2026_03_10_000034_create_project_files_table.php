<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('nombre_original');
            $table->string('nombre_storage');
            $table->string('path');
            $table->string('mime_type');
            $table->bigInteger('size');
            $table->string('categoria')->nullable();
            $table->string('ejecutivo');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_files');
    }
};
